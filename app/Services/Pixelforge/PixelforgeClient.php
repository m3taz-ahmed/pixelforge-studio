<?php

namespace App\Services\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for the Pixelforge image-generation API.
 *
 * Two behaviours matter here and are deliberately explicit rather than hidden
 * inside Http::retry():
 *  - POST /generations always carries a caller-provided Idempotency-Key, so a
 *    retry (ours or a reconcile job's) can never create a second generation.
 *  - 429/503 retries honour the provider's Retry-After header; without it we
 *    back off exponentially. Retries are capped — a caller that still fails
 *    gets a PixelforgeException to handle.
 */
class PixelforgeClient
{
    private const MAX_ATTEMPTS = 4;

    private const MAX_WAIT_SECONDS = 30;

    /** @var callable|null Injected for tests so retries do not actually sleep. */
    private $sleeper = null;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {}

    public function setSleeper(callable $sleeper): void
    {
        $this->sleeper = $sleeper;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function createGeneration(array $payload, string $idempotencyKey): array
    {
        return $this->withRetries(
            fn (): Response => $this->request()
                ->withHeader('Idempotency-Key', $idempotencyKey)
                ->post('/generations', $payload)
        );
    }

    /**
     * @return array<string, mixed>|null Null when the provider returns 404.
     *
     * @throws PixelforgeException
     */
    public function getGeneration(string $providerId): ?array
    {
        $response = $this->request()->get("/generations/{$providerId}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw PixelforgeException::fromResponse(
                $response->status(), $response->json(), $response->header('Retry-After')
            );
        }

        return $response->json();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function usage(string $startDate, string $endDate): array
    {
        return $this->withRetries(
            fn (): Response => $this->request()->get('/usage', [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ])
        );
    }

    /**
     * Public pricing endpoint — no authentication required.
     *
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    public function pricing(): array
    {
        return $this->withRetries(
            fn (): Response => Http::baseUrl($this->baseUrl)
                ->acceptJson()
                ->timeout(15)
                ->get('/pricing')
        );
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PixelforgeException
     */
    private function withRetries(callable $send): array
    {
        $attempt = 0;

        while (true) {
            try {
                $response = $send();
            } catch (ConnectionException $e) {
                if (++$attempt >= self::MAX_ATTEMPTS) {
                    throw PixelforgeException::connection($e);
                }
                $this->sleep($this->backoffSeconds($attempt));

                continue;
            }

            if ($response->successful()) {
                return $response->json();
            }

            $retryable = in_array($response->status(), [429, 503], true);

            if (! $retryable || ++$attempt >= self::MAX_ATTEMPTS) {
                throw PixelforgeException::fromResponse(
                    $response->status(), $response->json(), $response->header('Retry-After')
                );
            }

            $this->sleep($this->waitSeconds($response, $attempt));
        }
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30);
    }

    private function waitSeconds(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');

        if (is_numeric($retryAfter)) {
            return min((int) $retryAfter, self::MAX_WAIT_SECONDS);
        }

        return $this->backoffSeconds($attempt);
    }

    private function backoffSeconds(int $attempt): int
    {
        return min(2 ** $attempt, self::MAX_WAIT_SECONDS);
    }

    private function sleep(int $seconds): void
    {
        if ($seconds <= 0) {
            return;
        }

        ($this->sleeper ?? 'sleep')($seconds);
    }

    public static function fromConfig(): self
    {
        return new self(
            baseUrl: config('services.pixelforge.base_url'),
            apiKey: config('services.pixelforge.key'),
        );
    }
}
