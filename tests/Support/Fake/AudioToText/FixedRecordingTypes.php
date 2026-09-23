<?php

declare(strict_types=1);

namespace App\Tests\Support\Fake\AudioToText;

use App\AudioToText\Domain\AudioConversation;
use App\AudioToText\Domain\AudioConversationRepositoryInterface;
use App\AudioToText\Domain\ConversationMode;
use App\AudioToText\Domain\RecordingType;
use DateTimeImmutable;
use RuntimeException;

/**
 * The one question {@see \App\AudioToText\Application\RecordingVoiceReader} asks, answered from a map.
 *
 * A unit test that builds a TTS script has no database and needs none: what it is exercising is which
 * voice a declared recording type selects. Everything else on the interface throws, so a test that
 * starts depending on it fails loudly rather than silently reading a default.
 *
 * @psalm-suppress MissingImmutableAnnotation the interface is not readonly
 */
final class FixedRecordingTypes implements AudioConversationRepositoryInterface
{
    /** @param ?RecordingType $type what every conversation in this test declared */
    public function __construct(private ?RecordingType $type) {}

    public static function everything(?RecordingType $type): self
    {
        return new self($type);
    }

    public function recordingTypeFor(int $conversationId): ?RecordingType
    {
        return $this->type;
    }

    public function create(
        string $publicId,
        ?int $storeSourceId,
        ConversationMode $mode,
        int $uploadedByAdminId,
        DateTimeImmutable $createdAt,
        bool $generateAiAudio = false,
        ?RecordingType $recordingType = null,
        ?string $orderId = null,
    ): int {
        throw new RuntimeException('Not used by these tests.');
    }

    public function findByPublicId(string $publicId): ?AudioConversation
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function forStore(int $storeSourceId, int $limit, int $offset = 0): array
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function countForStore(int $storeSourceId): int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function storeSourceIdFor(int $conversationId): ?int
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function publicIdFor(int $conversationId): ?string
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function generatesAiAudio(int $conversationId): bool
    {
        throw new RuntimeException('Not used by these tests.');
    }

    public function deleteChildless(): int
    {
        throw new RuntimeException('Not used by these tests.');
    }
}
