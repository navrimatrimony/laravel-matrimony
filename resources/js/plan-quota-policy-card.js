/**
 * Admin plan form: quota policy card (Alpine state + live summary).
 * Factory receives JSON-safe initial state from Blade (@json).
 */
function boolFromInitial(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

export function planQuotaPolicyCard(initial) {
    return {
        phaseEnabled: boolFromInitial(initial.phaseEnabled),
        purchasableIfExhausted: boolFromInitial(initial.purchasableIfExhausted),
        perDayLimit: boolFromInitial(initial.perDayLimit),
        refreshType: String(initial.refreshType ?? ''),
        refreshUnlimited: String(initial.refreshUnlimited ?? ''),
        limitVal: initial.limitVal != null ? String(initial.limitVal) : '',
        dailyCapVal: initial.dailyCapVal != null ? String(initial.dailyCapVal) : '',
        packPrice: initial.packPrice != null ? String(initial.packPrice) : '',
        packMsgs: initial.packMsgs != null ? String(initial.packMsgs) : '',
        packDays: initial.packDays != null ? String(initial.packDays) : '',
        refreshLabels: initial.refreshLabels && typeof initial.refreshLabels === 'object' ? initial.refreshLabels : {},
        sum: initial.sum && typeof initial.sum === 'object' ? initial.sum : {},

        /** Strip leading zeros / junk; empty if NaN. Preserves 0 as "0". */
        coerceNonNegIntField(prop) {
            const raw = this[prop];
            const s = String(raw ?? '').trim();
            if (s === '') {
                return;
            }
            const n = parseInt(s, 10);
            if (Number.isNaN(n)) {
                this[prop] = '';
                return;
            }
            this[prop] = String(Math.max(0, n));
        },

        /** Pack counts/days: integer >= 1 or empty (backend treats empty as unset). */
        coercePackIntField(prop) {
            const raw = this[prop];
            const s = String(raw ?? '').trim();
            if (s === '') {
                return;
            }
            const n = parseInt(s, 10);
            if (Number.isNaN(n) || n < 1) {
                this[prop] = '';
                return;
            }
            this[prop] = String(n);
        },

        phase1SummaryLine() {
            const s = this.sum;
            const sep = s.sep ?? ' · ';
            const bits = [];
            bits.push(this.phaseEnabled ? s.quotaOn : s.quotaOff);
            bits.push(`${s.refresh}: ${this.refreshLabels[this.refreshType] || this.refreshType}`);
            if (this.refreshType === this.refreshUnlimited) {
                bits.push(`${s.limit}: ${s.limitUnlimited}`);
            } else {
                const lv = String(this.limitVal ?? '').trim();
                bits.push(`${s.limit}: ${lv === '' ? '0' : lv}`);
            }
            if (this.perDayLimit) {
                const d = String(this.dailyCapVal ?? '').trim();
                bits.push(`${s.perDay}: ${d === '' ? s.dash : d}`);
            }
            if (this.purchasableIfExhausted) {
                const pr = String(this.packPrice ?? '').trim();
                const ms = String(this.packMsgs ?? '').trim();
                const da = String(this.packDays ?? '').trim();
                if (pr || ms || da) {
                    bits.push(
                        `${s.topup}: ${s.topupDetail.replace(':price', pr || s.dash).replace(':msgs', ms || s.dash).replace(':days', da || s.dash)}`,
                    );
                } else {
                    bits.push(s.topupEnter);
                }
            }
            return bits.join(sep);
        },
    };
}
