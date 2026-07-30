<?php

declare(strict_types=1);

namespace App\KnowledgeBase\Application;

use App\KnowledgeBase\Domain\KnowledgeBaseRepositoryInterface;

use function preg_replace;
use function strtolower;
use function trim;

/**
 * Turns a knowledge-base name into a unique, URL-safe slug.
 *
 * The slug is the public identifier in every knowledge-base URL, so it must be stable and collision
 * free. Uniqueness is resolved by appending -2, -3, … against the repository. Once assigned, a slug is
 * never regenerated (see the update service), because changing it would break existing links.
 */
final readonly class SlugGenerator
{
    private const MAX_LENGTH = 160;

    public function __construct(
        private KnowledgeBaseRepositoryInterface $repository,
    ) {}

    public function generate(string $name): string
    {
        $base = $this->slugify($name);

        // A name made entirely of non-ASCII or punctuation can slugify to empty; fall back so a valid,
        // if generic, slug is always produced.
        if ($base === '') {
            $base = 'knowledge-base';
        }

        if (!$this->repository->slugExists($base)) {
            return $base;
        }

        // Reserve room for the "-N" suffix within the column length.
        $suffix = 2;
        do {
            $candidate = $this->withSuffix($base, $suffix);
            $suffix++;
        } while ($this->repository->slugExists($candidate));

        return $candidate;
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        // Replace any run of non-alphanumeric characters with a single hyphen, then trim hyphens.
        $value = (string) preg_replace('/[^a-z0-9]+/', '-', $value);
        $value = trim($value, '-');

        return $this->truncate($value, self::MAX_LENGTH);
    }

    private function withSuffix(string $base, int $suffix): string
    {
        $suffixText = '-' . $suffix;
        $trimmed = $this->truncate($base, self::MAX_LENGTH - strlen($suffixText));

        return trim($trimmed, '-') . $suffixText;
    }

    private function truncate(string $value, int $length): string
    {
        return strlen($value) <= $length ? $value : substr($value, 0, $length);
    }
}
