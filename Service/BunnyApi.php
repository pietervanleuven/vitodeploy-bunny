<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class BunnyApi
{
    public const string API_BASE_URL = 'https://api.bunny.net/';

    public const int CONNECT_TIMEOUT = 10;

    public const int TIMEOUT = 30;

    public const int ATTEMPTS = 3;

    public const int RETRY_DELAY_MS = 500;

    /** @var array<int, string> */
    private const array RETRYABLE_METHODS = ['GET', 'HEAD', 'DELETE'];

    public function client(string $apiKey): PendingRequest
    {
        return $this->configure(
            Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])->baseUrl(self::API_BASE_URL)
        );
    }

    public function storageClient(string $endpoint, string $accessKey): PendingRequest
    {
        return $this->configure(
            Http::withHeaders([
                'AccessKey' => $accessKey,
                'Accept' => 'application/json',
            ])->baseUrl("https://{$endpoint}/")
        );
    }

    public function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        if ($exception instanceof RequestException) {
            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        }

        return false;
    }

    private function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->retry(
                self::ATTEMPTS,
                self::RETRY_DELAY_MS,
                fn (Throwable $exception, PendingRequest $request, ?string $method = null): bool => $this->isTransient($exception)
                    && in_array(strtoupper((string) $method), self::RETRYABLE_METHODS, true),
                throw: false,
            );
    }
}
