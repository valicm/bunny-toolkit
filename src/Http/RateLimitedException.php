<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Http;

/**
 * Bunny answered 429 Too Many Requests.
 */
final class RateLimitedException extends ApiException
{
    /**
     * @param float|null $retryAfter Seconds to wait, from Retry-After; null when Bunny didn't say.
     */
    public function __construct(
        string $message,
        public readonly ?float $retryAfter,
        ?string $errorKey = null,
        ?string $field = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $errorKey, $field, $previous);
    }
}
