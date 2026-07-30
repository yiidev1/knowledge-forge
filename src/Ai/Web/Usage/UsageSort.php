<?php

declare(strict_types=1);

namespace App\Ai\Web\Usage;

use App\Ai\Application\Usage\UsageStoreRow;
use Yiisoft\Router\UrlGeneratorInterface;

use function array_reverse;
use function in_array;
use function is_string;
use function strcasecmp;
use function usort;

/**
 * Server-side ordering for the vector-store table.
 *
 * Sorting is done here rather than in JavaScript for two reasons: the Content-Security-Policy forbids
 * inline script, and a table that sorts via ordinary links keeps working with JavaScript disabled and
 * stays linkable — an operator can paste "sorted by storage, descending" to someone else.
 *
 * Both query values are validated against fixed allowlists and an unrecognised value silently becomes
 * the default. Nothing from the query string is ever echoed into the page, so a crafted `?sort=` cannot
 * reach the HTML at all.
 */
final readonly class UsageSort
{
    public const DEFAULT_FIELD = 'name';

    private const FIELDS = ['name', 'status', 'storage', 'files', 'created', 'last_active', 'expires'];

    private function __construct(
        public string $field,
        public bool $descending,
    ) {}

    public static function fromQuery(mixed $field, mixed $direction): self
    {
        $field = is_string($field) && in_array($field, self::FIELDS, true) ? $field : self::DEFAULT_FIELD;

        return new self($field, $direction === 'desc');
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    /**
     * The direction a header link should request: the opposite of the current one for the active
     * column, ascending for every other column.
     */
    public function nextDirectionFor(string $field): string
    {
        return $this->field === $field && !$this->descending ? 'desc' : 'asc';
    }

    /**
     * The URL a column header links to: the same page, re-sorted.
     *
     * Ordinary links rather than script, so sorting survives with JavaScript disabled, stays inside the
     * Content-Security-Policy, and produces a URL an operator can share.
     */
    public function linkFor(UrlGeneratorInterface $urlGenerator, string $field): string
    {
        return $urlGenerator->generate(
            'ai.usage.index',
            [],
            ['sort' => $field, 'dir' => $this->nextDirectionFor($field)],
        );
    }

    /**
     * The arrow appended to the active column's label. Empty for every other column.
     */
    public function markerFor(string $field): string
    {
        if ($this->field !== $field) {
            return '';
        }

        return $this->descending ? ' ▾' : ' ▴';
    }

    /**
     * The value for `aria-sort`, so screen readers announce the ordering rather than leaving it as a
     * purely visual cue.
     */
    public function ariaFor(string $field): string
    {
        if ($this->field !== $field) {
            return 'none';
        }

        return $this->descending ? 'descending' : 'ascending';
    }

    /**
     * @param list<UsageStoreRow> $rows
     *
     * @return list<UsageStoreRow>
     */
    public function apply(array $rows): array
    {
        $field = $this->field;

        usort($rows, static function (UsageStoreRow $a, UsageStoreRow $b) use ($field): int {
            return match ($field) {
                'status' => strcasecmp($a->status, $b->status),
                'storage' => $a->usageBytes <=> $b->usageBytes,
                'files' => $a->fileCounts->total <=> $b->fileCounts->total,
                'created' => ($a->createdAt ?? 0) <=> ($b->createdAt ?? 0),
                'last_active' => ($a->lastActiveAt ?? 0) <=> ($b->lastActiveAt ?? 0),
                // A store with no expiry sorts after every store that has one: "never" is the far end
                // of the scale, not the near end that 0 would put it at.
                'expires' => ($a->expiresAt ?? PHP_INT_MAX) <=> ($b->expiresAt ?? PHP_INT_MAX),
                default => strcasecmp($a->name, $b->name),
            };
        });

        return $this->descending ? array_reverse($rows) : $rows;
    }
}
