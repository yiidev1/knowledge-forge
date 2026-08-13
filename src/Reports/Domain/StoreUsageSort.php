<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function in_array;
use function is_string;

/**
 * Ordering for the Store Usage table, as a closed allow-list — the same shape as {@see AgentUsageSort}.
 *
 * The request never contributes SQL text: an unrecognised field silently becomes the default, and the
 * accepted names map to fixed expressions here.
 */
final readonly class StoreUsageSort
{
    public const DEFAULT_FIELD = 'questions';

    private const FIELDS = ['store', 'questions', 'agents', 'avg_rating', 'low_ratings', 'fallback', 'last_activity'];

    public function __construct(
        public string $field = self::DEFAULT_FIELD,
        public bool $descending = true,
    ) {}

    public static function fromRequest(mixed $field, mixed $direction): self
    {
        $accepted = is_string($field) && in_array($field, self::FIELDS, true) ? $field : self::DEFAULT_FIELD;
        $descending = $direction === 'desc' || ($direction !== 'asc' && $accepted !== 'store');

        return new self($accepted, $descending);
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    public function nextDirectionFor(string $field): string
    {
        return $this->field === $field && !$this->descending ? 'desc' : 'asc';
    }

    public function markerFor(string $field): string
    {
        if ($this->field !== $field) {
            return '';
        }

        return $this->descending ? ' ▾' : ' ▴';
    }

    public function ariaFor(string $field): string
    {
        if ($this->field !== $field) {
            return 'none';
        }

        return $this->descending ? 'descending' : 'ascending';
    }

    /** Stores with no rating sort last either way, rather than reading as a zero. */
    public function orderBy(): string
    {
        $direction = $this->descending ? 'DESC' : 'ASC';

        $expression = match ($this->field) {
            'store' => '[[store_name]]',
            'agents' => '[[agents]]',
            'avg_rating' => '[[avg_rating]] IS NULL, [[avg_rating]]',
            'low_ratings' => '[[low_ratings]]',
            'fallback' => '[[fallback]]',
            'last_activity' => '[[last_activity]]',
            default => '[[questions]]',
        };

        return $expression . ' ' . $direction . ', [[knowledge_base_id]] ASC';
    }
}
