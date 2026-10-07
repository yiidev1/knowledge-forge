<?php

declare(strict_types=1);

namespace App\OrderTesting\Domain;

/** What one upsert did, so an import run can report counts that mean something. */
enum DemoOrderUpsert
{
    /** A demo order this application had never seen. The only case that can credit an attempt. */
    case Inserted;

    /** Known, and the document had changed. Attribution is left exactly as it was. */
    case Updated;

    /** Known, byte for byte. Nothing was written. */
    case Unchanged;
}
