<?php

declare(strict_types=1);

namespace App\Chat\Web;

use App\Chat\Domain\ChatSourceItem;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;

use function json_encode;

use const JSON_HEX_TAG;
use const JSON_THROW_ON_ERROR;

/**
 * The JSON body of the source-detail endpoint, shared by all four chat surfaces.
 *
 * An allow-list, not a filter: the response is assembled field by field from {@see ChatSourceItem}, so a
 * field added to the item later cannot leak here by accident. Nothing internal is exposed — no OpenAI file
 * id, no vector store id, no storage path, no checksum or sync hash, no credential. `content` is the
 * document's own stored text as read for the transparency page; it is never synthesised, and a document
 * whose body cannot be read reports null rather than inventing one.
 */
final readonly class ChatSourcePayload
{
    public function __construct(private ResponseFactoryInterface $responseFactory) {}

    public function respond(ChatSourceItem $item): ResponseInterface
    {
        $body = [
            'title' => $item->title,
            'type' => $item->typeLabel(),
            'content' => $item->hasPreview() ? $item->preview : null,
            'truncated' => $item->previewTruncated,
            // Why retrieval cannot currently reach it, if it cannot — the same honest note the
            // "knowledge available to this chat" page shows. Null when the source is reachable.
            'unavailable_reason' => $item->unavailableReason(),
        ];

        $response = $this->responseFactory->createResponse(200)
            ->withHeader('Content-Type', 'application/json; charset=UTF-8');
        // Angle brackets escaped as <: a document body is arbitrary user-supplied text, and this makes
        // the response inert as markup no matter how it is later handled. Belt and braces — the content type
        // plus `X-Content-Type-Options: nosniff` already stop a browser executing it, and the client writes
        // the text with textContent — but the cost is nothing and the failure mode it removes is total.
        $response->getBody()->write(json_encode($body, JSON_THROW_ON_ERROR | JSON_HEX_TAG));

        return $response;
    }
}
