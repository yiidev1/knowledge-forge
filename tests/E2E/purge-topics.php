<?php

/** Removes every row the topics fixture creates, by its own markers. Safe to run at any time. */
$ids = $c->createCommand(
    'SELECT id FROM audio_conversations WHERE store_source_id = :s',
    [':s' => STORE],
)->queryColumn();

foreach ($ids as $id) {
    // The files first, by the ids that are about to go: once the rows are deleted there is nothing left
    // to name the directories with, and a fixture that leaves audio behind is a fixture that grows.
    $paths = $c->createCommand(
        'SELECT public_id FROM audio_transcription_jobs WHERE conversation_id = :c',
        [':c' => (int) $id],
    )->queryColumn();

    foreach ($paths as $publicId) {
        $dir = dirname(__DIR__, 2) . '/runtime/audio-to-text/recordings/' . $publicId;

        if (is_file($dir . '/source.wav')) {
            unlink($dir . '/source.wav');
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }
    }

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
