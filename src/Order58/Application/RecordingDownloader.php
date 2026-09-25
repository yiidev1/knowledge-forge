<?php

declare(strict_types=1);

namespace App\Order58\Application;

use App\Integration\Order58Recording\ChannelApiProbe;
use App\Integration\Order58Recording\ChannelDiagnosis;
use App\Integration\Order58Recording\ChannelRecordingRequest;
use App\Integration\Order58Recording\RecordingChannel;
use App\Integration\Order58Recording\WavSignature;
use App\Order58\Domain\CallImportItem;
use Throwable;

use function fclose;
use function fopen;
use function fwrite;
use function is_resource;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/**
 * Fetches one recording channel to a temporary file, and says whether it is usable.
 *
 * ## Why the download lives here and not in the client
 *
 * `ChannelApiProbe` is shared with the diagnostic page, and that page is held to a rule its own isolation
 * test enforces: it reads, and it writes nothing, anywhere. Adding a method that writes a file would
 * break that for both consumers. So the client hands over an open response and this class — which the
 * importer owns, and which is expected to touch disk — is what turns it into a file.
 *
 * ## The body is written and counted in one pass
 *
 * A recording is megabytes. It is streamed to disk in chunks, the first few kilobytes are kept for the
 * RIFF/WAVE check, and the running count is the honest byte total. That count is what catches a
 * truncated download — a file with a perfectly valid header that stops early, which is the failure most
 * likely to be mistaken for success.
 *
 * ## Every failure removes its own file
 *
 * A partial download is never handed on. On any non-success the temporary file is unlinked before
 * returning, so a caller cannot accidentally ingest a truncated recording and there is no cleanup path
 * for anyone to forget.
 */
final readonly class RecordingDownloader
{
    /** Enough for the RIFF/WAVE header and a readable error page; the rest is counted and dropped. */
    private const SAMPLE_BYTES = 8192;

    private const CHUNK_BYTES = 262144;

    public function __construct(
        private ChannelApiProbe $probe,
    ) {}

    public function fetch(CallImportItem $item): RecordingDownload
    {
        $request = new ChannelRecordingRequest(
            recordingId: $item->callSessionId,
            // Not sent to the provider — the recording endpoint takes no merchant parameter — but the
            // request object validates it, so it is given the store it belongs to rather than a blank.
            merchantId: (string) $item->storeSourceId,
            time: $item->callDate,
            company: $item->recordingCompany,
            // Overridden for caller and callee by the mapping, which appends the channel suffix itself.
            // For mixed it is the download display name, and the session id is the honest choice.
            name: $item->callSessionId,
        );

        try {
            $response = $this->probe->open($request, $item->channel);
        } catch (Throwable $e) {
            return RecordingDownload::failed(ChannelDiagnosis::fromTransportFailure($e->getMessage()), 0);
        }

        $status = $response->getStatusCode();

        // Anything but a 2xx has no body worth keeping. Read a bounded sample so an IP-allowlist refusal
        // is still recognised from the provider's own wording, and stop.
        if ($status < 200 || $status >= 300) {
            $sample = substr($response->getBody()->read(self::SAMPLE_BYTES), 0, self::SAMPLE_BYTES);

            return RecordingDownload::failed(
                ChannelDiagnosis::fromResponse($status, $response->getHeaderLine('Content-Type'), $sample, 0),
                $status,
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'kf-o58-');

        if ($path === false) {
            return RecordingDownload::failed(
                ChannelDiagnosis::fromTransportFailure('no temporary file could be created'),
                $status,
            );
        }

        $handle = fopen($path, 'wb');

        if (!is_resource($handle)) {
            @unlink($path);

            return RecordingDownload::failed(
                ChannelDiagnosis::fromTransportFailure('the temporary file could not be opened'),
                $status,
            );
        }

        $sample = '';
        $bytes = 0;
        $body = $response->getBody();

        try {
            while (!$body->eof()) {
                $chunk = $body->read(self::CHUNK_BYTES);

                if ($chunk === '') {
                    break;
                }

                if (fwrite($handle, $chunk) === false) {
                    throw new \RuntimeException('the recording could not be written to disk');
                }

                $bytes += strlen($chunk);
                $kept = strlen($sample);

                if ($kept < self::SAMPLE_BYTES) {
                    $sample .= substr($chunk, 0, self::SAMPLE_BYTES - $kept);
                }
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);

            return RecordingDownload::failed(
                ChannelDiagnosis::fromTransportFailure($e->getMessage()),
                $status,
                $bytes,
            );
        }

        fclose($handle);

        // The same verdicts the diagnostic page reaches, from the same two classes: empty body, a 200
        // carrying an error page, a WAV shorter than its own header claims, or a complete recording.
        $diagnosis = ChannelDiagnosis::fromResponse(
            $status,
            $response->getHeaderLine('Content-Type'),
            $sample,
            $bytes,
        );

        if (!$diagnosis->isSuccess()) {
            @unlink($path);

            return RecordingDownload::failed($diagnosis, $status, $bytes);
        }

        // Belt and braces: `isSuccess()` already required a complete WAV, and this says so at the point
        // the bytes are handed on.
        if (!WavSignature::inspect($sample, $bytes)->isWav) {
            @unlink($path);

            return RecordingDownload::failed(
                ChannelDiagnosis::fromResponse($status, 'application/octet-stream', $sample, $bytes),
                $status,
                $bytes,
            );
        }

        return RecordingDownload::succeeded($path, $bytes, $diagnosis, $status);
    }

    /** The name the recording is recorded under, which is where its extension comes from. */
    public function fileNameFor(string $callSessionId, RecordingChannel $channel): string
    {
        return $channel->fileNameFor($callSessionId);
    }
}
