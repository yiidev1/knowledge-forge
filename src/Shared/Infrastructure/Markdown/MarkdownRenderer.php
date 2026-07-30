<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Markdown;

use League\CommonMark\CommonMarkConverter;

/**
 * Renders Markdown to HTML for display. Model output and extracted document text are UNTRUSTED, so any
 * embedded HTML is escaped (`html_input => escape`) and unsafe link schemes are stripped
 * (`allow_unsafe_links => false`). The output is safe to echo without further escaping.
 */
final readonly class MarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]);
    }

    public function toHtml(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
