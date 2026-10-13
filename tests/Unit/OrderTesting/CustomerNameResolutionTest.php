<?php

declare(strict_types=1);

namespace App\Tests\Unit\OrderTesting;

use App\OrderTesting\Application\Comparison\OrderNormalizer;
use App\OrderTesting\Domain\Comparison\NormalizedCustomer;
use PHPUnit\Framework\TestCase;

use function json_encode;

/**
 * Where a customer's name is read from, and what happens when it is not there.
 *
 * ## Why a chain exists at all
 *
 * This provider keeps the name in one of four places and, for most orders, in none of them. Of the 396
 * orders mirrored in the installation this was written against, **6 carry a name**: five under
 * `data.reservation` and one under `data.shipping`. `commit_data` supplied none. So the chain is as
 * much about returning *nothing* correctly as about finding something — which is why the "no name
 * anywhere" case is asserted as carefully as the rest.
 *
 * ## The rule the chain obeys
 *
 * A whole block wins, never a field. Taking the first non-empty forename from one block and the first
 * non-empty surname from another would invent a person who appears nowhere in the document, and nobody
 * reading the page afterwards could tell that had happened.
 */
final class CustomerNameResolutionTest extends TestCase
{
    private OrderNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new OrderNormalizer();
    }

    public function testTheReservationIsPreferredWhenItNamesSomebody(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => ['first_name' => 'John', 'last_name' => 'Smith'],
                'shipping' => ['first_name' => 'David', 'last_name' => 'Wilson'],
            ]),
        ]);

        self::assertSame('John', $customer->firstName);
        self::assertSame('Smith', $customer->lastName);
        self::assertSame('John Smith', $customer->displayName());
    }

    /** The one real order in this installation whose name lives under shipping. */
    public function testShippingAnswersWhenTheReservationIsEmpty(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => ['first_name' => '', 'last_name' => null],
                'shipping' => ['first_name' => 'David', 'last_name' => 'Wilson'],
            ]),
        ]);

        self::assertSame('David Wilson', $customer->displayName());
    }

    public function testCommitDataReservationAnswersWhenDataNamesNobody(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode(['reservation' => ['first_name' => '', 'last_name' => null]]),
            'commit_data' => self::encode(['reservation' => ['first_name' => 'Ann', 'last_name' => 'Lee']]),
        ]);

        self::assertSame('Ann Lee', $customer->displayName());
    }

    public function testCommitDataShippingIsTheLastResort(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode(['reservation' => [], 'shipping' => null]),
            'commit_data' => self::encode([
                'reservation' => ['first_name' => '  '],
                'shipping' => ['first_name' => 'Pat'],
            ]),
        ]);

        self::assertSame('Pat', $customer->displayName());
    }

    /**
     * The common case here, and the one that must not be softened.
     *
     * 390 of 396 mirrored orders land on this branch. The page shows `-`; it does not fall back to a
     * customer id, a user id, or anything else that would read as a name without being one.
     */
    public function testAnOrderThatNamesNobodyResolvesToNothing(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => ['first_name' => '', 'last_name' => null],
                'shipping' => null,
            ]),
            'commit_data' => self::encode([
                'reservation' => ['first_name' => '', 'last_name' => null],
                'shipping' => null,
            ]),
            // Present in every real payload, and never a name.
            'customer_id' => '5071271',
            'user_id' => '9910',
        ]);

        self::assertNull($customer->firstName);
        self::assertNull($customer->lastName);
        self::assertNull($customer->displayName());
    }

    /** @dataProvider partialNames */
    public function testOnePartIsStillAName(?string $first, ?string $last, string $expected): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => ['first_name' => $first, 'last_name' => $last],
                'shipping' => ['first_name' => 'David', 'last_name' => 'Wilson'],
            ]),
        ]);

        self::assertSame($expected, (string) $customer->displayName());
    }

    /** @return array<string, array{0: ?string, 1: ?string, 2: string}> */
    public static function partialNames(): array
    {
        return [
            'forename only' => ['John', null, 'John'],
            'surname only' => [null, 'Miller', 'Miller'],
            'forename only, empty surname' => ['John', '', 'John'],
        ];
    }

    /** Whitespace is not a name, so the chain keeps walking. */
    public function testAWhitespaceOnlyBlockIsSkipped(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => ['first_name' => '   ', 'last_name' => "\t"],
                'shipping' => ['first_name' => 'Pat'],
            ]),
        ]);

        self::assertSame('Pat', $customer->displayName());
    }

    /**
     * An array where a name belongs must never reach a page.
     *
     * Rendering it would print `Array` at best and leak structure at worst, so a non-scalar counts as
     * absent and the chain moves on.
     */
    public function testANonScalarNameIsRefusedRatherThanRendered(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => ['first_name' => ['nested'], 'last_name' => null],
                'shipping' => ['first_name' => 'Safe'],
            ]),
        ]);

        self::assertSame('Safe', $customer->displayName());
    }

    /** @dataProvider malformedPayloads */
    public function testMalformedJsonDegradesToNoNameRatherThanAnError(array $raw): void
    {
        $customer = $this->customerFrom($raw);

        self::assertNull($customer->displayName());
    }

    /** @return array<string, array{0: array<array-key, mixed>}> */
    public static function malformedPayloads(): array
    {
        return [
            'inner data is not json' => [['data' => '{not json at all']],
            'inner data is a bare string' => [['data' => 'reservation']],
            'data is a number' => [['data' => 42]],
            'data absent entirely' => [[]],
            'commit_data malformed too' => [['data' => '{broken', 'commit_data' => '{also broken']],
        ];
    }

    /** A broken `data` must not stop a good `commit_data` being read. */
    public function testCommitDataStillAnswersWhenDataIsMalformed(): void
    {
        $customer = $this->customerFrom([
            'data' => '{not json',
            'commit_data' => self::encode(['reservation' => ['first_name' => 'Fall', 'last_name' => 'Back']]),
        ]);

        self::assertSame('Fall Back', $customer->displayName());
    }

    /**
     * The blocks are read as objects too, not only as encoded strings.
     *
     * Both shapes occur in the same document for this provider, which is why the normalizer routes
     * every block through one helper.
     */
    public function testAnAlreadyDecodedBlockIsReadTheSameWay(): void
    {
        $customer = $this->customerFrom([
            'data' => ['reservation' => ['first_name' => 'Decoded', 'last_name' => 'Object']],
        ]);

        self::assertSame('Decoded Object', $customer->displayName());
    }

    /** Everything other than the name still comes from the reservation, as it always did. */
    public function testTheFallbackMovesTheNameAndNothingElse(): void
    {
        $customer = $this->customerFrom([
            'data' => self::encode([
                'reservation' => [
                    'first_name' => '',
                    'last_name' => null,
                    'phone' => '15167794915',
                    'email' => 'someone@example.test',
                    'customer_count' => 2,
                ],
                'shipping' => ['first_name' => 'David', 'last_name' => 'Wilson', 'phone' => '19999999999'],
            ]),
        ]);

        self::assertSame('David Wilson', $customer->displayName(), 'the name comes from shipping');
        self::assertSame('15167794915', $customer->phone, 'the phone still comes from the reservation');
        self::assertSame('someone@example.test', $customer->email);
        self::assertSame(2, $customer->customerCount);
    }

    /** @param array<array-key, mixed> $raw */
    private function customerFrom(array $raw): NormalizedCustomer
    {
        return $this->normalizer->normalize($raw)->customer;
    }

    /** @param array<array-key, mixed> $value */
    private static function encode(array $value): string
    {
        return (string) json_encode($value);
    }
}
