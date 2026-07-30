<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Infrastructure\Log\SecretRedactor;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringContainsString;
use function PHPUnit\Framework\assertStringNotContainsString;

final class SecretRedactorTest extends Unit
{
    private const API_KEY = 'sk-proj-AbCdEfGh1234567890IjKlMnOp';

    public function testRemovesTheConfiguredLiteralKey(): void
    {
        $redactor = new SecretRedactor([self::API_KEY]);

        $result = $redactor->redact('Request failed with key ' . self::API_KEY . ' attached.');

        assertStringNotContainsString(self::API_KEY, $result);
        assertStringContainsString(SecretRedactor::PLACEHOLDER, $result);
    }

    /**
     * The literal list is seeded from configuration, but a provider error body can echo a key that is
     * no longer the configured one (after a rotation, say). The pattern rules are what cover that.
     */
    public function testRemovesUnknownOpenAiStyleKeys(): void
    {
        $redactor = new SecretRedactor();

        $result = $redactor->redact('Unauthorized: sk-someOtherKeyThatWasNeverConfigured123');

        assertStringNotContainsString('sk-someOtherKeyThatWasNeverConfigured123', $result);
    }

    public function testRemovesAuthorizationHeaders(): void
    {
        $redactor = new SecretRedactor();

        $result = $redactor->redact('Authorization: Bearer abcdef123456ghijkl');

        assertStringNotContainsString('abcdef123456ghijkl', $result);
        // The label is kept so a log record still says which header was involved.
        assertStringContainsString('Authorization: ', $result);
    }

    public function testIsCaseInsensitiveForHeaders(): void
    {
        $redactor = new SecretRedactor();

        assertStringNotContainsString(
            'zzzzzzzzzzzz',
            $redactor->redact('authorization: bearer zzzzzzzzzzzz'),
        );
    }

    public function testRemovesCredentialsFromJsonBodies(): void
    {
        $redactor = new SecretRedactor();

        $result = $redactor->redact('{"api_key":"abcdef1234567890","model":"gpt"}');

        assertStringNotContainsString('abcdef1234567890', $result);
        // Non-secret fields survive, because a redacted log record still has to be useful.
        assertStringContainsString('"model":"gpt"', $result);
    }

    public function testRemovesPasswordsFromConnectionStrings(): void
    {
        $redactor = new SecretRedactor();

        assertStringNotContainsString(
            'sup3rs3cret',
            $redactor->redact('SQLSTATE[HY000] mysql:host=db;password=sup3rs3cret;dbname=kf'),
        );
    }

    public function testRemovesEveryOccurrence(): void
    {
        $redactor = new SecretRedactor([self::API_KEY]);

        $result = $redactor->redact(self::API_KEY . ' then again ' . self::API_KEY);

        assertStringNotContainsString(self::API_KEY, $result);
    }

    /**
     * An unset credential is an empty string. Treating that as a literal to strike out would replace
     * every character boundary in the message, so short values are ignored by design.
     */
    public function testEmptyAndShortLiteralsAreIgnored(): void
    {
        $redactor = new SecretRedactor(['', 'abc']);

        assertSame('a plain message about abc', $redactor->redact('a plain message about abc'));
    }

    public function testLeavesOrdinaryTextAlone(): void
    {
        $redactor = new SecretRedactor([self::API_KEY]);
        $message = 'Document 42 finished indexing in 1200 ms.';

        assertSame($message, $redactor->redact($message));
    }

    public function testRedactsNestedContextArrays(): void
    {
        $redactor = new SecretRedactor([self::API_KEY]);

        $result = $redactor->redactContext([
            'document_id' => 42,
            'error_message' => 'failed with ' . self::API_KEY,
            'nested' => ['headers' => 'Authorization: Bearer abcdef123456'],
        ]);

        assertSame(42, $result['document_id']);
        assertStringNotContainsString(self::API_KEY, (string) $result['error_message']);
        /** @var array<string, string> $nested */
        $nested = $result['nested'];
        assertStringNotContainsString('abcdef123456', $nested['headers']);
    }

    public function testObjectsInContextAreNotSerialised(): void
    {
        $redactor = new SecretRedactor();

        $result = $redactor->redactContext(['thing' => new \stdClass()]);

        assertSame('<object>', $result['thing']);
    }

    /**
     * `documents.error_message` is a bounded column. Truncation must happen after redaction, never
     * before, or a cut could land mid-secret and leave a usable prefix behind.
     */
    public function testTruncatesAfterRedacting(): void
    {
        $redactor = new SecretRedactor([self::API_KEY]);

        $result = $redactor->redactAndTruncate(self::API_KEY . str_repeat('x', 500), 40);

        assertStringNotContainsString(self::API_KEY, $result);
        assertSame(40, mb_strlen($result));
    }

    public function testDoesNotTruncateShortMessages(): void
    {
        $redactor = new SecretRedactor();

        assertSame('short', $redactor->redactAndTruncate('short', 40));
    }
}
