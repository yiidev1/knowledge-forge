<?php

declare(strict_types=1);

namespace App\AudioToText\Web;

use App\Shared\Web\Support\Redirect;
use Psr\Http\Message\ResponseInterface;

/**
 * `GET /audio-to-text` — now the way in to the store picker rather than an upload form of its own.
 *
 * Every conversion belongs to a store, and the store comes from the URL of the page you upload from
 * (`audio-to-text.store`), so there is no longer a store-less form to render here. What there still is
 * is a great many links to this address: the sidebar entry, the conversions list's "Convert a file"
 * button, the breadcrumb on every job page, and whatever administrators have bookmarked. Redirecting
 * keeps all of them working and lands the reader one click from where they were going.
 *
 * `POST /audio-to-text` is gone entirely — the route no longer accepts it. That is deliberate: an
 * upload endpoint that could not name a store would have to either invent one or write a conversation
 * nobody's history shows, and both are worse than a 405.
 *
 * The picker is addressed by route name, not by class. This module may not name the Order58 module —
 * `ModuleIsolationTest` matches that namespace literally and fails the build — and Store chat has
 * linked out the same way since it was written.
 */
final readonly class Action
{
    public function __construct(
        private Redirect $redirect,
    ) {}

    public function __invoke(): ResponseInterface
    {
        return $this->redirect->toRoute('order58.store-audio');
    }
}
