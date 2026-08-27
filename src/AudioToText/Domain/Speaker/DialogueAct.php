<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use App\AudioToText\Domain\SpeakerRole;

/**
 * What an utterance *does* in an order call, as opposed to which words it contains.
 *
 * The distinction is the whole point. "Cash" is not evidence of anything — the agent says it when
 * offering the choice and the customer says it when making it. What separates them is the position in
 * the exchange: one asks, the other answers. So this enum names moves in a conversation, and
 * {@see self::answeredBy()} records which move answers which.
 *
 * Every act is deliberately domain-specific to restaurant/order calls. That is not a limitation being
 * apologised for: a general-purpose dialogue-act taxonomy would classify "cash or card?" as a plain
 * question and lose exactly the information that identifies the speaker.
 */
enum DialogueAct: string
{
    // ---- moves the person taking the order makes -------------------------------------------------
    /** "Thank you for calling…", "How may I help you?" */
    case GREET_BUSINESS = 'GREET_BUSINESS';
    case ASK_ORDER = 'ASK_ORDER';
    case ASK_DELIVERY_METHOD = 'ASK_DELIVERY_METHOD';
    case ASK_ADDRESS = 'ASK_ADDRESS';
    case ASK_PAYMENT = 'ASK_PAYMENT';
    case ASK_QUANTITY = 'ASK_QUANTITY';
    case ASK_ANYTHING_ELSE = 'ASK_ANYTHING_ELSE';
    /** Reading the order back: "let me recap", "so that's two orders of…, correct?" */
    case CONFIRM_ORDER = 'CONFIRM_ORDER';
    case QUOTE_PRICE = 'QUOTE_PRICE';
    case QUOTE_DELIVERY_TIME = 'QUOTE_DELIVERY_TIME';

    // ---- moves the caller makes ------------------------------------------------------------------
    /** The caller opening with their own intent: "I'd like to place an order." */
    case REQUEST_ORDER = 'REQUEST_ORDER';
    case PROVIDE_ORDER_ITEM = 'PROVIDE_ORDER_ITEM';
    case SELECT_DELIVERY_METHOD = 'SELECT_DELIVERY_METHOD';
    case PROVIDE_ADDRESS = 'PROVIDE_ADDRESS';
    case SELECT_PAYMENT = 'SELECT_PAYMENT';
    case PROVIDE_QUANTITY = 'PROVIDE_QUANTITY';
    case DECLINE_MORE = 'DECLINE_MORE';

    /** Which role makes this move. Never used on its own — see the weights for why. */
    public function expectedRole(): SpeakerRole
    {
        return match ($this) {
            self::GREET_BUSINESS,
            self::ASK_ORDER,
            self::ASK_DELIVERY_METHOD,
            self::ASK_ADDRESS,
            self::ASK_PAYMENT,
            self::ASK_QUANTITY,
            self::ASK_ANYTHING_ELSE,
            self::CONFIRM_ORDER,
            self::QUOTE_PRICE,
            self::QUOTE_DELIVERY_TIME => SpeakerRole::AGENT,
            default => SpeakerRole::CUSTOMER,
        };
    }

    /**
     * The move that answers this one, for the acts that expect an answer.
     *
     * A question and its answer coming from two *different* speakers is the strongest evidence available
     * here, because it cannot be produced by one person echoing the other. Everything else in this file
     * is supporting detail.
     */
    public function answeredBy(): ?self
    {
        return match ($this) {
            self::ASK_ORDER => self::PROVIDE_ORDER_ITEM,
            self::ASK_DELIVERY_METHOD => self::SELECT_DELIVERY_METHOD,
            self::ASK_ADDRESS => self::PROVIDE_ADDRESS,
            self::ASK_PAYMENT => self::SELECT_PAYMENT,
            self::ASK_QUANTITY => self::PROVIDE_QUANTITY,
            self::ASK_ANYTHING_ELSE => self::DECLINE_MORE,
            default => null,
        };
    }

    public function isQuestion(): bool
    {
        return $this->answeredBy() !== null;
    }
}
