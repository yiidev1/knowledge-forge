<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * The category of an uploaded document, which decides how it is ingested.
 *
 * Persisted as a plain string (not a DB enum), so adding a future kind — DOCX, TXT, Markdown — is a new
 * processor class plus a case here, with no migration. A PDF may be indexed directly or, if scanned,
 * converted to Markdown; an image is never a File Search document and is always converted to Markdown
 * (Phase 5). That divergence is why kind, not just MIME type, is recorded.
 */
enum DocumentKind: string
{
    case Pdf = 'pdf';
    case Image = 'image';
    // Plain UTF-8 text that is already index-ready: Order58-generated store/knowledge documents, and (in
    // later phases) uploaded .txt/.md and manual text. Indexed directly as a source file, with no AI step.
    case Text = 'text';

    public function label(): string
    {
        return match ($this) {
            self::Pdf => 'PDF',
            self::Image => 'Image',
            self::Text => 'Text',
        };
    }
}
