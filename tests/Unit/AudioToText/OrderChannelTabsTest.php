<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;
use function preg_match_all;
use function strpos;
use function substr;

/**
 * What the order-level dialog is allowed to fetch, and when.
 *
 * Every claim here is about cost, which no functional test notices: a modal that quietly read all three
 * recordings on open would look identical to one that read the selected one, and would behave
 * identically too — until a page of twenty orders was opened by somebody with a slow connection.
 *
 * The rules being pinned:
 *
 *  1. The channels are read from the row the page already rendered, so opening a store page costs
 *     nothing extra and opening a dialog is ONE request.
 *  2. A channel is fetched when its tab is chosen, and once.
 *  3. A cached payload can never outlive a change to the recording it describes.
 *
 * @see \App\AudioToText\Web\Job\Store\StoreAudioAsset the bundle this file belongs to
 */
final class OrderChannelTabsTest extends TestCase
{
    private static function script(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/assets/audio-store/audio-store.js');
    }

    /** One named function's body, so a match cannot come from elsewhere in a 3,000-line file. */
    private static function functionBody(string $signature): string
    {
        $script = self::script();
        $start = strpos($script, $signature);
        self::assertNotFalse($start, $signature . ' has moved.');

        $end = strpos($script, "\n    }\n", $start);
        self::assertNotFalse($end);

        return substr($script, $start, $end - $start);
    }

    /**
     * The tabs come from the row, not from a second request.
     *
     * The Details buttons in that row ARE the server's answer to "what can be opened here" — built
     * from the group's current recordings, one per kind, and only where there is something to show.
     * Asking an endpoint to repeat that would be a round trip whose only possible outcome is agreeing
     * with what is already on the page.
     */
    public function testTheChannelsAreReadFromTheRowRatherThanFetched(): void
    {
        $body = self::functionBody('function openOrder(');
        $reader = self::functionBody('function channelsIn(');

        self::assertStringContainsString("closest('tr')", $body);
        self::assertStringContainsString("querySelectorAll('[data-a2t-current-channel]')", $reader);

        // Not every Details button in the row. A side uploaded twice renders its superseded version in
        // the same cell with one of its own, and collecting those would offer two tabs called
        // "Customer" — which is exactly what this did before the marker existed.
        self::assertStringNotContainsString(
            "querySelectorAll('[data-a2t-details]')",
            $reader,
            'A superseded upload is not a channel.',
        );

        foreach ([$body, $reader] as $source) {
            self::assertSame(
                0,
                preg_match('/\b(load|fetch)\s*\(/', $source),
                'Opening an order fetches nothing of its own — only the default channel, via openReviewOn.',
            );
        }
    }

    /**
     * The tabs are ordered by the channel the server named, never by where the button sits.
     *
     * For the three named columns those agree, so the bug this guards is invisible there. It is a
     * legacy Customer + Agent pair that breaks it: both halves render inside the FIRST cell, in the
     * order their jobs were inserted, so document order would put them under Mix and let whichever was
     * enqueued first lead. The marker carries the channel's position and `channelsIn` indexes by it.
     */
    public function testTheTabsAreOrderedByTheChannelAndNotByPosition(): void
    {
        $body = self::functionBody('function channelsIn(');

        self::assertStringContainsString(
            "parseInt(control.getAttribute('data-a2t-current-channel'), 10)",
            $body,
            'The position comes from the server, as a number.',
        );
        self::assertStringContainsString('byRank[rank] = {', $body, 'And indexes the channel by it.');

        // One channel per position: two recordings claiming the same place would be two tabs with one
        // name, which is the "never duplicates Customer/Agent" half of the same requirement.
        self::assertStringContainsString('byRank[rank] !== undefined', $body);

        // Both ways in share this, so a tab strip and the tab chosen after a reload cannot disagree.
        self::assertStringContainsString('channelsIn(', self::functionBody('function openOrder('));
        self::assertStringContainsString('channelsIn(', self::functionBody('function channelIndexOf('));
    }

    /** Opening an order reads exactly one recording: the first channel in the row. */
    public function testOpeningAnOrderLoadsOnlyTheDefaultChannel(): void
    {
        $body = self::functionBody('function openOrder(');

        self::assertSame(
            1,
            preg_match_all('/openReviewOn\(/', $body),
            'One recording is opened, not three.',
        );
        self::assertStringContainsString(
            'channels[0]',
            $body,
            'And it is the first channel in the row, which is column order: Mix / Common, Customer, Agent.',
        );
    }

