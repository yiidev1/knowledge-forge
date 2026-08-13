<?php

declare(strict_types=1);

namespace App\Reports\Domain;

use function in_array;
use function is_string;

/**
 * Ordering for the Agent Usage table, as a closed allow-list.
 *
 * The field never reaches SQL as text from the request: an unrecognised value silently becomes the default,
 * and {@see self::orderBy()} maps the accepted names onto fixed expressions. Sorting is done with ordinary
 * links rather than JavaScript, so it works with scripting disabled and a sorted view stays linkable — the
 * same reasoning as the OpenAI usage table.
 */
final readonly class AgentUsageSort
{
    public const DEFAULT_FIELD = 'questions';

    private const FIELDS = ['agent', 'questions', 'avg_rating', 'low_ratings', 'chat_time', 'last_activity'];

    public function __construct(
        public string $field = self::DEFAULT_FIELD,
        public bool $descending = true,
    ) {}

    public static function fromRequest(mixed $field, mixed $direction): self
    {
        $accepted = is_string($field) && in_array($field, self::FIELDS, true) ? $field : self::DEFAULT_FIELD;

        // Names default to A–Z; every metric defaults to "most first", which is what a reader wants to see.
        $descending = $direction === 'desc' || ($direction !== 'asc' && $accepted !== 'agent');

        return new self($accepted, $descending);
    }

    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }

    /** The direction a header link should request: flip the active column, ascending for any other. */
    public function nextDirectionFor(string $field): string
    {
        return $this->field === $field && !$this->descending ? 'desc' : 'asc';
    }

    /** The arrow appended to the active column's label; empty for every other column. */
    public function markerFor(string $field): string
    {
        if ($this->field !== $field) {
            return '';
        }

        return $this->descending ? ' ▾' : ' ▴';
    }

    /** The `aria-sort` value, so the ordering is announced rather than only drawn. */
    public function ariaFor(string $field): string
    {
        if ($this->field !== $field) {
            return 'none';
        }

        return $this->descending ? 'descending' : 'ascending';
    }

    /**
     * The ORDER BY fragment. Only ever built from {@see self::FIELDS}, so no request text is interpolated.
     * Agents with no rating sort last in either direction rather than pretending to score zero.
     */
    public function orderBy(): string
    {
        $direction = $this->descending ? 'DESC' : 'ASC';

        $expression = match ($this->field) {
            'agent' => '[[agent_name]]',
            'avg_rating' => '[[avg_rating]] IS NULL, [[avg_rating]]',
            'low_ratings' => '[[low_ratings]]',
            'chat_time' => '[[chat_seconds]]',
            'last_activity' => '[[last_activity]]',
            default => '[[questions]]',
        };

        return $expression . ' ' . $direction . ', [[agent_admin_id]] ASC';
    }
}
