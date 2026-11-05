<?php

declare(strict_types=1);

namespace App\Tests\Unit\Practice;

use App\Practice\Domain\ExcludedStoreIds;
use PHPUnit\Framework\TestCase;

/**
 * `PRACTICE_TOPIC_EXCLUDED_STORE_IDS`, read leniently: a typo drops one id, it never stops the app.
 */
final class ExcludedStoreIdsTest extends TestCase
{
    public function testTheConfiguredListIsReadAsStoreIds(): void
    {
        $ids = ExcludedStoreIds::fromSetting('41,61,71,151,231,241,251,261,271,291,321,371,561,1891');

        self::assertSame([41, 61, 71, 151, 231, 241, 251, 261, 271, 291, 321, 371, 561, 1891], $ids->ids);
        self::assertTrue($ids->contains(61));
        self::assertFalse($ids->contains(1731), 'a real store is not excluded');
    }

    public function testSpacesDuplicatesAndOrderDoNotMatter(): void
    {
        self::assertSame([61, 71], ExcludedStoreIds::fromSetting(' 71 , 61,61 ')->ids);
    }

    public function testATokenThatIsNotAPositiveWholeNumberIsIgnored(): void
    {
        self::assertSame([61], ExcludedStoreIds::fromSetting('61,abc,,0,-5,7.5,0071')->ids);
    }

    public function testEmptyExcludesNothing(): void
    {
        self::assertTrue(ExcludedStoreIds::fromSetting('')->isEmpty());
        self::assertTrue(ExcludedStoreIds::fromSetting(' , ')->isEmpty());
        self::assertTrue((new ExcludedStoreIds())->isEmpty());
    }
}
