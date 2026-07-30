<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\Document;

use App\Document\Domain\ProcessingEvent;
use App\Document\Domain\ProcessingEventRepositoryInterface;
use DateTimeImmutable;
use DateTimeZone;

/**
 * In-memory processing-event log for unit tests, recording what was appended.
 */
final class InMemoryProcessingEventRepository implements ProcessingEventRepositoryInterface
{
    /** @var list<ProcessingEvent> */
    public array $events = [];

    private int $nextId = 1;

    public function record(int $documentId, string $status, ?string $message = null, array $metadata = []): void
    {
        $this->events[] = new ProcessingEvent(
            id: $this->nextId++,
            documentId: $documentId,
            status: $status,
            message: $message,
            metadata: $metadata,
            createdAt: new DateTimeImmutable('2026-01-01 00:00:00', new DateTimeZone('UTC')),
        );
    }

    public function findAllForDocument(int $documentId): array
    {
        $result = [];
        foreach ($this->events as $event) {
            if ($event->documentId === $documentId) {
                $result[] = $event;
            }
        }

        return $result;
    }

    /**
     * @return list<string> The recorded statuses, in order — convenient for assertions.
     */
    public function statuses(): array
    {
        return array_map(static fn(ProcessingEvent $e): string => $e->status, $this->events);
    }
}
