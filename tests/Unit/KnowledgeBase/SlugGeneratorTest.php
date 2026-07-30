<?php

declare(strict_types=1);

namespace App\Tests\Unit\KnowledgeBase;

use App\KnowledgeBase\Application\SlugGenerator;
use App\Tests\Support\Fake\KnowledgeBase\InMemoryKnowledgeBaseRepository;
use Codeception\Test\Unit;

use function PHPUnit\Framework\assertMatchesRegularExpression;
use function PHPUnit\Framework\assertSame;
use function PHPUnit\Framework\assertStringStartsWith;

final class SlugGeneratorTest extends Unit
{
    private InMemoryKnowledgeBaseRepository $repository;
    private SlugGenerator $generator;

    protected function _before(): void
    {
        $this->repository = new InMemoryKnowledgeBaseRepository();
        $this->generator = new SlugGenerator($this->repository);
    }

    public function testLowercasesAndHyphenates(): void
    {
        assertSame('hr-policies', $this->generator->generate('HR Policies'));
    }

    public function testCollapsesRunsOfNonAlphanumerics(): void
    {
        assertSame('a-b-c', $this->generator->generate('  A ... B  ///  C  '));
    }

    public function testDropsNonAsciiLettersAsSeparators(): void
    {
        // Non-ASCII letters are treated as separators (not transliterated), so "café" becomes "caf-".
        // A usable, URL-safe slug still results.
        assertSame('caf-menu', $this->generator->generate('café menu'));
    }

    public function testAppendsASuffixOnCollision(): void
    {
        $this->repository->create('Existing', 'hr-policies', null, null);

        assertSame('hr-policies-2', $this->generator->generate('HR Policies'));
    }

    public function testIncrementsUntilFree(): void
    {
        $this->repository->create('A', 'report', null, null);
        $this->repository->create('B', 'report-2', null, null);

        assertSame('report-3', $this->generator->generate('Report'));
    }

    public function testFallsBackWhenNameSlugifiesToEmpty(): void
    {
        assertSame('knowledge-base', $this->generator->generate('!!!'));
    }

    public function testTruncatesOverlongNames(): void
    {
        $slug = $this->generator->generate(str_repeat('a', 500));

        assertSame(160, strlen($slug));
        assertStringStartsWith('aaaa', $slug);
    }

    public function testProducesUrlSafeOutput(): void
    {
        assertMatchesRegularExpression('/^[a-z0-9-]+$/', $this->generator->generate('Q3 — Финансы & Reports!'));
    }
}
