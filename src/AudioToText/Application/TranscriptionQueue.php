<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\AudioTranscriptionException;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\SourceRole;
use App\AudioToText\Domain\TranscriptionJobRepositoryInterface;
use App\AudioToText\Infrastructure\AudioDurationProbe;
use App\Shared\Application\Transaction\TransactionRunnerInterface;
use App\Shared\Domain\Clock\ClockInterface;
use DateTimeImmutable;
use Psr\Http\Message\UploadedFileInterface;
use Throwable;

use function basename;
use function bin2hex;
use function count;
use function mb_substr;
use function pathinfo;
use function random_bytes;
use function scandir;
use function strtolower;

use const PATHINFO_EXTENSION;

/**
 * Turns a validated upload into a QUEUED job.
 *
 * **Uploading and processing are separate concerns.** An administrator may queue as many recordings as
 * they like, whatever else is in flight; the worker then processes them one at a time. There is
 * deliberately no per-administrator limit here — that belonged to an earlier design which enforced
 * "one at a time" in the upload form, and so stopped people queueing work, which is what a queue is
 * for. Concurrency now lives entirely in the worker, where it can be guaranteed rather than merely
 * discouraged.
 *
 * The ordering below is chosen around one constraint: the expensive and slow parts must happen
 * *outside* the lock, and the parts that decide whether a job may exist must happen *inside* it.
 *
 *   write the recording to its directory  — ffprobe needs bytes on disk; this is the slow part
 *   ffprobe duration check                — still outside the lock, so a slow probe blocks nobody
 *   ┌ GET_LOCK ────────────────────────────────────────────────────────┐
 *   │ count active → INSERT                                            │  a millisecond or two
 *   └ RELEASE_LOCK ────────────────────────────────────────────────────┘
 *
 * Counting and then inserting without that lock is not a check at all under concurrency: two uploads
 * can both observe the queue one short of its limit and both take the last slot. The lock is skipped
 * entirely when the limit is disabled, which is the default.
 *
 * Everything after the file is written is wrapped so the job directory is deleted on any rejection.
 * A recording on disk that no row owns is exactly the orphan the sweep exists to catch, and leaving
 * one behind on a predictable path is worse than the sweep is good.
 */
