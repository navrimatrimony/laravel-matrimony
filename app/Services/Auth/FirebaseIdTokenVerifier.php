<?php

namespace App\Services\Auth;

use App\Services\Push\FirebasePushService;
use App\Support\MobileNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The ONE place a Firebase ID token is turned into a proven phone number.
 *
 * Why verify the JWT ourselves instead of pulling in the Firebase Admin SDK:
 * verification needs the project id and Google's PUBLIC keys, nothing else.
 * The Admin SDK would need a service-account JSON — a real secret the product
 * owner would have to generate, store on the host and rotate — to establish a
 * fact that requires no secret at all. Push already carries that file for FCM;
 * making phone LOGIN depend on it would mean a missing/expired credentials
 * file locks every Suchak out of the app. So: public keys only.
 *
 * What is checked, in order, all of them, no exceptions:
 *
 *  1. `alg` is exactly RS256. There is no HMAC branch anywhere in this class,
 *     so the classic "alg: HS256, sign with the public key" confusion attack
 *     has nothing to reach. `alg: none` is rejected by the same check.
 *  2. `kid` names a key in Google's currently published set.
 *  3. The RS256 signature verifies against that key.
 *  4. `iss` === https://securetoken.google.com/<project>
 *  5. `aud` === <project>
 *  6. `exp` is in the future, `iat` and `auth_time` are not in the future
 *     (a small configured leeway absorbs clock skew).
 *  7. `sub` is a non-empty uid.
 *  8. `firebase.sign_in_provider` === `phone`. A Google/anonymous token minted
 *     for the SAME project would otherwise sail through 1–7.
 *  9. `phone_number` is present and normalizes to a storable Indian mobile.
 *
 * Anything that fails throws. There is no "probably fine" return value, and
 * there is no path in which a claim from an unverified token is returned.
 */
class FirebaseIdTokenVerifier
{
    private const ISSUER_PREFIX = 'https://securetoken.google.com/';

    private const CACHE_KEY_PREFIX = 'firebase:idtoken:jwks:';

    /** DER AlgorithmIdentifier for rsaEncryption + NULL params. */
    private const RSA_ALGORITHM_IDENTIFIER = '300d06092a864886f70d0101010500';

    public function __construct(private readonly FirebasePushService $push) {}

    /**
     * Is the server able to check tokens at all right now?
     *
     * False means the endpoints must answer 503, NOT fall back to anything.
     */
    public function isConfigured(): bool
    {
        return (bool) config('firebase_auth.enabled', true) && $this->projectId() !== null;
    }

    /**
     * Which Firebase project tokens must have been minted for.
     *
     * Reuses the project id the push channel already owns rather than adding a
     * second source of truth for the same fact; the dedicated env exists only
     * so a host without the FCM service-account file can still verify logins.
     */
    public function projectId(): ?string
    {
        $configured = trim((string) (config('firebase_auth.project_id') ?? ''));
        if ($configured !== '') {
            return $configured;
        }

        $push = trim((string) (config('engagement.push.project_id') ?? ''));
        if ($push !== '') {
            return $push;
        }

        try {
            $fromCredentials = trim((string) ($this->push->projectId() ?? ''));
        } catch (Throwable) {
            $fromCredentials = '';
        }

        return $fromCredentials !== '' ? $fromCredentials : null;
    }

