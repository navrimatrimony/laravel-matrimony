<?php

namespace App\Services;

use App\Contracts\WhatsApp\WhatsAppMessageProvider;
use App\Models\MediationRequest;
use App\Models\ProfilePhoto;
use App\Services\Image\ProfilePhotoUrlService;
use App\Services\WhatsApp\MetaWhatsAppMessageProvider;
use App\Services\WhatsApp\NullWhatsAppMessageProvider;
use App\Support\WhatsApp\WhatsAppSendResult;
use Illuminate\Support\Facades\Schema;

class WhatsAppResponseDeliveryService
{
    public function __construct(
        private readonly WhatsAppMessageProvider $provider,
        private readonly MediationRequestService $mediationRequestService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function buildPayload(MediationRequest $request): array
    {
        $request->loadMissing(['sender', 'receiver', 'senderProfile', 'receiverProfile']);

        $settings = $this->mediationRequestService->settings();
        $senderProfile = $request->senderProfile ?? $request->sender?->matrimonyProfile;
        $receiverProfile = $request->receiverProfile ?? $request->receiver?->matrimonyProfile;

        return [
            'type' => 'whatsapp_response_request',
            'request_id' => $request->id,
            'channel_mode' => $request->channel_mode ?? MediationRequest::CHANNEL_MANUAL_SIMULATION,
            'provider' => $this->providerName(),
            'provider_note' => 'Preview only. No WhatsApp API call is made.',
            'receiver_name' => $receiverProfile?->full_name ?? $request->receiver?->name,
            'sender_summary' => [
                'name' => $senderProfile?->full_name ?? $request->sender?->name,
                'education' => $this->cleanSummaryValue($senderProfile?->highest_education ?? null),
                'occupation' => $this->cleanSummaryValue($senderProfile?->occupation_title ?? null),
                'residence' => $senderProfile?->residenceDistrictStateLine() ?: null,
            ],
            'profile_link' => $settings['profile_link_enabled'] && $receiverProfile
                ? route('matrimony.profile.show', $receiverProfile->id)
                : null,
            'photo_url' => $settings['photo_in_summary'] && $senderProfile
                ? $this->approvedPhotoUrl($senderProfile)
                : null,
            'response_options' => [
                'interested' => __('mediation.interested'),
                'not_interested' => __('mediation.not_interested'),
                'need_more_info' => __('mediation.need_more_info'),
                'decide_later' => __('mediation.decide_later'),
                'talks_in_progress' => __('mediation.talks_in_progress'),
            ],
            'privacy_note' => 'Sender mobile number is not included in this payload.',
        ];
    }

    /**
     * @return array{configured_provider: string, active_provider: string, provider_configured: bool, live_send_enabled: bool, meta_core_configured: bool, engagement_template_configured: bool}
     */
    public function providerStatus(): array
    {
        $configuredProvider = strtolower(trim((string) config('whatsapp.response_provider', 'null')));
        $tokenConfigured = trim((string) config('whatsapp.access_token')) !== '';
        $phoneNumberConfigured = trim((string) config('whatsapp.phone_number_id')) !== '';
        $templateConfigured = trim((string) config('whatsapp.engagement_template_name')) !== '';
        $liveEnabled = (bool) config('whatsapp.response_live_enabled', false);

        return [
            'configured_provider' => $configuredProvider !== '' ? $configuredProvider : 'null',
            'active_provider' => $this->providerName(),
            'provider_configured' => $this->provider instanceof MetaWhatsAppMessageProvider,
            'live_send_enabled' => $liveEnabled,
            'meta_core_configured' => $tokenConfigured && $phoneNumberConfigured,
            'engagement_template_configured' => $templateConfigured,
        ];
    }

    public function attemptInitialSend(MediationRequest $request): WhatsAppSendResult
    {
        return $this->attemptProviderSend($request, 'initial');
    }

    public function attemptReminderSend(MediationRequest $request): WhatsAppSendResult
    {
        return $this->attemptProviderSend($request, 'reminder');
    }

    private function attemptProviderSend(MediationRequest $request, string $purpose): WhatsAppSendResult
    {
        $channelMode = $request->channel_mode ?? MediationRequest::CHANNEL_MANUAL_SIMULATION;

        if ($channelMode === MediationRequest::CHANNEL_IN_APP_ONLY) {
            return WhatsAppSendResult::failure('in_app_only', 'WhatsApp provider is not used in in-app only mode.');
        }

        if ($channelMode === MediationRequest::CHANNEL_MANUAL_SIMULATION) {
            return WhatsAppSendResult::failure('manual_simulation', 'Manual simulation mode does not call a WhatsApp provider.');
        }

        $payload = array_merge($this->buildPayload($request), [
            'purpose' => $purpose,
            'recipient_mobile' => $this->recipientMobile($request),
        ]);
        $result = $this->provider->sendTemplateMessage($payload);

        if ($channelMode === MediationRequest::CHANNEL_WHATSAPP_API_WITH_IN_APP_FALLBACK && ! $result->success) {
            $this->recordProviderFailure($request, $result, false);

            return WhatsAppSendResult::failure(
                $result->errorCode ?? 'provider_failed_with_fallback',
                ($result->errorMessage ?? 'WhatsApp provider failed.').' In-app fallback remains available.',
                $result->toArray()
            );
        }

        if ($result->success) {
            $this->recordProviderSuccess($request);
        } else {
            $this->recordProviderFailure($request, $result, true);
        }

        return $result;
    }

    private function cleanSummaryValue(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function approvedPhotoUrl($profile): ?string
    {
        if (Schema::hasTable('profile_photos')) {
            $photo = ProfilePhoto::query()
                ->where('profile_id', $profile->id)
                ->where('is_primary', true)
                ->effectivelyApproved()
                ->orderByDesc('id')
                ->first(['file_path']);

            if ($photo && trim((string) $photo->file_path) !== '') {
                return app(ProfilePhotoUrlService::class)->publicUrl((string) $photo->file_path);
            }
        }

        if ($profile->profile_photo && $profile->photo_approved !== false) {
            return app(ProfilePhotoUrlService::class)->publicUrl((string) $profile->profile_photo);
        }

        return null;
    }

    private function providerName(): string
    {
        if ($this->provider instanceof MetaWhatsAppMessageProvider) {
            return 'meta';
        }
        if ($this->provider instanceof NullWhatsAppMessageProvider) {
            return 'null';
        }

        return $this->provider::class;
    }

    private function recipientMobile(MediationRequest $request): ?string
    {
        $request->loadMissing('receiver');
        $mobile = trim((string) ($request->receiver?->mobile ?? ''));

        return $mobile !== '' ? $mobile : null;
    }

    private function recordProviderSuccess(MediationRequest $request): void
    {
        if ($request->hasResponded() || $request->isDeliveryExpired()) {
            return;
        }

        $request->delivery_status = MediationRequest::DELIVERY_SENT;
        $request->sent_at = now();
        $request->delivery_attempts = (int) $request->delivery_attempts + 1;
        $request->last_delivery_error = null;
        $request->save();
    }

    private function recordProviderFailure(MediationRequest $request, WhatsAppSendResult $result, bool $markFailed): void
    {
        if ($request->hasResponded() || $request->isDeliveryExpired()) {
            return;
        }

        $request->delivery_attempts = (int) $request->delivery_attempts + 1;
        $request->last_delivery_error = mb_substr($result->errorMessage ?? $result->errorCode ?? 'WhatsApp provider failed.', 0, 500);
        if ($markFailed) {
            $request->delivery_status = MediationRequest::DELIVERY_FAILED;
        }
        $request->save();
    }
}
