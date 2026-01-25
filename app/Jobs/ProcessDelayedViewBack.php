<?php

namespace App\Jobs;

use App\Models\MatrimonyProfile;
use App\Models\ProfileView;
use App\Notifications\ProfileViewedNotification;
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
| Creates a delayed view-back (demo views real) after admin-configured delay.
| Respects 24h cap per demo–real pair. No recursion.
|
*/
class ProcessDelayedViewBack implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $demoProfileId;
    public int $realProfileId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $demoProfileId, int $realProfileId)
    {
        $this->demoProfileId = $demoProfileId;
        $this->realProfileId = $realProfileId;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        // Re-check 24h cap at execution time (delay may have passed since dispatch)
        $since = now()->subDay();
        $exists = ProfileView::where('viewer_profile_id', $this->demoProfileId)
            ->where('viewed_profile_id', $this->realProfileId)
            ->where('created_at', '>=', $since)
            ->exists();

        if ($exists) {
            return;
        }

        // Re-check block status (uses ViewTrackingService as single source)
        if (ViewTrackingService::isBlocked($this->demoProfileId, $this->realProfileId)) {
            return;
        }

        // Load profiles
        $demoProfile = MatrimonyProfile::find($this->demoProfileId);
        $realProfile = MatrimonyProfile::find($this->realProfileId);

        if (!$demoProfile || !$realProfile) {
            return;
        }

        // Create view-back
        ProfileView::create([
            'viewer_profile_id' => $demoProfile->id,
            'viewed_profile_id' => $realProfile->id,
        ]);

        // Notify real user
        $realOwner = $realProfile->user;
        if ($realOwner) {
            $realOwner->notify(new ProfileViewedNotification($demoProfile, true));
        }
    }
}