final readonly class TranscriptionQueue
{
    public function __construct(
        private TranscriptionJobRepositoryInterface $jobs,
        private AudioConversationRepositoryInterface $conversations,
        private QueuedAudioStorage $storage,
        private AudioDurationProbe $durations,
        private AudioToTextSettings $settings,
        private ClockInterface $clock,
        private TransactionRunnerInterface $transaction,
    ) {}

    /**
     * Store one upload's recordings and queue a child job for each.
     *
     * One method for both modes, because they differ only in how many files arrive: a common upload is
     * one recording whose speakers the worker must work out, a separate upload is a Customer file and
     * an Agent file whose roles are already known. Everything else — the storage, the duration probe,
     * the cap, the cleanup — is identical, and giving them separate implementations would mean two
     * places for the cleanup to be got wrong.
     *
     * The whole pair is accepted or none of it is. Every file is written and probed **before** the
     * lock is taken, then the parent and all its children are inserted in one transaction inside it;
     * on any failure every file written so far is removed. There is no path that leaves a Customer
     * queued without its Agent.
     *
     * @param array<string, UploadedFileInterface> $files keyed by {@see SourceRole} value
     *
     * @return string the public id of the conversation
     *
     * @throws AudioTranscriptionException with a message written for the uploader
     */
    public function enqueueConversation(
        ConversationMode $mode,
        ?int $storeSourceId,
        array $files,
        int $adminUserId,
    ): string {
        $conversationPublicId = bin2hex(random_bytes(16));
        $children = [];
        $stored = [];

        try {
            // Slow work first and outside the lock: writing bytes and probing each recording.
            foreach ($mode->childRoles() as $role) {
                $file = $files[$role->value] ?? null;

                if ($file === null) {
                    throw AudioTranscriptionException::unexpected();
                }

                $publicId = bin2hex(random_bytes(16));
                $stored[] = $publicId;

                $storedName = $this->storage->store(
                    $publicId,
                    $file,
                    $this->extensionOf($file->getClientFilename()),
                );

                $children[] = [
                    'publicId' => $publicId,
                    'role' => $role,
                    'originalFilename' => $this->safeOriginalFilename($file->getClientFilename()),
                    'storedName' => $storedName,
                    'duration' => $this->assertDurationWithinLimit($publicId),
                ];
            }

            $insert = function () use ($conversationPublicId, $storeSourceId, $mode, $adminUserId, $children): string {
                // Parent and children in one transaction: a pair whose second insert failed would
                // otherwise leave a conversation promising two recordings and holding one.
                return $this->transaction->run(function () use (
                    $conversationPublicId,
                    $storeSourceId,
                    $mode,
                    $adminUserId,
                    $children
                ): string {
                    $conversationId = $this->conversations->create(
                        $conversationPublicId,
                        $storeSourceId,
                        $mode,
                        $adminUserId,
                        $this->clock->now(),
                    );

                    foreach ($children as $child) {
                        $this->jobs->create(
                            $child['publicId'],
                            $adminUserId,
                            $child['originalFilename'],
                            $child['storedName'],
                            $child['duration'],
                            $this->expiresAt(),
                            $conversationId,
                            $child['role'],
                        );
                    }

                    return $conversationPublicId;
                });
            };

            // With no cap there is nothing to serialise, so the named lock is skipped entirely. That is
            // not just an optimisation: holding a lock with a five-second timeout on every upload would
            // mean a busy moment could refuse an upload as "queue full" when no limit exists at all.
            if ($this->settings->transcription->maxQueue <= 0) {
                return $insert();
            }

            $needed = count($children);

            $accepted = $this->jobs->enqueueExclusively(function () use ($insert, $needed): string {
                // Room for the whole upload, checked once. Asking per child would let a pair take the
                // last slot with its Customer and be refused for its Agent.
                $this->assertQueueHasRoom($needed);

                return $insert();
            });

            // Null means one thing only: the named lock itself was refused. Every other outcome either
            // returned an id or threw. Proceeding without the lock would be proceeding without the check.
            if ($accepted === null) {
                throw AudioTranscriptionException::queueFull($this->settings->transcription->maxQueue);
            }

            return $accepted;
        } catch (Throwable $e) {
            foreach ($stored as $publicId) {
                $this->storage->remove($publicId);
            }

            throw $e;
        }
    }

    /**
     * Enforces the machine-wide queue cap, if there is one.
     *
     * `AUDIO_TRANSCRIPTION_MAX_QUEUE = 0` disables the cap entirely and is the default — an
     * administrator should not have to wait to hand the machine more work. A positive value caps the
     * number of QUEUED plus PROCESSING jobs across the whole installation, not per administrator.
     */
    private function assertQueueHasRoom(int $needed): void
    {
        $maxQueue = $this->settings->transcription->maxQueue;

        if ($maxQueue <= 0) {
            return;
        }

        // `+ $needed`, not `>=`: a separate upload needs two slots, and a pair that only half fits is
        // refused whole rather than accepted half.
        if ($this->jobs->countActive() + $needed > $maxQueue) {
            throw AudioTranscriptionException::queueFull($maxQueue);
        }
    }

    /**
     * When this job's record should be deleted, or null to keep it indefinitely.
     *
     * Indefinite is the default for this project: the conversations are meant to be read back later, so
     * nothing expires them on a timer unless an operator configures a window.
     */
    private function expiresAt(): ?DateTimeImmutable
    {
        if ($this->settings->transcription->retainsIndefinitely()) {
            return null;
        }

        return $this->clock->now()
            ->modify('+' . $this->settings->transcription->retentionSeconds . ' seconds');
    }

    /**
     * Measures the recording that was just written and enforces the duration limit.
     *
     * The stored name is not passed in; it is discovered by listing the job directory. That keeps this
     * method honest about what is actually on disk rather than what the caller believes it wrote.
     */
    private function assertDurationWithinLimit(string $publicId): float
    {
        $directory = $this->settings->transcription->jobsDirectory() . '/' . $publicId;
        $entries = @scandir($directory);

        $source = null;
        if ($entries !== false) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $source = $directory . '/' . $entry;

                    break;
                }
            }
        }

        if ($source === null) {
            throw AudioTranscriptionException::uploadUnreadable(
                'the queued audio disappeared before it could be inspected',
            );
        }

        $seconds = $this->durations->seconds($source);

        if (!$this->settings->transcription->allowsDuration($seconds)) {
            throw AudioTranscriptionException::tooLong(
                $seconds,
                $this->settings->transcription->maxDurationSeconds,
                $this->settings->transcription->maxDurationLabel(),
            );
        }

        return $seconds;
    }

    private function extensionOf(?string $clientFilename): string
    {
        if ($clientFilename === null || $clientFilename === '') {
            return 'bin';
        }

        $extension = strtolower(pathinfo(basename($clientFilename), PATHINFO_EXTENSION));

        return $extension === '' ? 'bin' : $extension;
    }

    /**
     * The name shown on the page. Stripped to a basename and length-limited on the way in; re-sanitised
     * again by {@see TranscriptFilename} before it can reach a response header.
     */
    private function safeOriginalFilename(?string $clientFilename): string
    {
        if ($clientFilename === null || $clientFilename === '') {
            return 'recording';
        }

        return mb_substr(basename($clientFilename), 0, 255);
    }
}
