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
            ],
            refresh: true,
        }),
    ],
});
