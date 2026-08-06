<?php

declare(strict_types=1);

namespace App\Tests\Unit\Rules;

use App\Rules\Application\AliasNormalizer;
use App\Rules\Application\RuleStoreMatcher;
use App\Rules\Domain\AliasType;
use App\Rules\Domain\ApprovedAlias;
use App\Rules\Domain\ClassificationStatus;
use App\Rules\Domain\RuleScope;
use App\Rules\Domain\StoreMatchMethod;
use App\Rules\Domain\StoreMatchStatus;
use Codeception\Test\Unit;

use function count;
use function PHPUnit\Framework\assertCount;
use function PHPUnit\Framework\assertSame;

/**
 * The deterministic store-matching policy, in strict priority order. A single exact, unambiguous signal
 * auto-matches with a confirmed link; several exact stores are ambiguous; an apparent-but-unknown store name is
 * unmatched; a fuzzy near-match is a pending suggestion; and a rule with no store candidate is auto-confirmed as
 * common (so it is searchable everywhere without a manual step).
 */
final class RuleStoreMatcherTest extends Unit
{
    private const KNOWN = [10, 20, 30];

    private RuleStoreMatcher $matcher;

    protected function _before(): void
    {
        $this->matcher = new RuleStoreMatcher();
    }

    public function testAuthoritativeSourceStoreIdWinsOverEverything(): void
    {
        // Even though the text names store 20, the authoritative source id (10) is used.
        $result = $this->matcher->match(10, self::KNOWN, 'Moon Temple special', 'body', [$this->nameAlias(20, 'Moon Temple')]);

        assertSame(RuleScope::StoreSpecific, $result->scope);
        assertSame(ClassificationStatus::AutoMatched, $result->status);
        assertCount(1, $result->candidates);
        assertSame(10, $result->candidates[0]->storeSourceId);
        assertSame(StoreMatchMethod::SourceStoreId, $result->candidates[0]->method);
        assertSame(StoreMatchStatus::Confirmed, $result->candidates[0]->status);
    }

    public function testExactDomainAliasMatches(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'Delivery note', 'See moontemple.order58.com for details', [
            new ApprovedAlias(10, 'moontemple.order58.com', 'moontemple.order58.com', AliasType::Domain),
        ]);

        assertSame(ClassificationStatus::AutoMatched, $result->status);
        assertSame(10, $result->candidates[0]->storeSourceId);
        assertSame(StoreMatchMethod::Domain, $result->candidates[0]->method);
    }

    public function testExactTitleAliasMatchesAsWholeWord(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'Moon Temple: advance orders', 'body', [$this->nameAlias(10, 'Moon Temple')]);

        assertSame(ClassificationStatus::AutoMatched, $result->status);
        assertSame(StoreMatchMethod::TitleExactAlias, $result->candidates[0]->method);
        assertSame(StoreMatchStatus::Confirmed, $result->candidates[0]->status);
    }

    public function testExactDescriptionAliasMatchesWithLowerConfidenceMethod(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'Advance orders', 'This applies to Luckyway Kitchen only', [$this->nameAlias(20, 'Luckyway Kitchen')]);

        assertSame(ClassificationStatus::AutoMatched, $result->status);
        assertSame(StoreMatchMethod::DescriptionExactAlias, $result->candidates[0]->method);
    }

    public function testAWholeWordAliasDoesNotMatchAnUnrelatedSubstring(): void
    {
        // "Ting Ho" must not exact-match inside "Tinghouse" — token boundaries protect against that.
        $result = $this->matcher->match(null, self::KNOWN, 'Tinghouse cleaning schedule', 'body', [$this->nameAlias(10, 'Ting Ho')]);

        assertSame(RuleScope::Unresolved, $result->scope);
        // It may surface as a fuzzy suggestion, but never as a confirmed exact match.
        foreach ($result->candidates as $candidate) {
            assertSame(StoreMatchStatus::Suggested, $candidate->status);
        }
    }

    public function testFuzzyNearMatchIsOnlyASuggestion(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'Moontemple weekend hours', 'body', [$this->nameAlias(10, 'Moon Temple')]);

        assertSame(RuleScope::Unresolved, $result->scope);
        assertSame(ClassificationStatus::Pending, $result->status);
        assertCount(1, $result->candidates);
        assertSame(StoreMatchMethod::FuzzySuggestion, $result->candidates[0]->method);
        assertSame(StoreMatchStatus::Suggested, $result->candidates[0]->status);
    }

    public function testMultipleExactStoresAreAmbiguous(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'Moon Temple and Luckyway Kitchen joint promo', 'body', [
            $this->nameAlias(10, 'Moon Temple'),
            $this->nameAlias(20, 'Luckyway Kitchen'),
        ]);

        assertSame(RuleScope::Unresolved, $result->scope);
        assertSame(ClassificationStatus::Ambiguous, $result->status);
        assertSame(2, count($result->candidates));
        foreach ($result->candidates as $candidate) {
            assertSame(StoreMatchStatus::Suggested, $candidate->status);
        }
    }

    public function testApparentButUnknownStoreIsUnmatchedNotCommon(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'For Golden Palace, hold advance orders', 'body', []);

        assertSame(RuleScope::Unresolved, $result->scope);
        assertSame(ClassificationStatus::Unmatched, $result->status);
        assertSame('Golden Palace', $result->detectedStoreText);
    }

    public function testGeneralLanguageIsAutoConfirmedCommon(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'General callback procedure for all stores', 'Applies to all agents', []);

        assertSame(ClassificationStatus::ConfirmedCommon, $result->status);
        assertSame(RuleScope::Common, $result->scope);
        assertSame('auto_confirmed_common_general_language', $result->reason);
    }

    public function testNoStoreCandidateIsAutoConfirmedCommon(): void
    {
        $result = $this->matcher->match(null, self::KNOWN, 'Always confirm the order total before hanging up', 'body', []);

        assertSame(ClassificationStatus::ConfirmedCommon, $result->status);
        assertSame(RuleScope::Common, $result->scope);
        assertCount(0, $result->candidates);
        assertSame('auto_confirmed_common_no_store_match', $result->reason);
    }

    public function testAManuallyApprovedAliasIsUsedInMatching(): void
    {
        // An admin-approved alias ("Lucky Way Kitchen") for store 20 resolves a spelling variation exactly.
        $result = $this->matcher->match(null, self::KNOWN, 'Lucky Way Kitchen delivery radius', 'body', [
            new ApprovedAlias(20, 'Lucky Way Kitchen', AliasNormalizer::normalize('Lucky Way Kitchen'), AliasType::Manual),
        ]);

        assertSame(ClassificationStatus::AutoMatched, $result->status);
        assertSame(20, $result->candidates[0]->storeSourceId);
    }

    private function nameAlias(int $storeId, string $name): ApprovedAlias
    {
        return new ApprovedAlias($storeId, $name, AliasNormalizer::normalize($name), AliasType::OfficialName);
    }
}
