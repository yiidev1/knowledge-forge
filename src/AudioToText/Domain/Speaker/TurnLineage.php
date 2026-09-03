<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function array_merge;
use function array_values;
use function usort;

/**
 * What has happened to one message of the conversation as it stands now.
 *
 * Built by walking the corrections forward: a turn no operation touched carries the lineage of whatever
 * stood in its place before, and a turn an operation produced carries that operation's event plus
 * everything the messages it replaced had already accumulated. So a message that was split out of
 * another, and then reworded, shows both.
 *
 * Immutable, like everything else in this layer — {@see with()} returns a new lineage rather than
 * growing one, so a turn that ends up in two places cannot have its history mutated through one of them.
 */
final readonly class TurnLineage
{
    /**
     * @param list<HistoryEvent> $events
     */
    public function __construct(public array $events = []) {}

    /**
     * This lineage plus one more event, and everything the replaced messages carried.
     *
     * Duplicates are possible and are removed by revision number: when a merge joins two turns that were
     * both produced by the same earlier split, that split's event arrives down both paths and is one
     * event, not two.
     *
     * @param list<self> $consumed the lineages of the messages this operation replaced
     */
    public function with(HistoryEvent $event, array $consumed = []): self
    {
        $events = [$event];
        foreach ($consumed as $lineage) {
            $events = array_merge($events, $lineage->events);
        }

        $unique = [];
        foreach ($events as $candidate) {
            $unique[$candidate->revisionNumber] = $candidate;
        }

        $ordered = array_values($unique);
        // Newest first: the question is almost always "what just happened to this message".
        usort($ordered, static fn(HistoryEvent $a, HistoryEvent $b): int => $b->revisionNumber <=> $a->revisionNumber);

        return new self($ordered);
    }

    public function isEmpty(): bool
    {
        return $this->events === [];
    }
}
