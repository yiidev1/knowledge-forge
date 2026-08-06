<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Order58;

use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Dto\Order58AuthResult;
use App\Order58\Contract\Dto\Order58Health;
use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use App\Order58\Contract\Dto\Order58Page;
use App\Order58\Contract\Dto\Order58RuleRecord;
use App\Order58\Contract\Exception\Order58Exception;
use App\Order58\Contract\Order58ClientInterface;
use LogicException;
use SensitiveParameter;

/**
 * A programmable {@see Order58ClientInterface} for tests. {@see authenticate()} and the rules list are
 * exercised; the other sync methods throw if a test reaches for them unexpectedly.
 */
final class FakeOrder58Client implements Order58ClientInterface
{
    public ?Order58AuthResult $authResult = null;
    public ?Order58Exception $authException = null;

    /** Usernames authenticate() was called with. Passwords are deliberately never recorded. */
    public array $authenticatedUsernames = [];

    /**
     * Scripted rule pages keyed by requested page number.
     *
     * @var array<int, Order58Page<Order58RuleRecord>>
     */
    public array $rulePages = [];

    /** Thrown by {@see listRules()} when set, to simulate a transport/API failure. */
    public ?Order58Exception $rulesException = null;

    /** @var list<int> Page numbers listRules() was called with, in order. */
    public array $listRulesCalls = [];

    public function authenticate(string $username, #[SensitiveParameter] string $password): Order58AuthResult
    {
        $this->authenticatedUsernames[] = $username;

        if ($this->authException !== null) {
            throw $this->authException;
        }

        return $this->authResult ?? Order58AuthResult::invalid();
    }

    public function health(): Order58Health
    {
        throw new LogicException('health() is not used in this fake.');
    }

    public function listAccounts(int $page, int $perPage): Order58Page
    {
        throw new LogicException('listAccounts() is not used in this fake.');
    }

    public function getAccount(int $id): Order58Account
    {
        throw new LogicException('getAccount() is not used in this fake.');
    }

    public function listAgents(int $page, int $perPage): Order58Page
    {
        throw new LogicException('listAgents() is not used in this fake.');
    }

    public function listKnowledge(?int $storeId, int $page, int $perPage): Order58Page
    {
        throw new LogicException('listKnowledge() is not used in this fake.');
    }

    public function getKnowledgeRecord(int $id): Order58KnowledgeRecord
    {
        throw new LogicException('getKnowledgeRecord() is not used in this fake.');
    }

    public function listRules(int $page, int $perPage): Order58Page
    {
        $this->listRulesCalls[] = $page;

        if ($this->rulesException !== null) {
            throw $this->rulesException;
        }

        return $this->rulePages[$page] ?? throw new LogicException('No scripted rule page ' . $page . '.');
    }

    public function getRule(int $id): Order58RuleRecord
    {
        throw new LogicException('getRule() is not used in this fake.');
    }
}
