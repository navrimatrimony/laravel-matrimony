<?php

return [
    // Signed-in change (POST /api/v1/account/password). 'reset' above belongs to
    // the emailed-link flow — the two are different journeys, not one string.
    'changed' => 'Your password has been updated. Other devices have been signed out.',
    'reset' => 'Your password has been reset!',
    'sent' => 'We have emailed your password reset link!',
    'throttled' => 'Please wait before retrying.',
    'token' => 'This password reset token is invalid.',
    'user' => "We can't find a user with that email address.",
];

