<?php

/*
| Member "Email" settings page — verifying the current address, or replacing it.
|
| Both are the same server flow (send a code to the address being claimed, write
| it only once the code checks out), so the copy separates them by intent rather
| than by mechanism.
*/

return [
    'title' => 'Email',
    'intro' => 'Your email is saved only after you prove you can receive mail at it. We send a 6-digit code to the address and verify that code here.',

    'current_email' => 'Current email',
    'no_email' => 'No email added yet',
    'verified' => 'Verified',
    'unverified' => 'Not verified',

    'verify_current_heading' => 'Verify this email',
    'verify_current_hint' => 'Send a code to the address already on your account.',
    'verify_current_cta' => 'Send code',

    'change_heading' => 'Change email',
    'change_hint' => 'Enter the new address. Your current email stays in place until the new one is verified.',
    'add_heading' => 'Add email',
    'add_hint' => 'Enter the address you want on this account.',
    'new_email_label' => 'New email address',
    'change_cta' => 'Send code to new address',

    'code_heading' => 'Enter the 6-digit code',
    'code_sent_to' => 'We sent a code to :email.',
    'code_label' => '6-digit code',
    'code_expires_in' => 'The code is valid for :seconds seconds.',
    'resend_after' => 'You can request another code after :seconds seconds.',
    'verify_cta' => 'Verify and save',
    'cancel_cta' => 'Cancel',
    'dev_code_notice' => 'Test server code: :otp',

    'status_sent' => 'Code sent. Enter it below to finish.',
    'status_verified' => 'Email verified and saved.',
    'challenge_expired' => 'That code request has expired. Request a new code.',

    'back_to_security' => 'Back to Account & Security',
];
