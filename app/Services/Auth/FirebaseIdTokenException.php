<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * A Firebase ID token was not accepted, or could not be checked.
 *
 * `code` is a stable machine string the API layer hands to the client so the
 * client can pick localized copy. It is never a Firebase/Google error code and
 * never leaks key material or token contents.
 */
class FirebaseIdTokenException extends RuntimeException
{
    /** The token itself was rejected — the caller is not authenticated. */
    public const KIND_REJECTED = 'rejected';

    /** We could not check the token (misconfigured server, keys unreachable). */
    public const KIND_UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly string $kind = self::KIND_REJECTED,
    ) {
        parent::__construct($message);
    }

    public static function rejected(string $code, string $message): self
    {
        return new self($code, $message, self::KIND_REJECTED);
    }

    public static function unavailable(string $code, string $message): self
    {
        return new self($code, $message, self::KIND_UNAVAILABLE);
    }

    /**
     * 401 for "your token is no good", 503 for "we could not check it".
     *
     * The two must never collapse into one status: a 503 tells the app to try
     * again later, a 401 tells it to send the Suchak back through Firebase.
     */
    public function statusCode(): int
    {
        return $this->kind === self::KIND_UNAVAILABLE ? 503 : 401;
    }
}
