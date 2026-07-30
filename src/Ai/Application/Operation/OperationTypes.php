<?php

declare(strict_types=1);

namespace App\Ai\Application\Operation;

/**
 * The vocabulary shared between the code that runs an operation and the code that reconciles it.
 *
 * Keeping the type strings and metadata keys in one place ensures the worker (which creates a vector
 * store tagged with the operation key) and the reconciler (which looks it up by that tag) cannot drift
 * apart.
 */
final class OperationTypes
{
    public const VECTOR_STORE_CREATE = 'vs.create';
    public const FILE_UPLOAD = 'file.upload';
    public const FILE_ATTACH = 'vs.attach';

    /**
     * Metadata key stamped on a created vector store so a lost create can be found again.
     */
    public const METADATA_OPERATION_KEY = 'kf_op';

    /**
     * @param int|string $subjectId
     */
    public static function key(string $type, string $subjectType, int|string $subjectId): string
    {
        return $type . ':' . $subjectType . ':' . $subjectId;
    }
}
