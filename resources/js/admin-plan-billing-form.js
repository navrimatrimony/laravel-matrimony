/**
 * Admin plan form: dynamic billing periods + duration preset (Alpine).
 */

function slugifyAscii(s) {
    try {
        return s
            .normalize('NFKD')
            .replace(/[\u0300-\u036f]/g, '')
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .replace(/-+/g, '-');
    } catch {
        return '';
    }
}

async function sha256First10Hex(str) {
    if (typeof crypto === 'undefined' || !crypto.subtle) {
        return '0000000000';
    }
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));

    return Array.from(new Uint8Array(buf))
        .slice(0, 5)
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

function catalogSuffix(g) {
    const x = String(g || 'all').toLowerCase();
    if (x === 'male') {
        return '-male';
    }
    if (x === 'female') {
        return '-female';
    }

    return '-all';
}

function buildCatalogSlugPreview(planName, appliesToGender, slugModel, slugHash10) {
    if ((slugModel || '').toLowerCase() === 'free') {
        return 'free';
    }
    let base = slugifyAscii(planName || '');
    if (!base) {
        const h = (slugHash10 || '0000000000').slice(0, 10);
        base = `p-${h}`;
    }
    const suffix = catalogSuffix(appliesToGender);
    const maxBase = Math.max(1, 64 - suffix.length);
    let basePart = base.slice(0, maxBase).replace(/-+$/g, '');
    if (!basePart) {
        basePart = 'plan';
    }

    return basePart + suffix;
}

function planAudienceLabelState() {
    return {
        get planAudienceLabel() {
            const raw = (this.planName || '').trim();
            return raw.length ? raw : '—';
        },
    };
}

/** Read-only “PlanName_Male” / “PlanName_Female” / “PlanName” (all); free-plan slug row uses fixed “free”. */
export function adminPlanAudienceHeader(config) {
    return {
        planName: config.planName ?? '',
        appliesToGender: config.appliesToGender ?? 'all',
        slug: 'free',
        ...planAudienceLabelState(),
        get catalogSlugPreview() {
            return 'free';
        },
    };
}

export function adminPlanBillingForm(config) {
    const billingLabels = config.billingLabels || {};
    const initialHash = config.initialPlanNameSha10 ?? '0000000000';

    return {
        slug: config.slug ?? '',
        planName: config.planName ?? '',
        appliesToGender: config.appliesToGender ?? 'all',
        initialPlanNameSha10: initialHash,
        _slugHash10: initialHash,
        ...planAudienceLabelState(),
        rows: Array.isArray(config.rows) ? [...config.rows] : [],
        durationPreset: config.durationPreset ?? 'monthly',
        defaultBilling: config.defaultBilling ?? '',
        presets: Array.isArray(config.presets) ? config.presets : [],
        billingPanelNotice: '',
        msgAllPeriodsAdded: config.msgAllPeriodsAdded ?? '',
        msgNoPresetList: config.msgNoPresetList ?? '',

        init() {
            if (!this.rows.length) {
                this.rows = [{
                    billing_key: 'monthly',
                    price: 0,
                    discount_percent: null,
                    is_visible: true,
                }];
            }

            const rowKeys = () => this.rows.map((r) => r.billing_key);
            const pickDefault = () => {
                const keys = rowKeys();
                if (keys.length === 0) {
                    return 'monthly';
                }
                const fromPlan = typeof this.defaultBilling === 'string' ? this.defaultBilling.trim() : '';
                if (fromPlan !== '' && keys.includes(fromPlan)) {
                    return fromPlan;
                }
                const cur = String(this.durationPreset || '');
                if (cur !== '' && keys.includes(cur)) {
                    return cur;
                }

                return keys[0];
            };
            this.durationPreset = pickDefault();

            this._slugHash10 = this.initialPlanNameSha10;
            void this.refreshSlugHash();
            this.$watch('planName', () => {
                void this.refreshSlugHash();
            });

            this.$watch(
                'rows',
                () => {
                    const keys = rowKeys();
                    if (keys.length && !keys.includes(this.durationPreset)) {
                        this.durationPreset = keys[0];
                    }
                },
                { deep: true }
            );
        },

        syncDefaultBillingKeyPresence() {
            const keys = this.rows.map((r) => r.billing_key);
            if (keys.length && !keys.includes(this.durationPreset)) {
                this.durationPreset = keys[0];
            }
        },

        async refreshSlugHash() {
            this._slugHash10 = await sha256First10Hex(this.planName || '');
        },

        get catalogSlugPreview() {
            return buildCatalogSlugPreview(
                this.planName,
                this.appliesToGender,
                this.slug,
                this._slugHash10
            );
        },

        billingLabel(k) {
            return billingLabels[k] || k;
        },

        addRow() {
            if (!Array.isArray(this.presets) || this.presets.length === 0) {
                this.billingPanelNotice = this.msgNoPresetList || 'Billing presets are not configured.';

                return;
            }
            const next = this.presets.find((k) => !this.rows.some((r) => r.billing_key === k));
            if (!next) {
                this.billingPanelNotice = this.msgAllPeriodsAdded || 'All billing periods are already added for this plan.';

                return;
            }
            this.billingPanelNotice = '';
            const monthlyRow = this.rows.find((r) => r.billing_key === 'monthly');
            const monthly = monthlyRow && Number(monthlyRow.price) > 0 ? Number(monthlyRow.price) : 0;
            const mult = { quarterly: 3, half_yearly: 6, yearly: 12, five_yearly: 60 };
            let price = 0;
            if (monthly > 0 && next !== 'lifetime' && mult[next]) {
                price = Math.round(monthly * mult[next] * 100) / 100;
            }
            this.rows.push({
                billing_key: next,
                price,
                discount_percent: null,
                is_visible: true,
            });
        },

        removeRow(index) {
            if (this.rows.length <= 1) {
                return;
            }
            this.rows.splice(index, 1);
        },
    };
}
