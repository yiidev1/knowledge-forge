<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Db;

use RuntimeException;

/** The server would not say which schema a connection is using. Treated as unsafe, never as empty. */
final class DatabaseTargetUnknown extends RuntimeException {}
