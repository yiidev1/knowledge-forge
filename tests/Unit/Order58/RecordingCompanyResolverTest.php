<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Integration\Order58Recording\ChannelRecordingRequest;
use App\Order58\Application\RecordingCompanyResolver;
use App\Order58\Domain\Exception\RecordingCompanyMissing;
use App\Tests\Support\Fake\Order58\OneStoreMirror;
use Codeception\Test\Unit;

/**
 * Which `company` code a store's recordings are fetched with.
 *
 * This is the parameter with the least provider documentation and the most room to be quietly wrong: a
 * mismatched value does not fail loudly, it fetches somebody else's audio with a 200. So the rule is
 * narrow on purpose — the selected store's own mirrored code, or no import at all — and these tests pin
 * both halves, including the refusals that keep a bad value from ever reaching a URL.
 */
final class RecordingCompanyResolverTest extends Unit
{
    private function resolver(?string $company, int $sourceId = 831): RecordingCompanyResolver
    {
        return new RecordingCompanyResolver(OneStoreMirror::withCompany($sourceId, $company));
    }

    /** The store's own code, used verbatim. */
    public function testTheStoresOwnCompanyCodeIsUsed(): void
    {
        self::assertSame('KONG', $this->resolver('KONG')->forStore(831));
    }

    /**
     * A different store resolves to a different code.
     *
     * Stated as its own test because the failure it guards against — one value shared by every store —
     * is exactly what a hard-coded constant would look like, and would pass the test above.
     */
    public function testEachStoreResolvesToItsOwnCode(): void
    {
        self::assertSame('KONG', $this->resolver('KONG', 831)->forStore(831));
        self::assertSame('WGEU', $this->resolver('WGEU', 1491)->forStore(1491));
    }

    /** Surrounding whitespace is a mirroring artefact, not part of the code. */
    public function testTheCodeIsTrimmed(): void
    {
        self::assertSame('KONG', $this->resolver("  KONG\t")->forStore(831));
    }

    /**
     * No code, no import.
     *
     * The alternative — falling back to a constant, or to another store's value — would build a
     * well-formed request for the wrong merchant's audio, which the provider answers with a 200.
     *
     * @dataProvider unusableCodes
     */
    public function testAStoreWithoutAUsableCodeIsRefused(?string $company, string $why): void
    {
        $this->expectException(RecordingCompanyMissing::class);

        $this->resolver($company)->forStore(831);
    }

    /**
     * @return iterable<string, array{?string, string}>
     */
    public function unusableCodes(): iterable
    {
        yield 'never mirrored' => [null, 'The column is nullable and older rows have no value.'];
        yield 'empty' => ['', 'An empty parameter is not a code.'];
        yield 'whitespace only' => ["  \t ", 'Trimming leaves nothing.'];
        yield 'control character' => ["KO\nNG", 'A newline would ride into a query string.'];
        yield 'over the length limit' => [str_repeat('A', 101), 'Longer than the provider field allows.'];
    }

    /** An unknown store is refused the same way, and the message does not reveal which ids exist. */
    public function testAnUnknownStoreIsRefused(): void
    {
        $resolver = new RecordingCompanyResolver(OneStoreMirror::empty());

        try {
            $resolver->forStore(831);
            self::fail('Expected a refusal.');
        } catch (RecordingCompanyMissing $e) {
            self::assertStringContainsString('#831', $e->getMessage());
            self::assertStringContainsString('store sync', $e->getMessage(), 'The remedy is named.');
        }
    }

    /**
     * The resolver's bound and the provider request's bound are the same number.
     *
     * They are separate checks — one guards a stored value, the other a submitted one — and they are
     * allowed to be separate. They are not allowed to *disagree*, because then a code could pass here
     * and be refused at the point it becomes a URL, which would fail after the batch was written.
     */
    public function testTheLengthRuleAgreesWithTheProviderRequestRule(): void
    {
        $atLimit = str_repeat('A', ChannelRecordingRequest::MAX_TEXT_LENGTH);

        self::assertSame($atLimit, $this->resolver($atLimit)->forStore(831));

        $this->expectException(RecordingCompanyMissing::class);
        $this->resolver($atLimit . 'A')->forStore(831);
    }
}
