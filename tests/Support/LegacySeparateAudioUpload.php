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
        // The page now has one upload form rather than three cards. The token is still a rendered
        // hidden input inside it, which is the only thing this helper ever needed from the markup.
        $csrfSelector = '#a2t-upload-form input[type="hidden"][name="_csrf"]';
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
