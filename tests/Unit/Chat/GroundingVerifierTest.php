<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Ai\Contract\Dto\GroundedAnswerResult;
use App\Ai\Contract\Dto\TokenUsage;
use App\Chat\Application\ChatParams;
use App\Chat\Application\Grounding\GroundingOutcome;
use App\Chat\Application\Grounding\GroundingVerifier;
use App\Chat\Domain\ResolvedCitation;
use Codeception\Test\Unit;

use function count;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The server-side grounding gate. A guessed or unretrieved answer must never survive verification.
 */
final class GroundingVerifierTest extends Unit
{
    private const FALLBACK = 'I could not find enough information to answer that.';
    private const TRUNCATED = 'The answer was cut short.';

    public function testNoRetrievalCallBecomesFallback(): void
    {
        $outcome = $this->verifier()->verify($this->answer(retrievalCalled: false), $this->oneCitation());

        assertSame(self::FALLBACK, $outcome->text);
        assertFalse($outcome->isGrounded);
        assertSame('not_called', $outcome->retrievalStatus);
    }

    public function testIncompleteRetrievalBecomesFallback(): void
    {
        $outcome = $this->verifier()->verify($this->answer(status: 'failed'), $this->oneCitation());

        assertSame(self::FALLBACK, $outcome->text);
        assertSame('failed', $outcome->retrievalStatus);
    }

    public function testNoResultsBecomesFallback(): void
    {
        $outcome = $this->verifier()->verify($this->answer(resultCount: 0), $this->oneCitation());

        assertSame(self::FALLBACK, $outcome->text);
    }

    public function testScoreBelowMinimumBecomesFallback(): void
    {
        $verifier = $this->verifier(minScore: 0.5);
        $outcome = $verifier->verify($this->answer(topScore: 0.2), $this->oneCitation());

        assertSame(self::FALLBACK, $outcome->text);
    }

    public function testUncitedAnswerIsSuppressedWhenCitationsRequired(): void
    {
        $outcome = $this->verifier(requireCitations: true)->verify($this->answer(), []);

        assertSame(self::FALLBACK, $outcome->text);
        assertFalse($outcome->isGrounded);
    }

    public function testUncitedAnswerIsShownUngroundedWhenCitationsNotRequired(): void
    {
        $outcome = $this->verifier(requireCitations: false)->verify($this->answer(text: 'The answer.'), []);

        assertSame('The answer.', $outcome->text);
        assertFalse($outcome->isGrounded);
        assertSame('completed', $outcome->retrievalStatus);
    }

    public function testGroundedAnswerWithCitationsSurvives(): void
    {
        $outcome = $this->verifier()->verify($this->answer(text: 'The answer.'), $this->oneCitation());

        assertSame('The answer.', $outcome->text);
        assertTrue($outcome->isGrounded);
        assertSame(1, count($outcome->citations));
    }

    /**
     * A truncated answer also arrives with no citations, but the cause is completely different: the model
     * ran out of output budget rather than finding nothing. It is still refused — an uncited answer is
     * never shown — but the user must be told the real reason, not "I could not find enough information".
     */
    public function testTruncatedAnswerIsRefusedWithItsOwnMessageAndReason(): void
    {
        $outcome = $this->verifier(requireCitations: true)->verify(
            $this->answer(text: '', responseStatus: 'incomplete', incompleteReason: 'max_output_tokens'),
            [],
        );

        assertSame(self::TRUNCATED, $outcome->text);
        assertFalse($outcome->isGrounded);
        assertSame(GroundingOutcome::REASON_TRUNCATED, $outcome->rejectionReason);
    }

    /**
     * The distinction must not become a loophole: a genuinely unsupported answer is still replaced by the
     * fallback, and still recorded as such.
     */
    public function testUnsupportedAnswerIsStillRejectedWithTheOriginalReason(): void
    {
        $outcome = $this->verifier(requireCitations: true)->verify($this->answer(text: 'I am certain.'), []);

        assertSame(self::FALLBACK, $outcome->text);
        assertFalse($outcome->isGrounded);
        assertSame(GroundingOutcome::REASON_NO_CITATIONS, $outcome->rejectionReason);
    }

    /**
     * A cited answer that happened to be cut short keeps its text — the citations prove it stands on real
     * documents — but the truncation is still recorded so the log shows why it may look short.
     */
    public function testTruncatedButCitedAnswerIsKeptAndFlagged(): void
    {
        $outcome = $this->verifier()->verify(
            $this->answer(text: 'Partial answer.', responseStatus: 'incomplete', incompleteReason: 'max_output_tokens'),
            $this->oneCitation(),
        );

        assertSame('Partial answer.', $outcome->text);
        assertTrue($outcome->isGrounded);
        assertSame(GroundingOutcome::REASON_TRUNCATED, $outcome->rejectionReason);
    }

    public function testAcceptedAnswerCarriesNoRejectionReason(): void
    {
        $outcome = $this->verifier()->verify($this->answer(text: 'The answer.'), $this->oneCitation());

        assertSame(null, $outcome->rejectionReason);
    }

    /**
     * @return list<ResolvedCitation>
     */
    private function oneCitation(): array
    {
        return [new ResolvedCitation(7, 'invoice.pdf', 'file_1')];
    }

    private function answer(
        string $text = 'answer',
        bool $retrievalCalled = true,
        string $status = 'completed',
        int $resultCount = 3,
        float $topScore = 0.9,
        string $responseStatus = 'completed',
        ?string $incompleteReason = null,
    ): GroundedAnswerResult {
        return new GroundedAnswerResult(
            text: $text,
            retrievalCalled: $retrievalCalled,
            retrievalStatus: $status,
            retrievalResultCount: $resultCount,
            topResultScore: $topScore,
            citations: [],
            usage: new TokenUsage(1, 1, 2),
            providerResponseId: null,
            model: 'm',
            responseStatus: $responseStatus,
            incompleteReason: $incompleteReason,
        );
    }

    private function verifier(bool $requireCitations = true, float $minScore = 0.0): GroundingVerifier
    {
        return new GroundingVerifier(new ChatParams(
            model: 'm',
            maxResults: 8,
            maxOutputTokens: 1200,
            forceFileSearch: true,
            requireCitations: $requireCitations,
            minCitationScore: $minScore,
            fallbackMessage: self::FALLBACK,
            maxQuestionLength: 2000,
            truncatedMessage: self::TRUNCATED,
        ));
    }
}
