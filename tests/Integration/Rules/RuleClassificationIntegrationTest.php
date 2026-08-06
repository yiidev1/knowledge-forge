<?php

declare(strict_types=1);

namespace App\Tests\Integration\Rules;

use App\Rules\Application\AliasNormalizer;
use App\Rules\Application\RuleClassificationService;
use App\Rules\Application\RuleHasher;
use App\Rules\Application\RuleStoreMatcher;
use App\Rules\Domain\AliasType;
use App\Rules\Domain\ClassificationContext;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Infrastructure\DbRuleCatalogRepository;
use App\Rules\Infrastructure\DbRuleClassificationEventRepository;
use App\Rules\Infrastructure\DbRuleStoreLinkRepository;
use App\Rules\Infrastructure\DbStoreAliasRepository;
use App\Tests\Support\IntegrationDb;
use Codeception\Test\Unit;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;
use function substr;
use function PHPUnit\Framework\assertSame;

use const STR_PAD_LEFT;

/**
 * Persisted classification against real MySQL: an exact alias auto-matches to a *confirmed* link with an audit
 * event, a repeat pass is idempotent (no new rows), a rule with no store candidate is auto-confirmed common, and
 * an automated pass never overwrites an admin decision.
 */
final class RuleClassificationIntegrationTest extends Unit
{
    private const TITLE_MARK = 'ZZCLASS';
    private const STORE = 973000030;

    private ConnectionInterface $connection;
    private DbRuleCatalogRepository $catalog;
    private DbStoreAliasRepository $aliases;
    private RuleClassificationService $service;
    private RuleHasher $hasher;
    private DateTimeImmutable $now;
    private int $seq = 0;

    protected function _before(): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->catalog = new DbRuleCatalogRepository($this->connection);
        $this->aliases = new DbStoreAliasRepository($this->connection);
        $this->hasher = new RuleHasher();
        $this->service = new RuleClassificationService(
            $this->catalog,
            new DbRuleStoreLinkRepository($this->connection),
            new DbRuleClassificationEventRepository($this->connection),
            new RuleStoreMatcher(),
        );
        $this->now = new DateTimeImmutable('2026-08-05 00:00:00', new DateTimeZone('UTC'));
        $this->cleanup();
    }

    protected function _after(): void
    {
        $this->cleanup();
    }

    public function testExactAliasAutoMatchesToAConfirmedLinkWithAnEventAndIsIdempotent(): void
    {
        $this->aliases->upsertApproved(self::STORE, 'Moon Temple', AliasNormalizer::normalize('Moon Temple'), AliasType::OfficialName, null, $this->now);
        $canonical = $this->seedCanonical(self::TITLE_MARK . ' Moon Temple', 'Advance orders policy');
        $context = new ClassificationContext([self::STORE], $this->aliases->findApprovedAliases());

        $this->service->classify($canonical, $context, $this->now);

        assertSame(ClassificationStatus::AutoMatched->value, $this->statusOf($canonical));
        assertSame(RuleScope::StoreSpecific->value, $this->scopeOf($canonical));
        // A single deterministic match is a CONFIRMED link → the store projection materializes automatically
        // (the matched store gets the rule at stage 1); an admin can still reject or change it later.
        assertSame(1, $this->linkCount($canonical, 'confirmed'));
        assertSame(0, $this->linkCount($canonical, 'suggested'));
        assertSame(1, $this->eventCount($canonical));

        // A second identical pass changes nothing and appends no new event.
        $this->service->classify($canonical, $context, $this->now);
        assertSame(1, $this->eventCount($canonical), 'idempotent: no duplicate audit event');
        assertSame(1, $this->linkCount($canonical, 'confirmed'), 'idempotent: no duplicate link');
    }

    public function testNoStoreCandidateIsAutoConfirmedCommon(): void
    {
        $canonical = $this->seedCanonical(self::TITLE_MARK . ' Callback', 'General callback procedure for all stores');
        $context = new ClassificationContext([], []);

        $this->service->classify($canonical, $context, $this->now);

        assertSame(ClassificationStatus::ConfirmedCommon->value, $this->statusOf($canonical), 'no store → auto-confirmed common');
        assertSame(RuleScope::Common->value, $this->scopeOf($canonical));
    }

    public function testAnAutomatedPassNeverOverwritesAnAdminDecision(): void
    {
        $canonical = $this->seedCanonical(self::TITLE_MARK . ' Confirmed', 'General callback for all stores');
        // An admin has confirmed this as a common rule.
        $this->catalog->updateClassification($canonical, RuleScope::Common->value, ClassificationStatus::ConfirmedCommon->value, 'admin', null, 7, $this->now);

        $this->service->classify($canonical, new ClassificationContext([], []), $this->now);

        assertSame(ClassificationStatus::ConfirmedCommon->value, $this->statusOf($canonical), 'admin decision preserved');
        assertSame(0, $this->eventCount($canonical), 'no automated event over an admin decision');
    }

    private function seedCanonical(string $title, string $description): int
    {
        $identity = $this->hasher->identify($title, $description);
        // Salt the hash with a sequence so distinct test cases never collide on the UNIQUE canonical_hash.
        return $this->catalog->insertCanonical(
            substr($identity->canonicalHash, 0, 56) . str_pad((string) ++$this->seq, 8, '0', STR_PAD_LEFT),
            $identity->descriptionHash,
            $title,
            $identity->content,
            $this->now,
        );
    }

    private function statusOf(int $canonicalId): string
    {
        return (string) $this->catalog->findClassification($canonicalId)['classification_status'];
    }

    private function scopeOf(int $canonicalId): string
    {
        return (string) $this->catalog->findClassification($canonicalId)['scope_type'];
    }

    private function linkCount(int $canonicalId, string $status): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%rule_store_links}}')
            ->where(['rule_catalog_rule_id' => $canonicalId, 'match_status' => $status])
            ->count();
    }

    private function eventCount(int $canonicalId): int
    {
        return (int) $this->connection->createQuery()
            ->from('{{%rule_classification_events}}')
            ->where(['rule_catalog_rule_id' => $canonicalId])
            ->count();
    }

    private function cleanup(): void
    {
        foreach (['{{%rule_store_links}}', '{{%rule_classification_events}}'] as $child) {
            $this->connection->createCommand(
                'DELETE [[c]] FROM ' . $child . ' [[c]]'
                . ' JOIN {{%rule_catalog_rules}} [[k]] ON [[k]].[[id]] = [[c]].[[rule_catalog_rule_id]]'
                . ' WHERE [[k]].[[title]] LIKE :mark',
                [':mark' => self::TITLE_MARK . '%'],
            )->execute();
        }
        IntegrationDb::cleanup($this->connection, '{{%rule_catalog_rules}}', ['like', 'title', self::TITLE_MARK]);
        IntegrationDb::cleanup($this->connection, '{{%order58_store_aliases}}', ['store_source_id' => self::STORE]);
    }
}
