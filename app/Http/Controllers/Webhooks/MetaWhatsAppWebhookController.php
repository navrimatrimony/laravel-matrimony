<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Meta WhatsApp Cloud API webhooks (verification + message status).
 *
 * Configure callback URL in Meta Developer app → WhatsApp → Configuration.
 */
class MetaWhatsAppWebhookController extends Controller
{
    /**
     * GET verification handshake (hub.mode, hub.verify_token, hub.challenge).
     */
    public function verify(Request $request): Response
    {
        // PHP converts dots in query keys to underscores (hub.mode → hub_mode).
        $mode = $request->query('hub_mode');
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge');
        $expected = (string) config('whatsapp.verify_token', '');

        if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $token) && $challenge !== null && $challenge !== '') {
            return response((string) $challenge, 200)->header('Content-Type', 'text/plain');
        }

        abort(403);
    }

    /**
     * POST delivery / inbound payloads.
     */
    public function handle(Request $request): \Illuminate\Http\JsonResponse
    {
        $secret = trim((string) config('whatsapp.app_secret', ''));
        if ($secret !== '') {
            $sig = (string) $request->header('X-Hub-Signature-256', '');
            if ($sig === '' || ! str_starts_with($sig, 'sha256=')) {
                abort(403);
            }
            $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);
            if (! hash_equals($expected, $sig)) {
                abort(403);
            }
        }

        if (config('app.debug')) {
            Log::debug('whatsapp_webhook_payload', ['payload' => $request->all()]);
        }

        return response()->json(['success' => true]);
    }
}
