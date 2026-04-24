import './bootstrap';

import './floating-panels';

import Alpine from 'alpinejs';
import { plansPricingCatalog } from './plans-catalog';
import { planQuotaPolicyCard } from './plan-quota-policy-card';
import './admin-plan-term-rows';
import { initLaravelValidationUi } from './laravel-validation-ui';

window.Alpine = Alpine;
window.planQuotaPolicyCard = planQuotaPolicyCard;
window.plansPricingCatalog = plansPricingCatalog;

Alpine.data('plansPricingCatalog', plansPricingCatalog);

Alpine.start();

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

/** Rule engine flash: modal actions + JSON fetch helpers (RuleResult-shaped API payloads). */
function initRuleActionFlash() {
    document.querySelectorAll('[data-rule-action-kind="modal"]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-rule-modal-id') || '';
            const modal = id ? document.getElementById(id) : null;
            if (modal && typeof modal.showModal === 'function') {
                modal.showModal();
            }
        });
    });
}

/**
 * @param {Record<string, unknown>} payload Parsed JSON from fetch/XHR (success + RuleResult fields).
 * @returns {boolean} false if rule failure was handled; true if caller should continue success path.
 */
window.handleRuleEngineJsonPayload = function handleRuleEngineJsonPayload(payload) {
    if (!payload) {
        return true;
    }
    if (payload.success !== false && payload.allowed !== false) {
        return true;
    }
    const message = typeof payload.message === 'string' ? payload.message : '';
    if (window.toastr && typeof window.toastr.error === 'function') {
        window.toastr.error(message);
    } else if (message) {
        window.alert(message);
    }
    const action = payload.action;
    if (action && typeof action === 'object') {
        if (action.type === 'redirect' && action.url) {
            window.location.href = String(action.url);
            return false;
        }
        if (action.type === 'modal' && action.modal_id) {
            const modal = document.getElementById(String(action.modal_id));
            if (modal && typeof modal.showModal === 'function') {
                modal.showModal();
            }
        }
    }
    return false;
};

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRuleActionFlash);
} else {
    initRuleActionFlash();
}
