<?php

namespace App\Services\WhatsApp;

use App\Models\IntakeWhatsAppMessage;
use App\Models\IntakeWhatsAppSession;
use App\Models\User;
use App\Services\Intake\BulkIntakeWhatsAppConsentService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class WhatsAppInboundProcessor
{
    public function __construct(
        private readonly BulkIntakeWhatsAppConsentService $bulkConsentService,
    ) {}

    public function process(array $payload): array
    {
        return DB::transaction(function () use ($payload): array {
            $result = [
                'entries_seen' => 0,
                'changes_seen' => 0,
                'sessions_created' => 0,
                'sessions_reused' => 0,
                'messages_created' => 0,
                'messages_existing' => 0,
                'messages_ignored' => 0,
            ];

            foreach (($payload['entry'] ?? []) as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $result['entries_seen']++;

                foreach (($entry['changes'] ?? []) as $change) {
                    if (! is_array($change)) {
                        continue;
                    }
                    $result['changes_seen']++;

                    $value = is_array($change['value'] ?? null) ? $change['value'] : [];
                    $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                    $contacts = $this->contactsByWaId($value['contacts'] ?? []);

                    foreach (($value['messages'] ?? []) as $message) {
                        if (! is_array($message)) {
                            continue;
                        }

                        $session = $this->sessionForMessage($entry, $metadata, $contacts, $message, $result);
                        if ($session === null) {
                            $result['messages_ignored']++;
                            continue;
                        }

                        $created = $this->recordMessage($session, $payload, $entry, $change, $value, $message);
                        $result[$created ? 'messages_created' : 'messages_existing']++;
                    }

                    foreach (($value['statuses'] ?? []) as $status) {
                        if (! is_array($status)) {
                            continue;
                        }

                        $session = $this->sessionForStatus($entry, $metadata, $status, $result);
                        if ($session === null) {
                            $result['messages_ignored']++;
                            continue;
                        }

                        $created = $this->recordStatus($session, $payload, $entry, $change, $value, $status);
                        $result[$created ? 'messages_created' : 'messages_existing']++;
                    }
                }
            }

            return $result;
        });
    }

    private function sessionForMessage(array $entry, array $metadata, array $contacts, array $message, array &$result): ?IntakeWhatsAppSession
    {
        $waId = $this->stringOrNull($message['from'] ?? null);
        if ($waId === null) {
            return null;
        }

        $contact = is_array($contacts[$waId] ?? null) ? $contacts[$waId] : [];

        return $this->findOrCreateSession(
            $metadata,
            $waId,
            $entry['id'] ?? null,
            $contact,
            $this->timestampOrNull($message['timestamp'] ?? null),
            $result
        );
    }

    private function sessionForStatus(array $entry, array $metadata, array $status, array &$result): ?IntakeWhatsAppSession
    {
        $waId = $this->stringOrNull($status['recipient_id'] ?? null);
        if ($waId === null) {
            return null;
        }

        return $this->findOrCreateSession(
            $metadata,
            $waId,
            $entry['id'] ?? null,
            [],
            $this->timestampOrNull($status['timestamp'] ?? null),
            $result
        );
    }

    private function findOrCreateSession(
        array $metadata,
        string $waId,
        mixed $businessAccountId,
        array $contact,
        ?Carbon $lastMessageAt,
        array &$result
    ): IntakeWhatsAppSession {
        $phoneNumberId = $this->stringOrNull($metadata['phone_number_id'] ?? null);
        $normalizedMobile = $this->normalizeMobile($waId);
        $linkedUser = $normalizedMobile !== null
            ? User::where('mobile', $normalizedMobile)->first()
            : null;

        $session = IntakeWhatsAppSession::where('wa_phone_number_id', $phoneNumberId)
            ->where('wa_contact_wa_id', $waId)
            ->first();

        if ($session === null) {
            $session = IntakeWhatsAppSession::create([
                'wa_phone_number_id' => $phoneNumberId,
                'wa_business_account_id' => $this->stringOrNull($businessAccountId),
                'wa_contact_wa_id' => $waId,
                'normalized_mobile' => $normalizedMobile,
                'linked_user_id' => $linkedUser?->id,
                'actor_type' => $linkedUser === null ? 'unknown' : 'profile_user',
                'source_surface' => IntakeWhatsAppSession::SOURCE_SURFACE_WHATSAPP,
                'session_status' => IntakeWhatsAppSession::STATUS_OPEN,
                'consent_status' => IntakeWhatsAppSession::CONSENT_UNKNOWN,
                'last_message_at' => $lastMessageAt,
                'session_meta_json' => array_filter([
                    'display_phone_number' => $metadata['display_phone_number'] ?? null,
                    'profile_name' => $contact['profile']['name'] ?? null,
                ], static fn (mixed $value): bool => $value !== null),
            ]);
            $result['sessions_created']++;

            return $session;
        }

        $result['sessions_reused']++;
        $dirty = false;

        if ($lastMessageAt !== null && ($session->last_message_at === null || $lastMessageAt->greaterThan($session->last_message_at))) {
            $session->last_message_at = $lastMessageAt;
            $dirty = true;
        }
        if ($session->normalized_mobile === null && $normalizedMobile !== null) {
            $session->normalized_mobile = $normalizedMobile;
            $dirty = true;
        }
        if ($session->linked_user_id === null && $linkedUser !== null) {
            $session->linked_user_id = $linkedUser->id;
            $session->actor_type = 'profile_user';
            $dirty = true;
        }

        if ($dirty) {
            $session->save();
        }

        return $session;
    }

    private function recordMessage(
        IntakeWhatsAppSession $session,
        array $payload,
        array $entry,
        array $change,
        array $value,
        array $message
    ): bool {
        $waMessageId = $this->stringOrNull($message['id'] ?? null);
        if ($waMessageId !== null && IntakeWhatsAppMessage::where('wa_message_id', $waMessageId)->exists()) {
            return false;
        }

        $type = $this->messageType($message['type'] ?? null);
        $media = $this->mediaMetadata($message, $type);

        IntakeWhatsAppMessage::create([
            'intake_whatsapp_session_id' => $session->id,
            'direction' => IntakeWhatsAppMessage::DIRECTION_INBOUND,
            'wa_message_id' => $waMessageId,
            'message_type' => $type,
            'text_body' => $this->textBody($message, $type),
            'media_id' => $media['id'],
            'media_mime_type' => $media['mime_type'],
            'media_filename' => $media['filename'],
            'processing_status' => IntakeWhatsAppMessage::STATUS_RECEIVED,
            'webhook_payload_json' => $this->payloadEvidence($payload, $entry, $change, $value, $message),
            'received_at' => $this->timestampOrNull($message['timestamp'] ?? null),
        ]);

        $this->bulkConsentService->processInboundReply(
            $session,
            $this->textBody($message, $type) ?? '',
            $this->buttonReplyId($message, $type)
        );

        return true;
    }

    private function recordStatus(
        IntakeWhatsAppSession $session,
        array $payload,
        array $entry,
        array $change,
        array $value,
        array $status
    ): bool {
        $waMessageId = $this->stringOrNull($status['id'] ?? null);
        if ($waMessageId !== null && IntakeWhatsAppMessage::where('wa_message_id', $waMessageId)->exists()) {
            return false;
        }

        IntakeWhatsAppMessage::create([
            'intake_whatsapp_session_id' => $session->id,
            'direction' => IntakeWhatsAppMessage::DIRECTION_STATUS,
            'wa_message_id' => $waMessageId,
            'message_type' => IntakeWhatsAppMessage::TYPE_STATUS,
            'processing_status' => IntakeWhatsAppMessage::STATUS_RECEIVED,
            'webhook_payload_json' => $this->payloadEvidence($payload, $entry, $change, $value, $status),
            'received_at' => $this->timestampOrNull($status['timestamp'] ?? null),
        ]);

        return true;
    }

    private function contactsByWaId(mixed $contacts): array
    {
        if (! is_array($contacts)) {
            return [];
        }

        $indexed = [];
        foreach ($contacts as $contact) {
            if (! is_array($contact)) {
                continue;
            }
            $waId = $this->stringOrNull($contact['wa_id'] ?? null);
            if ($waId !== null) {
                $indexed[$waId] = $contact;
            }
        }

        return $indexed;
    }

    private function messageType(mixed $value): string
    {
        $type = $this->stringOrNull($value) ?? IntakeWhatsAppMessage::TYPE_UNKNOWN;

        return in_array($type, IntakeWhatsAppMessage::ALLOWED_MESSAGE_TYPES, true)
            ? $type
            : IntakeWhatsAppMessage::TYPE_UNKNOWN;
    }

    private function textBody(array $message, string $type): ?string
    {
        if ($type === IntakeWhatsAppMessage::TYPE_TEXT) {
            return $this->stringOrNull($message['text']['body'] ?? null);
        }

        if ($type === IntakeWhatsAppMessage::TYPE_INTERACTIVE) {
            return $this->stringOrNull($message['interactive']['button_reply']['title'] ?? null)
                ?? $this->stringOrNull($message['interactive']['list_reply']['title'] ?? null);
        }

        return null;
    }

    private function buttonReplyId(array $message, string $type): ?string
    {
        if ($type !== IntakeWhatsAppMessage::TYPE_INTERACTIVE) {
            return null;
        }

        return $this->stringOrNull($message['interactive']['button_reply']['id'] ?? null)
            ?? $this->stringOrNull($message['interactive']['list_reply']['id'] ?? null);
    }

    /**
     * @return array{id: string|null, mime_type: string|null, filename: string|null}
     */
    private function mediaMetadata(array $message, string $type): array
    {
        $media = in_array($type, [
            IntakeWhatsAppMessage::TYPE_IMAGE,
            IntakeWhatsAppMessage::TYPE_DOCUMENT,
            IntakeWhatsAppMessage::TYPE_AUDIO,
            IntakeWhatsAppMessage::TYPE_VIDEO,
        ], true) && is_array($message[$type] ?? null)
            ? $message[$type]
            : [];

        return [
            'id' => $this->stringOrNull($media['id'] ?? null),
            'mime_type' => $this->stringOrNull($media['mime_type'] ?? null),
            'filename' => $this->stringOrNull($media['filename'] ?? null),
        ];
    }

    private function payloadEvidence(array $payload, array $entry, array $change, array $value, array $message): array
    {
        return [
            'object' => $payload['object'] ?? null,
            'entry' => $entry,
            'change_field' => $change['field'] ?? null,
            'value' => $value,
            'message' => $message,
        ];
    }

    private function normalizeMobile(string $waId): ?string
    {
        $digits = preg_replace('/\D+/', '', $waId);

        return is_string($digits) && $digits !== '' ? $digits : null;
    }

    private function timestampOrNull(mixed $value): ?Carbon
    {
        if (! is_numeric($value)) {
            return null;
        }

        return Carbon::createFromTimestamp((int) $value);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
