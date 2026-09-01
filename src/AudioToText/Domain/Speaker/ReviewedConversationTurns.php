<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\Exception\ReviewRejected;
use App\AudioToText\Domain\SpeakerRole;
use JsonException;

use function abs;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_array;
use function json_decode;
use function json_encode;
use function mb_strlen;
use function mb_strpos;
use function mb_substr;
use function trim;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * A reviewed conversation, and every rule about how it may be changed.
 *
 * Immutable: each operation returns a new instance, so an invalid change cannot leave a half-applied
 * conversation behind and the caller decides when to persist. Every operation validates first and
 * throws {@see ReviewRejected} rather than silently doing something approximate — this is the layer a
 * person's judgement is recorded in, and quietly reinterpreting their instruction would be worse than
 * refusing it.
 *
 * Order is never changed. No operation moves a turn to a different position, so the conversation stays
 * in the sequence it was spoken, and a reader comparing it to the recording is never misled.
 */
final readonly class ReviewedConversationTurns
{
    /**
     * @param list<ReviewedTurn> $turns
     */
    private function __construct(public array $turns) {}

    /**
     * Seed from the machine's own segments — what the first correction to a job starts from.
     *
     * @param list<SpeakerUtterance> $utterances
     */
    public static function fromUtterances(array $utterances): self
    {
        $turns = [];
        foreach ($utterances as $utterance) {
            $turns[] = ReviewedTurn::fromUtterance($utterance);
        }

        return new self($turns);
    }

    public static function fromJson(?string $json): self
    {
        if ($json === null || $json === '') {
            return new self([]);
        }

        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self([]);
        }

        if (!is_array($decoded)) {
            return new self([]);
        }

        $turns = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }

            $turn = ReviewedTurn::fromArray($row);
            if ($turn !== null) {
                $turns[] = $turn;
            }
        }

        return new self($turns);
    }

    public function toJson(): string
    {
        $rows = [];
        foreach ($this->turns as $turn) {
            $rows[] = $turn->toArray();
        }

        // Substituting invalid sequences rather than throwing, as the pipeline's own encoder does: an
        // encoder failure must not be able to lose a correction that is otherwise perfectly good.
        $encoded = json_encode(
            $rows,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? '[]' : $encoded;
    }

    public function count(): int
    {
        return count($this->turns);
    }

    public function isEmpty(): bool
    {
        return $this->turns === [];
    }

    /** Reassign a whole turn. Text and timestamps are untouched — only who said it changes. */
    public function moveTo(int $index, SpeakerRole $role): self
    {
        $turn = $this->turnAt($index);

        if ($role !== SpeakerRole::AGENT && $role !== SpeakerRole::CUSTOMER) {
            throw ReviewRejected::unsupportedRole();
        }

        if ($turn->role === $role) {
            // Nothing to record. Allowing it would write a revision describing no change.
            throw ReviewRejected::alreadyThatRole($role->label());
        }

        $turns = $this->turns;
        $turns[$index] = $turn->withRole($role);

        return new self(array_values($turns));
    }

    /**
     * Cut a turn in two at a character offset.
     *
     * Both halves must contain something once trimmed: splitting off pure whitespace produces an empty
     * turn, which is not a correction of anything.
     */
    public function splitAt(int $index, int $offset): self
    {
        $turn = $this->turnAt($index);

        if ($offset <= 0 || $offset >= mb_strlen($turn->text)) {
            throw ReviewRejected::splitOutsideText();
        }

        [$before, $after] = $turn->splitAt($offset);

        if (trim($before->text) === '' || trim($after->text) === '') {
            throw ReviewRejected::splitWouldEmptyATurn();
        }

        $turns = array_slice($this->turns, 0, $index);
        $turns[] = $before;
        $turns[] = $after;

        foreach (array_slice($this->turns, $index + 1) as $rest) {
            $turns[] = $rest;
        }

        return new self($turns);
    }

    /**
     * Join a turn with its neighbour.
     *
     * The neighbour must have the same speaker **and** the same role — the two must be indistinguishable
     * to a reader. Merging across a difference would silently reassign speech, which is the mistake this
     * whole feature exists to correct rather than commit.
     */
    public function mergeWithPrevious(int $index): self
    {
        return $this->merge($index, MergeDirection::Previous);
    }

    public function mergeWithNext(int $index): self
    {
        return $this->merge($index, MergeDirection::Next);
    }

    /**
     * Whether this turn may be merged in that direction, and if not, why not.
     *
     * The rule is unchanged — this is the rule, and {@see merge()} enforces it by calling here. Exposing
     * it lets the page disable a control *and say why*, without a second copy of the condition that
     * could later disagree with the one the service applies.
     */
    public function mergeAvailability(int $index, MergeDirection $direction): MergeRefusal
    {
        $neighbourIndex = $direction === MergeDirection::Previous ? $index - 1 : $index + 1;

        $turn = $this->turns[$index] ?? null;
        $neighbour = $this->turns[$neighbourIndex] ?? null;

        if ($turn === null || $neighbour === null) {
            return MergeRefusal::NoNeighbour;
        }

        // Role first: it is the difference an administrator can see on screen, so naming it is more
        // useful than naming the voice difference that may also be present.
        if ($turn->role !== $neighbour->role) {
            return MergeRefusal::DifferentRole;
        }

        if ($turn->speaker !== $neighbour->speaker) {
            return MergeRefusal::DifferentSpeaker;
        }

        return MergeRefusal::None;
    }

    /**
     * Reassign a selection — a whole turn, or some words inside one — to the other speaker.
     *
     * This is the operation an administrator actually performs: *these words belong to the other
     * person*. It is composed here from the primitives rather than added as a new concept, because a
     * genuine "move text between turns" would break three things at once. Token timings are never
     * persisted, so a fragment has no knowable time of its own; the conversation's order is fixed, so
     * words cannot be relocated to a moment they were not spoken; and a turn emptied of all its text
     * would have to be deleted, which nothing here can do.
     *
     * Splitting instead of relocating avoids all three. The fragment stays exactly where it was in the
     * conversation, inherits the parent's span already marked approximate, and only its *role* changes.
     *
     * A merge follows where the rule permits, so a fragment that lands beside a turn of the same voice
     * and role becomes one bubble rather than two indistinguishable ones. That is uncommon after a
     * partial move: the fragment keeps its parent's diarization speaker, so it will not merge into a
     * neighbour the diarizer heard as a different voice — which is the safeguard working, not a gap.
     *
     * @param string   $selection the administrator's selection, matched against this turn's text
     * @param int|null $hint      roughly where the selection started, used only to pick between
     *                            repeats of the same words; the browser counts UTF-16 units and this
     *                            counts codepoints, so it is a hint and never an offset
     *
     * @throws ReviewRejected when the selection is empty, is not in that turn, or changes nothing
     */
    public function moveTextTo(int $index, string $selection, SpeakerRole $role, ?int $hint = null): self
    {
        if ($role !== SpeakerRole::AGENT && $role !== SpeakerRole::CUSTOMER) {
            throw ReviewRejected::unsupportedRole();
        }

        $turn = $this->turnAt($index);
        $wanted = trim($selection);

        if ($wanted === '') {
            throw ReviewRejected::emptySelection();
        }

        // The whole turn: no split needed, and the fast path for the common correction.
        if ($wanted === trim($turn->text)) {
            return $this->moveTo($index, $role)->mergeAround($index);
        }

        $start = self::locate($turn->text, $wanted, $hint);

        if ($start === null) {
            throw ReviewRejected::selectionNotFound();
        }

        $end = $start + mb_strlen($wanted);
        // Compared after trimming, because a split discards the whitespace at its edges: a selection
        // with only spaces before it begins the turn as far as the result is concerned.
        $prefix = trim(mb_substr($turn->text, 0, $start));
        $suffix = trim(mb_substr($turn->text, $end));

        if ($prefix === '' && $suffix === '') {
            return $this->moveTo($index, $role)->mergeAround($index);
        }

        $working = $this;
        $fragment = $index;

        if ($prefix !== '') {
            $working = $working->splitAt($index, $start);
            $fragment = $index + 1;
        }

        if ($suffix !== '') {
            // After a leading split the fragment starts that turn, so its end is its own length.
            // Without one the turn is untouched and the original offset still applies.
            $working = $working->splitAt($fragment, $prefix !== '' ? mb_strlen($wanted) : $end);
        }

        return $working->moveTo($fragment, $role)->mergeAround($fragment);
    }

    /** Correct wording. Lives only here; the machine's `transcript` is never rewritten. */
    public function editText(int $index, string $text): self
    {
        $turn = $this->turnAt($index);
        $trimmed = trim($text);

        if ($trimmed === '') {
            throw ReviewRejected::emptyText();
        }

        if ($trimmed === trim($turn->text)) {
            throw ReviewRejected::textUnchanged();
        }

        $turns = $this->turns;
        $turns[$index] = $turn->withText($trimmed);

        return new self(array_values($turns));
    }

    /**
     * One role's text, assembled exactly as the pipeline assembles its own.
     *
     * Mirrors `SpeakerSeparationService::textFor()` deliberately: a reviewed job's two columns must be
     * built the same way the machine's were, or the same conversation would read differently depending
     * on who last touched it.
     */
    public function textFor(SpeakerRole $role): string
    {
        $lines = [];
        foreach ($this->turns as $turn) {
            if ($turn->role === $role && trim($turn->text) !== '') {
                $lines[] = trim($turn->text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Whether both roles carry something, which is the precondition for confirming them.
     *
     * Confirmation asserts that this conversation *is* an agent talking to a customer. A thread where
     * every turn sits on one side has not been reviewed into that shape yet, and publishing it would
     * put an empty block on screen under a heading that claims otherwise.
     */
    public function hasBothRoles(): bool
    {
        return $this->textFor(SpeakerRole::AGENT) !== '' && $this->textFor(SpeakerRole::CUSTOMER) !== '';
    }

    /**
     * Join a just-moved turn to whichever neighbour the rule allows.
     *
     * Next before previous: merging downward leaves this turn's index alone, whereas merging upward
     * consumes it. Doing them the other way round would apply the second merge to the wrong turn.
     */
    private function mergeAround(int $index): self
    {
        $working = $this;

        if ($working->mergeAvailability($index, MergeDirection::Next)->isAllowed()) {
            $working = $working->mergeWithNext($index);
        }

        if ($working->mergeAvailability($index, MergeDirection::Previous)->isAllowed()) {
            $working = $working->mergeWithPrevious($index);
        }

        return $working;
    }

    /**
     * Where in `$text` the selection sits, choosing the repeat nearest the browser's hint.
     *
     * "Yes." can appear twice in one turn, and moving the wrong one would be a silent corruption of
     * somebody's words. The hint disambiguates; without one the first occurrence is used.
     */
    private static function locate(string $text, string $needle, ?int $hint): ?int
    {
        $found = [];
        $from = 0;

        while (($at = mb_strpos($text, $needle, $from)) !== false) {
            $found[] = $at;
            $from = $at + 1;
        }

        if ($found === []) {
            return null;
        }

        if ($hint === null) {
            return $found[0];
        }

        $best = $found[0];
        foreach ($found as $offset) {
            if (abs($offset - $hint) < abs($best - $hint)) {
                $best = $offset;
            }
        }

        return $best;
    }

    private function merge(int $index, MergeDirection $direction): self
    {
        // An index that names no turn is a different mistake from a turn with no neighbour: the first
        // means the page is stale, the second means the edge of the conversation. Each says so.
        $this->turnAt($index);

        $availability = $this->mergeAvailability($index, $direction);

        if (!$availability->isAllowed()) {
            // The refusal carries its own wording, so the sentence on a disabled control and the
            // sentence in the flash after a stale page posts anyway are the same sentence.
            throw ReviewRejected::mergeRefused($availability);
        }

        $first = $direction === MergeDirection::Previous ? $index - 1 : $index;
        $second = $first + 1;

        $turns = array_slice($this->turns, 0, $first);
        $turns[] = $this->turns[$first]->mergedWith($this->turns[$second]);

        foreach (array_slice($this->turns, $second + 1) as $rest) {
            $turns[] = $rest;
        }

        return new self($turns);
    }

    private function turnAt(int $index): ReviewedTurn
    {
        return $this->turns[$index] ?? throw ReviewRejected::noSuchTurn($index);
    }
}
