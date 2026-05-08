<?php

return [
    // Backward-compat shim: keep old key while source-of-truth moves under config/data-governance/.
    'environment_profile' => config('data-governance.platform.environment_profile', env('DATA_AUDIT_ENV_PROFILE', 'local')),
    'entities' => config('data-governance.entities.map', []),
    'notification_hooks' => config('data-governance.platform.notification_hooks', []),
];

