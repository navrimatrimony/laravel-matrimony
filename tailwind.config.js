import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
        },
    },

    plugins: [
        forms,
        // Tailwind 3.2+ ships `landscape:`; we are on 3.1.x — register it so profile layout works in horizontal mode.
        function ({ addVariant }) {
            addVariant('landscape', '@media (orientation: landscape) { & }');
        },
    ],
};
