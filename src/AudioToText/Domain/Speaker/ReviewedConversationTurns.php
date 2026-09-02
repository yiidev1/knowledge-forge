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
     * Join a turn with the one beside it, because an administrator said they are one turn.
     *
     * Adjacency is the only requirement. The speaker and role of the neighbour are not consulted: this
     * is the manual correction path, and the diarizer's view of who was talking is exactly what the
     * person is here to overrule. The joined turn keeps the first one's speaker and role.
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
     * Whether an administrator may join this turn to its neighbour.
     *
     * Adjacency is the whole rule. A person pressing "merge with previous" has looked at both turns
     * and decided they are one; the diarizer's opinion about voices, and the role mapping derived
     * from it, are the very things they are correcting. Refusing them here would be the machine
     * overruling the reviewer in a screen that exists for the reviewer to overrule the machine.
     *
     * Distinct from {@see mergeAvailability()}, which stays strict because it governs the join that
     * happens *automatically* after a move — nobody asked for that one, so it only fires where the
     * two turns are indistinguishable anyway.
     */
    public function manualMergeAvailability(int $index, MergeDirection $direction): MergeRefusal
    {
        $neighbourIndex = $direction === MergeDirection::Previous ? $index - 1 : $index + 1;

        return isset($this->turns[$index], $this->turns[$neighbourIndex])
            ? MergeRefusal::None
            : MergeRefusal::NoNeighbour;
    }

    /**
     * Whether two turns are alike enough to be joined *without anyone asking*.
     *
     * Used only by the automatic join after a move. Left strict on purpose: an automatic merge across
     * two voices would silently reassign speech nobody looked at.
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

    /**
     * Move the words an administrator highlighted into the turn beside this one.
     *
     * The range is authoritative, never the text. Selecting the second "yes" in "yes no yes" has to
     * move *that* one, and searching for the substring would find the first — so the offsets decide,
     * and `$selected` is only a checksum proving the page and the stored turn still agree.
     *
     * Offsets count codepoints, matching `mb_substr`. The browser counts UTF-16 units, so it converts
     * before sending; the two disagree the moment a turn contains an emoji.
     *
     * Selecting the whole turn is the existing whole-turn merge, because nothing would be left behind.
     * A partial selection leaves the source in place with the rest of its words.
     *
     * Both turns are read and written in their displayed form — whisper's `>>` markers stripped — since
     * that is the text the administrator measured their selection against. Leaving the markers in one
     * turn and not the other would store two conventions in one conversation.
     *
     * @param int    $start    first codepoint of the selection, inclusive
     * @param int    $end      one past the last codepoint
     * @param string $selected what the page believed lies in that range
     *
     * @throws ReviewRejected when there is no neighbour, or the range is not a range, or the page and
     *                        the stored turn no longer agree about what it contains
     */
    public function mergeSelection(
        int $index,
        MergeDirection $direction,
        int $start,
        int $end,
        string $selected,
    ): self {
        $availability = $this->manualMergeAvailability($index, $direction);

        if (!$availability->isAllowed()) {
            throw ReviewRejected::mergeRefused($availability);
        }

        $source = $this->turnAt($index);
        $text = SpeakerMarkers::strip($source->text);
        $length = mb_strlen($text);

        if ($start < 0 || $end > $length || $start >= $end) {
            throw ReviewRejected::selectionOutOfRange();
        }

        $inRange = mb_substr($text, $start, $end - $start);

        // The checksum. If the page was rendered before somebody else corrected this turn, the words
        // at those offsets are not the words the administrator highlighted.
        if (trim($inRange) !== trim($selected)) {
            throw ReviewRejected::selectionNotFound();
        }

        if (trim($inRange) === '') {
            throw ReviewRejected::emptySelection();
        }

        $remaining = self::join(mb_substr($text, 0, $start), mb_substr($text, $end));

        // Nothing left behind: this is the whole-turn merge, and the source turn goes with it.
        if ($remaining === '') {
            return $this->merge($index, $direction);
        }

        $targetIndex = $direction === MergeDirection::Previous ? $index - 1 : $index + 1;
        $target = $this->turns[$targetIndex];
        $targetText = SpeakerMarkers::strip($target->text);

        $turns = $this->turns;
        $turns[$index] = $source->withMovedText($remaining);
        $turns[$targetIndex] = $target->withMovedText(
            $direction === MergeDirection::Previous
                ? self::join($targetText, trim($inRange))
                : self::join(trim($inRange), $targetText),
        );

        return new self(array_values($turns));
    }

    /**
     * Join two fragments across the seam a move left, and only there.
     *
     * One space between them, never two, and never none — "shrimp" and "fried" must not become
     * "shrimpfried". Whitespace elsewhere in either fragment is left exactly as it was.
     */
    private static function join(string $left, string $right): string
    {
        $left = trim($left);
        $right = trim($right);

        if ($left === '') {
            return $right;
        }

        return $right === '' ? $left : $left . ' ' . $right;
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

        // Manual: adjacency only. The strict predicate governs the automatic join, not this one.
        $availability = $this->manualMergeAvailability($index, $direction);

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
