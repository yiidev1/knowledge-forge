<?php

declare(strict_types=1);

namespace App\Migration;

use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * An index for the store page's grouped-by-order listing.
 *
 * ## Why
 *
 * The store's history stopped being a list of uploads and became a list of **orders**: the page counts
 * and pages over distinct group keys, and a group key is `order_id` when there is one. Both the count
 * and the page selection therefore scan every conversation for one store and group them, which the
 * existing `ix_audio_conversations_store (store_source_id, id)` narrows only by store.
 *
 * This index carries the second column the grouping actually reads, so the work stays inside the index
 * for a store with a long history instead of touching the rows.
 *
 * ## Why not unique
 *
 * **Duplicates are legitimate.** Nothing in this application says an order has at most one mixed
 * recording, and two uploads for one order are a real thing an administrator does — the page shows the
 * newest and keeps the rest reachable. A unique key here would refuse the second upload at 3am with a
 * database error, which is the opposite of what the feature is for.
 *
 * Adding an index rewrites no row and changes no value; `down()` removes only what `up()` added.
 */
final class M260923120000AddConversationOrderIndex implements RevertibleMigrationInterface
{
    private const CONVERSATIONS = 'audio_conversations';
    private const INDEX = 'ix_audio_conversations_store_order';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::CONVERSATIONS . '`
                ADD INDEX `' . self::INDEX . '` (`store_source_id`, `order_id`)',
        );
    }

    /**
     * Safe to reverse, unlike the column migrations either side of it.
     *
     * An index holds no information of its own: dropping it costs query time and nothing else, so this
     * one needs none of the refuse-rather-than-destroy guarding that `M260922120000AddConversationOrderId`
     * carries.
     */
    public function down(MigrationBuilder $b): void
    {
        $b->execute('ALTER TABLE `' . self::CONVERSATIONS . '` DROP INDEX `' . self::INDEX . '`');
    }
}
