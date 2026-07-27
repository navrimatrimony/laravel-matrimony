<?php

namespace App\Services\Push;

use Google\Auth\Credentials\ServiceAccountCredentials;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Transport for Firebase Cloud Messaging, HTTP v1.
 *
 * Scope: this class knows how to authenticate to Google and put one message on
 * one device token. It knows nothing about WHY a message is being sent — that
 * decision belongs to {@see PushDispatchService}.
 *
 * Two invariants:
 *
 *  1. It NEVER throws. Every public method returns a result array. A dead
 *     Firebase, a missing credentials file, an expired key or a network timeout
 *     must not turn "member sent an interest" into an HTTP 500.
 *
 *  2. It prunes dead tokens. FCM answers UNREGISTERED for a token whose app was
 *     uninstalled and INVALID_ARGUMENT for a malformed one. Both mean the row is
 *     garbage; left alone they accumulate forever and every future send pays for
 *     them. Pruning happens through DeviceTokenService, the only writer.
 */
class FirebasePushService
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    private const TOKEN_CACHE_PREFIX = 'fcm:access-token:';

    /**
     * Short negative cache after an auth failure.
     *
     * Without it, a revoked service-account key would make every single request
     * that creates a notification pay a full failed OAuth round trip. One minute
     * is long enough to protect the request path and short enough that fixing the
     * key takes effect almost immediately.
     */
    private const AUTH_FAILURE_BACKOFF_SECONDS = 60;

    /** FCM error codes that mean "this token is dead, delete the row". */
    private const DEAD_TOKEN_ERRORS = ['UNREGISTERED', 'INVALID_ARGUMENT'];

    private const DEAD_TOKEN_STATUSES = ['NOT_FOUND', 'INVALID_ARGUMENT'];

    public function __construct(private readonly DeviceTokenService $deviceTokens) {}

    /**
     * Is the channel usable right now? Config switch AND readable credentials.
     */
    public function enabled(): bool
    {
        if (! (bool) config('engagement.push.enabled', false)) {
            return false;
        }

        return $this->credentials() !== null;
    }

    public function credentialsPath(): string
    {
        $configured = trim((string) (config('engagement.push.credentials') ?? ''));

        return $configured !== '' ? $configured : storage_path('app/firebase/service-account.json');
    }

    /**
     * The Firebase project the credentials belong to.
     *
     * Read from the service-account JSON rather than from config, so there is
     * exactly one source of truth for "which project is this?". Config may
     * override it, but normally does not need to.
     */
    public function projectId(): ?string
    {
        $configured = trim((string) (config('engagement.push.project_id') ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $credentials = $this->credentials();
        $projectId = trim((string) ($credentials['project_id'] ?? ''));

        return $projectId !== '' ? $projectId : null;
    }

    /**
     * Send one message to many device tokens.
     *
     * FCM HTTP v1 has no multicast endpoint — `messages:send` takes exactly one
     * token — so this loops. At current volume that is correct and simple; if a
     * single notification ever fans out to hundreds of devices, batch it here
     * rather than at the call site.
     *
     * @param  list<string>  $tokens
     * @param  array<string, scalar|null>  $data  deep-link payload; values are stringified
     * @return array{enabled: bool, sent: int, failed: int, pruned: int, results: array<string, array{ok: bool, error: string|null, pruned: bool}>, reason?: string}
     */
    public function sendToTokens(array $tokens, string $title, string $body, array $data = []): array
    {
        $tokens = array_values(array_unique(array_filter(array_map('trim', $tokens), static fn (string $t): bool => $t !== '')));

        $result = ['enabled' => true, 'sent' => 0, 'failed' => 0, 'pruned' => 0, 'results' => []];

        if ($tokens === []) {
            return $result;
        }

        if (! $this->enabled()) {
            Log::info('push.skipped', [
                'reason' => (bool) config('engagement.push.enabled', false)
                    ? 'credentials_missing'
                    : 'disabled_by_config',
                'credentials_path' => $this->credentialsPath(),
                'tokens' => count($tokens),
            ]);

            return ['enabled' => false, 'sent' => 0, 'failed' => 0, 'pruned' => 0, 'results' => [], 'reason' => 'disabled'];
        }

        $accessToken = $this->accessToken();
        $projectId = $this->projectId();

        if ($accessToken === null || $projectId === null) {
            Log::warning('push.auth_unavailable', [
                'has_access_token' => $accessToken !== null,
                'project_id' => $projectId,
            ]);

            return ['enabled' => false, 'sent' => 0, 'failed' => 0, 'pruned' => 0, 'results' => [], 'reason' => 'auth_unavailable'];
        }

        $endpoint = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', $projectId);
        $timeout = max(1, (int) config('engagement.push.timeout', 5));

        foreach ($tokens as $token) {
            $outcome = $this->sendOne($endpoint, $accessToken, $timeout, $token, $title, $body, $data);

            $result['results'][$token] = $outcome;
            $outcome['ok'] ? $result['sent']++ : $result['failed']++;

            if ($outcome['pruned']) {
                $result['pruned']++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array{ok: bool, error: string|null, pruned: bool}
     */
    private function sendOne(
        string $endpoint,
        string $accessToken,
        int $timeout,
        string $token,
        string $title,
        string $body,
        array $data,
    ): array {
        try {
            $response = Http::withToken($accessToken)
                ->timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post($endpoint, ['message' => $this->buildMessage($token, $title, $body, $data)]);
        } catch (Throwable $e) {
            // Connection refused, DNS failure, timeout — never surfaced to the caller.
            Log::warning('push.transport_failed', [
                'token_tail' => $this->tokenTail($token),
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'error' => 'transport: '.$e->getMessage(), 'pruned' => false];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'pruned' => false];
        }

        $payload = (array) $response->json();
        $error = (array) ($payload['error'] ?? []);
        $status = (string) ($error['status'] ?? '');
        $errorCode = $this->fcmErrorCode($error);
        $message = (string) ($error['message'] ?? $response->body());

        $pruned = false;
        if (in_array($errorCode, self::DEAD_TOKEN_ERRORS, true) || in_array($status, self::DEAD_TOKEN_STATUSES, true)) {
            $pruned = $this->deviceTokens->forgetDeadToken($token) > 0;

            // INVALID_ARGUMENT is ambiguous: it is also what FCM answers for a
            // malformed PAYLOAD. Log it loudly so a payload bug is not mistaken
            // for a stream of dead devices.
            $level = $errorCode === 'UNREGISTERED' ? 'info' : 'warning';
            Log::log($level, 'push.token_pruned', [
                'token_tail' => $this->tokenTail($token),
                'status' => $status,
                'error_code' => $errorCode,
                'message' => $message,
                'row_deleted' => $pruned,
            ]);
        } else {
            Log::warning('push.send_failed', [
                'token_tail' => $this->tokenTail($token),
                'http_status' => $response->status(),
                'status' => $status,
                'error_code' => $errorCode,
                'message' => $message,
            ]);
        }

        return ['ok' => false, 'error' => $errorCode !== '' ? $errorCode : ($status !== '' ? $status : 'http_'.$response->status()), 'pruned' => $pruned];
    }

    /**
     * Both a `notification` block and a `data` block, on purpose.
     *
     * `notification` is what makes Android render a tray item while the app is
     * backgrounded or killed — a data-only message is delivered to a handler the
     * OS will not start in that state. `data` is what survives the tap and tells
     * the app where to go.
     *
     * @param  array<string, scalar|null>  $data
     * @return array<string, mixed>
     */
    private function buildMessage(string $token, string $title, string $body, array $data): array
    {
        return [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            // FCM requires every data value to be a string.
            'data' => $this->stringifyData($data),
            'android' => [
                'priority' => 'high',
                'notification' => [
                    'sound' => 'default',
                    'default_vibrate_timings' => true,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, scalar|null>  $data
     * @return array<string, string>
     */
    private function stringifyData(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if ($value === null) {
                continue;
            }

            $out[(string) $key] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
        }

        return $out;
    }

    /**
     * `error.details[].errorCode` carries the specific FcmError; `error.status`
     * is only the generic gRPC status.
     *
     * @param  array<string, mixed>  $error
     */
    private function fcmErrorCode(array $error): string
    {
        foreach ((array) ($error['details'] ?? []) as $detail) {
            if (is_array($detail) && isset($detail['errorCode'])) {
                return (string) $detail['errorCode'];
            }
        }

        return '';
    }

    /**
     * OAuth2 access token for the service account, cached for its lifetime.
     *
     * Google issues these for ~1 hour. Minting one per message would add a full
     * extra round trip to every notification, so the token is cached under a key
     * derived from the credentials themselves — rotating the service account
     * invalidates the cache automatically instead of serving a stale token.
     */
    private function accessToken(): ?string
    {
        $credentials = $this->credentials();
        if ($credentials === null) {
            return null;
        }

        $cacheKey = self::TOKEN_CACHE_PREFIX.sha1((string) ($credentials['client_email'] ?? '').'|'.(string) ($credentials['private_key_id'] ?? ''));

        $cached = Cache::get($cacheKey);
        if (is_string($cached)) {
            // Empty string is the negative-cache marker written after a failure.
            return $cached !== '' ? $cached : null;
        }

        try {
            $auth = (new ServiceAccountCredentials(self::SCOPE, $credentials))->fetchAuthToken();
        } catch (Throwable $e) {
            Log::error('push.oauth_failed', ['error' => $e->getMessage()]);
            Cache::put($cacheKey, '', self::AUTH_FAILURE_BACKOFF_SECONDS);

            return null;
        }

        $accessToken = trim((string) ($auth['access_token'] ?? ''));
        if ($accessToken === '') {
            Log::error('push.oauth_empty_token', ['keys' => array_keys((array) $auth)]);
            Cache::put($cacheKey, '', self::AUTH_FAILURE_BACKOFF_SECONDS);

            return null;
        }

        // Expire early so a token is never used in the last minutes of its life.
        $ttl = max(60, (int) ($auth['expires_in'] ?? 3600) - 300);
        Cache::put($cacheKey, $accessToken, $ttl);

        return $accessToken;
    }

    /**
     * Decoded service-account JSON, or null when it is missing/unreadable/invalid.
     *
     * @return array<string, mixed>|null
     */
    private function credentials(): ?array
    {
        $path = $this->credentialsPath();

        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && isset($decoded['client_email'], $decoded['private_key']) ? $decoded : null;
    }

    /**
     * Registration tokens are credentials — never log one in full.
     */
    private function tokenTail(string $token): string
    {
        return '…'.substr($token, -8);
    }
}
