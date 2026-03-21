import './bootstrap';

import Alpine from 'alpinejs';

window.Alpine = Alpine;

Alpine.start();

/** Flash banners in layouts.app: auto-dismiss + manual close so stacked messages do not linger. */
function initFlashDismiss() {
    document.querySelectorAll('[data-flash-dismissible]').forEach((el) => {
        const close = () => {
            el.classList.add('opacity-0', 'translate-y-[-4px]', 'transition-all', 'duration-200');
            setTimeout(() => el.remove(), 220);
        };
        el.querySelectorAll('[data-flash-close]').forEach((btn) => {
            btn.addEventListener('click', close);
        });
        const ms = parseInt(el.getAttribute('data-flash-auto-ms') || '7000', 10);
        if (ms > 0) {
            setTimeout(close, ms);
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initFlashDismiss);
} else {
    initFlashDismiss();
}
