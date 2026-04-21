@extends('layouts.admin')

@section('content')
<div class="bg-white dark:bg-gray-800 shadow rounded-lg p-6">
    <div class="flex flex-wrap items-start justify-between gap-4 mb-6">
        <h1 class="text-2xl font-bold text-gray-800 dark:text-gray-100">Admin Overview</h1>
		
        <div class="flex flex-wrap items-center gap-3">
            <label for="rangeSelector" class="text-sm text-gray-600 dark:text-gray-300">Period</label>
            <select id="rangeSelector" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="today">Today</option>
                <option value="7d">Last 7 days</option>
                <option value="30d">Last 30 days</option>
                <option value="month">This month</option>
                <option value="year">This year</option>
            </select>
            <label for="compareSelector" class="text-sm text-gray-600 dark:text-gray-300">Compare</label>
            <select id="compareSelector" class="rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 text-sm shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                <option value="none">No comparison</option>
                <option value="yesterday">Today vs Yesterday</option>
                <option value="last_week_same_day">Today vs Last week (same day)</option>
                <option value="last_week">This week vs Last week</option>
                <option value="last_month">This month vs Last month</option>
            </select>
            <p id="dashboardMetricsStatus" class="text-sm text-gray-500 dark:text-gray-400" aria-live="polite">Loading…</p>
			<div id="aiStatusBox" class="text-sm font-semibold">
    AI: <span id="aiStatusText" class="text-gray-500">Checking...</span>
