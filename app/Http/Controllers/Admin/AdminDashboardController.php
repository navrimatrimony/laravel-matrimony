<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UserActivity;
use App\Services\Admin\AdminDashboardMetricsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Services\System\AiHealthService;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly AdminDashboardMetricsService $metrics,
    ) {}

    public function getOverviewStats(Request $request): JsonResponse
    {
        $range = AdminDashboardMetricsService::normalizeRange((string) $request->query('range', AdminDashboardMetricsService::RANGE_TODAY));
        $compare = AdminDashboardMetricsService::normalizeCompare((string) $request->query('compare', 'none'));

        return response()->json(['data' => $this->metrics->getOverviewStats($range, $compare)]);
    }

    public function getUserActivityStats(Request $request): JsonResponse
    {
        $range = AdminDashboardMetricsService::normalizeRange((string) $request->query('range', AdminDashboardMetricsService::RANGE_TODAY));
        $compare = AdminDashboardMetricsService::normalizeCompare((string) $request->query('compare', 'none'));

        return response()->json(['data' => $this->metrics->getUserActivityStats($range, $compare)]);
    }

    public function getRevenueStats(Request $request): JsonResponse
    {
        $range = AdminDashboardMetricsService::normalizeRange((string) $request->query('range', AdminDashboardMetricsService::RANGE_TODAY));
        $compare = AdminDashboardMetricsService::normalizeCompare((string) $request->query('compare', 'none'));

        return response()->json(['data' => $this->metrics->getRevenueStats($range, $compare)]);
    }

    public function getFunnelStats(Request $request): JsonResponse
    {
        $range = AdminDashboardMetricsService::normalizeRange((string) $request->query('range', AdminDashboardMetricsService::RANGE_TODAY));

        return response()->json(['data' => $this->metrics->getFunnelStats($range)]);
    }

    public function getTimeSeriesData(Request $request): JsonResponse
    {
        $range = AdminDashboardMetricsService::normalizeRange((string) $request->query('range', AdminDashboardMetricsService::RANGE_TODAY));
        $compare = AdminDashboardMetricsService::normalizeCompare((string) $request->query('compare', 'none'));

        return response()->json(['data' => $this->metrics->getTimeSeriesData($range, $compare)]);
    }

    public function getInsights(Request $request): JsonResponse
    {
        $range = AdminDashboardMetricsService::normalizeRange((string) $request->query('range', AdminDashboardMetricsService::RANGE_TODAY));

        return response()->json(['data' => $this->metrics->getInsights($range, (int) $request->user()->id)]);
    }

    /**
     * Track dashboard insight action clicks (append-only user_activities).
     */
    public function postInsightActionClick(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'label' => ['required', 'string', 'max:255'],
            'insight_message' => ['nullable', 'string', 'max:500'],
            'insight_key' => ['nullable', 'string', 'max:128'],
        ]);

        $url = $validated['url'];
        if (! $this->isAllowedInsightActionUrl($request, $url)) {
            throw ValidationException::withMessages([
                'url' => ['URL must be an internal /admin path on this application.'],
            ]);
        }

        if (Schema::hasTable('user_activities')) {
            UserActivity::query()->create([
                'user_id' => (int) $request->user()->id,
                'type' => 'admin_action_click',
                'meta' => [
                    'url' => $url,
                    'label' => $validated['label'],
                    'insight_message' => $validated['insight_message'] ?? null,
                    'insight_key' => $validated['insight_key'] ?? null,
                ],
                'created_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    /**
     * Insight quality feedback (append-only user_activities).
     */
    public function postInsightFeedback(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'insight_key' => ['required', 'string', 'max:128'],
            'sentiment' => ['required', 'string', 'in:up,down'],
            'insight_message' => ['nullable', 'string', 'max:500'],
        ]);

        if (Schema::hasTable('user_activities')) {
            UserActivity::query()->create([
                'user_id' => (int) $request->user()->id,
                'type' => 'insight_feedback',
                'meta' => [
                    'insight_key' => $validated['insight_key'],
                    'sentiment' => $validated['sentiment'],
                    'insight_message' => $validated['insight_message'] ?? null,
                ],
                'created_at' => now(),
            ]);
        }

        return response()->json(['success' => true]);
    }

    private function isAllowedInsightActionUrl(Request $request, string $url): bool
    {
        $parsed = parse_url($url);
        if ($parsed === false || ! is_array($parsed)) {
            return false;
        }

        $scheme = $parsed['scheme'] ?? null;
        $host = $parsed['host'] ?? null;
        if ($scheme !== null && ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }
        if ($host !== null && $host !== $request->getHost()) {
            return false;
        }

        $path = $parsed['path'] ?? '';
        if ($path === '') {
            return false;
        }
        $path = '/'.ltrim($path, '/');

        return str_starts_with($path, '/admin/');
    }

    public function getRiskAlerts(): JsonResponse
    {
        return response()->json(['data' => $this->metrics->getRiskAlerts()]);
    }

    public function getLiveActions(): JsonResponse
    {
        return response()->json(['data' => $this->metrics->getLiveActions()]);
    }
public function getAiHealth(): JsonResponse
{
    return response()->json([
        'data' => AiHealthService::check()
    ]);
}
}
