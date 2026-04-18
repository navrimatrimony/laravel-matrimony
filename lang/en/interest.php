<?php

/**
 * Interest flow messages (controller flash/errors).
 */
return [
    'create_profile_first' => 'Please create your matrimony profile first.',
    'cannot_send_to_self' => 'You cannot send interest to your own profile.',
    'cannot_send_to_profile' => 'You cannot send interest to this profile.',
    'blocked_unblock_to_send' => 'You have blocked this profile. Unblock to send interest.',
    'sender_cannot_send_current_state' => 'Your profile cannot send interest in its current state.',
    'sender_min_core_completeness' => 'Your profile must be at least :min% complete (mandatory/core fields) to send interest.',
    'target_min_core_completeness' => 'Interest cannot be sent because this profile does not meet the minimum completeness required by site settings.',
    'receiver_min_core_completeness_accept' => 'Your profile must be at least :min% complete (mandatory/core fields) to accept interest.',
    'daily_limit_reached' => 'Daily interest limit reached. Upgrade to send more.',

    'interest_sent_successfully' => 'Interest sent successfully.',
    'interest_already_processed' => 'This interest is already processed.',
    'only_pending_withdraw' => 'Only pending interests can be withdrawn.',
    'interest_accepted' => 'Interest accepted.',
    'interest_rejected' => 'Interest rejected.',
    'interest_withdrawn_successfully' => 'Interest withdrawn successfully.',

    'showcase_policy_showcase_to_real_blocked' => 'Sending interest from a showcase profile to this member is disabled by site rules.',
    'showcase_policy_real_to_showcase_blocked' => 'Sending interest to a showcase profile from your account is disabled by site rules.',
    'showcase_policy_showcase_to_showcase_blocked' => 'Interest between two showcase profiles is disabled by site rules.',
    'showcase_policy_opposite_gender_required' => 'For this path, interest is only allowed between opposite genders.',
    'showcase_policy_showcase_daily_cap' => 'This showcase profile has reached the maximum number of interests allowed in the last 24 hours.',
    'showcase_policy_showcase_weekly_cap' => 'This showcase profile has reached the maximum number of interests allowed in the last 7 days.',
    'showcase_policy_showcase_sender_cooldown' => 'Please wait before sending another interest from this showcase profile.',
    'showcase_policy_real_to_showcase_daily_cap' => 'You have reached the daily limit for sending interest to showcase profiles.',
    'showcase_policy_real_cannot_accept_from_showcase' => 'Accepting interest from this showcase sender is disabled by site rules.',
    'showcase_policy_showcase_cannot_accept_from_real' => 'This showcase profile cannot accept interest from members under the current rules.',
    'showcase_policy_accept_both_showcase_blocked' => 'Accepting interest when both profiles are showcase is disabled by site rules.',
    'showcase_policy_showcase_sender_withdraw_blocked' => 'Withdrawal of this interest is not allowed for showcase senders under the current rules.',
    'showcase_policy_real_sender_withdraw_to_showcase_blocked' => 'Withdrawal of interest sent to a showcase profile is disabled by site rules.',

    'showcase_policy_stochastic_send_denied' => 'This action did not pass the random check configured for showcase interest (try again later).',
    'showcase_policy_stochastic_accept_denied' => 'Accept did not pass the random check configured for showcase interest (try again later).',
    'showcase_policy_stochastic_reject_denied' => 'Reject did not pass the random check configured for showcase interest (try again later).',
    'showcase_policy_showcase_distinct_receivers_cap' => 'This showcase profile has reached the daily limit for contacting different members.',
];
