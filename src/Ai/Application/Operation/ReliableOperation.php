<?php

declare(strict_types=1);

namespace App\Ai\Application\Operation;

use App\Ai\Contract\Exception\AiException;
use App\Ai\Domain\AiOperationRepositoryInterface;
use Closure;

use function bin2hex;
use function chr;
use function hash;
use function json_encode;
use function ord;
use function random_bytes;
use function str_split;
use function vsprintf;

/**
 * Runs a non-idempotent OpenAI operation exactly once in effect, backed by the ledger.
 *
 * The guarantee: for a given operation key, the underlying provider call produces at most one lasting
 * object across any number of retries. An already-succeeded operation is never re-run — its result id
 * is returned without a call. A failure is classified by whether it could have taken effect: a
 * definitely-ineffective failure is left `pending` (safe to retry fresh); a possibly-effective one is
 * left `needs_reconcile` for the reconciler to resolve against the provider. The in-flight marker is
 * committed *before* the call, so a crash mid-call is always detectable.
 */
final readonly class ReliableOperation
{
    public function __construct(
        private AiOperationRepositoryInterface $ledger,
    ) {}

    /**
     * @param array<string, mixed> $requestData Used to fingerprint the request (for reconciliation
     *                                           sanity checks); not sent anywhere.
     * @param Closure(string): string $call     Performs the provider call with the supplied idempotency
     *                                           key and returns the resulting provider id.
     *
     * @throws AiException The original provider failure, after the ledger has recorded the outcome.
     */
    public function run(
        string $operationKey,
        string $type,
        string $subjectType,
        int $subjectId,
        array $requestData,
        Closure $call,
    ): string {
        $existing = $this->ledger->findByKey($operationKey);

        // Already done: reuse the persisted id and make no call at all.
        if ($existing !== null && $existing->isSucceeded() && $existing->resultId !== null) {
            return $existing->resultId;
        }

        $fingerprint = hash('sha256', (string) json_encode($requestData));
        $idempotencyKey = $existing?->idempotencyKey ?? self::uuid4();

        // Committed before the request leaves, so an interruption is recoverable.
        $this->ledger->beginInFlight($operationKey, $type, $subjectType, $subjectId, $fingerprint, $idempotencyKey);

        try {
            $resultId = $call($idempotencyKey);
        } catch (AiException $e) {
            $details = $e->details();
            if ($e->isSideEffectPossible()) {
                $this->ledger->markNeedsReconcile($operationKey, $details->code, $details->safeMessage);
            } else {
                $this->ledger->markPending($operationKey, $details->code, $details->safeMessage);
            }
            throw $e;
        }

        $this->ledger->markSucceeded($operationKey, $resultId);

        return $resultId;
    }

    /**
     * A random RFC-4122 version-4 UUID, sent as the provider Idempotency-Key. Best-effort only — the
     * ledger, not this key, is what actually prevents duplicates.
     */
    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
