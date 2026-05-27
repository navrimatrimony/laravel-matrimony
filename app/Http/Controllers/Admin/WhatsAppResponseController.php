<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminSetting;
use App\Models\MediationRequest;
use App\Services\AuditLogService;
use App\Services\MediationRequestService;
use App\Services\WhatsAppResponseDeliveryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WhatsAppResponseController extends Controller
{
    public function __construct(
        private readonly MediationRequestService $mediationRequestService,
        private readonly WhatsAppResponseDeliveryService $deliveryService,
    ) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'delivery_status' => ['nullable', 'string', Rule::in($this->deliveryStatuses())],
            'status' => ['nullable', 'string', Rule::in($this->businessStatuses())],
            'channel_mode' => ['nullable', 'string', Rule::in($this->channelModes())],
            'quick' => ['nullable', 'string', Rule::in(['pending', 'reminder_due', 'expired', 'responded'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $requests = MediationRequest::query()
            ->with(['sender.matrimonyProfile', 'receiver.matrimonyProfile', 'senderProfile', 'receiverProfile'])
            ->when($filters['delivery_status'] ?? null, fn ($query, string $status) => $query->where('delivery_status', $status))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($filters['channel_mode'] ?? null, fn ($query, string $mode) => $query->where('channel_mode', $mode))
            ->when(($filters['quick'] ?? null) === 'pending', fn ($query) => $query->where('status', MediationRequest::STATUS_PENDING))
            ->when(($filters['quick'] ?? null) === 'reminder_due', fn ($query) => $query
                ->where('status', MediationRequest::STATUS_PENDING)
                ->whereNull('responded_at')
                ->whereNull('expired_at')
                ->whereNull('first_reminder_sent_at')
                ->whereNotNull('first_reminder_due_at')
                ->where('first_reminder_due_at', '<=', now()))
            ->when(($filters['quick'] ?? null) === 'expired', fn ($query) => $query
                ->whereNull('responded_at')
                ->where(function ($inner) {
                    $inner->where('delivery_status', MediationRequest::DELIVERY_EXPIRED)
                        ->orWhereNotNull('expired_at')
                        ->orWhere('expires_at', '<=', now());
                }))
            ->when(($filters['quick'] ?? null) === 'responded', fn ($query) => $query->whereNotNull('responded_at'))
            ->when($filters['from'] ?? null, fn ($query, string $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, string $to) => $query->whereDate('created_at', '<=', $to))
            ->latest()
            ->paginate(25)
            ->withQueryString();

        $payloadPreviews = $requests->getCollection()
            ->mapWithKeys(fn (MediationRequest $requestRow) => [
                $requestRow->id => $this->deliveryService->buildPayload($requestRow),
            ])
            ->all();

        return view('admin.whatsapp-response.index', [
            'settings' => $this->mediationRequestService->settings(),
            'requests' => $requests,
            'payloadPreviews' => $payloadPreviews,
            'providerStatus' => $this->deliveryService->providerStatus(),
            'filters' => $filters,
            'deliveryStatuses' => $this->deliveryStatuses(),
            'businessStatuses' => $this->businessStatuses(),
            'channelModes' => $this->channelModes(),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'whatsapp_response_enabled' => ['nullable', 'in:0,1'],
            'whatsapp_response_channel_mode' => ['required', 'string', Rule::in($this->channelModes())],
            'whatsapp_response_first_reminder_hours' => ['required', 'integer', 'min:1', 'max:168'],
            'whatsapp_response_expire_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'whatsapp_response_request_cooldown_days' => ['required', 'integer', 'min:0', 'max:365'],
            'whatsapp_response_allow_manual_send' => ['nullable', 'in:0,1'],
            'whatsapp_response_allow_manual_reminder' => ['nullable', 'in:0,1'],
            'whatsapp_response_photo_in_summary' => ['nullable', 'in:0,1'],
            'whatsapp_response_profile_link_enabled' => ['nullable', 'in:0,1'],
        ]);

        $before = $this->mediationRequestService->settings();
        $updates = [
            MediationRequestService::SETTING_ENABLED => $request->boolean('whatsapp_response_enabled') ? '1' : '0',
            MediationRequestService::SETTING_CHANNEL_MODE => $data['whatsapp_response_channel_mode'],
            MediationRequestService::SETTING_FIRST_REMINDER_HOURS => (string) $data['whatsapp_response_first_reminder_hours'],
            MediationRequestService::SETTING_EXPIRE_HOURS => (string) $data['whatsapp_response_expire_hours'],
            MediationRequestService::SETTING_REQUEST_COOLDOWN_DAYS => (string) $data['whatsapp_response_request_cooldown_days'],
            MediationRequestService::SETTING_ALLOW_MANUAL_SEND => $request->boolean('whatsapp_response_allow_manual_send') ? '1' : '0',
            MediationRequestService::SETTING_ALLOW_MANUAL_REMINDER => $request->boolean('whatsapp_response_allow_manual_reminder') ? '1' : '0',
            MediationRequestService::SETTING_PHOTO_IN_SUMMARY => $request->boolean('whatsapp_response_photo_in_summary') ? '1' : '0',
            MediationRequestService::SETTING_PROFILE_LINK_ENABLED => $request->boolean('whatsapp_response_profile_link_enabled') ? '1' : '0',
        ];

        foreach ($updates as $key => $value) {
            AdminSetting::setValue($key, $value);
        }

        $after = $this->mediationRequestService->settings();
        AuditLogService::log(
            $request->user(),
            'whatsapp_response_settings_update',
            'AdminSetting',
            null,
            'Reason: '.$data['reason'].'. Changes: '.json_encode(['before' => $before, 'after' => $after]),
            false
        );

        return back()->with('success', 'WhatsApp Response settings updated.');
    }

    public function manualAction(Request $request, MediationRequest $mediation_request): RedirectResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string', Rule::in(['mark_sent', 'mark_reminder_sent', 'expire', 'cancel'])],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = $this->mediationRequestService->settings();
        if ($data['action'] === 'mark_sent' && ! $settings['allow_manual_send']) {
            return back()->with('error', 'Manual send marking is disabled in settings.');
        }
        if ($data['action'] === 'mark_reminder_sent' && ! $settings['allow_manual_reminder']) {
            return back()->with('error', 'Manual reminder marking is disabled in settings.');
        }

        $before = $this->snapshot($mediation_request);
        $updated = match ($data['action']) {
            'mark_sent' => $this->mediationRequestService->markAsSent($mediation_request),
            'mark_reminder_sent' => $this->mediationRequestService->markReminderSent($mediation_request),
            'expire' => $this->mediationRequestService->expire($mediation_request),
            'cancel' => $this->mediationRequestService->cancel($mediation_request),
        };
        $after = $this->snapshot($updated);

        AuditLogService::log(
            $request->user(),
            'whatsapp_response_'.$data['action'],
            'MediationRequest',
            $updated->id,
            'Reason: '.($data['reason'] ?? 'Manual simulation action').'. Values: '.json_encode(['before' => $before, 'after' => $after]),
            false
        );

        return back()->with('success', 'Manual simulation action completed.');
    }

    public function runPipelineUpdate(Request $request): RedirectResponse
    {
        $result = $this->mediationRequestService->updateDuePipelineStates();
        AuditLogService::log(
            $request->user(),
            'whatsapp_response_run_pipeline_update',
            'MediationRequest',
            null,
            'Manual pipeline update. Result: '.json_encode($result),
            false
        );

        return back()->with('success', 'Pipeline update completed.');
    }

    /**
     * @return array<int, string>
     */
    private function deliveryStatuses(): array
    {
        return [
            MediationRequest::DELIVERY_PENDING,
            MediationRequest::DELIVERY_QUEUED,
            MediationRequest::DELIVERY_SENT,
            MediationRequest::DELIVERY_REMINDER_DUE,
            MediationRequest::DELIVERY_REMINDER_SENT,
            MediationRequest::DELIVERY_RESPONDED,
            MediationRequest::DELIVERY_EXPIRED,
            MediationRequest::DELIVERY_FAILED,
            MediationRequest::DELIVERY_CANCELLED,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function businessStatuses(): array
    {
        return [
            MediationRequest::STATUS_PENDING,
            MediationRequest::STATUS_INTERESTED,
            MediationRequest::STATUS_NOT_INTERESTED,
            MediationRequest::STATUS_NEED_MORE_INFO,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function channelModes(): array
    {
        return [
            MediationRequest::CHANNEL_IN_APP_ONLY,
            MediationRequest::CHANNEL_MANUAL_SIMULATION,
            MediationRequest::CHANNEL_WHATSAPP_API,
            MediationRequest::CHANNEL_WHATSAPP_API_WITH_IN_APP_FALLBACK,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(MediationRequest $request): array
    {
        return [
            'status' => $request->status,
            'delivery_status' => $request->delivery_status,
            'effective_delivery_status' => $request->effectiveDeliveryStatus(),
            'channel_mode' => $request->channel_mode,
            'sent_at' => $request->sent_at?->toIso8601String(),
            'first_reminder_sent_at' => $request->first_reminder_sent_at?->toIso8601String(),
            'expires_at' => $request->expires_at?->toIso8601String(),
            'expired_at' => $request->expired_at?->toIso8601String(),
            'responded_at' => $request->responded_at?->toIso8601String(),
            'delivery_attempts' => $request->delivery_attempts,
        ];
    }
}
