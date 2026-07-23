<?php

namespace App\Http\Controllers\Admin\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakVerificationRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Dedicated Suchak onboarding photo triage queue (profile / office / logo).
 * Approvals still go through AccountVerificationController + lifecycle service.
 *
 * Queues:
 * - needs_review: human must decide (AI review / AI error / legacy pending)
 * - auto_rejected: AI unsafe (admin may override-approve)
 * - auto_passed: AI safe auto-approved (history)
 */
class PhotoReviewController extends Controller
{
    public const QUEUE_NEEDS_REVIEW = 'needs_review';

    public const QUEUE_AUTO_REJECTED = 'auto_rejected';

    public const QUEUE_AUTO_PASSED = 'auto_passed';

    /**
     * @return list<string>
     */
    public static function photoTypes(): array
    {
        return [
            SuchakVerificationRecord::TYPE_PROFILE_PHOTO,
            SuchakVerificationRecord::TYPE_OFFICE_PHOTO,
            SuchakVerificationRecord::TYPE_ORGANIZATION_LOGO,
        ];
    }

    /**
     * @return list<string>
     */
    public static function queues(): array
    {
        return [
            self::QUEUE_NEEDS_REVIEW,
            self::QUEUE_AUTO_REJECTED,
            self::QUEUE_AUTO_PASSED,
        ];
    }

    public function index(Request $request): View
    {
        $photoTypes = self::photoTypes();
        $queue = (string) $request->query('queue', self::QUEUE_NEEDS_REVIEW);
        $queue = in_array($queue, self::queues(), true) ? $queue : self::QUEUE_NEEDS_REVIEW;

        $type = $request->query('verification_type');
        $type = in_array($type, $photoTypes, true) ? $type : null;

        $records = self::photoBaseQuery()
            ->with(['suchakAccount.user', 'adminUser'])
            ->tap(fn (Builder $query) => self::applyQueueFilter($query, $queue))
            ->when($type, fn (Builder $query) => $query->where('verification_type', $type))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $counts = self::queueCounts();

        return view('admin.suchak.photo-reviews.index', [
            'records' => $records,
            'photoTypes' => $photoTypes,
            'queue' => $queue,
            'type' => $type,
            'counts' => $counts,
            'queues' => self::queues(),
        ]);
    }

    /**
     * @return array{needs_review: int, auto_rejected: int, auto_passed: int}
     */
    public static function queueCounts(): array
    {
        return [
            self::QUEUE_NEEDS_REVIEW => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_NEEDS_REVIEW))
                ->count(),
            self::QUEUE_AUTO_REJECTED => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_AUTO_REJECTED))
                ->count(),
            self::QUEUE_AUTO_PASSED => self::photoBaseQuery()
                ->tap(fn (Builder $q) => self::applyQueueFilter($q, self::QUEUE_AUTO_PASSED))
                ->count(),
        ];
    }

    private static function photoBaseQuery(): Builder
    {
        return SuchakVerificationRecord::query()
            ->whereIn('verification_type', self::photoTypes())
            ->whereNotNull('document_path')
            ->where('document_path', '!=', '');
    }

    private static function applyQueueFilter(Builder $query, string $queue): void
    {
        match ($queue) {
            self::QUEUE_AUTO_REJECTED => $query
                ->where('admin_status', SuchakVerificationRecord::STATUS_REJECTED)
                ->where('moderation_decision', SuchakVerificationRecord::MODERATION_REJECTED),
            self::QUEUE_AUTO_PASSED => $query
                ->where('admin_status', SuchakVerificationRecord::STATUS_APPROVED)
                ->where('moderation_decision', SuchakVerificationRecord::MODERATION_SAFE),
            // Pending = needs human review (includes legacy rows without moderation_decision).
            default => $query->where('admin_status', SuchakVerificationRecord::STATUS_PENDING),
        };
    }
}
