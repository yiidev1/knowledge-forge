<?php

/** Removes every row this harness creates, by its own markers. Nothing else is touched. */
$ids = $c->createCommand(
    'SELECT id FROM audio_conversations WHERE store_source_id = :s',
    [':s' => STORE],
)->queryColumn();

foreach ($ids as $id) {
    $jobIds = $c->createCommand(
        'SELECT id FROM audio_transcription_jobs WHERE conversation_id = :c',
        [':c' => (int) $id],
    )->queryColumn();

    foreach ($jobIds as $jobId) {
        $c->createCommand()->delete('{{%audio_segment_revisions}}', ['job_id' => (int) $jobId])->execute();
        $c->createCommand()->delete('{{%audio_tts_renditions}}', ['job_id' => (int) $jobId])->execute();
    }

    $c->createCommand()->delete('{{%audio_transcription_jobs}}', ['conversation_id' => (int) $id])->execute();
}

$c->createCommand()->delete('{{%audio_conversations}}', ['store_source_id' => STORE])->execute();
$c->createCommand()->delete('{{%order58_stores}}', ['source_id' => STORE])->execute();
$c->createCommand()->delete('{{%knowledge_bases}}', ['slug' => SLUG])->execute();
$c->createCommand()->delete('{{%admin_users}}', ['username' => ADMIN])->execute();
