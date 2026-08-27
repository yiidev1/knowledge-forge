<?php

declare(strict_types=1);

namespace App\AudioToText\Application\Speaker;

use App\AudioToText\Domain\Speaker\DialogueAct;

use function in_array;
use function mb_strtolower;
use function preg_match;
use function preg_replace;
use function str_contains;
use function trim;

/**
 * Recognises what a single utterance is doing, deterministically and locally.
 *
 * Plain substrings and a handful of numeric patterns — no model, no network, no learned weights. The
 * matching is substring-based rather than token-based on purpose: these recordings mix English, Spanish,
 * Gujarati and Hindi inside one sentence, and whisper punctuates them inconsistently.
 *
 * **The rule that matters is family suppression.** Within one utterance only the most specific act of a
 * family survives, so "cash or card?" is a payment *question* and never also a payment *choice*. Without
 * that rule the old keyword scoring counted the agent's own question as evidence of them being the
 * customer, because "cash" and "card" are both substrings of it — which is a large part of why obvious
 * calls scored near zero.
 */
final readonly class DialogueActDetector
{
    /**
     * Ordered families. Where more than one member matches the same utterance, the earlier one wins.
     * Agent moves come first in every family: they are longer, more specific phrasings, and a text
     * containing one almost never *also* performs the answering move.
     *
     * @var non-empty-list<non-empty-list<DialogueAct>>
     */
    private const FAMILIES = [
        [DialogueAct::ASK_PAYMENT, DialogueAct::SELECT_PAYMENT],
        [DialogueAct::ASK_ADDRESS, DialogueAct::PROVIDE_ADDRESS],
        [
            DialogueAct::ASK_DELIVERY_METHOD,
            DialogueAct::QUOTE_DELIVERY_TIME,
            DialogueAct::SELECT_DELIVERY_METHOD,
        ],
        [DialogueAct::ASK_ORDER, DialogueAct::REQUEST_ORDER, DialogueAct::PROVIDE_ORDER_ITEM],
        [DialogueAct::ASK_QUANTITY, DialogueAct::PROVIDE_QUANTITY],
        [DialogueAct::ASK_ANYTHING_ELSE, DialogueAct::DECLINE_MORE],
    ];

    /**
     * Substrings, lower-cased, matched against a whitespace-normalised utterance.
     *
     * @var array<string, non-empty-list<string>>
     */
    private const PHRASES = [
        DialogueAct::GREET_BUSINESS->value => [
            'thank you for calling', 'thanks for calling', 'how may i help', 'how can i help',
            'can i help you', 'may i help you', 'how may i assist',
        ],
        DialogueAct::ASK_ORDER->value => [
            // Every agent phrasing here is anchored on "you". Without that anchor, "like to place an
            // order" also matches the caller's own "I'd like to place an order" — and because the more
            // specific act wins within a family, the customer's clearest opening line would be read as
            // the agent inviting it.
            'what would you like to order', 'what would you like', 'would you like to order',
            'would you like to place', 'you want to place', 'you want to order',
            'can i take your order', 'what can i get you', 'what do you want to order',
            'go ahead sir', 'go ahead ma', 'your order', 'kya chahiye', 'aapka order', 'su orden',
            'que va a ordenar', 'qué va a ordenar',
        ],
        DialogueAct::ASK_DELIVERY_METHOD->value => [
            'pickup or delivery', 'pick up or delivery', 'delivery or pickup', 'delivery or pick up',
            'for pickup or', 'is it pickup', 'is this delivery', 'para recoger o entrega',
        ],
        DialogueAct::ASK_ADDRESS->value => [
            "what's the address", 'what is the address', 'what address', 'your address',
            'the address please', 'address please', 'can i have the address', 'can i get the address',
            'may i have your address', 'address batao', 'cual es la direccion', 'cuál es la dirección',
            'la direccion por favor',
        ],
        DialogueAct::ASK_PAYMENT->value => [
            'cash or card', 'cash or credit', 'card or cash', 'credit or cash', 'cash or charge',
            'how would you like to pay', 'how are you paying', 'how do you want to pay',
            'efectivo o tarjeta',
        ],
        DialogueAct::ASK_QUANTITY->value => [
            'how many', 'how many orders', 'cuantos', 'cuántos', 'kitne',
        ],
        DialogueAct::ASK_ANYTHING_ELSE->value => [
            'anything else', 'something else', 'will that be all', 'is that everything',
            'algo mas', 'algo más', 'aur kuch',
        ],
        DialogueAct::CONFIRM_ORDER->value => [
            'let me recap', 'let me repeat', 'let me confirm', 'so that\'s', 'so you want',
            'to confirm', 'your order is', 'that is correct', 'correct?', 'repito',
        ],
        DialogueAct::QUOTE_PRICE->value => [
            'your total', 'that will be', 'that comes to', 'the total is', 'it comes to', 'el total',
        ],
        DialogueAct::QUOTE_DELIVERY_TIME->value => [
            'delivery time', 'half an hour', 'delivery in', 'ready in', 'it will take about',
        ],
        DialogueAct::REQUEST_ORDER->value => [
            'i want to place an order', 'i would like to place an order', "i'd like to place an order",
            'i want to order', 'i would like to order', "i'd like to order", 'i want to make an order',
            'quiero ordenar', 'quisiera ordenar',
        ],
        DialogueAct::PROVIDE_ORDER_ITEM->value => [
            'i want', 'i would like', "i'd like", 'can i get', 'can i have', 'i need', 'give me',
            'orders of', 'one order of', 'two orders', 'three orders',
            'chicken wing', 'sesame chicken', 'egg roll', 'french fries', 'fried rice', 'white rice',
            'tostones', 'coca-cola', 'coca cola', 'cans of coke', 'combo', 'lo mein', 'general tso',
            'quiero', 'quisiera', 'me da',
        ],
        DialogueAct::SELECT_DELIVERY_METHOD->value => [
            'for delivery', 'for pickup', 'for pick up', 'delivery please', 'pickup please',
            'para entrega', 'para recoger',
        ],
        DialogueAct::PROVIDE_ADDRESS->value => [
            'apartment', 'apt ', 'apartamento',
        ],
        DialogueAct::SELECT_PAYMENT->value => [
            'cash', 'credit card', 'debit', 'with card', 'by card', 'efectivo', 'tarjeta',
        ],
        DialogueAct::DECLINE_MORE->value => [
            "that's it", 'that is it', "that's all", 'that is all', "that's everything",
            'nothing else', 'no thank you', 'no thanks', 'nada mas', 'nada más', 'eso es todo',
        ],
    ];

    /**
     * Numeric shapes, which no phrase list can express.
     *
     * @var array<string, non-empty-list<string>>
     */
    private const PATTERNS = [
        // "$28", "$27.75", "27.75" — a bare decimal with two places is a price in this context.
        DialogueAct::QUOTE_PRICE->value => [
            '/\$\s?\d/u',
            '/\b\d{1,4}[.,]\d{2}\b/u',
            '/\b\d{1,3}\s*(?:dollars|dolares|dólares)\b/u',
        ],
        // "in 30 minutes", "45 minutes" — a number attached to minutes. Never a bare "minutes",
        // which the old signal list matched and which appears in plenty of unrelated speech.
        DialogueAct::QUOTE_DELIVERY_TIME->value => [
            '/\b\d{1,3}\s*(?:minutes?|mins?|minutos)\b/u',
        ],
        // A house number followed closely by a thoroughfare word.
        DialogueAct::PROVIDE_ADDRESS->value => [
            '/\b\d{1,5}\b[^.?!]{0,30}\b(?:street|avenue|road|boulevard|drive|lane|calle|ave|blvd)\b/u',
            '/\b(?:street|avenue|road|boulevard|calle)\b[^.?!]{0,15}\b\d{1,5}\b/u',
        ],
        // A standalone small number or number word — only ever meaningful as an answer, and weighted
        // accordingly by RoleScoreWeights::UNPAIRED_CUSTOMER_MOVE.
        DialogueAct::PROVIDE_QUANTITY->value => [
            '/^\W*(?:\d{1,2}|one|two|three|four|five|uno|dos|tres)\b\W{0,3}$/u',
            '/\b(?:just|only)\s+(?:\d{1,2}|one|two|three)\b/u',
        ],
        DialogueAct::SELECT_PAYMENT->value => [
            '/\bcard\b/u',
        ],
    ];

    /**
     * @return list<DialogueAct>
     */
    public function detect(string $text): array
    {
        $haystack = $this->normalise($text);

        if ($haystack === '') {
            return [];
        }

        $found = [];
        foreach (DialogueAct::cases() as $act) {
            if ($this->matches($act, $haystack)) {
                $found[] = $act;
            }
        }

        return $this->suppressWithinFamilies($found);
    }

    private function matches(DialogueAct $act, string $haystack): bool
    {
        foreach (self::PHRASES[$act->value] ?? [] as $phrase) {
            if (str_contains($haystack, $phrase)) {
                return true;
            }
        }

        foreach (self::PATTERNS[$act->value] ?? [] as $pattern) {
            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<DialogueAct> $found
     *
     * @return list<DialogueAct>
     */
    private function suppressWithinFamilies(array $found): array
    {
        $suppressed = [];

        foreach (self::FAMILIES as $family) {
            $winnerSeen = false;
            foreach ($family as $act) {
                if (!in_array($act, $found, true)) {
                    continue;
                }

                if ($winnerSeen) {
                    $suppressed[] = $act;
                    continue;
                }

                $winnerSeen = true;
            }
        }

        $kept = [];
        foreach ($found as $act) {
            if (!in_array($act, $suppressed, true)) {
                $kept[] = $act;
            }
        }

        return $kept;
    }

    private function normalise(string $text): string
    {
        $lowered = mb_strtolower($text);
        $collapsed = preg_replace('/\s+/u', ' ', $lowered);

        return trim($collapsed ?? $lowered);
    }
}
