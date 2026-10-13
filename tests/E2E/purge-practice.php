<?php

/** Removes every row and file the practice fixture creates, by its own markers. */
$c->createCommand()->delete('{{%order_testing_attempts}}', ['store_source_id' => STORE])->execute();
$c->createCommand()->delete('{{%order_testing_demo_orders}}', ['store_source_id' => STORE])->execute();

$ids = $c->createCommand(
    'SELECT id FROM audio_conversations WHERE store_source_id = :s',
    [':s' => STORE],
)->queryColumn();

foreach ($ids as $id) {
    $c->createCommand()->delete('{{%audio_transcription_jobs}}', ['conversation_id' => (int) $id])->execute();
}

$c->createCommand()->delete('{{%audio_conversations}}', ['store_source_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_orders}}', ['account_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_stores}}', ['source_id' => STORE])->execute();
$c->createCommand()->delete('{{%knowledge_bases}}', ['slug' => SLUG])->execute();
$c->createCommand()->delete('{{%admin_users}}', ['username' => ADMIN])->execute();

foreach ([JOB, 'beefbeefbeefbeefbeefbeefbeefbeef'] as $job) {
    $dir = dirname(__DIR__, 2) . '/runtime/audio-to-text/recordings/' . $job;

    if (is_file($dir . '/source.wav')) {
        unlink($dir . '/source.wav');
    }

    if (is_dir($dir)) {
        rmdir($dir);
    }
}
