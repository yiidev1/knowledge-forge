<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\CallRecordings\CallListParser;
use Codeception\Test\Unit;

/**
 * An order's `calls`, in every shape the provider sends or may send.
 */
final class CallListParserTest extends Unit
{
    private const URL_A = 'https://order58.xrainbow.com/api/external/recording/fetch/21403649'
        . '?time=2026-07-29&company=SWCC&name=15594361-10000000000-21403649';
    private const URL_B = 'https://order58.xrainbow.com/api/external/recording/fetch/21403649'
        . '?time=2026-07-29&company=SWCC&name=15594361-10000000000-21403649-2';
    private const URL_C = 'https://order58.xrainbow.com/api/external/recording/fetch/21403650'
        . '?time=2026-07-29&company=SWCC&name=15594361-10000000000-21403650';

    private CallListParser $parser;

    protected function _before(): void
    {
        $this->parser = new CallListParser();
    }

    public function testTodaysShapeIsOneSessionWithItsLink(): void
    {
        $parsed = $this->parser->parse(['session_id' => '21403649', 'wav_download_urls' => [self::URL_A]]);

        self::assertCount(1, $parsed->calls);
        self::assertSame('21403649', $parsed->calls[0]->sessionId);
        self::assertSame([self::URL_A], $parsed->calls[0]->urls, 'the URL exactly as sent');
        self::assertSame(0, $parsed->rejectedLinks);
    }

    public function testSeveralLinksKeepTheProvidersOrder(): void
    {
        $parsed = $this->parser->parse(['session_id' => '21403649', 'wav_download_urls' => [self::URL_B, self::URL_A]]);

        self::assertSame([self::URL_B, self::URL_A], $parsed->calls[0]->urls);
    }

    public function testARepeatedLinkIsOneLink(): void
    {
        $parsed = $this->parser->parse([
            'session_id' => '21403649',
            'wav_download_urls' => [self::URL_A, self::URL_A, self::URL_B],
        ]);

        self::assertSame([self::URL_A, self::URL_B], $parsed->calls[0]->urls);
    }

    /** The obvious way two calls on one order will arrive: a list of the objects. */
    public function testAListOfCallsIsSeveralSessions(): void
    {
        $parsed = $this->parser->parse([
            ['session_id' => '21403649', 'wav_download_urls' => [self::URL_A]],
            ['session_id' => '21403650', 'wav_download_urls' => [self::URL_C]],
        ]);

        self::assertCount(2, $parsed->calls);
        self::assertSame(['21403649', '21403650'], [$parsed->calls[0]->sessionId, $parsed->calls[1]->sessionId]);
    }

    public function testTheSameSessionListedTwiceIsMerged(): void
    {
        $parsed = $this->parser->parse([
            ['session_id' => '21403649', 'wav_download_urls' => [self::URL_A]],
            ['session_id' => '21403649', 'wav_download_urls' => [self::URL_B, self::URL_A]],
        ]);

        self::assertCount(1, $parsed->calls);
        self::assertSame([self::URL_A, self::URL_B], $parsed->calls[0]->urls);
    }

    /** A numeric session id arrives as an integer from some JSON encoders; it is still a string here. */
    public function testAnIntegerSessionIdIsReadAsText(): void
    {
        $parsed = $this->parser->parse(['session_id' => 21403649, 'wav_download_urls' => [self::URL_A]]);

        self::assertSame('21403649', $parsed->calls[0]->sessionId);
    }

    /**
     * @dataProvider noCalls
     */
    public function testAnythingElseIsNoCalls(mixed $calls): void
    {
        $parsed = $this->parser->parse($calls);

        self::assertSame([], $parsed->calls);
        self::assertSame(0, $parsed->rejectedLinks);
    }

    /** @return array<string, array{mixed}> */
    public static function noCalls(): array
    {
        return [
            'absent' => [null],
            'an empty object' => [[]],
            'a string' => ['calls'],
            'a number' => [5],
        ];
    }

    /** An empty list is a session with nothing said about its files — kept, and empty. */
    public function testAnEmptyLinkListIsKeptAsSaid(): void
    {
        $parsed = $this->parser->parse(['session_id' => '21403649', 'wav_download_urls' => []]);

        self::assertCount(1, $parsed->calls);
        self::assertSame([], $parsed->calls[0]->urls);
    }

    public function testALinkThatIsNotARecordingUrlIsDroppedAndCounted(): void
    {
        $parsed = $this->parser->parse([
            'session_id' => '21403649',
            'wav_download_urls' => [self::URL_A, 'https://example.com/x.wav', 42, null],
        ]);

        self::assertSame([self::URL_A], $parsed->calls[0]->urls);
        self::assertSame(3, $parsed->rejectedLinks);
    }

    /**
     * No usable session id means the links cannot be filed. They are counted, never guessed onto
     * another id such as the order's `call_id`.
     */
    public function testLinksWithoutAUsableSessionAreRejected(): void
    {
        foreach ([null, '', 'abc', '12 34'] as $sessionId) {
            $parsed = $this->parser->parse(['session_id' => $sessionId, 'wav_download_urls' => [self::URL_A]]);

            self::assertSame([], $parsed->calls);
            self::assertSame(1, $parsed->rejectedLinks);
        }
    }
}
