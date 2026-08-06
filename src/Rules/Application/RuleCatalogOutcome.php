<?php

declare(strict_types=1);

namespace App\Rules\Application;

/**
 * The result of linking one raw source rule to the canonical catalog.
 */
enum RuleCatalogOutcome
{
    /** A brand-new canonical rule was created and this source is its primary. */
    case CanonicalCreated;

    /** The canonical already existed; this source was linked as an exact duplicate. */
    case ExactDuplicateLinked;

    /** The source's content changed upstream; its link was moved to a different canonical rule. */
    case Relinked;

    /** Nothing changed — the source was already linked to the correct canonical. */
    case Unchanged;
}
