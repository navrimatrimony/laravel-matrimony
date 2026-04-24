import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                'resources/js/profile/religion-caste-selector.js',
                'resources/js/profile/location-typeahead.js',
                'resources/js/profile/about-me-narrative.js',
                'resources/js/intake-preview-crop.js',
                'resources/js/admin/suggestions-review.js',
                'resources/js/matrimony/occupation-engine-entry.js',
            ],
            // Narrow refresh paths: compiled Blade lives under storage/framework/views and must NOT
            // trigger full reloads (it interrupts POST /subscribe and feels like “only refresh”).
            refresh: [
                'routes/**',
                'resources/views/**',
                'app/**',
                'config/**',
                'lang/**',
                'resources/lang/**',
            ],
        }),
    ],
});