    /**
     * @throws FirebaseIdTokenException always, when the token is not provably good
     */
    public function verify(string $idToken): FirebasePhoneIdentity
    {
        if (! (bool) config('firebase_auth.enabled', true)) {
            throw FirebaseIdTokenException::unavailable(
                'firebase_auth_disabled',
                'Firebase phone sign-in is switched off on this server.'
            );
        }

        $projectId = $this->projectId();
        if ($projectId === null) {
            throw FirebaseIdTokenException::unavailable(
                'firebase_auth_unconfigured',
                'Firebase phone sign-in is not configured on this server (no project id).'
            );
        }

        $idToken = trim($idToken);
        if ($idToken === '') {
            throw FirebaseIdTokenException::rejected('token_missing', 'No Firebase token was sent.');
        }

        $parts = explode('.', $idToken);
        if (count($parts) !== 3) {
            throw FirebaseIdTokenException::rejected('token_malformed', 'Firebase token is not a JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodeJsonSegment($encodedHeader, 'header');
        $claims = $this->decodeJsonSegment($encodedPayload, 'payload');
        $signature = $this->base64UrlDecode($encodedSignature);

        if ($signature === null || $signature === '') {
            throw FirebaseIdTokenException::rejected('token_malformed', 'Firebase token signature is unreadable.');
        }

        // (1) Only RS256. Never read the alg to CHOOSE a verifier — only to refuse.
        if (($header['alg'] ?? null) !== 'RS256') {
            throw FirebaseIdTokenException::rejected(
                'unsupported_algorithm',
                'Firebase token is not signed with RS256.'
            );
        }

        $kid = trim((string) ($header['kid'] ?? ''));
        if ($kid === '') {
            throw FirebaseIdTokenException::rejected('token_malformed', 'Firebase token has no key id.');
        }

        // (2) + (3) signature against Google's published public key for this kid.
        $publicKeyPem = $this->publicKeyFor($kid);
        $this->assertSignature($encodedHeader.'.'.$encodedPayload, $signature, $publicKeyPem);

        // (4) issuer, (5) audience — both bound to OUR project.
        if ((string) ($claims['iss'] ?? '') !== self::ISSUER_PREFIX.$projectId) {
            throw FirebaseIdTokenException::rejected(
                'issuer_mismatch',
                'Firebase token was issued for a different project.'
            );
        }

        if ((string) ($claims['aud'] ?? '') !== $projectId) {
            throw FirebaseIdTokenException::rejected(
                'audience_mismatch',
                'Firebase token audience does not match this project.'
            );
        }

        // (6) lifetime.
        $leeway = max(0, (int) config('firebase_auth.leeway', 60));
        $now = Carbon::now()->getTimestamp();

        $exp = $this->timestampClaim($claims, 'exp');
        if ($exp === null || $exp <= $now - $leeway) {
            throw FirebaseIdTokenException::rejected('token_expired', 'Firebase token has expired.');
        }

        $iat = $this->timestampClaim($claims, 'iat');
        if ($iat === null || $iat > $now + $leeway) {
            throw FirebaseIdTokenException::rejected('token_not_yet_valid', 'Firebase token is not valid yet.');
        }

        $authTime = $this->timestampClaim($claims, 'auth_time');
        if ($authTime !== null && $authTime > $now + $leeway) {
            throw FirebaseIdTokenException::rejected('token_not_yet_valid', 'Firebase token is not valid yet.');
        }

        // (7) subject.
        $uid = trim((string) ($claims['sub'] ?? ''));
        if ($uid === '') {
            throw FirebaseIdTokenException::rejected('subject_missing', 'Firebase token has no subject.');
        }

        // (8) the token must come from the PHONE provider, not merely from a
        // token minted for the same project by some other sign-in method.
        $firebaseClaim = is_array($claims['firebase'] ?? null) ? $claims['firebase'] : null;
        $provider = trim((string) ($firebaseClaim['sign_in_provider'] ?? ''));
        if ($provider !== 'phone') {
            throw FirebaseIdTokenException::rejected(
                'provider_not_phone',
                'Firebase token was not created by phone sign-in.'
            );
        }

        // (9) the number itself.
        $phoneE164 = trim((string) ($claims['phone_number'] ?? ''));
        if ($phoneE164 === '') {
            throw FirebaseIdTokenException::rejected(
                'phone_number_missing',
                'Firebase token carries no verified phone number.'
            );
        }

        $mobile = MobileNumber::normalize($phoneE164);
        if ($mobile === null) {
            throw FirebaseIdTokenException::rejected(
                'phone_number_unsupported',
                'Verified number is not a 10 digit Indian mobile number.'
            );
        }

        return new FirebasePhoneIdentity(
            uid: $uid,
            phoneNumberE164: $phoneE164,
            mobile: $mobile,
            authenticatedAt: Carbon::createFromTimestampUTC($authTime ?? $iat),
            expiresAt: Carbon::createFromTimestampUTC($exp),
            signInProvider: $provider,
        );
    }

    private function assertSignature(string $signingInput, string $signature, string $publicKeyPem): void
    {
        $key = openssl_pkey_get_public($publicKeyPem);
        if ($key === false) {
            throw FirebaseIdTokenException::unavailable(
                'signing_keys_unusable',
                'Google signing key could not be read.'
            );
        }

        $result = openssl_verify($signingInput, $signature, $key, OPENSSL_ALGO_SHA256);

        if ($result !== 1) {
            throw FirebaseIdTokenException::rejected(
                'signature_invalid',
                'Firebase token signature is not valid.'
            );
        }
    }

    /**
     * PEM public key for one `kid`, from Google's published set.
     *
     * A kid we have never seen is retried once against a freshly fetched set —
     * Google rotates keys and a cached set going stale mid-rotation must not
     * log anyone out — but an unknown kid after that is a rejection, not a
     * reason to skip the check.
     */
    private function publicKeyFor(string $kid): string
    {
        $keys = $this->publishedKeys(false);

        if (! array_key_exists($kid, $keys)) {
            $keys = $this->publishedKeys(true);
        }

        if (! array_key_exists($kid, $keys)) {
            throw FirebaseIdTokenException::rejected(
                'unknown_signing_key',
                'Firebase token was signed with an unrecognised key.'
            );
        }

        return $keys[$kid];
    }

    /**
     * @return array<string, string> kid => PEM public key
     */
    private function publishedKeys(bool $forceRefresh): array
    {
        $url = (string) config('firebase_auth.jwks_url');
        $cacheKey = self::CACHE_KEY_PREFIX.sha1($url);

        if ($forceRefresh) {
            Cache::forget($cacheKey);
        }

        $cached = Cache::get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(max(1, (int) config('firebase_auth.http_timeout', 5)))
                ->acceptJson()
                ->get($url);
        } catch (Throwable) {
            throw FirebaseIdTokenException::unavailable(
                'signing_keys_unavailable',
                'Google signing keys could not be fetched.'
            );
        }

        if (! $response->successful()) {
            throw FirebaseIdTokenException::unavailable(
                'signing_keys_unavailable',
                'Google signing keys could not be fetched.'
            );
        }

        $body = $response->json();
        $keys = is_array($body) ? $this->parseKeySet($body) : [];

        if ($keys === []) {
            throw FirebaseIdTokenException::unavailable(
                'signing_keys_unavailable',
                'Google signing keys could not be read.'
            );
        }

        Cache::put($cacheKey, $keys, max(60, (int) config('firebase_auth.jwks_ttl', 3600)));

        return $keys;
    }

    /**
     * Understands both shapes Google publishes for these keys:
     *
     *  - RFC 7517 JWK set: {"keys":[{"kid":..,"kty":"RSA","n":..,"e":..}]}
     *  - the x509 map:     {"<kid>":"-----BEGIN CERTIFICATE-----..."}
     *
     * @param  array<mixed>  $body
     * @return array<string, string>
     */
    private function parseKeySet(array $body): array
    {
        $keys = [];

        if (isset($body['keys']) && is_array($body['keys'])) {
            foreach ($body['keys'] as $jwk) {
                if (! is_array($jwk)) {
                    continue;
                }

                $kid = trim((string) ($jwk['kid'] ?? ''));
                $kty = trim((string) ($jwk['kty'] ?? ''));
                if ($kid === '' || $kty !== 'RSA') {
                    continue;
                }

                // A key published for encryption must never verify a signature.
                $use = trim((string) ($jwk['use'] ?? 'sig'));
                if ($use !== 'sig') {
                    continue;
                }

                $modulus = $this->base64UrlDecode((string) ($jwk['n'] ?? ''));
                $exponent = $this->base64UrlDecode((string) ($jwk['e'] ?? ''));
                if ($modulus === null || $exponent === null || $modulus === '' || $exponent === '') {
                    continue;
                }

                $keys[$kid] = $this->rsaPublicKeyPem($modulus, $exponent);
            }

            return $keys;
        }

        foreach ($body as $kid => $certificate) {
            if (! is_string($kid) || ! is_string($certificate)) {
                continue;
            }

            if (! str_contains($certificate, 'BEGIN CERTIFICATE')) {
                continue;
            }

            $keys[$kid] = $certificate;
        }

        return $keys;
    }

    /**
     * SubjectPublicKeyInfo PEM from a raw RSA modulus and exponent.
     *
     * openssl_pkey_get_public() will not take n/e directly, and building the
     * DER by hand is 20 lines with no dependency — considerably less than the
     * cost of dragging in a JOSE library for one key format.
     */
    private function rsaPublicKeyPem(string $modulus, string $exponent): string
    {
        $rsaPublicKey = $this->derSequence(
            $this->derInteger($modulus).$this->derInteger($exponent)
        );

        $bitString = "\x03".$this->derLength(strlen($rsaPublicKey) + 1)."\x00".$rsaPublicKey;
        $algorithm = (string) hex2bin(self::RSA_ALGORITHM_IDENTIFIER);

        $spki = $this->derSequence($algorithm.$bitString);

        return "-----BEGIN PUBLIC KEY-----\n"
            .chunk_split(base64_encode($spki), 64, "\n")
            ."-----END PUBLIC KEY-----\n";
    }

    private function derSequence(string $contents): string
    {
        return "\x30".$this->derLength(strlen($contents)).$contents;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }

        // DER integers are signed: a leading bit of 1 would read as negative.
        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".$this->derLength(strlen($bytes)).$bytes;
    }

    private function derLength(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonSegment(string $segment, string $what): array
    {
        $json = $this->base64UrlDecode($segment);
        if ($json === null) {
            throw FirebaseIdTokenException::rejected('token_malformed', "Firebase token {$what} is unreadable.");
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            throw FirebaseIdTokenException::rejected('token_malformed', "Firebase token {$what} is unreadable.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    private function timestampClaim(array $claims, string $key): ?int
    {
        $value = $claims[$key] ?? null;

        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d{1,12}$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($value, true);

        return $decoded === false ? null : $decoded;
    }
}
