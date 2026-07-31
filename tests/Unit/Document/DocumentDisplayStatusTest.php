<?php

declare(strict_types=1);

namespace App\Tests\Unit\Document;

use App\Document\Domain\DocumentDisplayStatus;
use App\Document\Domain\DocumentStatus;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;

/**
 * The technical document lifecycle collapses to four admin-facing words. A disabled document reads as
 * "Disabled" wherever it is in the lifecycle; everything mid-pipeline reads as "Processing".
 */
final class DocumentDisplayStatusTest extends Unit
{
    public function testInProgressStatusesMapToProcessing(): void
    {
        foreach ([DocumentStatus::Uploaded, DocumentStatus::Queued, DocumentStatus::Processing, DocumentStatus::Indexing] as $status) {
            assertSame(DocumentDisplayStatus::Processing, DocumentDisplayStatus::for($status, true), $status->value);
        }
    }

    public function testReadyAndEnabledIsReady(): void
    {
        assertSame(DocumentDisplayStatus::Ready, DocumentDisplayStatus::for(DocumentStatus::Ready, true));
    }

    public function testFailedIsFailed(): void
    {
        assertSame(DocumentDisplayStatus::Failed, DocumentDisplayStatus::for(DocumentStatus::Failed, true));
    }

    public function testDisabledOverridesEveryLifecycleState(): void
    {
        foreach ([DocumentStatus::Ready, DocumentStatus::Failed, DocumentStatus::Queued, DocumentStatus::Indexing] as $status) {
            assertSame(DocumentDisplayStatus::Disabled, DocumentDisplayStatus::for($status, false), $status->value);
        }
    }
}
