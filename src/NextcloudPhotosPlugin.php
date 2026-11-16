<?php

declare(strict_types=1);

namespace Fahoba\NextcloudPhotos;

use Fahoba\PluginApi\Plugins\Catalog;
use Fahoba\PluginApi\Plugins\Plugin;

final class NextcloudPhotosPlugin extends Plugin
{
    protected function registerPlugin(): void
    {
        $this->app->make(Catalog::class)->offer('photos', NextcloudPhotoSource::KEY, NextcloudPhotoSource::class);

        // In register: boot already loads the German texts (see example-area).
        $this->loadJsonTranslationsFrom(__DIR__.'/../lang');
    }
}
