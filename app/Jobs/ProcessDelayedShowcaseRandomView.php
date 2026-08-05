<?php

namespace App\Jobs;

use App\Models\MatrimonyProfile;
use App\Services\Showcase\ShowcaseRandomViewService;
use App\Services\ViewTrackingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/*
|--------------------------------------------------------------------------
| ProcessDelayedShowcaseRandomView Job
|--------------------------------------------------------------------------
|
| Spreads the hourly showcase random-view batch across the hour so a member
| never sees several showcase views land on the same second.
|
| The row is still written by the one engine ({@see ViewTrackingService::recordShowcaseRandomProfileView})
| — this job only moves *when* that call happens. Block / showcase-side / daily-cap
| guards therefore stay in that method and are not restated here.
|
*/
class ProcessDelayedShowcaseRandomView implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $showcaseProfileId,
        public int $realProfileId,
    ) {}

    public function handle(): void
    {
        // Re-checked here because other showcase views may have landed on this member
        // between dispatch and execution — the gap rule is about the member, not the pair.
        if (! ShowcaseRandomViewService::minGapSatisfiedForReal($this->realProfileId)) {
            return;
        }

        $showcase = MatrimonyProfile::find($this->showcaseProfileId);
        $real = MatrimonyProfile::find($this->realProfileId);

        if (! $showcase || ! $real) {
            return;
        }

        ViewTrackingService::recordShowcaseRandomProfileView($showcase, $real);
    }
}
