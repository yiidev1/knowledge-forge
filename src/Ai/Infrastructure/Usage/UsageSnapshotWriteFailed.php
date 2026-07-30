<?php

declare(strict_types=1);

namespace App\Ai\Infrastructure\Usage;

use RuntimeException;

/**
 * The usage snapshot could not be persisted.
 *
 * Carries an operator-facing message only — never a path, never a provider payload. The sync action
 * turns it into a flash message, and the previous snapshot is still on disk and still served.
 */
final class UsageSnapshotWriteFailed extends RuntimeException {}
