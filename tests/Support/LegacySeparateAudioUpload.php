<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Codeception\Module\PhpBrowser;

/** Exercises the retained paired-upload endpoint now that its form is no longer shown. */
trait LegacySeparateAudioUpload
{
    private PhpBrowser $audioBrowser;

    public function _inject(PhpBrowser $browser): void
    {
        $this->audioBrowser = $browser;
    }

    /** @param array<string, string> $filenames Field name => fixture filename. */
    private function postSeparateAudio(WebTester $I, string $url, array $filenames = []): void
    {
        $I->amOnPage($url);
        $csrfSelector = '#a2t-common-form input[type="hidden"]:not([name="mode"])';
        $csrfName = $I->grabAttributeFrom($csrfSelector, 'name');
        $csrfValue = $I->grabAttributeFrom($csrfSelector, 'value');
        $files = [];
        foreach ($filenames as $field => $filename) {
            $files[$field] = ['name' => $filename, 'tmp_name' => codecept_data_dir($filename)];
        }

        $this->audioBrowser->_loadPage('POST', $url, [
            $csrfName => $csrfValue,
            'mode' => 'SEPARATE',
            'transcription_provider' => 'WHISPER',
        ], $files);
    }
}
