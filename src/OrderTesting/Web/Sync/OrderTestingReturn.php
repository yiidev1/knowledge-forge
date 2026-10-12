<?php

declare(strict_types=1);

namespace App\OrderTesting\Web\Sync;

/**
 * The only pages "Sync demo orders" may return to.
 *
 * ## Why these are literal strings and not a shared constant
 *
 * Two of the three route names are declared in `OrderTestingRoute`, which lives inside the
 * Audio-to-Text module, and the third belongs to Order58's web surface. `OrderTestingBoundariesTest`
 * forbids any file under `src/OrderTesting/` from naming either module, so importing those constants
 * is not available here.
 *
 * That is a boundary, not an inconvenience to work around: a route **name** is a string the router
 * owns, not a namespace, so repeating it couples this module to the router's vocabulary rather than
 * to another module's code. A rename breaks the allow-list loudly — the sync still works, the button
 * simply returns to the picker — rather than breaking the module graph.
 *
 * ## Why an allow-list at all
 *
 * The obvious design posts the URL to come back to. Anything that reflects a submitted URL into a
 * `Location` header is an open redirect waiting to be found. A route name cannot express an off-site
 * target, so the worst a crafted value can do is send somebody to the picker.
 */
final class OrderTestingReturn
{
    /** The Order Testing store picker, where this button has always lived. The default. */
    public const PICKER = 'order-testing';

    /** One store's Order Testing page. Takes `sourceId`. */
    public const STORE = 'order-testing.store';

    /** Order58 Call Recordings. Takes the `store`, `load`, `date` and `source` filters it reads. */
    public const CALL_RECORDINGS = 'order58.call-recordings';
}
