<?php

declare(strict_types=1);

namespace App\Document\Domain;

/**
 * What an indexed file represents relative to its document.
 *
 * `Source` is the original file indexed directly (a text-bearing PDF). `DerivedMarkdown` is Markdown the
 * worker generated from an image or a scanned PDF and indexed in place of the original — which is why a
 * citation must resolve back through the document to show the original filename, not this artifact.
 */
enum IndexedFileRole: string
{
    case Source = 'source';
    case DerivedMarkdown = 'derived_markdown';
}
