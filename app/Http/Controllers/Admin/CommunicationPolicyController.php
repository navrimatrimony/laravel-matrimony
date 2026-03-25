<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\AdminSetting;
use App\Services\CommunicationPolicyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Day-32 Step 8: Admin communication policy (contact request settings). All changes audited.
 */
class CommunicationPolicyController extends Controller
{
    public function index()
    {
        $current = CommunicationPolicyService::getCurrentForAdmin();
        return view('admin.communication-policy.index', [
            'current' => $current,
        ]);
    }

    public function update(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|min:10|max:500',

            // Messaging policy (chat)
            'allow_messaging' => 'boolean',
            'messaging_mode' => 'required|string|in:free_chat_with_reply_gate,contact_request_required',
            'max_consecutive_messages_without_reply' => 'required|integer|min:1|max:20',
            'reply_gate_cooling_hours' => 'required|integer|min:1|max:720',
            'max_messages_per_day_per_sender' => 'required|integer|min:1|max:500',
            'max_messages_per_week_per_sender' => 'required|integer|min:1|max:5000',
            'max_messages_per_month_per_sender' => 'required|integer|min:1|max:20000',
            'max_new_conversations_per_day' => 'required|integer|min:1|max:500',
            'allow_image_messages' => 'boolean',
            'image_messages_audience' => 'required|string|in:paid_only,all',

            'contact_request_mode' => 'required|string|in:mutual_only,direct_allowed,disabled',
            'reject_cooldown_days' => 'required|integer|min:7|max:365',
            'pending_expiry_days' => 'required|integer|min:1|max:30',
            'max_requests_per_day_per_sender' => 'nullable|integer|min:0',
            'grant_approve_once' => 'boolean',
            'grant_approve_7_days' => 'boolean',
            'grant_approve_30_days' => 'boolean',
            'scope_email' => 'boolean',
            'scope_phone' => 'boolean',
            'scope_whatsapp' => 'boolean',
        ]);
        $validator->sometimes('max_requests_per_day_per_sender', 'nullable', fn () => true);

        if ($validator->fails()) {
            return back()->withErrors($validator)->withInput();
        }

        $reason = $request->input('reason');
        $adminId = auth()->id();
        $prefix = CommunicationPolicyService::KEY_PREFIX;
        $current = CommunicationPolicyService::getCurrentForAdmin();

        $updates = [
            // Messaging policy (chat)
            'allow_messaging' => $request->boolean('allow_messaging'),
            'messaging_mode' => $request->input('messaging_mode'),
            'max_consecutive_messages_without_reply' => (string) $request->input('max_consecutive_messages_without_reply'),
            'reply_gate_cooling_hours' => (string) $request->input('reply_gate_cooling_hours'),
            'max_messages_per_day_per_sender' => (string) $request->input('max_messages_per_day_per_sender'),
            'max_messages_per_week_per_sender' => (string) $request->input('max_messages_per_week_per_sender'),
            'max_messages_per_month_per_sender' => (string) $request->input('max_messages_per_month_per_sender'),
            'max_new_conversations_per_day' => (string) $request->input('max_new_conversations_per_day'),
            'allow_image_messages' => $request->boolean('allow_image_messages'),
            'image_messages_audience' => (string) $request->input('image_messages_audience', 'paid_only'),

            'contact_request_mode' => $request->input('contact_request_mode'),
            'reject_cooldown_days' => (string) $request->input('reject_cooldown_days'),
            'pending_expiry_days' => (string) $request->input('pending_expiry_days'),
            'max_requests_per_day_per_sender' => $request->input('max_requests_per_day_per_sender') ? (string) $request->input('max_requests_per_day_per_sender') : '',
            'grant_approve_once' => $request->boolean('grant_approve_once'),
            'grant_approve_7_days' => $request->boolean('grant_approve_7_days'),
            'grant_approve_30_days' => $request->boolean('grant_approve_30_days'),
            'scope_email' => $request->boolean('scope_email'),
            'scope_phone' => $request->boolean('scope_phone'),
            'scope_whatsapp' => $request->boolean('scope_whatsapp'),
        ];

        $changes = [];
        foreach ($updates as $key => $newVal) {
            $oldVal = $current[$key] ?? null;
            if (is_bool($oldVal)) {
                $oldVal = $oldVal ? '1' : '0';
            }
            $newStr = is_bool($newVal) ? ($newVal ? '1' : '0') : (string) $newVal;
            if ((string) $oldVal !== $newStr) {
                $changes[$key] = ['old' => $oldVal, 'new' => $newStr];
            }
        }

        if (empty($changes)) {
            return back()->with('info', 'No changes to save.');
        }

        foreach ($updates as $key => $value) {
            $settingKey = $prefix . $key;
            $valueToStore = is_bool($value) ? ($value ? '1' : '0') : $value;
            AdminSetting::setValue($settingKey, $valueToStore);
        }

        $reasonText = 'Communication policy update. Reason: ' . $reason . '. Changes: ' . json_encode($changes);
        AdminAuditLog::create([
            'admin_id' => $adminId,
            'action_type' => 'communication_policy_update',
            'entity_type' => 'communication_policy',
            'entity_id' => null,
            'reason' => $reasonText,
            'is_demo' => false,
        ]);

        return back()->with('success', 'Communication policy updated. All changes have been logged.');
    }
}
