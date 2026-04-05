<?php

return [

    /**
     * Free users without an approved upload: max distinct profiles per day
     * where the first photo may be shown unblurred (subject to plan).
     */
    'max_profiles_per_day_without_own_photo' => (int) env('PHOTO_ACCESS_MAX_PROFILES_PER_DAY', 5),

];
