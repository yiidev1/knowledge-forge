<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Domain\DocumentSourceType;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The single source of truth for "qualifying chat content". Genuine answerable content — Order58 knowledge and
 * the admin uploads (manual text, PDF, text file, image) — enables chat; the store-profile snapshot and the rule
 * projections (store/global/common) never do on their own. The SQL eligibility fragment
 * ({@see \App\KnowledgeBase\Infrastructure\KnowledgeBaseChatEligibilitySql}) and the PHP availability policy
 * ({@see \App\Chat\Application\ChatAvailabilityPolicy}) both derive their exclusion from this, so pinning the
 * exact set here guards both against drift.
 */
final class DocumentSourceTypeTest extends Unit
{
    public function testGenuineContentTypesQualify(): void
    {
        assertTrue(DocumentSourceType::Order58Knowledge->isQualifyingChatContent());
        assertTrue(DocumentSourceType::ManualText->isQualifyingChatContent());
        assertTrue(DocumentSourceType::UploadedPdf->isQualifyingChatContent());
        assertTrue(DocumentSourceType::UploadedText->isQualifyingChatContent());
        assertTrue(DocumentSourceType::UploadedImage->isQualifyingChatContent());
    }

    public function testStoreProfileAndRuleProjectionsDoNotQualify(): void
    {
        assertFalse(DocumentSourceType::Order58StoreProfile->isQualifyingChatContent());
        assertFalse(DocumentSourceType::Order58RuleStore->isQualifyingChatContent());
        assertFalse(DocumentSourceType::Order58RuleGlobal->isQualifyingChatContent());
        assertFalse(DocumentSourceType::Order58RuleCommon->isQualifyingChatContent());
    }

    public function testNonQualifyingValuesAreExactlyTheProfileAndRuleProjections(): void
    {
        assertSame(
            [
                DocumentSourceType::Order58StoreProfile->value,
                DocumentSourceType::Order58RuleStore->value,
                DocumentSourceType::Order58RuleGlobal->value,
                DocumentSourceType::Order58RuleCommon->value,
            ],
            DocumentSourceType::nonQualifyingChatContentValues(),
        );
    }
}
