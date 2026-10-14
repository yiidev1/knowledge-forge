<?php

declare(strict_types=1);

namespace App\Practice\Application;

use App\Practice\Domain\TopicRepositoryInterface;
use App\Practice\Domain\TopicStatus;
use App\Shared\Domain\Exception\NotFoundException;
use App\Shared\Domain\Exception\ValidationException;

/**
 * Creating, renaming and retiring topics.
 *
 * Administrators only — enforced by the route group the actions sit in, which is what "admin-only"
 * means everywhere in this application.
 */
final readonly class TopicService
{
    public function __construct(
        private TopicRepositoryInterface $topics,
        private TopicInputValidator $validator,
    ) {}

    public function create(string $name, ?string $description, bool $active, ?int $adminId): int
    {
        [$name, $description] = $this->validator->validate($name, $description);

        return $this->topics->create(
            $name,
            $description,
            $active ? TopicStatus::Active : TopicStatus::Inactive,
            $adminId,
        );
    }

    /**
     * Rename, re-describe, and optionally set whether the topic is offered.
     *
     * Assignments point at the id, so a rename is invisible to every order that carries the topic —
     * which is the whole reason the name is not copied onto them.
     *
     * `$active` is nullable because "leave the status alone" has to be expressible: the edit form sends
     * a status, the create path does not, and a null must never be read as "deactivate". Deleting still
     * has its own endpoint — it is not reachable from this form, so a rename can never retire a topic
     * by accident.
     */
    public function update(int $id, string $name, ?string $description, ?bool $active = null): void
    {
        [$name, $description] = $this->validator->validate($name, $description);

        $topic = $this->existing($id);

        $this->topics->update($topic->id, $name, $description);

        if ($active === null) {
            return;
        }

        $wanted = $active ? TopicStatus::Active : TopicStatus::Inactive;

        if ($topic->status !== $wanted) {
            $active ? $this->activate($topic->id) : $this->deactivate($topic->id);
        }
    }

    public function activate(int $id): void
    {
        $topic = $this->existing($id);

        if ($topic->isArchived()) {
            throw ValidationException::forField(
                'status',
                'This topic has been deleted. A deleted topic cannot be made active again.',
            );
        }

        $this->topics->changeStatus($topic->id, TopicStatus::Active);
    }

    public function deactivate(int $id): void
    {
        $topic = $this->existing($id);

        if ($topic->isArchived()) {
            throw ValidationException::forField('status', 'This topic has already been deleted.');
        }

        $this->topics->changeStatus($topic->id, TopicStatus::Inactive);
    }

    /**
     * Delete, as this application means it: the topic goes from every list, the training record stays.
     *
     * The row is kept and its assignments are kept with it, so an order practised under this topic
     * still says what it was practised under — that history exists nowhere else, and a cascade would
     * destroy it. What changes is that the topic leaves every picker and frees its name for reuse.
     *
     * Stored as `ARCHIVED` because that is the value the table's CHECK constraint already allows; the
     * word is an implementation detail of the storage, and no screen says it.
     *
     * @return int how many orders kept the topic, so the confirmation can say so
     */
    public function delete(int $id): int
    {
        $topic = $this->existing($id);
        $assigned = $this->topics->assignmentCount($topic->id);

        $this->topics->changeStatus($topic->id, TopicStatus::Archived);

        return $assigned;
    }

    private function existing(int $id): \App\Practice\Domain\Topic
    {
        $topic = $this->topics->find($id);

        if ($topic === null) {
            throw new NotFoundException('topic_not_found', 'No topic has that id.');
        }

        return $topic;
    }
}
