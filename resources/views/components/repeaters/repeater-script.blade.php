{{-- Generic Repeater Pattern: add row (clone + replace names + clear), remove row. Dispatch repeater:row-added for section-specific init. --}}
<script>
(function() {
    function initRepeater(container) {
        if (!container || container.getAttribute('data-repeater-inited') === '1') return;
        var namePrefix = container.getAttribute('data-name-prefix');
        var rowClass = container.getAttribute('data-row-class');
        var minRows = parseInt(container.getAttribute('data-min-rows') || '1', 10);
        var containerId = container.id || '';
        var addBtn = containerId ? document.querySelector('[data-repeater-add][data-repeater-for="' + containerId + '"]') : null;
        if (!addBtn) addBtn = container.nextElementSibling;
        if (!addBtn || !addBtn.hasAttribute('data-repeater-add')) addBtn = null;

        if (addBtn && !addBtn._repeaterBound) {
            addBtn._repeaterBound = true;
            addBtn.addEventListener('click', function() {
                var rows = container.querySelectorAll('.' + rowClass);
                var last = rows[rows.length - 1];
                if (!last) return;
                var clone = last.cloneNode(true);
                var newIdx = rows.length;
                var escaped = namePrefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                var regex = new RegExp(escaped + '\\[\\d+\\]', 'g');
                clone.querySelectorAll('input, select, textarea').forEach(function(el) {
                    if (el.name) el.name = el.name.replace(regex, namePrefix + '[' + newIdx + ']');
                    if (el.type === 'checkbox') el.checked = false;
                    else if (el.type !== 'hidden') el.value = '';
                });
                var hid = clone.querySelector('input[type=hidden][name*="[id]"]');
                if (hid) hid.value = '';
                container.appendChild(clone);
                clone.dispatchEvent(new CustomEvent('repeater:row-added', { bubbles: true, detail: { row: clone, index: newIdx, container: container, namePrefix: namePrefix } }));
            });
        }

        container.addEventListener('click', function(e) {
            if (!e.target.hasAttribute('data-repeater-remove')) return;
            var row = e.target.closest('.' + rowClass);
            if (row && container.querySelectorAll('.' + rowClass).length > minRows) row.remove();
        });
        container.setAttribute('data-repeater-inited', '1');
    }
    document.querySelectorAll('[data-repeater-container]').forEach(initRepeater);
})();
</script>
