<?php

declare(strict_types=1);

namespace App\AudioToText\Application;

use App\AudioToText\Domain\SourceRole;
use Psr\Http\Message\UploadedFileInterface;

use function number_format;
use function sprintf;

/**
 * Validates a Customer and an Agent recording as one submission.
 *
 * It owns no rules of its own — every file goes through the existing {@see AudioUploadValidator}, so
 * the MIME sniffing, extension list, per-file size limit and upload-error handling stay in one place.
 * What it adds is *which field* an error belongs to, and one rule that only exists for a pair.
 *
 * ## The aggregate size rule
 *
 * Two files at the per-file limit is twice the per-file limit on the wire, and the proxy in front of
 * PHP has its own ceiling. When the combined body exceeds it the request dies at nginx with a bare
 * 413 that the application never sees and cannot explain. Checking the sum here means the common case
 * — a pair that is simply too big — produces a sentence naming both sizes instead.
 *
 * The server-side check is authoritative either way; nginx and PHP are the outer wall, not the rule.
 *
 * One honest caveat: because the aggregate is *derived* from the per-file limit — two files at the
 * ceiling plus the envelope — a pair that passes file-by-file always fits it too, so the runtime
 * comparison is a guard on that derivation rather than a rule that fires in normal use. The number
 * still earns its place: {@see aggregateLimitBytes()} is what the upload page quotes and what the
 * `client_max_body_size` in front of PHP has to be set to, and neither of those follows from the
 * per-file limit on its own.
 */
final readonly class SeparateUploadValidator
{
    /**
     * Headroom left for multipart boundaries, part headers and the other form fields.
     *
     * Small in absolute terms — a few hundred bytes per part — but the limit has to sit below what the
     * proxy accepts, not at it, or a request the application approves is refused one layer out.
     */
    private const MULTIPART_OVERHEAD_BYTES = 1_048_576;

    public function __construct(
        private AudioUploadValidator $validator,
        private AudioToTextSettings $settings,
    ) {}

    /**
     * @return array<string, list<string>> field name => errors, empty when the pair is acceptable
     */
    public function validate(?UploadedFileInterface $customer, ?UploadedFileInterface $agent): array
    {
        $errors = [];

        // Both files are checked even when the first already failed: an administrator fixing one
        // problem at a time, submission after submission, is a worse experience than being told both.
        foreach ([
            SourceRole::Customer->value => [$this->fieldFor(SourceRole::Customer), $customer],
            SourceRole::Agent->value => [$this->fieldFor(SourceRole::Agent), $agent],
        ] as [$field, $file]) {
            $messages = $this->validator->validate($file);

            if ($messages !== []) {
                $errors[$field] = $this->prefixed($field, $messages);
            }
        }

        if ($errors !== []) {
            return $errors;
        }

        $combined = ($customer?->getSize() ?? 0) + ($agent?->getSize() ?? 0);

        if ($combined > $this->aggregateLimitBytes()) {
            // Not attached to either field: neither file is individually at fault.
            $errors['form'] = [sprintf(
                'Those two recordings come to %s together, which is over the %s limit for one '
                . 'submission. Each file may be up to %s.',
                $this->megabytes($combined),
                $this->megabytes($this->aggregateLimitBytes()),
                $this->settings->transcription->maxUploadLabel(),
            )];
        }

        return $errors;
    }

    /** The form field an error belongs against. */
    public function fieldFor(SourceRole $role): string
    {
        return match ($role) {
            SourceRole::Customer => 'customer_audio',
            SourceRole::Agent => 'agent_audio',
            SourceRole::Common => 'audio',
        };
    }

    /**
     * Two files at the per-file ceiling, less room for the multipart envelope.
     *
     * Derived rather than configured: it is a consequence of the per-file limit, and a second setting
     * an operator could set inconsistently with the first would only create a way to be wrong.
     */
    public function aggregateLimitBytes(): int
    {
        return ($this->settings->transcription->maxUploadBytes * 2) + self::MULTIPART_OVERHEAD_BYTES;
    }

    /**
     * @param list<string> $messages
     *
     * @return list<string>
     */
    private function prefixed(string $field, array $messages): array
    {
        $label = $field === 'customer_audio' ? 'Customer audio' : 'Agent audio';

        $prefixed = [];
        foreach ($messages as $message) {
            $prefixed[] = $label . ': ' . $message;
        }

        return $prefixed;
    }

    private function megabytes(int $bytes): string
    {
        return number_format($bytes / 1_048_576, 1, '.', '') . ' MB';
    }
}
