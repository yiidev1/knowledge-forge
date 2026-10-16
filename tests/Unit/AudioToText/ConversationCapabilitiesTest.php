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
    /**
     * Everything an administrator or operation user may DO, and nothing they are not.
     *
     * `canTrackPlayback` is excluded, and the exclusion is the point: it is not a permission anybody
     * holds but a property of one surface — whether the listening done there is recorded. Managing
     * realms are not tracked, so a profile with it true would be claiming a permission that does not
     * exist and turning on a behaviour nobody asked for.
     */
    public function testTheFullProfileAllowsEveryManagementCapability(): void
    {
        self::assertSame(
            ['canTrackPlayback'],
            self::falseFlags(ConversationCapabilities::full()),
        );
    }

    /**
     * The agent's profile: read-only in every respect, and recorded.
     *
     * Asserted against `readOnly()` rather than written out, so a capability added later is false here
     * too unless somebody decides otherwise — which is the same protection the read-only test gives,
     * extended to the profile that is nearly it.
     */
    public function testTheAgentProfileIsReadOnlyPlusTrackingAndNothingElse(): void
    {
        self::assertSame(
            ['canTrackPlayback'],
            self::trueFlags(ConversationCapabilities::agentPractice()),
        );

        $agent = get_object_vars(ConversationCapabilities::agentPractice());
        $readOnly = get_object_vars(ConversationCapabilities::readOnly());

        foreach ($readOnly as $name => $value) {
            if ($name !== 'canTrackPlayback') {
                self::assertSame($value, $agent[$name], $name . ' must match the read-only profile');
            }
        }
    }

    /** **The one that matters.** Every flag, false — including any added after this was written. */
    public function testTheReadOnlyProfileAllowsNothing(): void
    {
        self::assertSame([], self::trueFlags(ConversationCapabilities::readOnly()));
    }

    /**
     * Opposites on every permission, and agreed on the one thing that is not one.
     *
     * Both profiles answer false to `canTrackPlayback`, because neither surface records listening —
     * the administrators' because they are not tracked, the administrators' practice page because it
     * is not the agent's. Only the agent profile says yes, and it says yes to nothing else.
     */
    public function testTheTwoProfilesAreOppositesOnEveryPermission(): void
    {
        $full = get_object_vars(ConversationCapabilities::full());
        $readOnly = get_object_vars(ConversationCapabilities::readOnly());

        self::assertSame(array_keys($full), array_keys($readOnly), 'both profiles answer the same questions');

        foreach ($full as $name => $value) {
            if ($name === 'canTrackPlayback') {
                self::assertFalse($value);
                self::assertFalse($readOnly[$name]);

                continue;
            }

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
            'canVerifyOrders',
            'canTrackPlayback',
            'canViewAgentEvents',
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
