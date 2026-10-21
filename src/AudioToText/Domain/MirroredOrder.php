<?php

declare(strict_types=1);

namespace App\AudioToText\Domain;

/**
 * What the mirrored Order58 record says about one order on a store page.
 *
 * Its **existence** is the useful half: an order id typed at upload is free text, and only some of
 * those ids turn out to name a real order under this store. A row here means one does, which is what
 * lets the page offer a receipt link for it and show a dash for the rest.
 *
 * `seq` is the provider's own order number — a short printed string such as `31#1`, not a count and not
 * something this application derives. It is nullable because the column is: a mirrored order may exist
 * without one, and that is a different fact from having no order at all.
 */
final readonly class MirroredOrder
{
    public function __construct(public ?string $seq) {}

    /** What the Seq cell shows. An em dash, the same one the rest of this table uses for "nothing". */
    public function seqLabel(): string
    {
        return $this->seq ?? '—';
    }
}
