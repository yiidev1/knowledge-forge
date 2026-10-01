<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\RecordingType;
use DateTimeImmutable;
use RuntimeException;

use function count;

/**
 * The four questions {@see \App\AudioToText\Application\SharedConversationReader} asks, answered from maps.
 *
 * Built around the shape of the real data rather than around the reader: conversations keyed by id, each
 * with a store, a declared type and a call session, and the "exactly one confirmed mixed" rule computed
 * here from that — so a test says "this call has two mixed recordings" by adding a row, the way the
 * database says it, instead of by stubbing the answer the reader is being tested for.
 *
 * Everything else on the interface throws, so a test that starts depending on it fails loudly.
 *
 * @psalm-suppress MissingImmutableAnnotation the interface is not readonly
 */
final class LinkedConversations implements AudioConversationRepositoryInterface
{
    /** @var array<int, array{store: int|null, type: ?RecordingType, session: ?string, jobId: int, confirmed: bool}> */
    private array $rows = [];

    /** Every call to {@see recordCallSession()}, in order, so the backfill's writes can be asserted. */
    public array $written = [];

    public function with(
        int $conversationId,
        ?RecordingType $type,
        ?string $callSessionId,
        int $jobId = 0,
        bool $rolesConfirmed = false,
        ?int $storeSourceId = 831,
    ): self {
        $this->rows[$conversationId] = [
            'store' => $storeSourceId,
            'type' => $type,
            'session' => $callSessionId,
            'jobId' => $jobId,
            'confirmed' => $rolesConfirmed,
        ];

        return $this;
    }

    public function recordingTypeFor(int $conversationId): ?RecordingType
    {
        return $this->rows[$conversationId]['type'] ?? null;
    }

    public function callSessionFor(int $conversationId): ?string
    {
        return $this->rows[$conversationId]['session'] ?? null;
    }

    public function storeSourceIdFor(int $conversationId): ?int
    {
        return $this->rows[$conversationId]['store'] ?? null;
    }

    /**
     * The real rule, computed rather than stubbed: none and several both answer null.
     *
     * Mirrors the SQL, which fetches two rows to answer a question about one precisely so that several
     * cannot be reported as the first of them.
     */
    public function confirmedMixedJobIdForCallSession(int $storeSourceId, string $callSessionId): ?int
    {
        $found = [];

        foreach ($this->rows as $row) {
            if (
                $row['store'] === $storeSourceId
                && $row['session'] === $callSessionId
                && $row['type'] === RecordingType::Mixed
                && $row['confirmed']
            ) {
                $found[] = $row['jobId'];
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    public function recordCallSession(int $conversationId, string $callSessionId): bool
    {
        $this->written[] = [$conversationId, $callSessionId];

        if (($this->rows[$conversationId]['session'] ?? null) !== null) {
            return false;
        }

        $this->rows[$conversationId]['session'] = $callSessionId;

        return true;
    }

    public function create(
        string $publicId,
        ?int $storeSourceId,
        ConversationMode $mode,
        int $uploadedByAdminId,
        DateTimeImmutable $createdAt,
        bool $generateAiAudio = false,
        ?RecordingType $recordingType = null,
        ?string $orderId = null,
        ?string $callSessionId = null,
        ?string $callTimeRaw = null,
    ): int {
        throw new RuntimeException('Not used by these tests.');
    }

    /** @var list<array{id: int, storeSourceId: int|null, recordingType: string|null, filename: string|null}> */
    public array $unlinked = [];

    public function unlinked(int $conversationId, ?string $filename, ?int $storeSourceId = 831): self
    {
        $this->unlinked[] = [
            'id' => $conversationId,
            'storeSourceId' => $storeSourceId,
            'recordingType' => null,
            'filename' => $filename,
        ];

        return $this;
    }

    public function unlinkedForCallSessionBackfill(int $limit): array
    {
        return $this->unlinked;
    }

    public function findByPublicId(string $publicId): ?AudioConversation
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function forStore(int $storeSourceId, int $limit, int $offset = 0): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function countForStore(int $storeSourceId): int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function publicIdFor(int $conversationId): ?string
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function generatesAiAudio(int $conversationId): bool
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function deleteChildless(): int
    {
        throw new RuntimeException('Not used by these tests.');
    }
}
