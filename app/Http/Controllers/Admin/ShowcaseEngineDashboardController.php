<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Interest;
use App\Models\ProfileView;
use Illuminate\View\View;

/**
 * Admin-only overview of showcase-driven activity: profile views (showcase → real) and interests.
 */
class ShowcaseEngineDashboardController extends Controller
{
    private const ROW_LIMIT = 50;

    private const TIMELINE_LIMIT = 80;

    public function index(): View
    {
        $limit = self::ROW_LIMIT;

        $viewRows = ProfileView::query()
            ->whereHas('viewerProfile', fn ($q) => $q->whereShowcase())
            ->whereHas('viewedProfile', fn ($q) => $q->whereNonShowcase())
            ->with(['viewerProfile', 'viewedProfile'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        $outgoing = Interest::query()
            ->whereHas('senderProfile', fn ($q) => $q->whereShowcase())
            ->whereHas('receiverProfile', fn ($q) => $q->whereNonShowcase())
            ->with(['senderProfile', 'receiverProfile'])
            ->latest('created_at')
            ->limit($limit)
            ->get();

        $incoming = Interest::query()
            ->whereHas('receiverProfile', fn ($q) => $q->whereShowcase())
            ->whereHas('senderProfile', fn ($q) => $q->whereNonShowcase())
            ->with(['senderProfile', 'receiverProfile'])
            ->latest('updated_at')
            ->limit($limit)
            ->get();

        $since24h = now()->subDay();
        $since7d = now()->subDays(7);

        $stats = [
            'views_24h' => ProfileView::query()
                ->where('created_at', '>=', $since24h)
                ->whereHas('viewerProfile', fn ($q) => $q->whereShowcase())
                ->whereHas('viewedProfile', fn ($q) => $q->whereNonShowcase())
                ->count(),
            'views_7d' => ProfileView::query()
                ->where('created_at', '>=', $since7d)
                ->whereHas('viewerProfile', fn ($q) => $q->whereShowcase())
                ->whereHas('viewedProfile', fn ($q) => $q->whereNonShowcase())
                ->count(),
            'outgoing_24h' => Interest::query()
                ->where('created_at', '>=', $since24h)
                ->whereHas('senderProfile', fn ($q) => $q->whereShowcase())
                ->whereHas('receiverProfile', fn ($q) => $q->whereNonShowcase())
                ->count(),
            'outgoing_7d' => Interest::query()
                ->where('created_at', '>=', $since7d)
                ->whereHas('senderProfile', fn ($q) => $q->whereShowcase())
                ->whereHas('receiverProfile', fn ($q) => $q->whereNonShowcase())
                ->count(),
            'incoming_24h' => Interest::query()
                ->where('updated_at', '>=', $since24h)
                ->whereHas('receiverProfile', fn ($q) => $q->whereShowcase())
                ->whereHas('senderProfile', fn ($q) => $q->whereNonShowcase())
                ->count(),
            'incoming_7d' => Interest::query()
                ->where('updated_at', '>=', $since7d)
                ->whereHas('receiverProfile', fn ($q) => $q->whereShowcase())
                ->whereHas('senderProfile', fn ($q) => $q->whereNonShowcase())
                ->count(),
        ];

        $timeline = collect();
        foreach ($viewRows as $pv) {
            $timeline->push([
                'at' => $pv->created_at,
                'kind' => 'view',
                'row' => $pv,
            ]);
        }
        foreach ($outgoing as $i) {
            $timeline->push([
                'at' => $i->created_at,
                'kind' => 'outgoing_interest',
                'row' => $i,
            ]);
        }
        foreach ($incoming as $i) {
            $timeline->push([
                'at' => $i->updated_at,
                'kind' => 'incoming_interest',
                'row' => $i,
            ]);
        }

        $timeline = $timeline->sortByDesc(fn (array $x) => $x['at']->timestamp)->take(self::TIMELINE_LIMIT)->values();

        return view('admin.showcase-engine-dashboard.index', compact('stats', 'timeline', 'viewRows', 'outgoing', 'incoming'));
    }
}
