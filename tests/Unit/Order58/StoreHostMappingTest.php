<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Mapper\StoreMapper;
use App\Order58\Contract\Dto\Order58Account;
use Codeception\Test\Unit;

use function str_repeat;

/**
 * What this application will accept as a store's hostname. **No test here reaches the network.**
 *
 * The rule under test is one sentence with consequences: `null` out of the normaliser always means *the
 * source said nothing usable*, and never *this store has no host*. Everything downstream depends on that
 * reading — the repository refuses to write a null, so a single malformed response can never blank a
 * hostname that was previously good. The cases below are the ways a source can say nothing usable, and
 * they are pinned individually because each one arrives through a different mistake at the other end.
 */
final class StoreHostMappingTest extends Unit
{
    /** The real shape, from the client's own Postman capture of `GET /accounts`. */
    private const REAL_HOST = '0000000004.17ip.com';

    public function testTheHostFromARealAccountRecordIsMapped(): void
    {
        $mirror = (new StoreMapper())->toMirror($this->account(['host' => self::REAL_HOST]));

        $this->assertSame(self::REAL_HOST, $mirror->host);
    }

    /**
     * The snapshot keeps its own copy, and it keeps it unchanged.
     *
     * The store-profile document is generated from `snapshot.fields`, so a column that quietly altered
     * that structure would rewrite every store's document on the next sync for no reason.
     */
    public function testTheSnapshotStillCarriesTheHostUnchanged(): void
    {
        $mirror = (new StoreMapper())->toMirror($this->account(['host' => self::REAL_HOST]));

        $this->assertSame(self::REAL_HOST, $mirror->snapshot['fields']['Host']);
    }

    /**
     * Every way of saying nothing collapses to null.
     *
     * @dataProvider unusableValues
     */
    public function testAnUnusableHostIsNullRatherThanStored(mixed $value): void
    {
        $this->assertNull(StoreMapper::normaliseHost($value));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableValues(): array
    {
        return [
            // The four the client enumerated.
            'json null' => [null],
            'empty string' => [''],
            'whitespace only' => ["  \t "],
            'wrong type: int' => [12345],
            'wrong type: array' => [['host' => 'a.example.com']],
            'wrong type: bool' => [true],
            // Malformed enough that storing it would put a non-host in a host column.
            'carries a scheme' => ['https://shop.example.com'],
            'carries a path' => ['shop.example.com/menu'],
            'carries a port' => ['shop.example.com:8080'],
            'carries an at sign' => ['user@shop.example.com'],
            'inner whitespace' => ['shop example.com'],
            'leading dot' => ['.example.com'],
            'trailing dot' => ['example.com.'],
            'longer than the column' => [str_repeat('a', 256)],
        ];
    }

    /** An absent key is not an error and not a value — it is the commonest way of saying nothing. */
    public function testAnAbsentHostKeyIsNull(): void
    {
        $this->assertNull((new StoreMapper())->toMirror($this->account([]))->host);
    }

    /** Surrounding whitespace is the one thing repaired, because it changes no meaning. */
    public function testSurroundingWhitespaceIsTrimmed(): void
    {
        $this->assertSame(self::REAL_HOST, StoreMapper::normaliseHost('  ' . self::REAL_HOST . "\n"));
    }

    /**
     * Real values from the live mirror, as a guard against over-strict validation.
     *
     * The risk a hostname pattern carries is not that it accepts too much; it is that it rejects a
     * legitimate value and the column silently stops updating. These are taken from stores already
     * mirrored in this database.
     *
     * @dataProvider realWorldHosts
     */
    public function testHostsAlreadyInTheMirrorAreAllAccepted(string $host): void
    {
        $this->assertSame($host, StoreMapper::normaliseHost($host));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function realWorldHosts(): array
    {
        return [
            'numeric subdomain' => ['5162070808.17ip.com'],
            'word subdomain' => ['seawolf.order58.com'],
            'short subdomain' => ['magw.order58.com'],
            'zero padded' => ['0000000002.order58.com'],
            'hyphenated' => ['wok-n-roll.order58.com'],
            'underscored' => ['wok_n_roll.order58.com'],
        ];
    }

    /** Two stores in one response keep their own hosts. The failure this guards is a shared buffer. */
    public function testEachStoreKeepsItsOwnHost(): void
    {
        $mapper = new StoreMapper();

        $first = $mapper->toMirror($this->account(['host' => 'first.order58.com'], 61));
        $second = $mapper->toMirror($this->account(['host' => 'second.order58.com'], 62));

        $this->assertSame('first.order58.com', $first->host);
        $this->assertSame('second.order58.com', $second->host);
        $this->assertSame(61, $first->sourceId);
        $this->assertSame(62, $second->sourceId);
    }

    /** Mapping a host changes nothing else about the record. */
    public function testUnrelatedMirrorFieldsAreUntouched(): void
    {
        $mirror = (new StoreMapper())->toMirror($this->account(['host' => self::REAL_HOST]));

        $this->assertSame('0000000004 Test', $mirror->name);
        $this->assertSame('0004', $mirror->company);
        $this->assertSame('h61', $mirror->syncHash);
        $this->assertNull($mirror->sourceUpdatedAt);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function account(array $extra, int $id = 61): Order58Account
    {
        $raw = ['id' => $id, 'name' => '0000000004 Test', 'company' => '0004', '_sync_hash' => 'h61'] + $extra;

        return new Order58Account(
            id: $id,
            name: '0000000004 Test',
            company: '0004',
            active: false,
            activeKnown: true,
            syncHash: 'h61',
            raw: $extra + $raw,
        );
    }
}
