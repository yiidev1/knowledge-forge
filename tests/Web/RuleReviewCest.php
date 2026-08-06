<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Rules\Application\EnsureCommonRulesKnowledgeBaseService;
use App\Shared\Domain\Clock\SystemClock;
use App\Shared\Infrastructure\Db\DbDateTime;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use DateTimeImmutable;
use DateTimeZone;
use Yiisoft\Db\Connection\ConnectionInterface;

use function str_pad;

/**
 * The rule review UI on the served admin surface: it requires an authenticated admin, and a review action
 * (mark common) reconciles the searchable projection immediately — queuing a document (no OpenAI in the
 * request) and returning to the rule's page with the updated state.
 */
final class RuleReviewCest
{
    private const USERNAME = '__kf_rulerev_admin__';
    private const PASSWORD = 'RuleRevPassw0rd!secure';
    private const TITLE = 'ZZREV Review-me rule';

    private ConnectionInterface $connection;
    private int $adminId;
    private int $ruleId;
    private string $ts;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();
        $this->ts = DbDateTime::format(new DateTimeImmutable('now', new DateTimeZone('UTC')));

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
        $this->adminId = (int) $this->connection
            ->createCommand('SELECT id FROM {{%admin_users}} WHERE username = :u', [':u' => self::USERNAME])
            ->queryScalar();

        // A pending canonical rule awaiting review.
        $this->connection->createCommand()->insert('{{%rule_catalog_rules}}', [
            'canonical_hash' => 'zzrev' . str_pad('1', 59, '0'),
            'description_hash' => 'zzrevd' . str_pad('1', 58, '0'),
            'title' => self::TITLE,
            'content' => 'Always confirm the order total before hanging up.',
            'scope_type' => 'unresolved',
            'classification_status' => 'pending',
            'is_active' => 1,
            'created_at' => $this->ts,
            'updated_at' => $this->ts,
        ])->execute();
        $this->ruleId = (int) $this->connection
            ->createCommand('SELECT id FROM {{%rule_catalog_rules}} WHERE title = :t', [':t' => self::TITLE])
            ->queryScalar();
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function reviewPageRequiresAnAuthenticatedAdmin(WebTester $I): void
    {
        $I->amOnPage('/admin/order58/rules/' . $this->ruleId);
        // The admin middleware bounces an anonymous visitor to the login page.
        $I->seeInCurrentUrl('/login');
    }

    public function adminMarksARulePendingRuleCommonWhichQueuesADocument(WebTester $I): void
    {
        $this->login($I);

        $I->amOnPage('/admin/order58/rules/' . $this->ruleId);
        $I->see(self::TITLE);
        $I->see('Review actions');

        // Marking common reconciles immediately: it queues a document (no OpenAI in the request) and returns here.
        $I->click('Mark as common');
        $I->seeInCurrentUrl('/admin/order58/rules/' . $this->ruleId);
        $I->see('confirmed_common');
        // The materialized common document is visible and queued for the worker.
        $I->see('queued');
    }

    private function login(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    private function cleanup(): void
    {
        $connection = $this->connection ?? IntegrationDb::connectOrSkip();
        // Common-rule documents in the hidden base, then the base, then the rule + its events, then the admin.
        $connection->createCommand(
            'DELETE [[d]] FROM {{%documents}} [[d]] JOIN {{%knowledge_bases}} [[k]] ON [[k]].[[id]] = [[d]].[[knowledge_base_id]]'
            . ' WHERE [[k]].[[slug]] = :slug',
            [':slug' => EnsureCommonRulesKnowledgeBaseService::SLUG],
        )->execute();
        $connection->createCommand(
            'DELETE [[e]] FROM {{%rule_classification_events}} [[e]]'
            . ' JOIN {{%rule_catalog_rules}} [[k]] ON [[k]].[[id]] = [[e]].[[rule_catalog_rule_id]]'
            . ' WHERE [[k]].[[title]] LIKE :mark',
            [':mark' => 'ZZREV%'],
        )->execute();
        IntegrationDb::cleanup($connection, '{{%rule_catalog_rules}}', ['like', 'title', 'ZZREV']);
        IntegrationDb::cleanup($connection, '{{%knowledge_bases}}', ['slug' => EnsureCommonRulesKnowledgeBaseService::SLUG]);
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
