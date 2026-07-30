<?php

declare(strict_types=1);

namespace App\Document\Application\Processing;

use App\Document\Domain\DocumentProcessingRepositoryInterface;
use App\Shared\Domain\Clock\ClockInterface;
use App\Worker\Application\WorkerParams;

/**
 * Returns documents stranded in `processing` (a worker claimed them and then crashed, so they were
 * never released) back to `queued`. Shared by the processing drainer's per-run recovery and the
 * `kf:documents:recover` command, so both use the same timeout threshold.
 */
final readonly class RecoverStuckDocumentsService
{
    public function __construct(
        private DocumentProcessingRepositoryInterface $documents,
        private ClockInterface $clock,
        private WorkerParams $params,
    ) {}

    /**
     * @return int Number of documents recovered.
     */
    public function recover(): int
    {
        $now = $this->clock->now();
        $threshold = $now->modify('-' . $this->params->processingTimeoutMinutes . ' minutes');

        return $this->documents->recoverStuck($threshold, $now);
    }
}
