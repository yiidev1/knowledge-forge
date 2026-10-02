<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Order58\DemoLinkStatus;
use App\Shared\Order58\DemoOrderUrl;
use Codeception\Test\Unit;

use function str_repeat;

/**
 * Building the Order58 demo link. **No test here reaches a network or a database.**
 *
 * The host is a value a third party controls that this application puts in an `href`, and the
 * destination carries a customer's phone number in its path. Most of what follows is therefore about
 * what must be *refused*.
 */
final class DemoOrderUrlTest extends Unit
{
    public function testAValidOrderBuildsTheDocumentedUrl(): void
    {
        $link = DemoOrderUrl::for('joymeal.order58.com', 16655531, '15163932150');

        $this->assertTrue($link->isReady());
        $this->assertSame(
            'https://joymeal.order58.com/admin/demo/order/make/16655531-15163932150',
            $link->url,
        );
    }

    /** Both real domains in the mirror work: 230 stores on one, 5 on the other. */
    public function testBothTrustedDomainsAreAccepted(): void
    {
        $this->assertTrue(DemoOrderUrl::for('magw.order58.com', 1, '15551234567')->isReady());
        $this->assertTrue(DemoOrderUrl::for('5162070808.17ip.com', 1, '15551234567')->isReady());
    }

    /** A phone is an identifier, not a number: a leading zero or `+` is part of it. */
    public function testThePhoneIsCarriedThroughAsAString(): void
    {
        $this->assertStringEndsWith('-0015551234567', (string) DemoOrderUrl::for('a.order58.com', 7, '0015551234567')->url);
        $this->assertStringEndsWith('-%2B15551234567', (string) DemoOrderUrl::for('a.order58.com', 7, '+15551234567')->url);
    }

    public function testTheUrlIsAlwaysHttps(): void
    {
        $this->assertStringStartsWith('https://', (string) DemoOrderUrl::for('a.order58.com', 1, '15551234567')->url);
    }

    // ------------------------------------------------------------------ what must be refused

    /**
     * **The open-redirect guard.** An untrusted host is refused, never tidied into one.
     *
     * @dataProvider untrustedHosts
     */
    public function testAnUntrustedHostNeverProducesALink(?string $host): void
    {
        $link = DemoOrderUrl::for($host, 16655531, '15163932150');

        $this->assertFalse($link->isReady());
        $this->assertNull($link->url);
        $this->assertSame(DemoLinkStatus::HostUnavailable, $link->status);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function untrustedHosts(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['   '],
            // The attack this exists to stop: a host that is not ours at all.
            'attacker domain' => ['evil.example.com'],
            // Ends in the right letters but is a different registrable domain — the leading-dot check.
            'lookalike suffix' => ['notorder58.com'],
            'lookalike subdomain trick' => ['order58.com.attacker.net'],
            // Anything that would reshape the URL rather than sit in its host position.
            'carries a scheme' => ['https://a.order58.com'],
            'carries a path' => ['a.order58.com/admin/demo'],
            'carries a query' => ['a.order58.com?x=1'],
            'carries a fragment' => ['a.order58.com#x'],
            'carries a port' => ['a.order58.com:8443'],
            'carries credentials' => ['user:pass@a.order58.com'],
            'protocol relative' => ['//evil.example.com'],
            'backslash trick' => ['a.order58.com\\@evil.example.com'],
            'space injection' => ['a.order58.com evil.example.com'],
            'newline injection' => ["a.order58.com\nevil.example.com"],
            'tab injection' => ["a.order58.com\tevil"],
            'too long' => [str_repeat('a', 250) . '.order58.com'],
        ];
    }

    /**
     * @dataProvider unusablePhones
     */
    public function testAnUnusablePhoneNeverProducesALink(?string $phone): void
    {
        $link = DemoOrderUrl::for('a.order58.com', 16655531, $phone);

        $this->assertFalse($link->isReady());
        $this->assertSame(DemoLinkStatus::PhoneUnavailable, $link->status);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function unusablePhones(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'whitespace' => ['  '],
            'letters' => ['call me'],
            'too short' => ['12'],
            'too long' => [str_repeat('9', 21)],
            'path injection' => ['1555/../../etc'],
            'slash' => ['1555/1234567'],
        ];
    }

    /**
     * @dataProvider badOrderIds
     */
    public function testAnUnusableOrderIdNeverProducesALink(?int $orderId): void
    {
        $link = DemoOrderUrl::for('a.order58.com', $orderId, '15551234567');

        $this->assertFalse($link->isReady());
        $this->assertSame(DemoLinkStatus::OrderNotFound, $link->status);
    }

    /**
     * @return array<string, array{int|null}>
     */
    public static function badOrderIds(): array
    {
        return ['null' => [null], 'zero' => [0], 'negative' => [-5]];
    }

    /** Nothing is ever built from partial data: a missing part means no URL at all. */
    public function testNoPartialUrlIsEverReturned(): void
    {
        foreach ([
            DemoOrderUrl::for(null, 1, '15551234567'),
            DemoOrderUrl::for('a.order58.com', 1, null),
            DemoOrderUrl::for('a.order58.com', null, '15551234567'),
            DemoOrderUrl::unavailable(DemoLinkStatus::OrderNotFound),
        ] as $link) {
            $this->assertNull($link->url);
            $this->assertFalse($link->isReady());
        }
    }

    /** Every status has an operator-facing sentence, so the cell is never blank. */
    public function testEveryStatusHasAMessage(): void
    {
        foreach (DemoLinkStatus::cases() as $status) {
            $this->assertNotSame('', $status->message());
        }

        $this->assertSame('Order not found', DemoLinkStatus::OrderNotFound->message());
        $this->assertSame('Store not found', DemoLinkStatus::StoreNotFound->message());
        $this->assertSame('Host unavailable', DemoLinkStatus::HostUnavailable->message());
        $this->assertSame('Phone unavailable', DemoLinkStatus::PhoneUnavailable->message());
        $this->assertSame('Order match ambiguous', DemoLinkStatus::Ambiguous->message());
    }

    /** No host is baked in: the same order on two stores yields two different URLs. */
    public function testTheHostAlwaysComesFromTheArgument(): void
    {
        $first = DemoOrderUrl::for('one.order58.com', 42, '15551234567')->url;
        $second = DemoOrderUrl::for('two.order58.com', 42, '15551234567')->url;

        $this->assertNotSame($first, $second);
        $this->assertStringContainsString('//one.order58.com/', (string) $first);
        $this->assertStringContainsString('//two.order58.com/', (string) $second);
    }
}
