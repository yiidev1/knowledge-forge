<?php

declare(strict_types=1);

namespace App\Order58\Domain;

use function is_int;
use function max;

/**
 * The mutable progress + counters of a sync run, persisted as `progress_json`.
 *
 * `nextPage` is the resume cursor: a run stamps it after each page so an interrupted worker continues
 * from where it stopped rather than restarting the scan. The counters back the admin status display and
 * the completed-with-warnings decision (`skippedMissingStore`/`warnings`).
 */
final class SyncProgress
{
    public function __construct(
        public int $nextPage = 1,
        public int $pagesProcessed = 0,
        public int $created = 0,
        public int $updated = 0,
        public int $unchanged = 0,
        public int $deactivated = 0,
        public int $skippedMissingStore = 0,
        public int $documentsQueued = 0,
        public int $documentsSkippedUnchanged = 0,
        public int $warnings = 0,
    ) {}

    /**
     * @param array<array-key, mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $int = static function (string $key, int $default = 0) use ($data): int {
            $value = $data[$key] ?? null;

            return is_int($value) ? $value : $default;
        };

        return new self(
            nextPage: max(1, $int('next_page', 1)),
            pagesProcessed: $int('pages_processed'),
            created: $int('created'),
            updated: $int('updated'),
            unchanged: $int('unchanged'),
            deactivated: $int('deactivated'),
            skippedMissingStore: $int('skipped_missing_store'),
            documentsQueued: $int('documents_queued'),
            documentsSkippedUnchanged: $int('documents_skipped_unchanged'),
            warnings: $int('warnings'),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'next_page' => $this->nextPage,
            'pages_processed' => $this->pagesProcessed,
            'created' => $this->created,
            'updated' => $this->updated,
            'unchanged' => $this->unchanged,
            'deactivated' => $this->deactivated,
            'skipped_missing_store' => $this->skippedMissingStore,
            'documents_queued' => $this->documentsQueued,
            'documents_skipped_unchanged' => $this->documentsSkippedUnchanged,
            'warnings' => $this->warnings,
        ];
    }
}
