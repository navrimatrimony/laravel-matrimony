/**
 * Alpine state for /plans: optional coupon meta + per-card duration selection uses local x-data.
 */
import Alpine from 'alpinejs';

document.addEventListener('alpine:init', () => {
    Alpine.data('plansPricingCatalog', (initial) => ({
        couponCode: '',
        couponMeta: null,
        couponError: '',
        couponLoading: false,
        validateUrl: initial.validateUrl,
        csrf: initial.csrf,

        discountFor(planId, row) {
            const base = Number(row.final);
            if (!this.couponMeta || !this.couponMeta.valid) {
                return { final: base, savings: 0, active: false };
            }
            const { type, value, plan_ids, duration_types } = this.couponMeta;
            if (Array.isArray(plan_ids) && plan_ids.length > 0) {
                const ok = plan_ids.map(Number).includes(Number(planId));
                if (!ok) {
                    return { final: base, savings: 0, active: false };
                }
            }
            if (Array.isArray(duration_types) && duration_types.length > 0) {
                if (!duration_types.includes(row.duration_type)) {
                    return { final: base, savings: 0, active: false };
                }
            }
            let off = 0;
            if (type === 'percent') {
                off = Math.round(base * (Number(value) / 100) * 100) / 100;
            } else if (type === 'fixed') {
                off = Math.min(base, Number(value));
            }
            const final = Math.round(Math.max(0, base - off) * 100) / 100;

            return { final, savings: Math.round((base - final) * 100) / 100, active: true };
        },

        async validateCoupon() {
            this.couponLoading = true;
            this.couponError = '';
            try {
                const r = await fetch(this.validateUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({ code: this.couponCode }),
                });
                const data = await r.json();
                if (!data.valid) {
                    this.couponMeta = null;
                    this.couponError = data.message || '';
                } else {
                    this.couponMeta = data;
                }
            } catch {
                this.couponMeta = null;
                this.couponError = 'Network error';
            }
            this.couponLoading = false;
        },

        clearCoupon() {
            this.couponMeta = null;
            this.couponError = '';
            this.couponCode = '';
        },
    }));
});
