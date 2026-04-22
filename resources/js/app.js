import './bootstrap';

import Alpine from 'alpinejs';
import { plansPricingCatalog } from './plans-catalog';
import { planQuotaPolicyCard } from './plan-quota-policy-card';
import { adminPlanAudienceHeader, adminPlanBillingForm } from './admin-plan-billing-form';
import { initLaravelValidationUi } from './laravel-validation-ui';

window.Alpine = Alpine;
window.planQuotaPolicyCard = planQuotaPolicyCard;
/** Plan form blade uses `x-data="window.adminPlanBillingForm({ ... })"` (same pattern as quota cards). */
window.adminPlanBillingForm = adminPlanBillingForm;
window.adminPlanAudienceHeader = adminPlanAudienceHeader;
window.plansPricingCatalog = plansPricingCatalog;

Alpine.data('adminPlanBillingForm', adminPlanBillingForm);
Alpine.data('adminPlanAudienceHeader', adminPlanAudienceHeader);
Alpine.data('plansPricingCatalog', plansPricingCatalog);

Alpine.start();

/**
 * Admin plan form: "+ Add period" lives inside `x-data="window.adminPlanBillingForm(...)"`, but some
 * browsers/extensions evaluate `@click="addRow()"` outside that Alpine scope → ReferenceError.
 * Delegate from the real DOM root so we call `addRow` on `._x_dataStack[0]` for `.js-admin-plan-billing-root`.
 */
document.addEventListener(
    'click',
    (e) => {
        const btn = e.target.closest('[data-billing-add-period]');
        if (!btn) {
            return;
        }
        const root = btn.closest('.js-admin-plan-billing-root');
        const stack = root && root._x_dataStack;
        const scope = stack && stack.length ? stack[0] : null;
        if (scope && typeof scope.addRow === 'function') {
            e.preventDefault();
            scope.addRow();
        }
    },
    true
);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLaravelValidationUi);
} else {
    initLaravelValidationUi();
}

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
