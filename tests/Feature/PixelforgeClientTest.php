<?php

namespace Tests\Feature;

use App\Services\Pixelforge\PixelforgeClient;
use App\Services\Pixelforge\PixelforgeException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixelforgeClientTest extends TestCase
{
    private function client(array &$sleeps): PixelforgeClient
    {
        $client = new PixelforgeClient('https://pixelforge.test/v1', 'pf_test');
        $client->setSleeper(function (int $seconds) use (&$sleeps): null {
            $sleeps[] = $seconds;

            return null;
        });

        return $client;
    }

    public function test_retries_retryable_statuses_honouring_retry_after(): void
    {
        Http::fake([
            '*/v1/generations' => Http::sequence()
                ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '5'])
                ->push(['error' => ['code' => 'overloaded']], 503, ['Retry-After' => '2'])
                ->push(['id' => 'gen_OK', 'status' => 'queued'], 202),
        ]);

        $sleeps = [];
        $object = $this->client($sleeps)->createGeneration(['prompt' => 'x'], 'key-1');

        $this->assertSame('gen_OK', $object['id']);
        $this->assertSame([5, 2], $sleeps);
        Http::assertSentCount(3);
    }

    public function test_gives_up_after_capped_attempts_with_typed_exception(): void
    {
        Http::fake([
            '*/v1/generations' => Http::response(
                ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'Slow down.']],
                429,
                ['Retry-After' => '3']
            ),
        ]);

        $sleeps = [];

        try {
            $this->client($sleeps)->createGeneration(['prompt' => 'x'], 'key-2');
            $this->fail('Expected PixelforgeException');
        } catch (PixelforgeException $e) {
            $this->assertSame('rate_limit_exceeded', $e->errorCode);
            $this->assertSame(429, $e->status);
            $this->assertSame(3, $e->retryAfter);
        }

        Http::assertSentCount(4);
    }

    public function test_non_retryable_errors_fail_immediately(): void
    {
        Http::fake([
            '*/v1/generations' => Http::response(
                ['error' => ['code' => 'validation_failed', 'message' => 'Bad size.']],
                422
            ),
        ]);

        $sleeps = [];

        try {
            $this->client($sleeps)->createGeneration(['prompt' => 'x'], 'key-3');
            $this->fail('Expected PixelforgeException');
        } catch (PixelforgeException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }

        Http::assertSentCount(1);
        $this->assertSame([], $sleeps);
    }
}
