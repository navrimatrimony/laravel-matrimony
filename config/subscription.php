<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Grace period (days)
    |--------------------------------------------------------------------------
    |
    | After ends_at, access may continue until this many days after ends_at.
    | Expiry batch jobs mark subscriptions expired only after grace elapses.
    |
    */
    'grace_days' => 3,
];
