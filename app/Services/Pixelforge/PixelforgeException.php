<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use RuntimeException;
use Throwable;

class PixelforgeException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(
        public readonly ?string $errorCode,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @param  array<string, mixed>|null  $body
     */
    public static function fromResponse(int $status, ?array $body, ?string $retryAfter): self
    {
        $error = is_array($body) ? ($body['error'] ?? []) : [];

        return new self(
            errorCode: is_array($error) ? ($error['code'] ?? null) : null,
            message: is_array($error) ? ($error['message'] ?? "Pixelforge request failed with status {$status}.") : "Pixelforge request failed with status {$status}.",
            status: $status,
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            errors: is_array($error) ? ($error['errors'] ?? []) : [],
        );
    }

    public static function connection(ConnectionException $e): self
    {
        return new self(
            errorCode: 'connection_error',
            message: 'Could not reach Pixelforge. Please try again.',
            previous: $e,
        );
    }

    /**
     * A rejected request creates nothing and costs nothing on the provider side,
     * so it is safe to record the local generation as failed immediately.
     */
    public function isRejected(): bool
    {
        return ! ($this->status === null);
    }
}
