<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ConversationCapabilities;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function get_object_vars;

/**
 * The two profiles, asserted as wholes rather than flag by flag.
 *
 * A capability that quietly defaults to true is the failure this guards against: the read-only profile
 * is what stands between an agent and every mutating control on a 2,200-line page, and a new flag added
 * later must be a deliberate decision in both directions. Reading every property reflectively means a
 * flag that is added and forgotten fails here rather than shipping as "allowed".
 */
final class ConversationCapabilitiesTest extends TestCase
{
    public function testTheFullProfileAllowsEverything(): void
    {
        self::assertSame([], self::falseFlags(ConversationCapabilities::full()));
    }

    /** **The one that matters.** Every flag, false — including any added after this was written. */
    public function testTheReadOnlyProfileAllowsNothing(): void
    {
        self::assertSame([], self::trueFlags(ConversationCapabilities::readOnly()));
    }

    public function testTheTwoProfilesAreExactOpposites(): void
    {
        $full = get_object_vars(ConversationCapabilities::full());
        $readOnly = get_object_vars(ConversationCapabilities::readOnly());

        self::assertSame(array_keys($full), array_keys($readOnly), 'both profiles answer the same questions');

        foreach ($full as $name => $value) {
            self::assertNotSame($value, $readOnly[$name], $name . ' must differ between the profiles');
        }
    }

    /** The named questions the template actually asks. Renaming one must be a decision, not a typo. */
    public function testItAnswersTheQuestionsTheTemplateAsks(): void
    {
        self::assertSame([
            'canUploadAudio',
            'canReplaceAudio',
            'canTranscribe',
            'canEditTranscript',
            'canGenerateTts',
            'canOpenFullEditor',
            'canSyncDemoOrders',
            'canAssignTopics',
        ], array_keys(get_object_vars(ConversationCapabilities::full())));
    }

    /** @return list<string> */
    private static function falseFlags(ConversationCapabilities $c): array
    {
        $out = [];

        foreach (get_object_vars($c) as $name => $value) {
            if ($value !== true) {
                $out[] = $name;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private static function trueFlags(ConversationCapabilities $c): array
    {
        $out = [];

        foreach (get_object_vars($c) as $name => $value) {
            if ($value !== false) {
                $out[] = $name;
            }
        }

        return $out;
    }
}
