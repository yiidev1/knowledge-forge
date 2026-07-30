<?php

declare(strict_types=1);

namespace App\Document\Application\Validation;

use App\Document\Domain\DocumentKind;

use function array_keys;

/**
 * The allowlist of accepted document types, keyed by SERVER-DETECTED MIME type.
 *
 * This is the single source of truth for "what can be uploaded". Each entry maps a trusted MIME type to
 * the canonical extension the file will be stored with and the ingestion kind it belongs to. Adding
 * DOCX/TXT later is one row here plus a processor class — the upload path does not change. The
 * browser-supplied MIME and filename are never consulted; only the sniffed type is matched against
 * these keys.
 */
final class SupportedFileTypes
{
    /**
     * @var array<string, array{extension: string, kind: DocumentKind}>
     */
    private const MAP = [
        'application/pdf' => ['extension' => 'pdf', 'kind' => DocumentKind::Pdf],
        'image/png' => ['extension' => 'png', 'kind' => DocumentKind::Image],
        'image/jpeg' => ['extension' => 'jpg', 'kind' => DocumentKind::Image],
        'image/webp' => ['extension' => 'webp', 'kind' => DocumentKind::Image],
    ];

    public static function isSupported(string $detectedMime): bool
    {
        return isset(self::MAP[$detectedMime]);
    }

    public static function extensionFor(string $detectedMime): ?string
    {
        return self::MAP[$detectedMime]['extension'] ?? null;
    }

    public static function kindFor(string $detectedMime): ?DocumentKind
    {
        return self::MAP[$detectedMime]['kind'] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function acceptedMimeTypes(): array
    {
        return array_keys(self::MAP);
    }

    /**
     * The `accept` attribute value for the file input, so the browser's own picker pre-filters. This is
     * a convenience only — it is never trusted for validation.
     */
    public static function acceptAttribute(): string
    {
        return '.pdf,.png,.jpg,.jpeg,.webp,application/pdf,image/png,image/jpeg,image/webp';
    }
}
