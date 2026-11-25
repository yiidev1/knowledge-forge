<?php

declare(strict_types=1);

namespace App\Tests\Web;

use App\Auth\Infrastructure\DbAdminUserRepository;
use App\Auth\Infrastructure\NativePasswordHasher;
use App\Shared\Domain\Clock\SystemClock;
use App\Tests\Support\IntegrationDb;
use App\Tests\Support\WebTester;
use PHPUnit\Framework\Assert;
use Yiisoft\Db\Connection\ConnectionInterface;
use Yiisoft\Db\Query\Query;

use function json_decode;

/**
 * The Recent Calls page through the real router, login and asset pipeline.
 *
 * Response headers (types, file names, no-store) are asserted in tests/Unit/Order58/RecentCallsTest.php;
 * this browser module does not read them.
 *
 * The call list and recordings come from the repository's sample files (`?source=fixture`, honoured only
 * in development and test), so no request leaves this machine — the live provider allowlists by IP and
 * this machine is not on the list. What is proved here is everything on this side of the provider.
 */
final class RecentCallsCest
{
    private const USERNAME = '__kf_recent_calls_admin__';
    private const PASSWORD = 'RecentCallsPassw0rd!secure';
    private const SAMPLE = '22342359';

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

    public function thePageNeedsASignedInAdministrator(WebTester $I): void
    {
        $I->stopFollowingRedirects();
        $I->amOnPage('/admin/order58/recent-calls');
        $I->seeResponseCodeIsRedirection();

        $I->amOnPage('/admin/order58/recent-calls/list?store=1731&source=fixture');
        $I->seeResponseCodeIsRedirection();
    }

    public function thePageOffersExactlyTheTwoStores(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/recent-calls');
        $I->seeResponseCodeIs(200);

        $I->see('Recent calls', 'h1');
        $I->seeNumberOfElements('select[data-rc-store] option', 2);
        $I->seeElement('select[data-rc-store] option[value="1731"][selected]');
        $I->see('Best Chinese Cuisine II', 'select[data-rc-store] option[value="1731"]');
        $I->see('GREAT WALL', 'select[data-rc-store] option[value="91"]');
        $I->see('Load Calls', 'button[data-rc-load]');

        Assert::assertSame(
            ['Order No', 'Call Session ID', 'Call Time', 'Mix Audio', 'Customer Audio (Caller)', 'Agent Audio (Callee)', 'View Receipt'],
            $I->grabMultiple('.rc-table thead th'),
        );
        // Nothing is listed until Load Calls is pressed.
        $I->see('Choose a store and press Load Calls.', '[data-rc-body]');
    }

    public function loadCallsAnswersWithEveryAddressARowNeeds(WebTester $I): void
    {
        $this->signIn($I);
        $data = $this->json($I, '/admin/order58/recent-calls/list?store=1731&source=fixture');

        Assert::assertSame(1731, $data['store']);
        Assert::assertSame('Best Chinese Cuisine II', $data['storeName']);
        Assert::assertCount(3, $data['calls']);

        $first = $data['calls'][0];
        Assert::assertSame(self::SAMPLE, $first['sessionId']);
        Assert::assertSame(['mixed', 'caller', 'callee'], array_keys($first['channels']));
        Assert::assertStringStartsWith('/admin/order58/recent-calls/audio?', $first['channels']['caller']['play']);
        Assert::assertStringContainsString('session=' . self::SAMPLE, $first['channels']['caller']['play']);
        Assert::assertStringContainsString('date=2026-03-11', $first['channels']['caller']['play']);
        Assert::assertNull($first['receipt'], 'the sample order is not mirrored, so no receipt is offered');

        // The sample's third call has no date the page can read: it lists, without recordings.
        Assert::assertSame(['mixed' => null, 'caller' => null, 'callee' => null], $data['calls'][2]['channels']);
    }

    public function anotherStoreIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/recent-calls/list?store=871&source=fixture');
        $I->seeResponseCodeIs(400);

        $I->amOnPage('/admin/order58/recent-calls/audio?store=871&session=' . self::SAMPLE . '&date=2026-03-11&channel=mixed&source=fixture');
        $I->seeResponseCodeIs(400);
    }

    public function eachChannelPlaysAndDownloadsAWavFile(WebTester $I): void
    {
        $this->signIn($I);

        foreach (['mixed', 'caller', 'callee'] as $channel) {
            $base = '/admin/order58/recent-calls/audio?store=1731&session=' . self::SAMPLE
                . '&date=2026-03-11&channel=' . $channel . '&source=fixture';

            $I->amOnPage($base);
            $I->seeResponseCodeIs(200);
            Assert::assertStringStartsWith('RIFF', $I->grabPageSource(), $channel . ' plays a WAV file');

            $I->amOnPage($base . '&download=1');
            $I->seeResponseCodeIs(200);
            Assert::assertStringStartsWith('RIFF', $I->grabPageSource(), $channel . ' downloads a WAV file');
        }
    }

    public function aBadRecordingRequestIsRefused(WebTester $I): void
    {
        $this->signIn($I);
        $I->amOnPage('/admin/order58/recent-calls/audio?store=1731&session=abc&date=2026-03-11&channel=mixed&source=fixture');
        $I->seeResponseCodeIs(400);

        $I->amOnPage('/admin/order58/recent-calls/audio?store=1731&session=' . self::SAMPLE . '&date=2026-02-31&channel=mixed&source=fixture');
        $I->seeResponseCodeIs(400);

        $I->amOnPage('/admin/order58/recent-calls/audio?store=1731&session=' . self::SAMPLE . '&date=2026-03-11&channel=agent&source=fixture');
        $I->seeResponseCodeIs(400);
    }

    // ---------------------------------------------------------------- helpers

    private function signIn(WebTester $I): void
    {
        $I->amOnPage('/login');
        $I->submitForm('form', ['username' => self::USERNAME, 'password' => self::PASSWORD]);
        $I->seeCurrentUrlEquals('/');
    }

    /** @return array<string, mixed> */
    private function json(WebTester $I, string $url): array
    {
        $I->amOnPage($url);
        $I->seeResponseCodeIs(200);

        /** @var array<string, mixed> */
        return json_decode($I->grabPageSource(), true, 16, JSON_THROW_ON_ERROR);
    }

    /** Scoped to this suite's own administrator; never a blanket delete. */
    private function cleanup(): void
    {
        $adminIds = (new Query($this->connection))
            ->select('id')->from('{{%admin_users}}')->where(['username' => self::USERNAME])->column();

        if ($adminIds !== []) {
            $this->connection->createCommand()->delete('{{%admin_users}}', ['id' => $adminIds])->execute();
        }
    }
}
