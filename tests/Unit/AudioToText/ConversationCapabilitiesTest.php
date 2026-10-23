<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Domain\ConversationCapabilities;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function get_object_vars;
use function in_array;

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
            // Tracking is the one thing this profile does that read-only does not; `canViewOrderSeq`
            // is true on every surface that lists orders and grants no ability of any kind.
            ['canTrackPlayback', 'canViewOrderSeq'],
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
        self::assertSame(
            self::NOT_PERMISSIONS === [] ? [] : ['canViewOrderSeq'],
            self::trueFlags(ConversationCapabilities::readOnly()),
            'the read-only profile may grant no permission — only the display-only flags',
        );
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
            if (in_array($name, self::NOT_PERMISSIONS, true)) {
                // Not a permission, so the two profiles are allowed to agree about it. They must
                // still agree, which is what keeps a real permission from being hidden in here.
                self::assertSame($value, $readOnly[$name], $name . ' is not a permission and must match');

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
            // Added with the Seq and View Receipt columns. The list is pinned on purpose: a new flag
            // has to be added here deliberately, which is the moment to ask whether every profile
            // below answers it correctly — and the agent and read-only profiles both say false.
            'canViewOrderReceipts',
            // Added with the audio-only practice surfaces: whether the conversation may be read as
            // words at all, and whether the provider's order number is shown. The second is split
            // from the receipt above it because they are different decisions — see NOT_PERMISSIONS.
            'canReadConversation',
            'canViewOrderSeq',
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

    /**
     * Flags that are not permissions, and so are exempt from the "opposites" rules below.
     *
     * `canTrackPlayback` grants nothing: it records that somebody listened, and only the agent
     * surface does. `canViewOrderSeq` grants nothing either — the provider's own order number is one
     * more fact about a row the reader is already looking at, shown on every surface that lists
     * orders at all. Neither lets anybody change anything, which is what the profiles are about.
     *
     * Anything added here must be argued for. A permission that quietly joined this list would be a
     * permission nobody was asked about.
     *
     * @var list<string>
     */
    private const NOT_PERMISSIONS = ['canTrackPlayback', 'canViewOrderSeq'];

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
