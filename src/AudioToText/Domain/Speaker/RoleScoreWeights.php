<?php

declare(strict_types=1);

namespace App\AudioToText\Domain\Speaker;

use function max;
use function min;

/**
 * Every tunable number in role mapping, in one file.
 *
 * These are algorithm coefficients, not operator settings, so they live in code rather than `.env` —
 * changing one is a change to how the classifier reasons and belongs with the tests that pin it. The
 * only operator-level dial remains `AUDIO_DIARIZATION_MIN_CONFIDENCE`, which decides how much certainty
 * is required to publish, not how certainty is arrived at.
 *
 * The ordering below is the design in miniature: **a question answered by the other speaker is worth
 * several times an isolated phrase.** That ratio is what stops the classifier being fooled by an agent
 * who repeats the customer's address back to confirm it — the repeat is real speech containing real
 * address words, and under a keyword-counting scheme it scored as customer evidence against the agent.
 */
final class RoleScoreWeights
{
    /**
     * A question and its matching answer, from two different speakers.
     *
     * Address, payment, delivery method and order are equally decisive: each one is a transaction step
     * only the order-taker initiates. Quantity is lower because "how many?" / "two" also occurs between
     * two customers conferring, and because a bare number is the easiest thing for the diarizer to
     * attach to the wrong side.
     */
    public const PAIR_ADDRESS = 3.0;
    public const PAIR_PAYMENT = 3.0;
    public const PAIR_DELIVERY_METHOD = 3.0;
    public const PAIR_ORDER = 3.0;
    public const PAIR_ANYTHING_ELSE = 2.5;
    public const PAIR_QUANTITY = 1.5;

    /**
     * An agent move with no answering utterance found.
     *
     * Still meaningful — quoting a total or a delivery window is something only the order-taker does —
     * but worth a fraction of a completed exchange, because an unanswered question may simply be one the
     * diarizer split badly.
     */
    public const UNPAIRED_QUOTE_PRICE = 1.5;
    public const UNPAIRED_QUOTE_DELIVERY_TIME = 1.5;
    public const UNPAIRED_GREET_BUSINESS = 1.0;
    public const UNPAIRED_CONFIRM_ORDER = 1.0;
    public const UNPAIRED_QUESTION = 0.75;

    /**
     * A customer move with no question in front of it: **worth nothing**, and that is a finding rather
     * than a shrug.
     *
     * Taking the order back to the caller is the order-taker's job. They repeat the address to confirm
     * it, recite the items during the recap, and echo "cash" before closing. On the reference call the
     * true agent produced one PROVIDE_ADDRESS, three PROVIDE_ORDER_ITEMs and one SELECT_PAYMENT that way
     * — five textbook *customer* moves, every one of them spoken by the agent. An unpaired customer act
     * is therefore indistinguishable from an agent echo and says nothing about orientation. Scoring it
     * at any positive weight is how competence at the job became evidence against the person doing it.
     *
     * The exception is a caller announcing their own intent. "I'd like to place an order" is not
     * something an order-taker ever says, so it cannot be an echo.
     */
    public const UNPAIRED_REQUEST_ORDER = 1.0;
    public const UNPAIRED_DECLINE_MORE = 0.5;
    public const UNPAIRED_ECHOABLE_CUSTOMER_MOVE = 0.0;

    /**
     * Words an utterance needs before an unpaired act is trusted in full.
     *
     * Short fragments are where diarization boundaries go wrong, and a stray strong act on a two-word
     * fragment can otherwise cancel a real exchange. On the reference call "okay let me recap" (four
     * words) and "yes $28" (one word) both landed on the customer — agent moves, misattributed — and
     * between them nearly outweighed a completed address exchange.
     *
     * So an unpaired act is scaled by `min(1, words / this)`. Answers inside a pair are exempt: "Cash."
     * is a one-word utterance and a perfectly reliable reply.
     */
    public const RELIABLE_WORD_COUNT = 6;

    /**
     * How much oriented evidence counts as a full case.
     *
     * Confidence is scaled by evidence volume up to this point, so agreement alone is not enough: a
     * single lucky pair in a two-line call gives a high *ratio* but little *evidence*, and publishing on
     * that would be guessing. Six is roughly two decisive exchanges — an order call that got as far as
     * an address and a payment method has revealed who is who.
     */
    public const SUFFICIENT_EVIDENCE = 6.0;

    /**
     * How far ahead to look for the answer to a question, counted in utterances by the other speaker.
     *
     * Two rather than one because the answer is regularly interrupted by a backchannel ("okay", "yes")
     * or split across a diarization boundary. Wider than that and unrelated later speech starts pairing
     * with stale questions.
     */
    public const ANSWER_WINDOW = 2;

    public static function forPair(DialogueAct $question): float
    {
        return match ($question) {
            DialogueAct::ASK_ADDRESS => self::PAIR_ADDRESS,
            DialogueAct::ASK_PAYMENT => self::PAIR_PAYMENT,
            DialogueAct::ASK_DELIVERY_METHOD => self::PAIR_DELIVERY_METHOD,
            DialogueAct::ASK_ORDER => self::PAIR_ORDER,
            DialogueAct::ASK_ANYTHING_ELSE => self::PAIR_ANYTHING_ELSE,
            DialogueAct::ASK_QUANTITY => self::PAIR_QUANTITY,
            default => 0.0,
        };
    }

    public static function forUnpaired(DialogueAct $act): float
    {
        return match ($act) {
            DialogueAct::QUOTE_PRICE => self::UNPAIRED_QUOTE_PRICE,
            DialogueAct::QUOTE_DELIVERY_TIME => self::UNPAIRED_QUOTE_DELIVERY_TIME,
            DialogueAct::GREET_BUSINESS => self::UNPAIRED_GREET_BUSINESS,
            DialogueAct::CONFIRM_ORDER => self::UNPAIRED_CONFIRM_ORDER,
            DialogueAct::REQUEST_ORDER => self::UNPAIRED_REQUEST_ORDER,
            DialogueAct::DECLINE_MORE => self::UNPAIRED_DECLINE_MORE,
            default => $act->isQuestion()
                ? self::UNPAIRED_QUESTION
                : self::UNPAIRED_ECHOABLE_CUSTOMER_MOVE,
        };
    }

    /**
     * How far an unpaired act on a short utterance is trusted, from 0.0 to 1.0.
     *
     * @param int $words words in the utterance the act was found in
     */
    public static function reliabilityOf(int $words): float
    {
        return min(1.0, max(0, $words) / self::RELIABLE_WORD_COUNT);
    }
}
