<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58\Items;

use App\Order58\Client\Items\OrderItemSettings;
use PHPUnit\Framework\TestCase;

use function implode;

/**
 * What the item client is told about itself, and what it tells an administrator when it cannot run.
 *
 * ## What is really being guarded
 *
 * The diagnostic. `ORDER58_ITEM_API_TOKEN` is empty by design — `config/common/params.php` falls back
 * to `ORDER58_ORDERS_API_TOKEN`, because the two endpoints share one credential. So by the time this
 * class sees an empty token, **both** variables are unset, and a message naming only the override
 * would send somebody to set it: the request would start working, and the same secret would now live
 * in two places, which is the exact duplication the fallback exists to prevent.
 *
 * Nothing here opens a socket; these are value objects and strings.
 */
final class OrderItemSettingsTest extends TestCase
{
    private const URL = 'https://order58.com/api/order-item-data/list';

    // ------------------------------------------------------------------ the diagnostic

    /** The message names the variable that actually supplies the credential. */
    public function testAnAbsentTokenPointsAtTheSharedOrdersVariableFirst(): void
    {
        $problems = implode(' ', $this->settings(token: '')->problems());

        $this->assertStringContainsString('ORDER58_ORDERS_API_TOKEN', $problems);
        $this->assertStringContainsString('shared with the Orders endpoint', $problems);
    }

    /** And still mentions the override, so an items-specific credential is discoverable. */
    public function testTheOverrideIsStillNamedAsAnOverride(): void
    {
        $problems = implode(' ', $this->settings(token: '')->problems());

        $this->assertStringContainsString('ORDER58_ITEM_API_TOKEN to override it', $problems);
    }

    /**
     * The regression this test exists for.
     *
     * The old message was `ORDER58_ITEM_API_TOKEN is not set.` — true but misleading, because setting
     * that variable duplicates a secret the fallback already supplies.
     */
    public function testTheMessageNoLongerBlamesTheOverrideAlone(): void
    {
        $problems = implode(' ', $this->settings(token: '')->problems());

        $this->assertStringNotContainsString('ORDER58_ITEM_API_TOKEN is not set', $problems);
    }

    /** A configured installation has nothing to report. */
    public function testAFullyConfiguredInstallationReportsNoProblems(): void
    {
        $this->assertSame([], $this->settings()->problems());
        $this->assertTrue($this->settings()->isUsable());
    }

    /** Each missing piece is named separately, so one fix at a time is possible. */
    public function testEachMissingPieceIsReportedOnItsOwn(): void
    {
        $this->assertCount(1, $this->settings(url: '')->problems());
        $this->assertCount(1, $this->settings(token: '')->problems());
        $this->assertCount(1, $this->settings(enabled: false)->problems());
        // Switched off AND unconfigured: all three, so nobody fixes one and is surprised by the next.
        $this->assertCount(3, $this->settings(url: '', token: '', enabled: false)->problems());
    }

    /** No problem message may ever carry the credential itself. */
    public function testTheDiagnosticNeverRepeatsTheTokenValue(): void
    {
        $secret = 'super-secret-token-value';

        $problems = implode(' ', $this->settings(token: $secret, url: '')->problems());

        $this->assertStringNotContainsString($secret, $problems);
    }

    // ------------------------------------------------------------------ usability and the header

    /** "Switched off" and "never set up" are different questions, and stay different. */
    public function testEnabledAndUsableAreIndependent(): void
    {
        $this->assertTrue($this->settings(enabled: false)->isUsable(), 'Off is still configured.');
        $this->assertFalse($this->settings(token: '')->isUsable());
        $this->assertFalse($this->settings(url: '')->isUsable());
    }

    /** The same header the orders endpoint sends, which is what the live test confirmed. */
    public function testTheHeaderIsTheSchemeAndTheToken(): void
    {
        $this->assertSame('Bearer abc123', $this->settings(token: 'abc123')->authorizationHeader());
    }

    /** An empty scheme sends the token bare, for an endpoint that wants no prefix. */
    public function testAnEmptySchemeSendsTheTokenWithNoPrefix(): void
    {
        $this->assertSame('abc123', $this->settings(token: 'abc123', scheme: '')->authorizationHeader());
    }

    // ------------------------------------------------------------------ harness

    private function settings(
        string $url = self::URL,
        string $token = 'a-token',
        string $scheme = 'Bearer',
        bool $enabled = true,
    ): OrderItemSettings {
        return new OrderItemSettings($url, $token, $scheme, 5, 25, 4 * 1024 * 1024, $enabled);
    }
}
