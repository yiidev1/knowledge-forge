<?php

declare(strict_types=1);

namespace App\Practice\Application;

use App\Practice\Domain\StoreOptionsInterface;
use App\Practice\Domain\Topic;
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
        private StoreOptionsInterface $stores,
    ) {}

    public function create(
        string $name,
        ?string $description,
        bool $active,
        ?int $adminId,
        ?int $storeSourceId = null,
    ): int {
        [$name, $description] = $this->validator->validate($name, $description);
        $storeSourceId = $this->resolveStore($storeSourceId);
        $this->refuseAmbiguousName($name, $storeSourceId, null);

        return $this->topics->create(
            $name,
            $description,
            $active ? TopicStatus::Active : TopicStatus::Inactive,
            $adminId,
            $storeSourceId,
        );
    }

    /**
     * The store a topic may be saved against, or null for global.
     *
     * Checked against the live directory on every write. A form only ever offers stores that are
     * there, but a request is not a form: without this, a hand-made POST could file a topic under a
     * store id that resolves to nothing, and the listing would then show a number nobody can place.
     */
    private function resolveStore(?int $storeSourceId): ?int
    {
        // 0 and negatives are what an empty select posts, and they mean global rather than "store 0".
        if ($storeSourceId === null || $storeSourceId <= 0) {
            return null;
        }

        if (!$this->stores->exists($storeSourceId)) {
            throw ValidationException::forField(
                'store_source_id',
                'That store is not one this application knows about. Choose one from the list, or '
                . 'leave it on Global.',
            );
        }

        return $storeSourceId;
    }

    /**
     * A store's topic may not take the name of a global one, or the other way round.
     *
     * The database already refuses two global topics with one name, and two of one store's. What it
     * cannot express is this: a store that has its own "Refunds" while a global "Refunds" also exists
     * would show the assignment dropdown two entries with the same word on them, and nobody could
     * tell which was which or which one an order ended up carrying.
     *
     * Enforced here rather than in the schema because the rule spans two scopes, and a unique index
     * can only speak about one tuple. Two administrators creating both halves at the same instant
     * could still slip through that window — the result is cosmetic rather than corrupting, and the
     * page is the place it would be noticed.
     */
    private function refuseAmbiguousName(string $name, ?int $storeSourceId, ?int $ignoreId): void
    {
        $folded = TopicInputValidator::fold($name);

        foreach ($this->topics->assignable() as $existing) {
            if ($existing->id === $ignoreId
                || TopicInputValidator::fold($existing->name) !== $folded) {
                continue;
            }

            // One of the two is global and the other is not: that is the ambiguous pair.
            if ($existing->isGlobal() !== ($storeSourceId === null)) {
                throw ValidationException::forField(
                    'name',
                    $existing->isGlobal()
                        ? 'A global topic is already called "' . $existing->name . '". Two topics '
                            . 'with one name would be offered side by side on that store, so give '
                            . 'this one a name of its own.'
                        : 'A topic in another store is already called "' . $existing->name . '". A '
                            . 'global topic of the same name would be offered beside it, so give '
                            . 'this one a name of its own.',
                );
            }
        }
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
    public function update(
        int $id,
        string $name,
        ?string $description,
        ?bool $active = null,
        ?int $storeSourceId = null,
        bool $changeStore = false,
    ): void {
        [$name, $description] = $this->validator->validate($name, $description);

        $topic = $this->existing($id);
        // `$changeStore` separates "the form said global" from "the form said nothing about the
        // store", exactly as `$active` separates a status from a silence. Without it an endpoint
        // that does not send the field would quietly make every topic it touched global.
        $scope = $changeStore ? $this->resolveStore($storeSourceId) : $topic->storeSourceId;

        if ($scope !== $topic->storeSourceId) {
            $this->refuseUnsafeScopeChange($topic, $scope);
        }

        $this->refuseAmbiguousName($name, $scope, $topic->id);
        $this->topics->update($topic->id, $name, $description, $scope);

        if ($active === null) {
            return;
        }

        $wanted = $active ? TopicStatus::Active : TopicStatus::Inactive;

        if ($topic->status !== $wanted) {
            $active ? $this->activate($topic->id) : $this->deactivate($topic->id);
        }
    }

    /**
     * Narrowing a topic to one store may not strand the orders that already carry it.
     *
     * Widening is always safe — every assignment a store-specific topic has is in that store, and
     * global covers it. Narrowing is what has to be checked: if store B's orders carry this topic and
     * it is about to belong to store A only, those orders would hold a topic their store may not use.
     *
     * Refused rather than fixed up. The alternative — detaching the offending assignments — would
     * change what somebody's training record says, and this page's own promise is that assignments
     * survive whatever happens to the topic.
     */
    private function refuseUnsafeScopeChange(Topic $topic, ?int $scope): void
    {
        if ($scope === null) {
            return;
        }

        $stranded = $this->topics->assignmentsOutsideStore($topic->id, $scope);

        if ($stranded > 0) {
            throw ValidationException::forField(
                'store_source_id',
                '"' . $topic->name . '" is assigned to ' . $stranded . ' order'
                . ($stranded === 1 ? '' : 's') . ' in another store. Restricting it to one store '
                . 'would leave those orders carrying a topic that store cannot use, so the change '
                . 'was not made. Unassign them first, or leave this topic global.',
            );
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
