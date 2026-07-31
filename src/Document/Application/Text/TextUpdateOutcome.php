<?php

declare(strict_types=1);

namespace App\Document\Application\Text;

/**
 * The result of editing a manual-text document: whether the normalized content actually changed (and was
 * therefore requeued for re-indexing) or only the title/original text was touched.
 */
enum TextUpdateOutcome
{
    case Unchanged;
    case Reindexed;
}
