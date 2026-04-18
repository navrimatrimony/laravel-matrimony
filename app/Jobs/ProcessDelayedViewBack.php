<?php

namespace App\Jobs;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Services\ViewTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/*
|--------------------------------------------------------------------------
| ProcessDelayedViewBack Job
|--------------------------------------------------------------------------
|
| Creates a delayed view-back (showcase views real) after admin-configured delay.
| Respects 24h cap per showcase–real pair. No recursion.
|
*/
class ProcessDelayedViewBack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $showcaseProfileId;
    public int $realProfileId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $showcaseProfileId, int $realProfileId)
    {
        $this->showcaseProfileId = $showcaseProfileId;
        $this->realProfileId = $realProfileId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Re-check 24h cap at execution time (delay may have passed since dispatch)
        $since = now()->subDay();
        $exists = ProfileView::where('viewer_profile_id', $this->showcaseProfileId)
            ->where('viewed_profile_id', $this->realProfileId)
            ->where('created_at', '>=', $since)
            ->exists();

        if ($exists) {
            return;
        }

        // Re-check block status (uses ViewTrackingService as single source)
        if (ViewTrackingService::isBlocked($this->showcaseProfileId, $this->realProfileId)) {
            return;
        }

        // Load profiles
        $showcaseProfile = MatrimonyProfile::find($this->showcaseProfileId);
        $realProfile = MatrimonyProfile::find($this->realProfileId);

        if (! $showcaseProfile || ! $realProfile) {
            return;
        }

        // Create view-back
        ProfileView::create([
            'viewer_profile_id' => $showcaseProfile->id,
            'viewed_profile_id' => $realProfile->id,
        ]);

        ViewTrackingService::consumeDailyProfileViewUsageForViewer($showcaseProfile);

        ViewTrackingService::touchViewerLastSeenForPresence($showcaseProfile);

        // Notify real user (with dedup guard from service).
        ViewTrackingService::notifyProfileViewIfEligible($realProfile->user, $showcaseProfile, true);
    }
}
