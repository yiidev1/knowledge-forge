<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chat;

use App\Chat\Domain\ChatRetrievalScope;
use App\Document\Domain\DocumentSourceType;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertTrue;

final class ChatRetrievalScopeTest extends Unit
{
    public function testStoreKnowledgeRejectsAllRuleProjectionsAndAllowsOtherSources(): void
    {
        $scope = ChatRetrievalScope::StoreKnowledge;

        assertFalse($scope->allows(DocumentSourceType::Order58RuleStore));
        assertFalse($scope->allows(DocumentSourceType::Order58RuleGlobal));
        assertFalse($scope->allows(DocumentSourceType::Order58RuleCommon));

        assertTrue($scope->allows(DocumentSourceType::Order58StoreProfile));
        assertTrue($scope->allows(DocumentSourceType::Order58Knowledge));
        assertTrue($scope->allows(DocumentSourceType::UploadedPdf));
        assertTrue($scope->allows(DocumentSourceType::UploadedImage));
        assertTrue($scope->allows(DocumentSourceType::UploadedText));
        assertTrue($scope->allows(DocumentSourceType::ManualText));
    }

    public function testRuleOnlyAllowsGlobalAndLegacyCommonRuleProjectionsOnly(): void
    {
        $scope = ChatRetrievalScope::RuleOnly;

        assertTrue($scope->allows(DocumentSourceType::Order58RuleGlobal));
        assertTrue($scope->allows(DocumentSourceType::Order58RuleCommon));

        assertFalse($scope->allows(DocumentSourceType::Order58RuleStore));
        assertFalse($scope->allows(DocumentSourceType::Order58Knowledge));
        assertFalse($scope->allows(DocumentSourceType::Order58StoreProfile));
        assertFalse($scope->allows(DocumentSourceType::ManualText));
        assertFalse($scope->allows(DocumentSourceType::UploadedPdf));
    }
}
