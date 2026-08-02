<?php

namespace App\Http\Controllers\Suchak;

use App\Http\Controllers\Controller;
use App\Models\SuchakConsent;
use App\Modules\Suchak\Services\SuchakConsentService;
use App\Support\LocalizedText;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use InvalidArgumentException;

class PublicConsentController extends Controller
{
    public function show(
        string $token,
        SuchakConsentService $consentService,
    ): View {
        $consent = $consentService->resolvePublicConsentToken($token);

        return view('suchak.consents.public', [
            'consent' => $consent,
            'token' => $token,
            'summary' => $this->summaryFor($consent),
            'state' => $this->stateFor($consent),
            'message' => null,
        ]);
    }

    public function decision(
        Request $request,
        string $token,
        SuchakConsentService $consentService,
    ): View {
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:accepted,rejected'],
        ]);

        $consent = $consentService->resolvePublicConsentToken($token);
        if ($consent === null) {
            return view('suchak.consents.public', [
                'consent' => null,
                'token' => $token,
                'summary' => [],
                'state' => 'invalid',
                // Same key the page's own invalid-state banner reads: one
                // sentence, whether it arrives as a message or as a state.
                'message' => __('suchak.public_pages.link_invalid'),
            ]);
        }

        try {
            $consent = $consentService->recordPublicConsentDecision(
                $consent,
                (string) $validated['decision'],
                $request->ip(),
                $request->userAgent(),
            );
            $message = $consent->consent_status === SuchakConsent::STATUS_ACCEPTED
                ? __('suchak.public_pages.consent.accepted')
                : __('suchak.public_pages.consent.rejected');
        } catch (InvalidArgumentException $exception) {
            $message = $exception->getMessage();
        }

        return view('suchak.consents.public', [
            'consent' => $consent->fresh(['suchakAccount', 'matrimonyProfile.gender', 'matrimonyProfile.maritalStatus', 'matrimonyProfile.location.parent.parent.parent', 'representation']),
            'token' => $token,
            'summary' => $this->summaryFor($consent),
            'state' => $this->stateFor($consent),
            'message' => $message,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryFor(?SuchakConsent $consent): array
    {
        if ($consent?->matrimonyProfile === null) {
            return [];
        }

        $consent->loadMissing([
            'suchakAccount.cityLocation',
            'suchakAccount.districtLocation',
            'matrimonyProfile.gender',
            'matrimonyProfile.location.parent.parent.parent',
            'representation',
        ]);

        $account = $consent->suchakAccount;
        $profile = $consent->matrimonyProfile;
        $genderKey = (string) ($profile->gender?->key ?? '');

        return [
            'suchak' => [
                'name' => $this->displayText(
                    $account?->suchak_name_mr,
                    $account?->suchak_name,
                    __('profile.suchak_default_name'),
                ),
                'business_name' => $this->displayText($account?->office_name_mr, $account?->office_name),
                'address' => $this->suchakAddress($account),
                'masked_mobile' => $this->maskMobile($account?->mobile_number ?: $account?->whatsapp_number),
                'photo_path' => trim((string) ($account?->profile_photo_path ?? '')),
            ],
            'profile' => [
                /*
                 * The KEY, not the wording. This used to be a Marathi literal,
                 * so an English consent letter carried a Devanagari label above
                 * the name of the person being asked to consent. The page owns
                 * the wording (suchak.public_pages.consent.name_label.*); the
                 * controller only knows which of the three applies.
                 */
                'name_label_key' => match ($genderKey) {
                    'female' => 'bride',
                    'male' => 'groom',
                    default => 'candidate',
                },
                'name' => trim((string) ($profile->full_name ?? '')) ?: __('suchak.labels.common.not_available'),
                'age' => $this->ageYears($profile->date_of_birth),
                'photo_url' => (string) ($profile->profile_photo_url ?? ''),
            ],
        ];
    }

    private function displayText(?string $preferred, ?string $fallback = null, ?string $default = null): ?string
    {
        $resolved = LocalizedText::pick($preferred, $fallback);

        return $resolved !== '' ? $resolved : $default;
    }

    private function suchakAddress(mixed $account): ?string
    {
        if ($account === null) {
            return null;
        }

        $line = $this->displayText($account->address_line_mr ?? null, $account->address_line ?? null);
        if ($line !== null) {
            return $line;
        }

        $parts = array_values(array_filter([
            $account->cityLocation?->localizedName(),
            $account->districtLocation?->localizedName(),
        ]));

        return $parts === [] ? null : implode(', ', array_unique($parts));
    }

    private function ageYears(mixed $dateOfBirth): string
    {
        // Latin digits by product rule, whatever the locale — the only thing
        // that follows the reader here is the "we do not have it" wording.
        $unknown = __('suchak.labels.common.not_available');

        if ($dateOfBirth === null || $dateOfBirth === '') {
            return $unknown;
        }

        try {
            $age = Carbon::parse($dateOfBirth)->age;
        } catch (\Throwable) {
            return $unknown;
        }

        return $age >= 18 && $age <= 100 ? (string) $age : $unknown;
    }

    private function maskMobile(?string $mobile): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $mobile) ?: '';
        if (strlen($digits) < 4) {
            return null;
        }

        return substr($digits, 0, 2).str_repeat('x', max(2, strlen($digits) - 4)).substr($digits, -2);
    }

    private function stateFor(?SuchakConsent $consent): string
    {
        if ($consent === null) {
            return 'invalid';
        }

        if ($consent->public_token_used_at !== null || in_array($consent->consent_status, [
            SuchakConsent::STATUS_ACCEPTED,
            SuchakConsent::STATUS_REJECTED,
            SuchakConsent::STATUS_REVOKED,
            SuchakConsent::STATUS_EXPIRED,
            SuchakConsent::STATUS_CANCELLED,
        ], true)) {
            return $consent->consent_status;
        }

        if ($consent->isTokenExpired()) {
            return 'expired';
        }

        return 'open';
    }
}
