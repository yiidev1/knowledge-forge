<?php

declare(strict_types=1);

namespace App\Order58\Client\CaseOrders;

/**
 * The one Case Order request.
 *
 * An interface so the synchronisation service can be tested without a socket — which matters more
 * here than usual, because the real call may be made **once a day** and a test that made it would
 * spend the client's allowance.
 */
interface CaseOrderClientInterface
{
    /**
     * Fetch every case order the provider is offering.
     *
     * No arguments: the endpoint takes an empty body and answers for everything. That is also why the
     * allowance is daily rather than per store — there is nothing to ask about.
     *
     * @throws CaseOrderFetchFailed and the exception says whether the allowance was spent
     */
    public function fetch(): CaseOrderResponse;
}
