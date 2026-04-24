(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        init();
    });

    function init() {
        var root = document.getElementById('suggestions-review-root');
        var form = document.getElementById('review-apply-form');
        var payloadInput = document.getElementById('review_payload');
        var expectedMapEl = document.getElementById('expected-current-map');
        var fieldCardsEl = document.getElementById('field-cards');
        var filterActionable = document.getElementById('filter-actionable');

        if (!root || !form || !payloadInput || !fieldCardsEl) {
            return;
        }

        var expectedMap = {};
        if (expectedMapEl && expectedMapEl.textContent) {
            try {
                expectedMap = JSON.parse(expectedMapEl.textContent) || {};
            } catch (e) {
                expectedMap = {};
            }
        }

        var safeTh = parseFloat(root.getAttribute('data-safe-threshold') || '0.85', 10);
        if (isNaN(safeTh)) {
            safeTh = 0.85;
        }

        var decisions = {};
        var filterMode = 'all';

        function normalizeDisplay(s) {
            return String(s || '').replace(/\s+/g, ' ').trim();
        }

        function cardByRowId(rowId) {
            var found = null;
            root.querySelectorAll('[data-field-card]').forEach(function (c) {
                if (c.getAttribute('data-row-id') === rowId) {
                    found = c;
                }
            });
            return found;
        }

        function priorityScore(card) {
            var hc = card.getAttribute('data-has-conflict') === '1' ? 1 : 0;
            var low = card.getAttribute('data-lowconf') === '1' ? 1 : 0;
            var ch = card.getAttribute('data-identical') === '0' ? 1 : 0;
            return hc * 100 + low * 10 + ch;
        }

        function sortCardsByPriority() {
            var cards = Array.prototype.slice.call(
                fieldCardsEl.querySelectorAll('[data-field-card]')
            );
            if (cards.length <= 1) {
                return;
            }
            cards.sort(function (a, b) {
                return priorityScore(b) - priorityScore(a);
            });
            cards.forEach(function (c) {
                fieldCardsEl.appendChild(c);
            });
        }

        function visibleFieldCards() {
            return Array.prototype.filter.call(
                root.querySelectorAll('[data-field-card]'),
                function (c) {
                    return c.offsetParent !== null;
                }
            );
        }

        function getNextVisibleCard(currentCard) {
            if (!currentCard) {
                return null;
            }
            var vis = visibleFieldCards();
            var idx = vis.indexOf(currentCard);
            if (idx < 0 || !vis[idx + 1]) {
                return null;
            }
            return vis[idx + 1];
        }

        function highlightCard(el) {
            if (!el || !el.offsetParent) {
                return;
            }
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add(
                'ring-2',
                'ring-blue-400',
                'ring-offset-2',
                'dark:ring-offset-gray-900',
                'rounded-lg'
            );
            window.setTimeout(function () {
                el.classList.remove(
                    'ring-2',
                    'ring-blue-400',
                    'ring-offset-2',
                    'dark:ring-offset-gray-900',
                    'rounded-lg'
                );
            }, 1800);
        }

        function updateSummary() {
            var cards = root.querySelectorAll('[data-field-card]');
            var total = cards.length;
            var acc = 0;
            var rej = 0;
            var flg = 0;
            Object.keys(decisions).forEach(function (k) {
                if (decisions[k] === 'accept') {
                    acc++;
                } else if (decisions[k] === 'reject') {
                    rej++;
                } else if (decisions[k] === 'flag') {
                    flg++;
                }
            });
            var set = acc + rej + flg;
            var byId = function (id) {
                return document.getElementById(id);
            };
            if (byId('sum-total')) {
                byId('sum-total').textContent = String(total);
            }
            if (byId('sum-accept')) {
                byId('sum-accept').textContent = String(acc);
            }
            if (byId('sum-reject')) {
                byId('sum-reject').textContent = String(rej);
            }
            if (byId('sum-flag')) {
                byId('sum-flag').textContent = String(flg);
            }
            if (byId('sum-unset')) {
                byId('sum-unset').textContent = String(Math.max(0, total - set));
            }
        }

        function applyFilters() {
            var actionableOn = filterActionable && filterActionable.checked;
            root.querySelectorAll('[data-field-card]').forEach(function (card) {
                var rowId = card.getAttribute('data-row-id');
                var show = true;
                if (filterMode === 'all') {
                    show = true;
                } else {
                    var identical = card.getAttribute('data-identical') === '1';
                    var low = card.getAttribute('data-lowconf') === '1';
                    var hc = card.getAttribute('data-has-conflict') === '1';
                    if (filterMode === 'changed') {
                        show = !identical;
                    }
                    if (filterMode === 'lowconf') {
                        show = low;
                    }
                    if (filterMode === 'conflict') {
                        show = hc;
                    }
                }
                if (actionableOn && rowId) {
                    var decided = !!decisions[rowId];
                    var hc2 = card.getAttribute('data-has-conflict') === '1';
                    var changed = card.getAttribute('data-identical') === '0';
                    var actionable = !decided || hc2 || changed;
                    show = show && actionable;
                }
                card.style.display = show ? '' : 'none';
            });
            root.querySelectorAll('.filter-btn').forEach(function (b) {
                var on = b.getAttribute('data-filter') === filterMode;
                b.classList.toggle('ring-2', on);
                b.classList.toggle('ring-indigo-500', on);
            });
        }

        function applyDecision(rowId, actionType, sourceCard) {
            var nextEl = sourceCard ? getNextVisibleCard(sourceCard) : null;
            decisions[rowId] = actionType;
            var card = cardByRowId(rowId);
            if (card) {
                var disp = card.querySelector('[data-decision-display]');
                if (disp) {
                    disp.textContent = actionType ? 'Selected: ' + actionType : '';
                }
            }
            updateSummary();
            applyFilters();
            if (nextEl && nextEl.offsetParent) {
                highlightCard(nextEl);
            }
        }

        function handleDecisionClick(card, type) {
            if (!card || !type) {
                return;
            }
            var rowId = card.getAttribute('data-row-id');
            if (!rowId) {
                return;
            }
            applyDecision(rowId, type, card);
        }

        function buildSubmitPayload() {
            var payloadDecisions = {};
            Object.keys(decisions).forEach(function (rowId) {
                var d = decisions[rowId];
                if (!d) {
                    return;
                }
                var exp = Object.prototype.hasOwnProperty.call(expectedMap, rowId)
                    ? expectedMap[rowId]
                    : '';
                payloadDecisions[rowId] = {
                    decision: d,
                    expected_current: exp,
                };
            });
            return { decisions: payloadDecisions };
        }

        function checkForWarningsBeforeSubmit(payload) {
            var dec = payload.decisions || {};
            var hasFlag = false;
            var riskyAccept = false;
            Object.keys(dec).forEach(function (rowId) {
                var row = dec[rowId];
                var d = row && row.decision;
                if (d === 'flag') {
                    hasFlag = true;
                }
                if (d === 'accept') {
                    var c = cardByRowId(rowId);
                    if (c && c.getAttribute('data-has-conflict') === '1') {
                        riskyAccept = true;
                    }
                }
            });
            if (hasFlag || riskyAccept) {
                return true;
            }
            Object.keys(dec).forEach(function (rowId) {
                if (!dec[rowId] || dec[rowId].decision !== 'accept') {
                    return;
                }
                var c = cardByRowId(rowId);
                if (!c) {
                    return;
                }
                var el = c.querySelector('.review-current-display');
                if (!el) {
                    return;
                }
                var shown = normalizeDisplay(el.textContent);
                if (shown === '—') {
                    shown = '';
                }
                var snap = normalizeDisplay(
                    expectedMap[rowId] != null ? expectedMap[rowId] : ''
                );
                if (snap !== shown) {
                    riskyAccept = true;
                }
            });
            return riskyAccept;
        }

        function handleSubmit(e) {
            var payload = buildSubmitPayload();
            if (Object.keys(payload.decisions).length === 0) {
                e.preventDefault();
                var noActionMsg =
                    (root && root.getAttribute('data-msg-no-action')) ||
                    'Apply करण्यापूर्वी किमान एक निवडा.';
                if (window.toastr && typeof window.toastr.error === 'function') {
                    window.toastr.error(noActionMsg);
                } else {
                    window.alert(noActionMsg);
                }
                return;
            }
            if (checkForWarningsBeforeSubmit(payload)) {
                if (
                    !window.confirm(
                        'Some fields may create conflicts or the page may be out of date versus the database. Continue?'
                    )
                ) {
                    e.preventDefault();
                    return;
                }
            }
            payloadInput.value = JSON.stringify(payload);
        }

        sortCardsByPriority();

        root.querySelectorAll('.filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                filterMode = btn.getAttribute('data-filter') || 'all';
                applyFilters();
            });
        });

        if (filterActionable) {
            filterActionable.addEventListener('change', applyFilters);
        }

        root.querySelectorAll('[data-field-card] [data-action]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var card = btn.closest('[data-field-card]');
                if (!card) {
                    return;
                }
                var action = btn.getAttribute('data-action');
                handleDecisionClick(card, action);
            });
        });

        root.querySelectorAll('.toggle-identical').forEach(function (t) {
            t.addEventListener('click', function () {
                var card = t.closest('[data-field-card]');
                if (!card) {
                    return;
                }
                var grid = card.querySelector('.diff-grid');
                if (grid) {
                    grid.classList.toggle('hidden');
                }
            });
        });

        var acceptSafe = document.getElementById('accept-safe');
        if (acceptSafe) {
            acceptSafe.addEventListener('click', function () {
                root.querySelectorAll('[data-field-card]').forEach(function (card) {
                    var rowId = card.getAttribute('data-row-id');
                    if (!rowId) {
                        return;
                    }
                    var hc = card.getAttribute('data-has-conflict') === '1';
                    var cStr = card.getAttribute('data-confidence');
                    var c = cStr === '' ? NaN : parseFloat(cStr, 10);
                    if (hc) {
                        return;
                    }
                    if (isNaN(c) || c < safeTh) {
                        return;
                    }
                    applyDecision(rowId, 'accept', null);
                });
            });
        }

        var rejectAll = document.getElementById('reject-all');
        if (rejectAll) {
            rejectAll.addEventListener('click', function () {
                root.querySelectorAll('[data-field-card]').forEach(function (card) {
                    var rowId = card.getAttribute('data-row-id');
                    if (!rowId) {
                        return;
                    }
                    applyDecision(rowId, 'reject', null);
                });
            });
        }

        form.addEventListener('submit', handleSubmit);

        updateSummary();
        applyFilters();
    }
})();
