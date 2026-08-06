<?php

namespace App\Support;

/**
 * Known feature-flag keys. The framework itself is key-agnostic;
 * constants exist so call sites never scatter magic strings.
 */
final class FeatureFlagKey
{
    public const SHOWCASE_PROFILES = 'showcase_profiles_enabled';
}
