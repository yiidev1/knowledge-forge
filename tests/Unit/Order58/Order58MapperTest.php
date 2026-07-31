<?php

declare(strict_types=1);

namespace App\Tests\Unit\Order58;

use App\Order58\Application\Mapper\AgentMapper;
use App\Order58\Application\Mapper\KnowledgeMapper;
use App\Order58\Application\Mapper\StoreMapper;
use App\Order58\Contract\Dto\Order58Account;
use App\Order58\Contract\Dto\Order58Agent;
use App\Order58\Contract\Dto\Order58KnowledgeRecord;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertArrayNotHasKey;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertTrue;

/**
 * The mappers convert API DTOs to mirror write models: a curated store snapshot, safe agent fields with
 * account_id as data only, and knowledge ownership taken from the store reference.
 */
final class Order58MapperTest extends Unit
{
    public function testStoreMapsToMirrorWithCuratedSnapshot(): void
    {
        $account = new Order58Account(
            id: 1861,
            name: 'Bamboo House',
            company: 'WAOW',
            active: true,
            activeKnown: true,
            syncHash: 'h1861',
            raw: ['id' => 1861, 'name' => 'Bamboo House', 'company' => 'WAOW', 'city' => 'Groton', 'state' => 'CT', 'balance' => '0.00000', '_sync_hash' => 'h1861'],
        );

        $mirror = (new StoreMapper())->toMirror($account);

        assertSame(1861, $mirror->sourceId);
        assertTrue($mirror->active);
        assertSame('Bamboo House', $mirror->snapshot['name']);
        assertSame(true, $mirror->snapshot['active']);
        // Curated fields are labelled; noise like "balance" is excluded.
        assertSame('Groton', $mirror->snapshot['fields']['City']);
        assertArrayNotHasKey('balance', $mirror->snapshot['fields']);
    }

    public function testAgentMapsSafeFieldsAndTreatsAccountIdAsData(): void
    {
        $agent = new Order58Agent(
            adminId: 139,
            username: 'agent',
            firstName: 'agent',
            lastName: '1',
            email: 'agent@test.com',
            contactNumber: null,
            role: '1',
            status: 'active',
            userType: 'agent',
            accountId: 2,
            modifiedAt: null,
            syncHash: 'ha',
            raw: ['admin_id' => 139, 'username' => 'agent', 'account_id' => 2, '_sync_hash' => 'ha'],
        );

        $mirror = (new AgentMapper())->toMirror($agent);

        assertSame(139, $mirror->adminId);
        assertSame('agent', $mirror->userType);
        assertSame(2, $mirror->accountId);
        // The snapshot is safe: the sync hash is stripped and no credential field exists.
        assertArrayNotHasKey('_sync_hash', $mirror->snapshot);
    }

    public function testKnowledgeOwnershipComesFromStore(): void
    {
        $record = new Order58KnowledgeRecord(
            id: 44,
            storeId: 61,
            title: 'Dish',
            content: 'General Tso',
            knowledgeNumber: '100612001',
            keyword: 'order',
            type: 'Knowledge',
            createdAt: null,
            updatedAt: null,
            syncHash: 'hk',
            raw: ['id' => 44, 'store' => 61, '_sync_hash' => 'hk'],
        );

        $mirror = (new KnowledgeMapper())->toMirror($record);

        assertSame(44, $mirror->sourceId);
        assertSame(61, $mirror->storeSourceId);
        assertTrue($mirror->active);
        assertArrayNotHasKey('_sync_hash', $mirror->snapshot);
    }
}
