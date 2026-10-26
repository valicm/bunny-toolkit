<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Http;

/**
 * Error response from a bunny.net API.
 */
class ApiException extends \RuntimeException
{
    /**
     * @param int $status HTTP status code.
     * @param string|null $errorKey Bunny's machine-readable error key, when given.
     * @param string|null $field The request field Bunny rejected, when given.
     */
    public function __construct(
        string $message,
        public readonly int $status,
        public readonly ?string $errorKey = null,
        public readonly ?string $field = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $status, $previous);
    }

    /**
     * Whether the same request may succeed later.
     */
    public function isRetryable(): bool
    {
        return $this->status === 429 || $this->status >= 500;
    }
}
