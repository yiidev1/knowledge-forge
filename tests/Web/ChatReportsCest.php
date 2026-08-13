<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use Yiisoft\Db\Connection\ConnectionInterface;

/**
 * The report page over real HTTP: it is admin-only, it survives every filter, and it never renders an
 * internal identifier.
 *
 * The agent realm cannot be exercised here — an agent authenticates against the live Order58 API — so the
 * fact that agents have no route to this page is a property of the route group, asserted by the admin
 * redirect below plus the group's own middleware.
 */
final class ChatReportsCest
{
    private const USERNAME = '__kf_reports_admin__';
    private const PASSWORD = 'ReportsTestPassw0rd!secure';
    private const URL = '/admin/reports/chat';

    private ConnectionInterface $connection;

    public function _before(WebTester $I): void
    {
        $this->connection = IntegrationDb::connectOrSkip();
        $this->cleanup();

        (new DbAdminUserRepository($this->connection, new SystemClock()))
            ->create(self::USERNAME, (new NativePasswordHasher())->hash(self::PASSWORD));
    }

    public function _after(WebTester $I): void
    {
        $this->cleanup();
    }

    public function guestCannotReachTheReport(WebTester $I): void
    {
        $I->amOnPage(self::URL);

        // RequireAdminMiddleware redirects rather than returning 401/403.
        $I->seeCurrentUrlEquals('/login');
        $I->dontSee('Agent usage');
    }

    public function adminSeesTheReportAndItsSections(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage(self::URL);

        $I->seeResponseCodeIs(200);
        $I->see('Chat Reports');
        $I->see('Active agents');
        $I->see('Agent usage');
        $I->see('Store usage');
        $I->see('Questions');

        // The derived nature of the time metric is stated on the page, not just in the code.
        $I->see('estimated from message activity');
        $I->see('activity span, not time on page');
    }

    public function quickDatePresetsRenderAndDefaultToLastThirtyDays(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage(self::URL);

        foreach (['Today', 'Last 7 days', 'This week', 'Last 30 days', 'This month', 'Last month'] as $label) {
            $I->see($label);
        }

        // With no parameters the report still opens on the last 30 days, so that chip is the active one.
        $I->seeElement('.report__presets .filter-chip--active[aria-current=true]');
        $I->see('Last 30 days', '.report__presets .filter-chip--active');
    }

    public function followingAPresetAppliesItsDatesAndKeepsOtherFilters(WebTester $I): void
    {
        $this->login($I);
        // Arrive with an unrelated filter already set; the preset must not discard it.
        $I->amOnPage(self::URL . '?type=store');

        $I->click('Today', '.report__presets');

        $I->seeResponseCodeIs(200);
        $I->seeInCurrentUrl('from=');
        $I->seeInCurrentUrl('to=');
        $I->seeInCurrentUrl('type=store');
        // The chosen preset is now the highlighted one.
        $I->see('Today', '.report__presets .filter-chip--active');
    }

    public function sidebarLinksToTheReport(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/');

        $I->seeElement("aside.sidebar a[href='" . self::URL . "']");
    }

    public function filtersAreAcceptedAndSurvivePaging(WebTester $I): void
    {
        $this->login($I);

        $I->amOnPage(self::URL . '?type=rule&rating=low&status=fallback&feedback=with_comment&page=1');
        $I->seeResponseCodeIs(200);
        $I->see('Chat Reports');

        // Paging links carry the active filters forward rather than resetting them.
        $I->amOnPage(self::URL . '?type=store&from=2026-01-01&to=2026-01-31');
        $I->seeResponseCodeIs(200);
    }

    public function malformedInputIsRejectedSafely(WebTester $I): void
    {
        $this->login($I);

        $I->amOnPage(self::URL . '?from=not-a-date&to=%27%3B+DROP+TABLE+messages%3B+--');
        $I->seeResponseCodeIs(200);
        $I->see('were not usable');
        // Nothing from the query string is echoed back into the page.
        $I->dontSee('DROP TABLE');

        $I->amOnPage(self::URL . '?rating=bogus&type=bogus&sort=bogus&dir=bogus&agent=abc&store=-5');
        $I->seeResponseCodeIs(200);
        $I->dontSee('bogus');
    }

    public function reportNeverExposesInternalIdentifiers(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage(self::URL);

        // No OpenAI file or vector-store id, storage path, token or sync hash reaches the page.
        $I->dontSee('openai_file_id');
        $I->dontSee('vector_store');
        $I->dontSee('storage_token');
        $I->dontSee('sync_hash');
        $I->dontSee('knowledge-bases/');
    }

    public function reportIsReadOnly(WebTester $I): void
    {
        $this->login($I);
        $I->amOnPage(self::URL);

        // Every form the report renders is a GET filter; it offers no state-changing action at all.
        // Scoped to the content area, since the shared layout's sidebar carries the sign-out POST.
        $I->dontSeeElement('.content form[method=post]');
        $I->seeElement('.content form[method=get]');
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
        IntegrationDb::cleanup($connection, '{{%admin_users}}', ['username' => self::USERNAME]);
    }
}
