<?php

namespace App\Services\Admin;

use App\Models\HomepageSectionImage;
use Illuminate\Support\Facades\Cache;

class HomepageImageService
{
    /**
     * Get full URL (asset) for a section image. Uses DB if set, else default path.
     */
    public function url(string $sectionKey): ?string
    {
        $paths = $this->allPaths();
        $path = $paths[$sectionKey] ?? null;

        return $path ? asset($path) : null;
    }

    /**
     * Get all section keys => image path (relative to public). Cached briefly.
     *
     * @return array<string, string|null>
     */
    public function allPaths(): array
    {
        return Cache::remember('homepage_section_images', 300, function () {
            $rows = HomepageSectionImage::all()->keyBy('section_key');
            $out = [];
            foreach (HomepageSectionImage::SECTIONS as $key => $label) {
                $out[$key] = $rows->get($key)?->image_path ?? HomepageSectionImage::DEFAULTS[$key] ?? null;
            }

            return $out;
        });
    }

    /**
     * Set image path for a section (store upload path) and clear cache.
     */
    public function set(string $sectionKey, ?string $imagePath): void
    {
        HomepageSectionImage::updateOrCreate(
            ['section_key' => $sectionKey],
            ['image_path' => $imagePath]
        );
        Cache::forget('homepage_section_images');
    }
}
