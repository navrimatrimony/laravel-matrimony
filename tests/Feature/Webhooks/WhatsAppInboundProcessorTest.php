<?php

use App\Models\BiodataIntake;
use App\Models\IntakeWhatsAppMessage;
use App\Models\IntakeWhatsAppSession;
use App\Models\MatrimonyProfile;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppInboundProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('whatsapp inbound processor persists text message and reuses message idempotently', function () {
    $user = User::factory()->create(['mobile' => '919876543210']);
    $payload = whatsappInboundPayload();
    $processor = app(WhatsAppInboundProcessor::class);
    $expectedReceivedAt = Carbon::createFromTimestamp(1710000000)->toDateTimeString();

    $first = $processor->process($payload);
    $second = $processor->process($payload);

    $session = IntakeWhatsAppSession::first();
    $message = IntakeWhatsAppMessage::first();

    expect($first['sessions_created'])->toBe(1)
        ->and($first['messages_created'])->toBe(1)
        ->and($second['sessions_reused'])->toBe(1)
        ->and($second['messages_existing'])->toBe(1)
        ->and(IntakeWhatsAppSession::count())->toBe(1)
        ->and(IntakeWhatsAppMessage::count())->toBe(1)
        ->and($session->wa_phone_number_id)->toBe('PHONE_NUMBER_ID_1')
        ->and($session->wa_business_account_id)->toBe('WABA_1')
        ->and($session->wa_contact_wa_id)->toBe('919876543210')
        ->and($session->normalized_mobile)->toBe('919876543210')
        ->and($session->linked_user_id)->toBe($user->id)
        ->and($session->source_surface)->toBe('whatsapp')
        ->and($session->session_status)->toBe('open')
        ->and($session->consent_status)->toBe('unknown')
        ->and($session->last_message_at?->toDateTimeString())->toBe($expectedReceivedAt)
        ->and($message->session->is($session))->toBeTrue()
        ->and($message->direction)->toBe('inbound')
        ->and($message->wa_message_id)->toBe('wamid.TEST1')
        ->and($message->message_type)->toBe('text')
        ->and($message->text_body)->toBe('Namaskar, biodata upload karaycha aahe')
        ->and($message->processing_status)->toBe('received')
        ->and($message->webhook_payload_json['message']['id'])->toBe('wamid.TEST1')
        ->and($message->received_at?->toDateTimeString())->toBe($expectedReceivedAt)
        ->and(BiodataIntake::count())->toBe(0)
        ->and(MatrimonyProfile::count())->toBe(0);
});

test('whatsapp webhook controller keeps hmac verification and returns success', function () {
    config(['whatsapp.app_secret' => 'test-secret']);
    $payload = whatsappInboundPayload([
        'message_id' => 'wamid.CONTROLLER',
        'body' => 'Controller webhook test',
    ]);
    $content = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $content, 'test-secret');

    $response = $this
        ->withHeaders([
            'X-Hub-Signature-256' => $signature,
            'Content-Type' => 'application/json',
        ])
        ->postJson('/api/webhooks/whatsapp', $payload);

    $response->assertOk()->assertJson(['success' => true]);

    expect(IntakeWhatsAppSession::count())->toBe(1)
        ->and(IntakeWhatsAppMessage::where('wa_message_id', 'wamid.CONTROLLER')->exists())->toBeTrue();
});

test('whatsapp inbound processor stores document metadata without downloading media', function () {
    $payload = whatsappInboundPayload([
        'message_id' => 'wamid.DOC1',
        'type' => 'document',
        'document' => [
            'id' => 'MEDIA_1',
            'mime_type' => 'application/pdf',
            'filename' => 'candidate.pdf',
        ],
    ]);

    app(WhatsAppInboundProcessor::class)->process($payload);

    $message = IntakeWhatsAppMessage::first();

    expect($message->message_type)->toBe('document')
        ->and($message->media_id)->toBe('MEDIA_1')
        ->and($message->media_mime_type)->toBe('application/pdf')
        ->and($message->media_filename)->toBe('candidate.pdf')
        ->and($message->media_storage_path)->toBeNull()
        ->and(BiodataIntake::count())->toBe(0)
        ->and(MatrimonyProfile::count())->toBe(0);
});

function whatsappInboundPayload(array $overrides = []): array
{
    $message = [
        'from' => $overrides['from'] ?? '919876543210',
        'id' => $overrides['message_id'] ?? 'wamid.TEST1',
        'timestamp' => $overrides['timestamp'] ?? '1710000000',
        'type' => $overrides['type'] ?? 'text',
    ];

    if (($message['type'] ?? null) === 'text') {
        $message['text'] = [
            'body' => $overrides['body'] ?? 'Namaskar, biodata upload karaycha aahe',
        ];
    }

    foreach (['image', 'document', 'audio', 'video', 'interactive'] as $key) {
        if (array_key_exists($key, $overrides)) {
            $message[$key] = $overrides[$key];
        }
    }

    return [
        'object' => 'whatsapp_business_account',
        'entry' => [
            [
                'id' => 'WABA_1',
                'changes' => [
                    [
                        'field' => 'messages',
                        'value' => [
                            'messaging_product' => 'whatsapp',
                            'metadata' => [
                                'display_phone_number' => '15551234567',
                                'phone_number_id' => 'PHONE_NUMBER_ID_1',
                            ],
                            'contacts' => [
                                [
                                    'profile' => [
                                        'name' => 'Test Sender',
                                    ],
                                    'wa_id' => $message['from'],
                                ],
                            ],
                            'messages' => [
                                $message,
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ];
}
