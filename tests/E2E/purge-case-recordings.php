<?php

declare(strict_types=1);

/**
 * Removes the Case Order recording fixture. Included by the seed and the cleanup, with `$c`, `ADMIN`
 * and `STORE` defined. Sessions and links go with their orders, by cascade.
 */

/** @var Yiisoft\Db\Connection\ConnectionInterface $c */
$c->createCommand()->delete('{{%order58_orders}}', ['account_id' => STORE])->execute();
$c->createCommand()->delete('{{%knowledge_bases}}', ['source_store_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_stores}}', ['source_id' => STORE])->execute();
$c->createCommand()->delete('{{%admin_users}}', ['username' => ADMIN])->execute();
