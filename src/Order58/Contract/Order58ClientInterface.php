<?php

declare(strict_types=1);

namespace App\Order58\Contract;

use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58Health;
use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use App\Order58\Contract\Dto\Order58Page;
use App\Order58\Contract\Exception\Order58Exception;
use SensitiveParameter;

/**
 * The port to the Order58 Integration API. Application code depends only on this; {@see \App\Order58\Client\HttpOrder58Client}
 * is the one adapter behind it, so the transport can be faked in tests without a live server.
 *
 * Every list call is paginated: the caller passes an explicit page and reads {@see Order58Page::$pagination}
 * to decide whether to continue. Agent authentication (`POST /authenticate`) is deliberately absent in
 * Phase 1 — it belongs to the Phase 2 agent login realm.
 */
interface Order58ClientInterface
{
    /**
     * @throws Order58Exception
     */
    public function health(): Order58Health;

    /**
     * @return Order58Page<Order58Account>
     *
     * @throws Order58Exception
     */
    public function listAccounts(int $page, int $perPage): Order58Page;

    /**
     * @throws Order58Exception
     */
    public function getAccount(int $id): Order58Account;

    /**
     * @return Order58Page<\App\Order58\Contract\Dto\Order58Agent>
     *
     * @throws Order58Exception
     */
    public function listAgents(int $page, int $perPage): Order58Page;

    /**
     * @param int|null $storeId When set, scopes to `?store_id=` for one store's knowledge.
     *
     * @return Order58Page<Order58KnowledgeRecord>
     *
     * @throws Order58Exception
     */
    public function listKnowledge(?int $storeId, int $page, int $perPage): Order58Page;

    /**
     * @throws Order58Exception
     */
    public function getKnowledgeRecord(int $id): Order58KnowledgeRecord;

    /**
     * Verifies an agent's credentials server-side. The password is sent only in the request body and is
     * never logged or stored.
     *
     * @throws Order58Exception on a transport failure or a rejected Knowledge Forge Bearer token (not on a
     *                          wrong username/password, which returns an unauthenticated result).
     */
    public function authenticate(string $username, #[SensitiveParameter] string $password): Order58AuthResult;
}