    /**
     * Switching to a channel already read serves it from memory.
     *
     * Customer → Agent → Customer is two requests, not three.
     */
    public function testSwitchingBackToAChannelDoesNotFetchItAgain(): void
    {
        $switch = self::functionBody('function chooseChannel(');
        self::assertMatchesRegularExpression(
            '/openReviewOn\([^)]*true\s*\)/s',
            $switch,
            'A tab switch is the one caller allowed to reuse a payload.',
        );

        $render = self::functionBody('function renderReview(');
        self::assertMatchesRegularExpression(
            '/fromCache\s*===\s*true\s*&&\s*channelCache\[requested\]\s*!==\s*undefined/',
            $render,
            'And it is served only when this dialog has actually read that channel.',
        );
    }

    /**
     * Every other caller re-reads, which is what stops a cached payload going stale.
     *
     * A correction and a generation both change the recording the dialog is showing. `renderReview`
     * takes the cache flag as its third argument, so a caller that passes two arguments — which is
     * every action path — re-reads by construction rather than by remembering to.
     */
    public function testAnActionAlwaysRereadsRatherThanUsingTheCache(): void
    {
        $script = self::script();

        preg_match_all('/renderReview\(([^;]*?)\);/s', $script, $calls);

        $cached = 0;

        foreach ($calls[1] as $arguments) {
            if (preg_match('/,\s*true\s*$/', $arguments) === 1) {
                $cached++;
            }
        }

        self::assertSame(
            0,
            $cached,
            'Nothing calls renderReview with the cache flag directly; only openReviewOn passes it on.',
        );
    }

    /**
     * The revision trail is cached with the payload, or a repeat switch would still hit the server.
     *
     * `loadHistory` is a second request on every render — small, but it would make "switching back
     * costs nothing" untrue by one fetch per tab, which is exactly the kind of claim that is easy to
     * believe and wrong.
     */
    public function testTheRevisionTrailIsCachedWithTheChannel(): void
    {
        $history = self::functionBody('function loadHistory(');

        self::assertMatchesRegularExpression(
            '/fromCache\s*===\s*true\s*&&\s*historyCache\[url\]\s*!==\s*undefined/',
            $history,
        );
        self::assertStringContainsString('historyCache[url] = html;', $history);

        self::assertStringContainsString(
            'loadHistory(data.urls.history, requested, fromCache);',
            self::functionBody('function paintReview('),
            'And the flag reaches it, rather than being dropped on the way.',
        );
    }

    /** A fresh read replaces what was cached, so the entry is the newest answer rather than the first. */
    public function testAFreshReadOverwritesTheCachedCopy(): void
    {
        self::assertStringContainsString(
            'channelCache[requested] = data;',
            self::functionBody('function renderReview('),
        );

        self::assertStringContainsString(
            'channelCache[requested] = fresh;',
            self::functionBody('function watchGenerated('),
            'The generation watcher too, or switching away and back would undo what it just saw.',
        );
    }

    /** The cache belongs to one open dialog and to one call. */
    public function testTheCacheIsClearedWhenTheDialogClosesAndWhenAnotherCallOpens(): void
    {
        self::assertStringContainsString(
            'channelCache = {};',
            self::functionBody('function showChannels('),
            'Opening a different call may not reuse the last one.',
        );

        $script = self::script();
        $close = strpos($script, "reviewDialog.addEventListener('close'");
        self::assertNotFalse($close);

        // A generous window: the handler has grown as the dialog gained things to stop — the AI-audio
        // watcher, and the progress card's poll and clock — and the claim is that the cache is cleared
        // somewhere in it, not that it is cleared within a particular number of characters.
        self::assertStringContainsString(
            'channelCache = {};',
            substr($script, $close, 1400),
            'And nothing read survives the dialog being shut.',
        );
    }

    /**
     * After an Update Audio reload the reader lands back in the order view, on the right tab.
     *
     * That reload is the existing workflow — the row is server-rendered and nothing re-renders one row
     * — but dropping the reader into a single recording afterwards would silently take away the other
     * two channels they had open a moment before. A row with no order id still opens the one recording.
     */
    public function testTheReloadAfterAnUpdateReturnsToTheOrderView(): void
    {
        $body = self::functionBody('function openRequestedRecording(');

        self::assertStringContainsString("querySelector('[data-a2t-order-open]')", $body);
        self::assertStringContainsString('openOrder(order);', $body);
        self::assertStringContainsString('selectChannelFor(', $body);
        self::assertStringContainsString(
            'openReview(button);',
            $body,
            'A row that named no order has one recording and still opens it directly.',
        );
    }

    /**
     * The single-recording path still shows no tabs.
     *
     * The Details button on a row opens one recording and offers no way to another, exactly as before
     * orders could be opened. The strip is also hidden for an order holding one recording, which is
     * the rule the Original transcript dialog already follows.
     */
    public function testOneRecordingGetsNoTabStrip(): void
    {
        self::assertStringContainsString(
            'showChannels([], null)',
            self::functionBody('function openReview('),
            'A Details button opens one recording and draws no tabs.',
        );

        self::assertStringContainsString(
            'reviewTabs.hidden = channels.length < 2;',
            self::functionBody('function showChannels('),
        );
    }
}
