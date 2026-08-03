<?php

declare(strict_types=1);

namespace App\Tests\Unit\KnowledgeBase;

use App\Document\Domain\DocumentStatus;
use Codeception\Test\Unit;

use function dirname;
use function file_get_contents;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertTrue;
use function preg_match;
use function str_contains;

/**
 * Guards the document-row operator actions on the knowledge-base detail page.
 *
 * Asserted against the template source (same approach as other template safety tests): rendering would
 * pull the view package into the unit suite. Visibility is expressed with domain predicates that are
 * unit-tested below and must appear in the template source.
 */
final class ShowDocumentActionsTemplateTest extends Unit
{
    private string $template;

    protected function _before(): void
    {
        $this->template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/src/KnowledgeBase/Web/Show/template.php',
        );
    }

    /**
     * @return iterable<string, array{0: DocumentStatus, 1: bool, 2: bool, 3: bool}>
     */
    public static function statusVisibilityProvider(): iterable
    {
        // status => [processNext, reindex, retry]
        yield 'queued' => [DocumentStatus::Queued, true, false, false];
        yield 'processing' => [DocumentStatus::Processing, true, false, false];
        yield 'indexing' => [DocumentStatus::Indexing, true, false, false];
        yield 'ready' => [DocumentStatus::Ready, false, true, false];
        yield 'failed' => [DocumentStatus::Failed, false, false, true];
        yield 'uploaded' => [DocumentStatus::Uploaded, false, false, false];
    }

    /**
     * @dataProvider statusVisibilityProvider
     */
    public function testButtonVisibilityByStatus(
        DocumentStatus $status,
        bool $expectProcessNext,
        bool $expectReindex,
        bool $expectRetry,
    ): void {
        assertTrue($expectProcessNext === $status->isInProgress(), 'Process next for ' . $status->value);
        assertTrue($expectReindex === ($status === DocumentStatus::Ready), 'Re-index for ' . $status->value);
        assertTrue($expectRetry === ($status === DocumentStatus::Failed), 'Retry for ' . $status->value);

        // Cross-check the mutually exclusive pairs required by the ticket.
        if ($expectProcessNext) {
            assertFalse($expectReindex);
        }
        if ($expectReindex) {
            assertFalse($expectProcessNext);
        }
        if ($expectRetry) {
            assertFalse($expectProcessNext);
            assertFalse($expectReindex);
        }
    }

    public function testTemplateGatesProcessNextOnInProgressStatuses(): void
    {
        assertStringContainsString('$document->status->isInProgress()', $this->template);
        assertStringContainsString("generate('kb.documents.process-now'", $this->template);
        assertStringContainsString('Process next', $this->template);
        assertStringContainsString(
            'Move this document to the front of the processing queue.',
            $this->template,
        );
    }

    public function testTemplateGatesReindexOnReadyStatus(): void
    {
        assertStringContainsString('DocumentStatus::Ready', $this->template);
        assertStringContainsString("generate('kb.documents.reindex'", $this->template);
        assertStringContainsString('Re-index', $this->template);
        assertStringContainsString(
            'Re-index this document? It will be uploaded and attached to OpenAI again.',
            $this->template,
        );
    }

    public function testFailedDocumentsStillOfferRetryAndNotTheRestoredActions(): void
    {
        assertStringContainsString('DocumentDisplayStatus::Failed', $this->template);
        assertStringContainsString("generate('kb.documents.retry'", $this->template);
        assertStringContainsString('>Retry</button>', $this->template);

        // Retry is gated on Failed; Process next / Re-index use different predicates.
        assertTrue((bool) preg_match(
            '/\$displayStatus === DocumentDisplayStatus::Failed/',
            $this->template,
        ));
        assertFalse((bool) preg_match(
            '/DocumentDisplayStatus::Failed[^;]{0,200}process-now|DocumentDisplayStatus::Failed[^;]{0,200}reindex/s',
            $this->template,
        ));
    }

    public function testProcessNextAndReindexFormsCarryCsrf(): void
    {
        // Both restored forms sit inside the same CSRF pattern used by Edit/Retry/Disable/Remove.
        assertStringContainsString('$csrfField = (string) $csrf->hiddenInput();', $this->template);

        assertTrue((bool) preg_match(
            '/\$processNowUrl[^>]*>.*?<\?= \$csrfField \?>.*?Process next/s',
            $this->template,
        ), 'Process next form must include the CSRF field');

        assertTrue((bool) preg_match(
            '/\$reindexUrl[^>]*>.*?<\?= \$csrfField \?>.*?Re-index/s',
            $this->template,
        ), 'Re-index form must include the CSRF field');
    }

    public function testRestoredActionsRemainSiblingFormsNotNested(): void
    {
        // Each action is its own <form> inside .table__actions; no nested forms.
        assertTrue(str_contains($this->template, 'class="table__actions"'));
        assertFalse((bool) preg_match('/<form[^>]*>[^<]*<form/s', $this->template));
    }
}
