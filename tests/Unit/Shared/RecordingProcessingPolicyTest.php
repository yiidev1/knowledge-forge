<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Audio\RecordingProcessingPolicy as Policy;
use Codeception\Test\Unit;

/**
 * What a recording is, and therefore what is done with it.
 *
 * Two rules, both pinned here so neither can acquire a second, contradictory opinion elsewhere.
 *
 * **Caller is the customer and callee is the agent.** Two opinions about which side is the customer is
 * not a bug anybody notices quickly.
 *
 * **Only a file that names a side is transcribed.** A mixed recording holds two people nobody separated,
 * and this application no longer guesses which is which — it keeps the audio and reads the call's words
 * from the Customer and Agent recordings, if there are any. A call with neither is a call with no
 * transcript, and that is accepted rather than worked around.
 */
final class RecordingProcessingPolicyTest extends Unit
{
    public function testCallerIsAlwaysTheCustomerSide(): void
    {
        $policy = Policy::decide(Policy::TYPE_CALLER);

        $this->assertSame(Policy::ROLE_CUSTOMER, $policy->sourceRole);
        $this->assertTrue($policy->isDeterministic());
        $this->assertTrue($policy->transcribe);
    }

    public function testCalleeIsAlwaysTheAgentSide(): void
    {
        $policy = Policy::decide(Policy::TYPE_CALLEE);

        $this->assertSame(Policy::ROLE_AGENT, $policy->sourceRole);
        $this->assertTrue($policy->isDeterministic());
        $this->assertTrue($policy->transcribe);
    }

    /** **The rule.** A mixed recording is kept as audio and is never transcribed. */
    public function testAMixedRecordingIsNeverTranscribed(): void
    {
        $policy = Policy::decide(Policy::TYPE_MIXED);

        $this->assertFalse($policy->transcribe);
        $this->assertFalse($policy->isDeterministic());
        $this->assertSame(Policy::ROLE_COMMON, $policy->sourceRole);
    }

    /**
     * And asking for one does not change the answer.
     *
     * This is the request shape that has to be refused: a form, or a crafted POST, saying "transcribe
     * this". The policy only ever withdraws transcription, so there is no value of this argument that
     * turns a mixed recording into a transcription job.
     */
    public function testAskingToTranscribeAMixedRecordingChangesNothing(): void
    {
        $this->assertFalse(Policy::decide(Policy::TYPE_MIXED, transcribeRequested: true)->transcribe);
        $this->assertFalse(Policy::decide(Policy::TYPE_MIXED, transcribeRequested: false)->transcribe);
    }

    /**
     * A request that names no type at all is treated as a mixed recording, not as a free pass.
     *
     * An upload naming no side is a mixed recording by every other measure, and leaving this branch
     * permissive would have made "omit the field" the way to transcribe one.
     */
    public function testNamingNoTypeIsNotAWayToGetATranscript(): void
    {
        foreach ([null, 'SOMETHING_ELSE'] as $type) {
            $policy = Policy::decide($type);

            $this->assertFalse($policy->transcribe);
            $this->assertFalse($policy->isDeterministic());
            $this->assertSame(Policy::ROLE_COMMON, $policy->sourceRole);
        }
    }

    /** A side that was downloaded without a transcript in mind stays that way until somebody asks. */
    public function testASideNotAskedForIsNotTranscribed(): void
    {
        $this->assertFalse(Policy::decide(Policy::TYPE_CALLER, transcribeRequested: false)->transcribe);
        $this->assertFalse(Policy::decide(Policy::TYPE_CALLEE, transcribeRequested: false)->transcribe);
    }

    /**
     * The same rule read from a stored row, which is what the "Transcribe audio" control asks.
     *
     * Keyed on `source_role` rather than on the recording type, so a legacy Customer or Agent half —
     * which carries a role but no type — is still allowed the transcript it has always been allowed.
     */
    public function testOnlyARecordingHoldingOneDeclaredSideMayBeTranscribedOnRequest(): void
    {
        $this->assertTrue(Policy::allowsTranscription(Policy::ROLE_CUSTOMER));
        $this->assertTrue(Policy::allowsTranscription(Policy::ROLE_AGENT));
        $this->assertFalse(Policy::allowsTranscription(Policy::ROLE_COMMON));
        $this->assertFalse(Policy::allowsTranscription(null));
        $this->assertFalse(Policy::allowsTranscription('ANYTHING_ELSE'));
    }
}
