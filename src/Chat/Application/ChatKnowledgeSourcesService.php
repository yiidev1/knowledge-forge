<?php

declare(strict_types=1);

namespace App\Chat\Application;

use App\Chat\Domain\ChatRetrievalScope;
use App\Chat\Domain\ChatSourceItem;
use App\Document\Application\ServeCanonicalDocumentService;
use App\Document\Domain\DocumentListItem;
use App\Document\Domain\DocumentRepositoryInterface;
use App\Document\Domain\DocumentSourceType;
use App\Document\Domain\TextDocumentRepositoryInterface;
use App\KnowledgeBase\Domain\KnowledgeBase;
use App\Order58\Application\Order58DisplayParams;
use Throwable;

use function in_array;
use function mb_strlen;
use function mb_substr;
use function rtrim;
use function trim;

/**
 * Builds the read-only "knowledge available to this chat" list for one knowledge base.
 *
 * Deliberately assembled from the SAME primitives the chat itself uses, so the page can never disagree with
 * retrieval: the document listing is the knowledge base's own ({@see TextDocumentRepositoryInterface::findListForKnowledgeBase()},
 * already scoped by `knowledge_base_id`, which is what makes cross-store leakage impossible), reachability is
 * the canonical usable-snapshot set ({@see DocumentRepositoryInterface::findUsableDocumentIds()}), and the
 * source-type filter is the surface's own {@see ChatRetrievalScope} — the same object
 * {@see \App\Chat\Application\Citation\CitationResolver} enforces when it accepts or drops a citation.
 *
 * No business rule is re-derived here; this only joins the two and labels the result.
 */
final readonly class ChatKnowledgeSourcesService
{
    /**
     * How much of a document's text the page shows. Generous enough that a typical knowledge record is
     * complete, bounded so a long vision-extracted PDF cannot dominate the response.
     */
    private const PREVIEW_MAX_CHARS = 4000;

    public function __construct(
        private TextDocumentRepositoryInterface $documents,
        private DocumentRepositoryInterface $repository,
        private ServeCanonicalDocumentService $canonical,
        private Order58DisplayParams $order58Display,
    ) {}

    /**
     * @return list<ChatSourceItem> Newest first, matching the knowledge-base document list's own order.
     */
    public function forKnowledgeBase(
        KnowledgeBase $knowledgeBase,
        ChatRetrievalScope $scope = ChatRetrievalScope::StoreKnowledge,
    ): array {
        $knowledgeBaseId = $knowledgeBase->id();
        $usableIds = $this->repository->findUsableDocumentIds($knowledgeBaseId);

        $items = [];
        foreach ($this->documents->findListForKnowledgeBase($knowledgeBaseId) as $document) {
            // A document outside the surface's retrieval scope is not "knowledge this chat may use" at all —
            // it is omitted rather than listed as unavailable, so Store Chat's page never advertises a rule
            // projection and Rule Chat's never advertises store knowledge.
            if (!$scope->allows($document->sourceType)) {
                continue;
            }

            // The same operator switch the knowledge-base management page honours
            // ({@see \App\KnowledgeBase\Web\Show\Action}). Filtering here rather than in the template is what
            // keeps the counts truthful: both the total and the "available" tally are derived from this list.
            if (!$this->order58Display->showStoreProfileDocuments
                && $document->sourceType === DocumentSourceType::Order58StoreProfile) {
                continue;
            }

            $items[] = $this->toItem($document, in_array($document->id, $usableIds, true), $knowledgeBaseId);
        }

        return $items;
    }

    /**
     * How many of the listed documents retrieval can actually reach right now.
     *
     * @param list<ChatSourceItem> $items
     */
    public function retrievableCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            if ($item->retrievable) {
                ++$count;
            }
        }

        return $count;
    }

    private function toItem(DocumentListItem $document, bool $usable, int $knowledgeBaseId): ChatSourceItem
    {
        [$preview, $truncated] = $this->preview($document->id, $knowledgeBaseId);

        return new ChatSourceItem(
            documentId: $document->id,
            title: $document->title,
            sourceType: $document->sourceType,
            kind: $document->kind,
            displayStatus: $document->displayStatus(),
            // Enabled + a completed index snapshot is exactly what makes a file searchable in the vector store.
            retrievable: $usable && $document->isEnabled,
            createdAt: $document->createdAt,
            preview: $preview,
            previewTruncated: $truncated,
        );
    }

    /**
     * The document's own text, via the same reader the View page uses — so the page shows the artifact
     * retrieval reads, not a re-derived summary.
     *
     * One lookup + one read per document. That is bounded by `MAX_DOCUMENTS_PER_KNOWLEDGE_BASE`, and a
     * document whose body cannot be read (binary original, missing file) simply has no preview rather than
     * failing the page.
     *
     * @return array{0: string|null, 1: bool} The preview text and whether it was cut short.
     */
    private function preview(int $documentId, int $knowledgeBaseId): array
    {
        try {
            $document = $this->repository->findCanonicalForKnowledgeBase($documentId, $knowledgeBaseId);
            if ($document === null) {
                return [null, false];
            }

            $body = trim($this->canonical->textBody($document));
        } catch (Throwable) {
            return [null, false];
        }

        if ($body === '') {
            return [null, false];
        }

        if (mb_strlen($body) <= self::PREVIEW_MAX_CHARS) {
            return [$body, false];
        }

        return [rtrim(mb_substr($body, 0, self::PREVIEW_MAX_CHARS)), true];
    }
}