</div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Registrations</p>
            <p id="totalProfiles" class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">Loading…</p>
            <p id="totalProfilesDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">New accounts in selected period</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p id="activeCardLabel" class="text-sm font-medium text-gray-500 uppercase tracking-wider">Active</p>
            <p id="activeToday" class="text-2xl font-bold text-emerald-600 mt-1">Loading…</p>
            <p id="activeTodayDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Last seen in period</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Suspended</p>
            <p class="text-2xl font-bold text-amber-600 mt-1">{{ $suspendedProfiles }}</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Showcase</p>
            <p class="text-2xl font-bold text-sky-600 mt-1">{{ $showcaseProfilesCount }}</p>
        </div>
    </div>

    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Business metrics</h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-8">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Paid users</p>
            <p id="paidUsers" class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">Loading…</p>
            <p id="paidUsersDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Subscriptions started in period</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Free users</p>
            <p id="freeUsers" class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">Loading…</p>
            <p id="freeUsersDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Registrations minus paid (period)</p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Revenue (period)</p>
            <p id="totalRevenue" class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">Loading…</p>
            <p id="totalRevenueDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Conversion %</p>
            <p id="conversionPct" class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">Loading…</p>
            <p id="conversionPctDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Paid ÷ registrations (period)</p>
        </div>
    </div>
    <p id="revenueByPlan" class="text-sm text-gray-600 dark:text-gray-400 mb-8 hidden" role="status"></p>

    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Insights</h2>
    <div id="insightsPanel" class="mb-8 grid grid-cols-1 md:grid-cols-2 gap-4" role="region" aria-label="Insights">
        <p class="text-sm text-gray-500 dark:text-gray-400 col-span-full">Loading…</p>
    </div>

    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Trends</h2>
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-4">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">User growth</p>
            <div class="relative h-56 w-full">
                <canvas id="chartUserGrowth" aria-label="User growth chart"></canvas>
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-4">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">Revenue</p>
            <div class="relative h-56 w-full">
                <canvas id="chartRevenue" aria-label="Revenue chart"></canvas>
            </div>
        </div>
        <div class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-4">
            <p class="text-sm font-medium text-gray-700 dark:text-gray-200 mb-2">Engagement</p>
            <div class="relative h-56 w-full">
                <canvas id="chartEngagement" aria-label="Engagement chart"></canvas>
            </div>
        </div>
    </div>

    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Activity (period)</h2>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Logins</p>
            <p id="actLogins" class="text-lg font-bold text-gray-800 dark:text-gray-100 mt-1">—</p>
            <p id="actLoginsDelta" class="text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500"></p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Profiles created</p>
            <p id="actProfiles" class="text-lg font-bold text-gray-800 dark:text-gray-100 mt-1">—</p>
            <p id="actProfilesDelta" class="text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500"></p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Interests sent</p>
            <p id="actInterests" class="text-lg font-bold text-gray-800 dark:text-gray-100 mt-1">—</p>
            <p id="actInterestsDelta" class="text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500"></p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Chats started</p>
            <p id="actChats" class="text-lg font-bold text-gray-800 dark:text-gray-100 mt-1">—</p>
            <p id="actChatsDelta" class="text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500"></p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Messages</p>
            <p id="actMessages" class="text-lg font-bold text-gray-800 dark:text-gray-100 mt-1">—</p>
            <p id="actMessagesDelta" class="text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500"></p>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-4">
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Profile views</p>
            <p id="actViews" class="text-lg font-bold text-gray-800 dark:text-gray-100 mt-1">—</p>
            <p id="actViewsDelta" class="text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500"></p>
        </div>
    </div>

    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Conversion funnel</h2>
    <div id="funnelBlock" class="mb-8 rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-5">
        <p class="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">
        <div>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Live actions</h2>
            <div id="livePanel" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
            </div>
        </div>
        <div>
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100 mb-3">Risk alerts</h2>
            <div id="riskPanel" class="rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-700/50 p-5">
                <p class="text-sm text-gray-500 dark:text-gray-400">Loading…</p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Pending abuse reports</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $pendingAbuseReports }}</p>
            <a href="{{ route('admin.abuse-reports.index') }}" class="inline-block mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View reports →</a>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Biodata Intakes</p>
            <p class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">{{ $totalBiodataIntakes ?? 0 }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Last 7 days: <span class="font-semibold">{{ $intakeLast7Count ?? 0 }}</span> ·
                Last 30 days: <span class="font-semibold">{{ $intakeLast30Count ?? 0 }}</span>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Parsed: <span class="font-semibold">{{ $intakeLast30Parsed ?? 0 }}</span> ·
                Errors: <span class="font-semibold">{{ $intakeLast30Errors ?? 0 }}</span>
            </p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Avg parse: <span class="font-semibold">{{ $intakeAvgParseMs ?? 0 }} ms</span> ·
                Avg edits: <span class="font-semibold">{{ number_format($intakeAvgManualEdits ?? 0, 1) }}</span> ·
                Avg auto-filled: <span class="font-semibold">{{ number_format($intakeAvgAutoFilled ?? 0, 1) }}</span>
            </p>
            <a href="{{ route('admin.biodata-intakes.index') }}" class="inline-block mt-3 text-sm font-medium text-indigo-600 hover:text-indigo-800 dark:text-indigo-400">View intakes →</a>
        </div>
        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-600 p-5 sm:col-span-2 lg:col-span-1">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wider">Revenue (same period)</p>
            <p id="monthlyRevenue" class="text-2xl font-bold text-gray-800 dark:text-gray-100 mt-1">Loading…</p>
            <p id="monthlyRevenueDelta" class="text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500" aria-live="polite"></p>
            <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Matches selected period</p>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const E = {
        overview: @json(route('admin.dashboard-metrics.overview')),
        activity: @json(route('admin.dashboard-metrics.activity')),
        revenue: @json(route('admin.dashboard-metrics.revenue')),
        funnel: @json(route('admin.dashboard-metrics.funnel')),
        timeseries: @json(route('admin.dashboard-metrics.timeseries')),
        insights: @json(route('admin.dashboard-metrics.insights')),
        insightActionClick: @json(route('admin.dashboard-metrics.insights.action-click')),
        insightFeedback: @json(route('admin.dashboard-metrics.insights.feedback')),
        risk: @json(route('admin.dashboard-metrics.risk')),
        live: @json(route('admin.dashboard-metrics.live')),
		aiHealth: @json(route('admin.dashboard-metrics.ai-health')),
    };

    const statusEl = document.getElementById('dashboardMetricsStatus');
    const rangeEl = document.getElementById('rangeSelector');
    const compareEl = document.getElementById('compareSelector');
    const REFRESH_MS = 60000;

    let dashboardCharts = [];

    function currentRange() {
        return rangeEl ? rangeEl.value : 'today';
    }

    function currentCompare() {
        return compareEl ? compareEl.value : 'none';
    }

    function withParams(url) {
        const r = currentRange();
        const cmp = currentCompare();
        const sep = url.indexOf('?') >= 0 ? '&' : '?';
        return url + sep + 'range=' + encodeURIComponent(r) + '&compare=' + encodeURIComponent(cmp);
    }

    function setDelta(id, pct) {
        const el = document.getElementById(id);
        if (!el) return;
        if (pct === null || pct === undefined || Number.isNaN(Number(pct))) {
            el.textContent = '';
            el.className = el.id.indexOf('act') === 0
                ? 'text-xs mt-0.5 min-h-[1.1rem] text-gray-500 dark:text-gray-500'
                : 'text-xs mt-1 min-h-[1.25rem] text-gray-500 dark:text-gray-500';
            return;
        }
        const n = Number(pct);
        const up = n >= 0;
        el.textContent = (up ? '↑ ' : '↓ ') + Math.abs(n).toFixed(2) + '% vs prior';
        el.className = (el.id.indexOf('act') === 0 ? 'text-xs mt-0.5 min-h-[1.1rem] font-medium ' : 'text-xs mt-1 min-h-[1.25rem] font-medium ')
            + (up ? 'text-emerald-600 dark:text-emerald-400' : 'text-rose-600 dark:text-rose-400');
    }

    function clearAllMetricDeltas() {
        [
            'totalProfilesDelta', 'activeTodayDelta', 'paidUsersDelta', 'freeUsersDelta', 'totalRevenueDelta',
            'conversionPctDelta', 'monthlyRevenueDelta',
            'actLoginsDelta', 'actProfilesDelta', 'actInterestsDelta', 'actChatsDelta', 'actMessagesDelta', 'actViewsDelta',
        ].forEach(function (id) { setDelta(id, null); });
    }

    function fmtMoney(n) {
        if (n === null || n === undefined || Number.isNaN(Number(n))) return '—';
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(Number(n));
        } catch (e) {
            return String(n);
        }
    }

    function fmtPct(n) {
        if (n === null || n === undefined) return '—';
        return Number(n).toFixed(2) + '%';
    }

    function setText(id, text) {
        const el = document.getElementById(id);
        if (el) el.textContent = text;
    }

    function showGlobalError() {
        if (statusEl) statusEl.textContent = 'Failed to load data';
    }

    function chartTextColor() {
        const dark = document.documentElement.classList.contains('dark');
        return dark ? '#e5e7eb' : '#374151';
    }

    function gridColor() {
        const dark = document.documentElement.classList.contains('dark');
        return dark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
    }

    function destroyCharts() {
        dashboardCharts.forEach(function (c) {
            try { c.destroy(); } catch (e) {}
        });
        dashboardCharts = [];
    }

    function renderCharts(ts) {
        destroyCharts();
        if (!ts || !ts.dates || !window.Chart) return;

        const prev = ts.previous;
        const hasPrev = prev && prev.registrations && prev.registrations.length > 0;
        const curLen = (ts.registrations || []).length;
        const labels = (ts.dates || []).slice(0, curLen);

        const commonOpts = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { labels: { color: chartTextColor() } } },
            scales: {
                x: { ticks: { color: chartTextColor(), maxRotation: 45, minRotation: 0 }, grid: { color: gridColor() } },
                y: { ticks: { color: chartTextColor() }, grid: { color: gridColor() }, beginAtZero: true },
            },
        };

        function lineDatasets(currentLabel, curData, prevData, colorRgb, fillRgb) {
            const ds = [{
                label: currentLabel,
                data: (curData || []).slice(0, labels.length),
                borderColor: colorRgb,
                backgroundColor: fillRgb,
                fill: true,
                tension: 0.2,
            }];
            if (hasPrev && prevData) {
                ds.push({
                    label: 'Previous period',
                    data: (prevData || []).slice(0, labels.length),
                    borderColor: 'rgb(148, 163, 184)',
                    backgroundColor: 'transparent',
                    fill: false,
                    tension: 0.2,
                    borderDash: [5, 5],
                });
            }
            return ds;
        }

        const g1 = document.getElementById('chartUserGrowth');
        const g2 = document.getElementById('chartRevenue');
        const g3 = document.getElementById('chartEngagement');
        if (g1) {
            dashboardCharts.push(new Chart(g1, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: lineDatasets('Registrations', ts.registrations, prev ? prev.registrations : null, 'rgb(79, 70, 229)', 'rgba(79, 70, 229, 0.1)'),
                },
                options: commonOpts,
            }));
        }
        if (g2) {
            dashboardCharts.push(new Chart(g2, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: lineDatasets('Revenue (₹)', ts.revenue, prev ? prev.revenue : null, 'rgb(16, 185, 129)', 'rgba(16, 185, 129, 0.1)'),
                },
                options: commonOpts,
            }));
        }
        if (g3) {
            dashboardCharts.push(new Chart(g3, {
                type: 'line',
                data: {
                    labels: labels,
                    datasets: lineDatasets('Engagement', ts.engagements, prev ? prev.engagements : null, 'rgb(245, 158, 11)', 'rgba(245, 158, 11, 0.1)'),
                },
                options: commonOpts,
            }));
        }
    }

    function renderFunnel(d) {
        const wrap = document.getElementById('funnelBlock');
        if (!wrap || !d || !d.stages) {
            if (wrap) wrap.innerHTML = '<p class="text-sm text-red-600 dark:text-red-400">Failed to load data</p>';
            return;
        }
        const s = d.stages;
        const dr = d.dropoff_percent || {};
        const steps = [
            { key: 'signups', label: 'Signup' },
            { key: 'profile_completed_active', label: 'Profile' },
            { key: 'interest_sent', label: 'Interest' },
            { key: 'chat_started', label: 'Chat' },
            { key: 'payment_done', label: 'Payment' },
        ];
        const dropKeys = [
            'signups_to_profile_completed_active',
            'profile_completed_active_to_interest_sent',
            'interest_sent_to_chat_started',
            'chat_started_to_payment_done',
        ];

        let html = '<div class="flex flex-col gap-3">';
        steps.forEach(function (step, i) {
            const count = s[step.key] != null ? s[step.key] : '—';
            html += '<div class="flex flex-wrap items-center gap-2 text-sm">';
            html += '<span class="font-semibold text-gray-800 dark:text-gray-100 min-w-[5rem]">' + step.label + '</span>';
            html += '<span class="text-lg font-bold text-indigo-600 dark:text-indigo-400">' + count + '</span>';
            if (i < steps.length - 1) {
                const dk = dropKeys[i];
                const drop = dr[dk];
                const dropStr = drop === null || drop === undefined ? '—' : (Number(drop).toFixed(2) + '% drop');
                html += '<span class="text-gray-400">→</span>';
                html += '<span class="text-xs text-gray-500 dark:text-gray-400">' + dropStr + '</span>';
            }
            html += '</div>';
        });
        html += '</div>';
        wrap.innerHTML = html;
    }

    function renderLive(d) {
        const wrap = document.getElementById('livePanel');
        if (!wrap) return;
        if (!d) {
            wrap.innerHTML = '<p class="text-sm text-red-600 dark:text-red-400">Failed to load data</p>';
            return;
        }
        const exp = d.expiring_subscriptions_next_2_days || [];
        let listHtml = '';
        if (exp.length === 0) {
            listHtml = '<li class="text-sm text-gray-500 dark:text-gray-400">None in the next 2 days</li>';
        } else {
            exp.forEach(function (row) {
                const name = row.user && row.user.name ? row.user.name : ('User #' + row.user_id);
                const plan = row.plan && row.plan.name ? row.plan.name : '—';
                const ends = row.ends_at ? row.ends_at : '—';
                listHtml += '<li class="text-sm border-b border-gray-200 dark:border-gray-600 py-2 last:border-0">';
                listHtml += '<span class="font-medium text-gray-800 dark:text-gray-100">' + name + '</span>';
                listHtml += ' · <span class="text-gray-600 dark:text-gray-300">' + plan + '</span>';
                listHtml += ' · <span class="text-xs text-gray-500">' + ends + '</span>';
                listHtml += '</li>';
            });
        }
        wrap.innerHTML =
            '<ul class="space-y-2 mb-4">' +
            '<li class="text-sm"><span class="text-gray-500 dark:text-gray-400">Pending photo approvals:</span> <strong class="text-gray-900 dark:text-gray-100">' + (d.pending_photo_approvals != null ? d.pending_photo_approvals : '—') + '</strong></li>' +
            '<li class="text-sm"><span class="text-gray-500 dark:text-gray-400">Open abuse reports:</span> <strong class="text-gray-900 dark:text-gray-100">' + (d.reported_users_open != null ? d.reported_users_open : '—') + '</strong></li>' +
            '<li class="text-sm"><span class="text-gray-500 dark:text-gray-400">New users today:</span> <strong class="text-gray-900 dark:text-gray-100">' + (d.new_users_today != null ? d.new_users_today : '—') + '</strong></li>' +
            '</ul>' +
            '<p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">Expiring subscriptions (next 2 days)</p>' +
            '<ul class="max-h-48 overflow-y-auto pl-0 list-none">' + listHtml + '</ul>';
    }

    function escAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function insightPriorityCardClasses(priority) {
        const p = (priority || 'medium').toLowerCase();
        if (p === 'high') {
            return 'border-rose-300 dark:border-rose-800 bg-rose-50 dark:bg-rose-950/40';
        }
        if (p === 'low') {
            return 'border-sky-200 dark:border-sky-800 bg-sky-50 dark:bg-sky-950/30';
        }
        return 'border-amber-200 dark:border-amber-900/50 bg-amber-50 dark:bg-amber-950/30';
    }

    function insightPriorityTextClasses(priority) {
        const p = (priority || 'medium').toLowerCase();
        if (p === 'high') {
            return { title: 'text-rose-900 dark:text-rose-100', body: 'text-rose-800/90 dark:text-rose-200/90', badge: 'bg-rose-200 text-rose-900 dark:bg-rose-900 dark:text-rose-100' };
        }
        if (p === 'low') {
            return { title: 'text-sky-900 dark:text-sky-100', body: 'text-sky-800/90 dark:text-sky-200/90', badge: 'bg-sky-200 text-sky-900 dark:bg-sky-900 dark:text-sky-100' };
        }
        return { title: 'text-amber-900 dark:text-amber-100', body: 'text-amber-800/90 dark:text-amber-200/90', badge: 'bg-amber-200 text-amber-900 dark:bg-amber-900 dark:text-amber-100' };
    }

    function insightSuccessCardClasses() {
        return 'border-emerald-300 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-950/40';
    }

    function insightSuccessTextClasses() {
        return { title: 'text-emerald-900 dark:text-emerald-100', body: 'text-emerald-800/90 dark:text-emerald-200/90', badge: 'bg-emerald-200 text-emerald-900 dark:bg-emerald-900 dark:text-emerald-100' };
    }

    function renderInsights(d) {
        const wrap = document.getElementById('insightsPanel');
        if (!wrap) return;
        if (!d || !Array.isArray(d.insights)) {
            wrap.innerHTML = '<p class="text-sm text-red-600 dark:text-red-400 col-span-full">Failed to load data</p>';
            return;
        }
        let items = d.insights.slice();
        if (items.length === 0) {
            wrap.innerHTML = '<p class="text-sm text-gray-500 dark:text-gray-400 col-span-full">No rule-based insights for this period.</p>';
            return;
        }
        const btnClass = 'insight-action-link inline-flex items-center justify-center rounded-md border border-indigo-600 bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-indigo-500 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800';
        const fbBtn = 'insight-feedback-btn rounded border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 px-2 py-1 text-sm leading-none hover:bg-gray-50 dark:hover:bg-gray-700 disabled:opacity-50';
        let html = '';
        items.forEach(function (row) {
            const isSuccess = row.type === 'success';
            const icon = isSuccess ? '✓' : (row.type === 'warning' ? '⚠' : 'ℹ');
            const pri = (row.priority || 'medium').toLowerCase();
            const cardCls = isSuccess ? insightSuccessCardClasses() : insightPriorityCardClasses(pri);
            const txt = isSuccess ? insightSuccessTextClasses() : insightPriorityTextClasses(pri);
            const ikey = escAttr(row.insight_key || '');
            const msg = escAttr(row.message || '');
            const sug = escAttr(row.suggestion || '');
            const meta = row.meta && typeof row.meta === 'object' ? row.meta : {};
            const prev = meta.previous !== undefined && meta.previous !== null ? String(meta.previous) : '—';
            const cur = meta.current !== undefined && meta.current !== null ? String(meta.current) : '—';
            html += '<div class="flex gap-3 rounded-lg border p-4 ' + cardCls + '" data-insight-key="' + ikey + '">';
            html += '<span class="text-2xl leading-none shrink-0" aria-hidden="true">' + icon + '</span>';
            html += '<div class="min-w-0 flex-1">';
            html += '<div class="flex flex-wrap items-center gap-2 mb-1">';
            html += '<span class="text-xs font-bold uppercase tracking-wide rounded px-1.5 py-0.5 ' + txt.badge + '">' + escAttr(pri) + '</span>';
            if (isSuccess) {
                html += '<span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">Follow-up</span>';
            }
            html += '</div>';
            html += '<p class="text-sm font-semibold ' + txt.title + '">' + msg + '</p>';
            html += '<p class="text-sm mt-1 ' + txt.body + '">' + sug + '</p>';
            html += '<p class="text-xs mt-2 text-gray-600 dark:text-gray-400">Previous: <span class="font-semibold text-gray-800 dark:text-gray-200">' + escAttr(prev) + '</span> · Current: <span class="font-semibold text-gray-800 dark:text-gray-200">' + escAttr(cur) + '</span></p>';
            html += '<div class="flex flex-wrap items-center gap-2 mt-2">';
            html += '<span class="text-xs text-gray-500 dark:text-gray-400">Was this insight helpful?</span>';
            html += '<button type="button" class="' + fbBtn + '" data-insight-key="' + ikey + '" data-insight-message="' + msg + '" data-sentiment="up" title="Helpful">👍</button>';
            html += '<button type="button" class="' + fbBtn + '" data-insight-key="' + ikey + '" data-insight-message="' + msg + '" data-sentiment="down" title="Not helpful">👎</button>';
            html += '<span class="insight-feedback-status text-xs text-emerald-600 dark:text-emerald-400 hidden" aria-live="polite">Thanks for the feedback.</span>';
            html += '</div>';
            const actions = Array.isArray(row.actions) ? row.actions : [];
            if (actions.length > 0) {
                html += '<div class="flex flex-wrap gap-2 mt-3">';
                actions.forEach(function (act) {
                    const href = escAttr(act.url || '#');
                    const actLabel = escAttr(act.label || 'Open');
                    html += '<a href="' + href + '" class="' + btnClass + '" data-action-label="' + actLabel + '" data-insight-message="' + msg + '" data-insight-key="' + ikey + '">' + actLabel + '</a>';
                });
                html += '</div>';
            }
            html += '</div></div>';
        });
        wrap.innerHTML = html;
    }

    function renderRisk(d) {
        const wrap = document.getElementById('riskPanel');
        if (!wrap) return;
        if (!d || !Array.isArray(d.alerts)) {
            wrap.innerHTML = '<p class="text-sm text-red-600 dark:text-red-400">Failed to load data</p>';
            return;
        }
        const n = d.alerts.length;
        let body = '<p class="text-sm mb-3"><span class="text-gray-500 dark:text-gray-400">Flagged users (top risk):</span> <strong class="text-gray-900 dark:text-gray-100">' + n + '</strong></p>';
        if (n === 0) {
            body += '<p class="text-sm text-gray-500 dark:text-gray-400">No high-risk users right now.</p>';
        } else {
            body += '<ul class="space-y-2 max-h-56 overflow-y-auto">';
            d.alerts.slice(0, 12).forEach(function (a) {
                const flags = (a.flags && a.flags.length) ? a.flags.join(', ') : '—';
                body += '<li class="text-sm border-b border-gray-200 dark:border-gray-600 pb-2 last:border-0">';
                body += '<span class="font-medium">User #' + a.user_id + '</span> · score <strong>' + a.risk_score + '</strong>';
                body += '<br><span class="text-xs text-gray-500 dark:text-gray-400">' + flags + '</span>';
                body += '</li>';
            });
            body += '</ul>';
        }
        wrap.innerHTML = body;
    }

    async function fetchJson(url) {
        const res = await fetch(url, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.json();
    }

    function activeLabelForRange(r) {
        if (r === 'today') return 'Active today';
        return 'Active in period';
    }
async function loadAiHealth() {
    try {
        const res = await fetch(E.aiHealth, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' }
        });

        const json = await res.json();
        const status = json?.data?.status;

        const el = document.getElementById('aiStatusText');

        if (!el) return;

        if (status === 'up') {
            el.textContent = '🟢 Running';
            el.className = 'text-emerald-600 font-bold';
        } else {
            el.textContent = '🔴 Down';
            el.className = 'text-red-600 font-bold';
        }

    } catch (e) {
        const el = document.getElementById('aiStatusText');
        if (el) {
            el.textContent = '⚠️ Error';
            el.className = 'text-yellow-600 font-bold';
        }
    }
}
    async function loadAll() {
        if (statusEl) statusEl.textContent = 'Loading…';
        const r = currentRange();
        setText('activeCardLabel', activeLabelForRange(r));

        try {
            const [ov, act, rev, fun, ts, ins, risk, live] = await Promise.all([
                fetchJson(withParams(E.overview)),
                fetchJson(withParams(E.activity)),
                fetchJson(withParams(E.revenue)),
                fetchJson(withParams(E.funnel)),
                fetchJson(withParams(E.timeseries)),
                fetchJson(withParams(E.insights)),
                fetchJson(withParams(E.risk)),
                fetchJson(withParams(E.live)),
            ]);

            const o = ov && ov.data ? ov.data : null;
            const a = act && act.data ? act.data : null;
            const revD = rev && rev.data ? rev.data : null;
            const f = fun && fun.data ? fun.data : null;
            const tser = ts && ts.data ? ts.data : null;
            const insightPayload = ins && ins.data ? ins.data : null;
            const k = risk && risk.data ? risk.data : null;
            const l = live && live.data ? live.data : null;

            if (!o || !o.current) throw new Error('overview');

            const oc = o.current;
            const och = o.change;

            setText('totalProfiles', String(oc.total_users != null ? oc.total_users : '—'));
            setText('activeToday', String(oc.active_users_today != null ? oc.active_users_today : '—'));
            setText('paidUsers', String(oc.paid_users_count != null ? oc.paid_users_count : '—'));
            setText('freeUsers', String(oc.free_users_count != null ? oc.free_users_count : '—'));
            setText('totalRevenue', fmtMoney(oc.total_revenue));
            setText('conversionPct', fmtPct(oc.conversion_rate_percent));
            setText('monthlyRevenue', fmtMoney(oc.monthly_revenue));

            if (och) {
                setDelta('totalProfilesDelta', och.total_users);
                setDelta('activeTodayDelta', och.active_users_today);
                setDelta('paidUsersDelta', och.paid_users_count);
                setDelta('freeUsersDelta', och.free_users_count);
                setDelta('totalRevenueDelta', och.total_revenue);
                setDelta('conversionPctDelta', och.conversion_rate_percent);
                setDelta('monthlyRevenueDelta', och.monthly_revenue);
            } else {
                clearAllMetricDeltas();
            }

            const rpEl = document.getElementById('revenueByPlan');
            if (rpEl) {
                const revCur = revD && revD.current ? revD.current : null;
                const plans = revCur && revCur.revenue_by_plan ? revCur.revenue_by_plan : [];
                if (plans.length) {
                    rpEl.textContent = 'Revenue by plan: ' + plans.slice(0, 8).map(function (x) {
                        return x.name + ' ' + fmtMoney(x.revenue);
                    }).join(' · ');
                    rpEl.classList.remove('hidden');
                } else {
                    rpEl.textContent = '';
                    rpEl.classList.add('hidden');
                }
            }

            if (a && a.current) {
                const ac = a.current;
                const achA = a.change;
                setText('actLogins', String(ac.daily_logins != null ? ac.daily_logins : '—'));
                setText('actProfiles', String(ac.profiles_created_today != null ? ac.profiles_created_today : '—'));
                setText('actInterests', String(ac.interests_sent_today != null ? ac.interests_sent_today : '—'));
                setText('actChats', String(ac.chats_started_today != null ? ac.chats_started_today : '—'));
                setText('actMessages', String(ac.messages_sent_today != null ? ac.messages_sent_today : '—'));
                setText('actViews', String(ac.contact_views_today != null ? ac.contact_views_today : '—'));
                if (achA) {
                    setDelta('actLoginsDelta', achA.daily_logins);
                    setDelta('actProfilesDelta', achA.profiles_created_today);
                    setDelta('actInterestsDelta', achA.interests_sent_today);
                    setDelta('actChatsDelta', achA.chats_started_today);
                    setDelta('actMessagesDelta', achA.messages_sent_today);
                    setDelta('actViewsDelta', achA.contact_views_today);
                } else {
                    ['actLoginsDelta', 'actProfilesDelta', 'actInterestsDelta', 'actChatsDelta', 'actMessagesDelta', 'actViewsDelta'].forEach(function (id) { setDelta(id, null); });
                }
            }

            renderCharts(tser);
            renderInsights(insightPayload);
            renderFunnel(f);
            renderRisk(k);
            renderLive(l);

            const ttl = oc.cache_ttl_seconds != null ? oc.cache_ttl_seconds : 90;
            const t = new Date();
            if (statusEl) {
                statusEl.textContent = 'Updated ' + t.toLocaleTimeString() + ' · cache ~' + ttl + 's';
            }
        } catch (err) {
            showGlobalError();
            destroyCharts();
            clearAllMetricDeltas();
            [
                'totalProfiles', 'activeToday', 'paidUsers', 'freeUsers', 'totalRevenue', 'conversionPct', 'monthlyRevenue',
                'actLogins', 'actProfiles', 'actInterests', 'actChats', 'actMessages', 'actViews',
            ].forEach(function (id) { setText(id, 'Failed to load data'); });
            const rpErr = document.getElementById('revenueByPlan');
            if (rpErr) {
                rpErr.textContent = '';
                rpErr.classList.add('hidden');
            }
            const insP = document.getElementById('insightsPanel');
            if (insP) insP.innerHTML = '<p class="text-sm text-red-600 dark:text-red-400 col-span-full">Failed to load data</p>';
            const fb = document.getElementById('funnelBlock');
            if (fb) fb.innerHTML = '<p class="text-sm text-red-600 dark:text-red-400">Failed to load data</p>';
            renderRisk(null);
            renderLive(null);
        }
    }

    if (rangeEl) {
        rangeEl.addEventListener('change', function () {
            loadAll();
        });
    }
    if (compareEl) {
        compareEl.addEventListener('change', function () {
            loadAll();
        });
    }

    document.body.addEventListener('click', function (e) {
        const a = e.target.closest('a.insight-action-link');
        if (!a) return;
        const panel = document.getElementById('insightsPanel');
        if (!panel || !panel.contains(a)) return;
        e.preventDefault();
        const url = a.getAttribute('href');
        if (!url || url === '#') return;
        const label = a.getAttribute('data-action-label') || '';
        const insightMsg = a.getAttribute('data-insight-message') || '';
        const insightKey = a.getAttribute('data-insight-key') || '';
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        const token = tokenMeta ? tokenMeta.getAttribute('content') : '';
        fetch(E.insightActionClick, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                url: url,
                label: label,
                insight_message: insightMsg,
                insight_key: insightKey,
            }),
        }).catch(function () {}).finally(function () {
            window.location.href = url;
        });
    });

    document.body.addEventListener('click', function (e) {
        const btn = e.target.closest('button.insight-feedback-btn');
        if (!btn) return;
        const panel = document.getElementById('insightsPanel');
        if (!panel || !panel.contains(btn)) return;
        e.preventDefault();
        if (btn.disabled) return;
        const card = btn.closest('[data-insight-key]');
        if (!card) return;
        card.querySelectorAll('.insight-feedback-btn').forEach(function (b) {
            b.disabled = true;
        });
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        const token = tokenMeta ? tokenMeta.getAttribute('content') : '';
        const insightKey = btn.getAttribute('data-insight-key') || '';
        const insightMsg = btn.getAttribute('data-insight-message') || '';
        const sentiment = btn.getAttribute('data-sentiment') || '';
        fetch(E.insightFeedback, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': token,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                insight_key: insightKey,
                sentiment: sentiment,
                insight_message: insightMsg,
            }),
        }).catch(function () {}).finally(function () {
            const st = card.querySelector('.insight-feedback-status');
            if (st) st.classList.remove('hidden');
        });
    });

    loadAll();
	loadAiHealth();
    setInterval(loadAll, REFRESH_MS);
	setInterval(loadAiHealth, REFRESH_MS);
})();
</script>
@endsection
