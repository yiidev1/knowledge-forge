<?php

declare(strict_types=1);

namespace App\Migration;

use RuntimeException;
use Yiisoft\Db\Migration\MigrationBuilder;
use Yiisoft\Db\Migration\RevertibleMigrationInterface;

/**
 * Lets a batch of recordings be downloaded without asking for a transcript.
 *
 * ## Two things that were one
 *
 * Importing a recording and transcribing it have always happened together: the importer hands each
 * downloaded file to the audio pipeline, and the pipeline's only way of accepting one is to queue it for
 * transcription. That is right for the Calls page, where the point is a transcript. It is wrong for an
 * operator who wants to hear a call before deciding whether a transcript is worth paying for — and
 * every imported recording currently costs CPU or money whether anybody wanted the text or not.
 *
 * This column is the fork. It is read once, by the import worker, to decide whether the recording it has
 * just stored should also be asked about.
 *
 * ## Why the default is the old behaviour
 *
 * `DOWNLOAD_AND_TRANSCRIBE` is what every existing row did and what the Calls page will keep writing, so
 * adding the column changes nothing that already exists and nothing that page does. A batch has to opt
 * *out* deliberately, which is what makes this safe to deploy ahead of the page that uses it.
 *
 * ## Why a mode and not a boolean
 *
 * `transcribe_after_import = 0` reads as a missing step. These are two different kinds of request, and a
 * row should say which it is in its own words — the same reason `triggered_by` beside it spells MANUAL
 * and AUTOMATIC rather than storing a flag.
 *
 * The CHECK matches the one `triggered_by` already carries on this table. A column the application
 * validates and the database does not is a column that eventually holds something else.
 */
final class M261001100000AddBatchImportMode implements RevertibleMigrationInterface
{
    private const BATCHES = 'order58_call_import_batches';

    public function up(MigrationBuilder $b): void
    {
        $b->execute(
            'ALTER TABLE `' . self::BATCHES . '`
                ADD COLUMN `import_mode` VARCHAR(24) NOT NULL DEFAULT \'DOWNLOAD_AND_TRANSCRIBE\'
                    AFTER `triggered_by`,
                ADD CONSTRAINT `chk_order58_call_import_batches_mode`
                    CHECK (`import_mode` IN (\'DOWNLOAD_ONLY\', \'DOWNLOAD_AND_TRANSCRIBE\'))',
        );
    }

    /**
     * Refuse rather than destroy, the house rule.
     *
     * A download-only batch is the only record that its recordings were deliberately acquired without a
     * transcript. Dropping the column would leave them indistinguishable from batches whose transcription
     * failed, so this stops instead and says how many are at stake. A database where nobody has used the
     * new page yet reverts cleanly.
     */
    public function down(MigrationBuilder $b): void
    {
        $downloadOnly = (int) $b->getDb()->createCommand(
            'SELECT COUNT(*) FROM `' . self::BATCHES . '` WHERE `import_mode` = \'DOWNLOAD_ONLY\'',
        )->queryScalar();

        if ($downloadOnly > 0) {
            throw new RuntimeException(
                'migrate:down aborted: ' . $downloadOnly . ' batch(es) were downloaded without being '
                . 'asked to transcribe, and this column is the only record of that. Clear them first.',
            );
        }

        $b->execute(
            'ALTER TABLE `' . self::BATCHES . '`
                DROP CHECK `chk_order58_call_import_batches_mode`,
                DROP COLUMN `import_mode`',
        );
    }
}
