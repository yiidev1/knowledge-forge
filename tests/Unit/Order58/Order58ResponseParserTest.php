<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Client\Order58ErrorMapper;
use App\Order58\Client\Order58ResponseParser;
use App\Order58\Contract\Exception\Order58InvalidResponse;
use App\Shared\Infrastructure\Log\SecretRedactor;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNull;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The envelope-validation boundary: required identifiers are enforced, additional fields are tolerated and
 * preserved, and stable mappings (store from knowledge.store, identity from admin_id) hold.
 */
final class Order58ResponseParserTest extends Unit
{
    private function parser(): Order58ResponseParser
    {
        return new Order58ResponseParser(new Order58ErrorMapper(new SecretRedactor()));
    }

    public function testParsesAgentAndKeepsAccountIdAsDataOnly(): void
    {
        $page = $this->parser()->parseAgentsPage([
            'success' => true,
            'data' => [[
                'admin_id' => 139,
                'username' => 'agent',
                'first_name' => 'agent',
                'last_name' => '1',
                'email_address' => 'agent@test.com',
                'status' => 'active',
                'user_type' => 'agent',
                'account_id' => 2,
                '_sync_hash' => 'ha',
                // An unexpected extra field must be tolerated, not rejected.
                'some_future_field' => 'ignored',
            ]],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'total_pages' => 1],
        ]);

        $agent = $page->items[0];
        assertSame(139, $agent->adminId);
        assertSame('agent', $agent->userType);
        assertSame(2, $agent->accountId);
        assertSame('ha', $agent->syncHash);
        // account_id is preserved as raw data; nothing in the DTO treats it as authorization.
        assertSame(2, $agent->raw['account_id']);
    }

    public function testKnowledgeOwnershipComesFromStore(): void
    {
        $record = $this->parser()->parseKnowledgeRecord([
            'success' => true,
            'data' => [
                'id' => 44,
                'store' => 61,
                'title' => 'Dish',
                'description' => 'General Tso',
                'knowledge_number' => '100612001',
                '_sync_hash' => 'hk',
            ],
        ]);

        assertSame(44, $record->id);
        assertSame(61, $record->storeId);
        assertSame('General Tso', $record->content);
    }

    public function testAccountActiveFlagAndNullCompany(): void
    {
        $page = $this->parser()->parseAccountsPage([
            'success' => true,
            'data' => [['id' => 71, 'name' => 'X', 'company' => null, 'active' => 1, '_sync_hash' => 'h']],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'total_pages' => 1],
        ]);

        assertTrue($page->items[0]->active);
        assertTrue($page->items[0]->activeKnown);
        assertNull($page->items[0]->company);
    }

    /**
     * The API has sent active as a boolean, an integer and a numeric string; all three round-trip to the
     * same typed flag, with `activeKnown` true.
     *
     * @dataProvider activeRepresentations
     */
    public function testAccountActiveIsNormalizedFromEveryRepresentation(mixed $raw, bool $expected): void
    {
        $account = $this->parser()->parseAccount([
            'success' => true,
            'data' => ['id' => 71, 'name' => 'X', 'active' => $raw, '_sync_hash' => 'h'],
        ]);

        assertSame($expected, $account->active);
        assertTrue($account->activeKnown);
    }

    /**
     * @return iterable<string, array{mixed, bool}>
     */
    public static function activeRepresentations(): iterable
    {
        yield 'int 1' => [1, true];
        yield 'int 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'bool true' => [true, true];
        yield 'bool false' => [false, false];
    }

    /**
     * A missing or unrecognised active value must not be silently treated as inactive: the record parses
     * (id and hash are valid) but is flagged `activeKnown === false` so the sync leaves the store's status
     * alone instead of overwriting it with a guessed "false".
     *
     * @dataProvider invalidActiveValues
     */
    public function testInvalidOrMissingActiveIsFlaggedUnknown(array $data): void
    {
        $account = $this->parser()->parseAccount([
            'success' => true,
            'data' => ['id' => 71, 'name' => 'X', '_sync_hash' => 'h'] + $data,
        ]);

        assertFalse($account->activeKnown);
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function invalidActiveValues(): iterable
    {
        yield 'missing' => [[]];
        yield 'null' => [['active' => null]];
        yield 'empty string' => [['active' => '']];
        yield 'word' => [['active' => 'yes']];
        yield 'other int' => [['active' => 2]];
    }

    public function testSuccessFalseIsRejected(): void
    {
        $this->expectException(Order58InvalidResponse::class);
        $this->parser()->parseAccountsPage(['success' => false, 'error' => ['code' => 'X', 'message' => 'nope']]);
    }

    public function testMissingIdentifierIsRejected(): void
    {
        $this->expectException(Order58InvalidResponse::class);
        $this->parser()->parseAgentsPage([
            'success' => true,
            'data' => [['username' => 'no admin id', '_sync_hash' => 'h']],
            'pagination' => ['page' => 1, 'per_page' => 10, 'total' => 1, 'total_pages' => 1],
        ]);
    }
}
