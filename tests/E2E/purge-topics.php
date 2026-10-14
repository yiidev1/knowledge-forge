<?php

/** Removes every row the topics fixture creates, by its own markers. Safe to run at any time. */
$ids = $c->createCommand(
    'SELECT id FROM audio_conversations WHERE store_source_id = :s',
    [':s' => STORE],
)->queryColumn();

foreach ($ids as $id) {
    $c->createCommand()->delete('{{%audio_transcription_jobs}}', ['conversation_id' => (int) $id])->execute();
}

$c->createCommand()->delete('{{%audio_conversations}}', ['store_source_id' => STORE])->execute();

// Assignments before topics: the foreign key is RESTRICT, which is the whole point of it.
$c->createCommand()->delete('{{%practice_order_topics}}', ['store_source_id' => STORE])->execute();
$c->createCommand()->delete('{{%practice_topics}}', ['name' => [TOPIC_A, TOPIC_B]])->execute();

$c->createCommand()->delete('{{%order58_orders}}', ['account_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_stores}}', ['source_id' => STORE])->execute();
$c->createCommand()->delete('{{%knowledge_bases}}', ['slug' => SLUG])->execute();
$c->createCommand()->delete('{{%admin_users}}', ['username' => ADMIN])->execute();
