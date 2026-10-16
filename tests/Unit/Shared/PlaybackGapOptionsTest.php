<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Audio\PlaybackGapOptions;
use PHPUnit\Framework\TestCase;

use function array_sum;

/**
 * The one list of gaps, and the rules that keep a value nobody offered out of the records.
 *
 * Every other layer — the dropdown, the tracking endpoint, the reporting — reads this class rather
 * than a list of its own, so these are the tests for all three.
 */
final class PlaybackGapOptionsTest extends TestCase
{
    public function testTheFiveOfferedGapsAreTheOnesTheSpecificationNames(): void
    {
        self::assertSame([500, 1000, 2000, 3000, 4000], PlaybackGapOptions::allowed());
    }

    /** Agents start at a full second; the surfaces with no control keep the pause they always had. */
    public function testTheDefaultsAreDistinctAndDeliberate(): void
    {
        self::assertSame(1000, PlaybackGapOptions::DEFAULT_MS);
        self::assertSame(1500, PlaybackGapOptions::LEGACY_MS);
        self::assertTrue(PlaybackGapOptions::isAllowed(PlaybackGapOptions::DEFAULT_MS));
        // The legacy pause is longer than anything the dropdown offers, which is why it is not in it.
        self::assertFalse(PlaybackGapOptions::isAllowed(PlaybackGapOptions::LEGACY_MS));
    }

    public function testLabelsReadAsSecondsWithoutPaddedZeros(): void
    {
        self::assertSame('0.5 s', PlaybackGapOptions::label(500));
        self::assertSame('1 s', PlaybackGapOptions::label(1000));
        self::assertSame('2 s', PlaybackGapOptions::label(2000));
        self::assertSame('4 s', PlaybackGapOptions::label(4000));
    }

    /**
     * A gap that is no longer offered must still print.
     *
     * The records hold gaps from every list this control has ever had — seven real sessions in this
     * database used 100, 200, 800, 900 and 1000 — and the history on Manage Orders formats a stored
     * value through this method. A label that refused an old one would hide what somebody actually
     * did, which is the one thing an audit trail may not do.
     */
    public function testAGapNoLongerOfferedStillHasALabel(): void
    {
        self::assertSame('0.1 s', PlaybackGapOptions::label(100));
        self::assertSame('0.2 s', PlaybackGapOptions::label(200));
        self::assertSame('0.3 s', PlaybackGapOptions::label(300));
        self::assertSame('0.9 s', PlaybackGapOptions::label(900));
        // And the legacy pause the managing realms still use, which was never in any dropdown.
        self::assertSame('1.5 s', PlaybackGapOptions::label(PlaybackGapOptions::LEGACY_MS));
    }

    /**
     * Both shapes a browser can send, because the label is seconds and the value is milliseconds.
     *
     * `0.3` is not representable in binary floating point, so this is also the test that the rounding
     * lands on 300 rather than 299 — which would be refused as a gap nobody offered.
     */
    public function testNormalizeAcceptsSecondsAndMilliseconds(): void
    {
        self::assertSame(500, PlaybackGapOptions::normalize(500));
        self::assertSame(500, PlaybackGapOptions::normalize('500'));
        self::assertSame(500, PlaybackGapOptions::normalize(0.5));
        self::assertSame(500, PlaybackGapOptions::normalize('0.5'));
        self::assertSame(1000, PlaybackGapOptions::normalize('1'));
        self::assertSame(2000, PlaybackGapOptions::normalize('2'));
        self::assertSame(4000, PlaybackGapOptions::normalize(4));
        self::assertSame(4000, PlaybackGapOptions::normalize('4000'));
    }

    /**
     * A value that used to be offered is refused for anything NEW.
     *
     * The distinction this pins: the records keep what they already hold, and nothing may be recorded
     * now with a gap the page cannot offer. Reading and writing answer differently on purpose.
     */
    public function testValuesThatWereRemovedAreNoLongerAccepted(): void
    {
        foreach ([100, 200, 300, 400, 600, 700, 800, 900, '0.3', '0.9'] as $retired) {
            self::assertNull(
                PlaybackGapOptions::normalize($retired),
                'should no longer accept ' . var_export($retired, true),
            );
            self::assertFalse(PlaybackGapOptions::isAllowed((int) $retired));
        }
    }

    /**
     * Everything else is null, and null is refused rather than defaulted.
     *
     * A gap nobody chose must not be recorded as one they did, so there is no fallback here: the
     * caller turns a null into a rejected request.
     */
    public function testNormalizeRefusesAnythingElse(): void
    {
        foreach ([0, -1, 50, 150, 1100, 2500, 1500, 'abc', '', null, [], '0.35', 5001] as $bad) {
            self::assertNull(
                PlaybackGapOptions::normalize($bad),
                'should refuse ' . var_export($bad, true),
            );
        }
    }

    /** What the server hands the browser: the values and their labels, and nothing else. */
    public function testTheBrowserIsHandedValuesAndLabels(): void
    {
        $options = PlaybackGapOptions::forBrowser();

        self::assertCount(5, $options);
        self::assertSame(['ms' => 500, 'label' => '0.5 s'], $options[0]);
        self::assertSame(['ms' => 4000, 'label' => '4 s'], $options[4]);
    }

    /**
     * The database's bound is wider than the list, on purpose.
     *
     * The CHECK is a range so that offering an eleventh gap is a change to this class rather than a
     * schema migration. The bound still has to contain every value the list can produce.
     */
    public function testEveryOfferedGapFitsTheStorableBound(): void
    {
        foreach (PlaybackGapOptions::allowed() as $ms) {
            self::assertGreaterThanOrEqual(PlaybackGapOptions::MIN_STORABLE_MS, $ms);
            self::assertLessThanOrEqual(PlaybackGapOptions::MAX_STORABLE_MS, $ms);
        }

        // And the legacy pause too, since a surface could one day record with it.
        self::assertLessThanOrEqual(PlaybackGapOptions::MAX_STORABLE_MS, PlaybackGapOptions::LEGACY_MS);
    }

    public function testTheSelectShapeKeepsValuesAndLabelsTogether(): void
    {
        $select = PlaybackGapOptions::forSelect();

        self::assertSame('0.5 s', $select[500]);
        self::assertSame(PlaybackGapOptions::allowed(), PlaybackGapOptions::values());
        self::assertSame(10500, array_sum(PlaybackGapOptions::values()));
    }
}
