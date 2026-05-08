{{-- Fixture blade for lineage regex detection --}}
{{ $profile->height_cm }}
{{ $user->height }}
{{ $profile->city ?? $user->city }}
{{ optional($profile)->education }}
{{ data_get($profile, 'mother_tongue') }}
{{ $profile['occupation'] }}
