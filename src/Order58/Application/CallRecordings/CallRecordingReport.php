<?php

declare(strict_types=1);

namespace App\Order58\Application\CallRecordings;

use function implode;

/**
 * What one pass of {@see CallRecordingCatalog} did, in counts only — never a URL.
 */
final class CallRecordingReport
{
    public int $ordersSeen = 0;

    /** Orders whose stored links already matched the listing: no statement was run for them. */
    public int $ordersUnchanged = 0;

    public int $ordersChanged = 0;

    /** Orders listing links whose order row is not stored, so there was nothing to attach them to. */
    public int $ordersMissing = 0;

    public int $ordersFailed = 0;

    public int $sessionsAdded = 0;

    public int $recordingsAdded = 0;

    /** Links marked replaced because a later listing of their session named others instead. */
    public int $recordingsReplaced = 0;

    /** Previously replaced links the provider listed again. */
    public int $recordingsRelisted = 0;

    /** Links that were not recording URLs, or belonged to a session with no usable id. */
    public int $linksRejected = 0;

    /** One sentence for a run summary. Empty when there was nothing worth saying. */
    public function describe(): string
    {
        if ($this->recordingsAdded === 0 && $this->recordingsReplaced === 0
            && $this->ordersFailed === 0 && $this->linksRejected === 0) {
            return '';
        }

        $parts = [$this->recordingsAdded . ' new call recording link(s)'];

        if ($this->recordingsReplaced > 0) {
            $parts[] = $this->recordingsReplaced . ' replaced';
        }

        if ($this->linksRejected > 0) {
            $parts[] = $this->linksRejected . ' link(s) ignored';
        }

        if ($this->ordersFailed > 0) {
            $parts[] = $this->ordersFailed . ' order(s) not saved';
        }

        return implode(', ', $parts);
    }
}
