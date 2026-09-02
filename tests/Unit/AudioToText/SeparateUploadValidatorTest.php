<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Application\AudioUploadValidator;
use App\AudioToText\Application\SeparateUploadValidator;
use App\AudioToText\Domain\SourceRole;
use App\Tests\Support\AudioToTextSettingsFactory;
use HttpSoft\Message\Stream;
use HttpSoft\Message\UploadedFile;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\UploadedFileInterface;

use function fopen;
use function fwrite;
use function pack;
use function rewind;
use function str_repeat;
use function strlen;

use const UPLOAD_ERR_OK;

/**
 * A Customer recording and an Agent recording, validated as one submission.
 *
 * Every per-file rule still belongs to {@see AudioUploadValidator} and is tested there. What is tested
 * here is the part that only exists for a pair: which field an error belongs against, that both files
 * are reported at once, and the aggregate size limit that keeps a too-big pair from dying at the proxy
 * as a bare 413 the application never sees.
 */
final class SeparateUploadValidatorTest extends TestCase
{
    private const PER_FILE_BYTES = 4096;

    private SeparateUploadValidator $validator;

    protected function setUp(): void
    {
        $settings = AudioToTextSettingsFactory::create(maxUploadBytes: self::PER_FILE_BYTES);

        $this->validator = new SeparateUploadValidator(new AudioUploadValidator($settings), $settings);
    }

    public function testTwoGoodRecordingsAreAccepted(): void
    {
        self::assertSame([], $this->validator->validate(
            $this->wav('customer.wav'),
            $this->wav('agent.wav'),
        ));
    }

    /**
     * Both files are checked even though the first already failed.
     *
     * An administrator fixing one problem per submission — upload, rejected, upload, rejected — is a
     * worse experience than being told about both at once, and it is the reason this class does not
     * return on the first error.
     */
    public function testBothMissingRecordingsAreReportedTogether(): void
    {
        $errors = $this->validator->validate(null, null);

        self::assertArrayHasKey('customer_audio', $errors);
        self::assertArrayHasKey('agent_audio', $errors);
    }

    public function testAnErrorNamesTheRecordingItBelongsTo(): void
    {
        $errors = $this->validator->validate($this->wav('customer.wav'), null);

        self::assertArrayNotHasKey('customer_audio', $errors);
        self::assertArrayHasKey('agent_audio', $errors);
        self::assertStringStartsWith('Agent audio: ', $errors['agent_audio'][0]);
    }

    public function testAMissingCustomerRecordingIsReportedAgainstItsOwnField(): void
    {
        $errors = $this->validator->validate(null, $this->wav('agent.wav'));

        self::assertArrayHasKey('customer_audio', $errors);
        self::assertArrayNotHasKey('agent_audio', $errors);
        self::assertStringStartsWith('Customer audio: ', $errors['customer_audio'][0]);
    }

    public function testAnOversizedRecordingIsReportedAgainstItsOwnField(): void
    {
        $errors = $this->validator->validate(
            $this->wav('customer.wav', self::PER_FILE_BYTES + 1),
            $this->wav('agent.wav'),
        );

        self::assertArrayHasKey('customer_audio', $errors);
        self::assertArrayNotHasKey('form', $errors);
    }

    // --------------------------------------------------------------------- the aggregate rule

    /**
     * Any pair the per-file rule accepts also fits the combined ceiling — by construction.
     *
     * This is the invariant that matters, and it is worth pinning because it is easy to break: the
     * combined limit is two files at the per-file ceiling *plus* room for the multipart envelope, so a
     * submission cannot pass file-by-file and then be refused as a whole. Derive the aggregate any
     * other way — a flat number, a fraction of the proxy's limit — and this stops holding.
     *
     * The runtime aggregate check is therefore a guard on that derivation rather than a rule that
     * fires in normal use. What the number is really for is the two places that have to agree with it:
     * the sentence on the upload page, and the `client_max_body_size` the operator sets in front of
     * PHP. Neither can be computed from the per-file limit alone.
     */
    public function testAnyPairThePerFileRuleAcceptsFitsTheCombinedLimit(): void
    {
        $errors = $this->validator->validate(
            $this->wav('customer.wav', self::PER_FILE_BYTES),
            $this->wav('agent.wav', self::PER_FILE_BYTES),
        );

        self::assertSame([], $errors, 'Two files at exactly the per-file limit must be accepted.');
        self::assertGreaterThanOrEqual(
            self::PER_FILE_BYTES * 2,
            $this->validator->aggregateLimitBytes(),
        );
    }

    /**
     * Derived from the per-file limit rather than configured separately.
     *
     * A second setting an operator could set inconsistently with the first would only create a new way
     * to be wrong — so the ceiling is two files plus room for the multipart envelope, always.
     */
    public function testTheCombinedLimitLeavesRoomForTheMultipartEnvelope(): void
    {
        self::assertGreaterThan(self::PER_FILE_BYTES * 2, $this->validator->aggregateLimitBytes());
    }

    public function testEachRoleKnowsItsFormField(): void
    {
        self::assertSame('customer_audio', $this->validator->fieldFor(SourceRole::Customer));
        self::assertSame('agent_audio', $this->validator->fieldFor(SourceRole::Agent));
        self::assertSame('audio', $this->validator->fieldFor(SourceRole::Common));
    }

    // ------------------------------------------------------------------------------ helpers

    private function wav(string $filename, ?int $padTo = null): UploadedFileInterface
    {
        $bytes = $this->wavBytes();

        if ($padTo !== null && $padTo > strlen($bytes)) {
            $bytes .= str_repeat("\0", $padTo - strlen($bytes));
        }

        return new UploadedFile(
            $this->streamOf($bytes),
            strlen($bytes),
            UPLOAD_ERR_OK,
            $filename,
            'application/octet-stream',
        );
    }

    private function streamOf(string $contents): Stream
    {
        $resource = fopen('php://temp', 'r+');
        fwrite($resource, $contents);
        rewind($resource);

        return new Stream($resource);
    }

    /** A minimal but genuine RIFF/WAVE header — enough for libmagic to identify it as audio. */
    private function wavBytes(): string
    {
        return 'RIFF' . pack('V', 36) . 'WAVEfmt ' . pack('V', 16) . pack('v', 1) . pack('v', 1)
            . pack('V', 8000) . pack('V', 16000) . pack('v', 2) . pack('v', 16) . 'data' . pack('V', 0);
    }
}
