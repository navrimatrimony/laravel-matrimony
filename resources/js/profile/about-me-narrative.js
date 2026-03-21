/**
 * Quick template chips for About me / Expectations textareas (wizard & full form).
 */
(function () {
    'use strict';

    function parsePayload(root) {
        const script = root.querySelector('script[data-narrative-json]');
        if (!script) {
            return null;
        }
        try {
            return JSON.parse(script.textContent);
        } catch {
            return null;
        }
    }

    function templateText(payload, group, index) {
        if (!payload || !payload[group] || !Array.isArray(payload[group])) {
            return null;
        }
        const row = payload[group][index];
        if (row == null) {
            return null;
        }
        if (typeof row === 'string') {
            return row;
        }
        if (typeof row === 'object' && row.text != null) {
            return String(row.text);
        }

        return null;
    }

    document.addEventListener('click', function (e) {
        const btn = e.target.closest('[data-narrative-template]');
        if (!btn) {
            return;
        }
        const root = btn.closest('[data-narrative-templates-root]');
        if (!root) {
            return;
        }
        const payload = parsePayload(root);
        if (!payload) {
            return;
        }
        const group = btn.getAttribute('data-narrative-group');
        const index = parseInt(btn.getAttribute('data-narrative-index'), 10);
        const targetId = btn.getAttribute('data-narrative-target');
        const ta = targetId ? document.getElementById(targetId) : null;
        const text = templateText(payload, group, index);
        if (!ta || text == null) {
            return;
        }
        ta.value = text;
        ta.focus();
        ta.dispatchEvent(new Event('input', { bubbles: true }));
        ta.dispatchEvent(new Event('change', { bubbles: true }));
    });
})();
