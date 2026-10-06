<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

/**
 * Which tab the conversation dialog opens on, asserted against the script itself.
 *
 * ## Why this is a test and not a comment
 *
 * The store table now has one column where it had three, so the dialog behind it is the only place a
 * reader can reach a particular channel. Two of its rules are easy to break and silent when broken:
 *
 *  1. it must open on the **highest-priority channel the call actually has** — Mix / Common when there
 *     is one, else Customer, else Agent — and never on a tab with nothing behind it;
 *  2. a call with one recording must not draw a tab strip holding a single tab.
 *
 * Both are carried by ordering rather than by a conditional, which is what makes them worth pinning:
 * `channelsIn()` builds a **sparse array indexed by the server's own channel rank** and filters it, so
 * ascending order with no gaps falls out, and `openOrder()` takes `channels[0]`. Re-order that array,
 * or sort it by where the buttons sit, and the dialog quietly opens on the wrong side of the call.
 *
 * It is deliberately a text assertion. There is no JavaScript test runner in this project, and the
 * alternative — asserting nothing about the only route to a channel — is worse.
 *
 * @see \App\Tests\Unit\AudioToText\CombinedTurnOwnershipInBrowserTest for the same approach applied to
 *      the rule that decides which recording a correction is written to.
 */
final class ConversationTabsInBrowserTest extends TestCase
{
    /** The dialog opens on the first channel the row has, which rank order makes the right one. */
    public function testTheDialogOpensOnTheFirstChannelTheCallHas(): void
    {
        $body = self::functionBody(self::storeModule(), 'function openOrder(');

        self::assertNotSame('', $body, 'openOrder() is gone; the single way in has moved.');
        self::assertTrue(
            str_contains($body, 'channelsIn(button.closest(\'tr\'))'),
            'The channels must come from the row, so the dialog can only offer what the call has.',
        );
        self::assertTrue(
            str_contains($body, 'channels[0]'),
            'The dialog must open on the first channel — Mix / Common, else Customer, else Agent.',
        );
        self::assertTrue(
            str_contains($body, 'channels.length === 0'),
            'A row with no channel must open nothing rather than an empty dialog.',
        );
    }

    /**
     * Priority is the server's channel rank, read from the markup and never re-derived.
     *
     * A sparse array indexed by rank — 0 mixed, 1 the customer's side, 2 the agent's — filtered into a
     * dense one. That is what makes "Mix if present, else Customer, else Agent" true without a rule
     * saying so, and what keeps a legacy pair whose halves share one cell in the right order.
     */
    public function testChannelOrderComesFromTheServersRankAndNotFromThePage(): void
    {
        $body = self::functionBody(self::storeModule(), 'function channelsIn(');

        self::assertNotSame('', $body);
        self::assertTrue(
            str_contains($body, 'data-a2t-current-channel'),
            'The rank is read from the marker the server puts on each channel.',
        );
        self::assertTrue(
            str_contains($body, 'byRank[rank] = {'),
            'Channels must be indexed by rank, not appended in document order.',
        );
        self::assertTrue(
            str_contains($body, 'byRank[rank] !== undefined'),
            'One channel per rank: two recordings claiming one position would be two tabs with one name.',
        );
        self::assertFalse(
            str_contains($body, '.sort('),
            'Nothing is sorted here — indexing by rank is what produces the order.',
        );
    }

    /** One recording draws no tab strip: a single tab is a control that cannot do anything. */
    public function testASingleChannelDrawsNoTabStrip(): void
    {
        $body = self::functionBody(self::storeModule(), 'function showChannels(');

        self::assertNotSame('', $body);
        self::assertTrue(
            str_contains($body, 'reviewTabs.hidden = channels.length < 2'),
            'The strip must hide below two channels.',
        );
        self::assertTrue(
            str_contains($body, 'channelCache = {}'),
            'A different call may not reuse anything read for the last one.',
        );
    }

    /** Switching tabs re-reads that channel, and never leaves the last one's state behind. */
    public function testSwitchingTabsRepointsTheDialogAtTheOtherRecording(): void
    {
        $body = self::functionBody(self::storeModule(), 'function chooseChannel(');

        self::assertNotSame('', $body);
        self::assertTrue(
            str_contains($body, 'openReviewOn(channel.url'),
            'A tab switch must re-open the dialog on that channel\'s own endpoint.',
        );
        self::assertTrue(
            str_contains($body, 'aria-selected'),
            'The pressed tab must say it is the selected one.',
        );
        self::assertTrue(
            str_contains($body, 'reviewUrl === channel.url'),
            'Re-pressing the open tab must do nothing rather than re-render it.',
        );
    }

    /** A deep link to one recording still lands on that recording's tab inside the call. */
    public function testADeepLinkToOneRecordingSelectsItsTab(): void
    {
        $body = self::functionBody(self::storeModule(), 'function selectChannelFor(');

        self::assertNotSame('', $body);
        self::assertTrue(
            str_contains($body, 'channelIndexOf(url)'),
            'The tab to press is found by the channel the url belongs to.',
        );
        self::assertTrue(
            str_contains($body, 'reviewUrl === url'),
            'Already showing it is not a reason to press anything.',
        );
    }

    private static function storeModule(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');
    }

    /** The source of one function, brace-matched so a nested block cannot end it early. */
    private static function functionBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);

        if ($start === false) {
            return '';
        }

        $open = strpos($source, '{', $start);

        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($source);

        for ($i = $open; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return substr($source, $open, $i - $open + 1);
                }
            }
        }

        return '';
    }
}
