<?php

/** Removes every row the comparison fixture creates, by its own markers. Nothing else is touched. */
$c->createCommand()->delete('{{%order_testing_demo_orders}}', ['store_source_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_order_items}}', ['store_account_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_orders}}', ['account_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_stores}}', ['source_id' => STORE])->execute();
$c->createCommand()->delete('{{%knowledge_bases}}', ['slug' => SLUG])->execute();
$c->createCommand()->delete('{{%admin_users}}', ['username' => ADMIN])->execute();
