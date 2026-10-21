<?php

declare(strict_types=1);

namespace App\Practice\Domain;

/**
 * The topic master list.
 *
 * `nameKey` never appears in this interface: it is the folded form the database uniqueness rule is
 * built on, and deriving it is the repository's business, not a caller's. A caller that could pass its
 * own folded name could also pass a mismatched one.
 */
interface TopicRepositoryInterface
{
    /**
     * One page of topics, newest first, with each one's assignment count.
     *
     * @param string           $search trimmed; matches the name, case-insensitively
     * @param TopicStatus|null $status null means every status except archived
     *
     * @return array{items: list<Topic>, total: int}
     */
    public function page(string $search, ?TopicStatus $status, int $page, int $perPage): array;

    /**
     * Every topic that may be put on an order right now, by name.
     *
     * A store id narrows it to that store's topics and the global ones; null asks for all of them,
     * which is what a caller with no store in hand wants.
     *
     * @return list<Topic>
     */
    public function assignable(?int $storeSourceId = null): array;

    public function find(int $id): ?Topic;

    /** @throws \App\Shared\Domain\Exception\ValidationException on a duplicate live name */
    public function create(
        string $name,
        ?string $description,
        TopicStatus $status,
        ?int $createdByAdminId,
        ?int $storeSourceId = null,
    ): int;

    /** @throws \App\Shared\Domain\Exception\ValidationException on a duplicate live name */
    public function update(
        int $id,
        string $name,
        ?string $description,
        ?int $storeSourceId = null,
    ): void;

    public function changeStatus(int $id, TopicStatus $status): void;

    /** How many source orders carry this topic. Asked before archiving, to say so out loud. */
    /** Assignments this topic has in any store but the one given. */
    public function assignmentsOutsideStore(int $id, int $storeSourceId): int;

    public function assignmentCount(int $id): int;
}
