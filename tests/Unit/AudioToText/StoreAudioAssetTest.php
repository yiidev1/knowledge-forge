<?php

declare(strict_types=1);

namespace App\Tests\Unit\AudioToText;

use App\AudioToText\Web\Job\Store\StoreAudioAsset;
use App\Web\Shared\Layout\Admin\AdminAsset;
use PHPUnit\Framework\TestCase;
use Yiisoft\Aliases\Aliases;
use Yiisoft\Assets\AssetLoader;
use Yiisoft\Assets\AssetManager;
use Yiisoft\Assets\AssetPublisher;
use Yiisoft\Files\FileHelper;

final class StoreAudioAssetTest extends TestCase
{
    public function testStoreAssetsPublishIndependentlyOfAnAlreadyPublishedAdminBundle(): void
    {
        $directory = sys_get_temp_dir() . '/kf-store-assets-' . bin2hex(random_bytes(6));
        $aliases = new Aliases([
            '@assets' => $directory,
            '@assetsUrl' => '/knowledge-forge/assets',
            '@assetsSource' => dirname(__DIR__, 3) . '/assets',
        ]);
        $manager = static fn(): AssetManager => (new AssetManager($aliases, new AssetLoader($aliases)))
            ->withPublisher(new AssetPublisher($aliases));

        try {
            // Simulate a different admin page having populated the asset cache first.
            $admin = $manager();
            $admin->register(AdminAsset::class);
            self::assertCount(1, $admin->getJsFiles());
            self::assertCount(1, $admin->getCssFiles());
            self::assertStringNotContainsString('audio-store', json_encode($admin->getJsFiles(), JSON_THROW_ON_ERROR));

            // A separate request must find both store files, without changing the shared bundle.
            $store = $manager();
            $store->register(StoreAudioAsset::class);
            self::assertCount(2, $store->getJsFiles());
            self::assertCount(2, $store->getCssFiles());
            foreach (array_merge($store->getCssFiles(), $store->getJsFiles()) as $file) {
                self::assertStringStartsWith('/knowledge-forge/assets/', $file[0]);
                self::assertFileExists(str_replace('/knowledge-forge/assets', $directory, $file[0]));
            }
            self::assertStringContainsString('/audio-store/', array_values($store->getJsFiles())[1][0]);
            self::assertStringContainsString('/audio-store/', array_values($store->getCssFiles())[1][0]);
        } finally {
            FileHelper::removeDirectory($directory);
        }
    }
}
