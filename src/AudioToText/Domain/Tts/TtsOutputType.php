<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Tts;

use App\AudioToText\Domain\SourceRole;

/**
 * Which AI audio file is being produced from one recording.
 *
 * ## The availability matrix lives here and nowhere else
 *
 * {@see availableFor()} is the single authority on what may be generated from what. The page asks it
 * what to render, the queue asks it what to enqueue, and the worker asks it what it is allowed to build
 * — so a control can never appear for something the worker would refuse, and the worker can never
 * produce something the page has no way to show.
 *
 * Today it answers with exactly one type per job, which is also what keeps the same words from being
 * billed twice — a mixed file and a pair of per-role files contain the same speech:
 *
 * | upload                       | job's source role | output   |
 * |------------------------------|-------------------|----------|
 * | COMMON — one mixed recording | COMMON            | MIXED    |
 * | SEPARATE — two recordings    | CUSTOMER          | CUSTOMER |
 * |                              | AGENT             | AGENT    |
 *
 * ## Why MIXED is never offered for a separate pair
 *
 * Two files recorded independently carry no synchronisation this application can trust — no shared
 * clock, no common start — so interleaving them into one track would mean inventing an ordering. That is
 * the same refusal {@see \App\AudioToText\Web\Job\Conversion\Action} already makes on screen, and it is
 * a property of the recordings rather than a limit of this phase.
 *
 * ## Why CUSTOMER and AGENT are not offered for a mixed recording *yet*
 *
 * They could be: the turns are there and the roles are published. They are simply not what was asked
 * for, and a mixed file plus two per-role files bills the same words twice. The case is left in the enum
 * — and the unique key in the schema admits all three — so adding it later is this method and a template
 * branch, with no migration and no change to the worker, the storage or the cost rules.
 */
enum TtsOutputType: string
{
    /** Both speakers, in order, in one file. Only possible where a single recording holds both. */
    case Mixed = 'MIXED';

    case Customer = 'CUSTOMER';
    case Agent = 'AGENT';

    public function label(): string
    {
        return match ($this) {
            self::Mixed => 'Mixed AI audio',
            self::Customer => 'Customer AI audio',
            self::Agent => 'Agent AI audio',
        };
    }

    /** The word used inside a generated filename and a URL segment. */
    public function slug(): string
    {
        return strtolower($this->value);
    }

    /**
     * Whether this output speaks one role only.
     *
     * A single-role file needs one voice for its whole length; MIXED alternates. The distinction is worth
     * a method because it decides both which models are recorded against the rendition and whether any
     * inter-turn silence is inserted at all.
     */
    public function isSingleRole(): bool
    {
        return $this !== self::Mixed;
    }

    public static function fromStorage(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /** Decode the lowercase form used in URLs. Separate from {@see fromStorage()} so neither is lenient. */
    public static function fromSlug(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom(strtoupper($value));
    }

    /**
     * What may be generated from one recording.
     *
     * Keyed on the job's `source_role` alone, which is sufficient and is the only thing every caller
     * already has. The conversation's mode would be redundant: `TranscriptionQueue` derives the child
     * roles from the mode at enqueue, so `COMMON` appears on exactly the children of mixed uploads and
     * `CUSTOMER`/`AGENT` on exactly the children of separate ones. Taking the mode as well would mean two
     * inputs that cannot disagree, and a second place for a caller to get it wrong.
     *
     * A legacy job predating source roles reads `NULL`, which the conversations migration back-filled as
     * `COMMON` — those uploads were all single mixed recordings.
     *
     * The order matters where more than one is returned: it is the order the page renders them in.
     *
     * @return list<self>
     */
    public static function availableFor(?SourceRole $sourceRole): array
    {
        return match ($sourceRole) {
            SourceRole::Customer => [self::Customer],
            SourceRole::Agent => [self::Agent],
            SourceRole::Common, null => [self::Mixed],
        };
    }

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return [self::Mixed, self::Customer, self::Agent];
    }
}
