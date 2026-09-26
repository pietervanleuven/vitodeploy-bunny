<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support;

use App\Models\DNSProvider;
use App\Models\Site;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class BunnyApi
{
    public const string TYPE_DATA_KEY = 'bunny_cdn';

    public const string API_BASE_URL = 'https://api.bunny.net/';

    /**
     * Seconds to wait for a TCP connection.
     */
    public const int CONNECT_TIMEOUT = 10;

    /**
     * Seconds to wait for the whole request.
     */
    public const int TIMEOUT = 30;

    /**
     * Total attempts (the first request plus retries) for transient failures.
     */
    public const int ATTEMPTS = 3;

    public const int RETRY_DELAY_MS = 500;

    /**
     * Only safe/idempotent requests are retried; a repeated write could
     * otherwise create duplicate records.
     *
     * @var array<int, string>
     */
    private const array RETRYABLE_METHODS = ['GET', 'HEAD', 'DELETE'];

    /**
     * A client for the Bunny management API (DNS, pull zones, purging).
     */
    public static function client(string $apiKey): PendingRequest
    {
        return self::configure(
            Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])->baseUrl(self::API_BASE_URL)
        );
    }

    /**
     * A client for the Edge Storage API of one storage zone endpoint.
     */
    public static function storageClient(string $endpoint, string $accessKey): PendingRequest
    {
        return self::configure(
            Http::withHeaders([
                'AccessKey' => $accessKey,
                'Accept' => 'application/json',
            ])->baseUrl("https://{$endpoint}/")
        );
    }

    /**
     * Whether a failure is worth retrying: the API could not be reached, is
     * rate limiting us, or reported a server-side error.
     */
    public static function isTransient(Throwable $exception): bool
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

    private static function configure(PendingRequest $request): PendingRequest
    {
        return $request
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->retry(
                self::ATTEMPTS,
                self::RETRY_DELAY_MS,
                fn (Throwable $exception, PendingRequest $request, ?string $method = null): bool => self::isTransient($exception)
                    && in_array(strtoupper((string) $method), self::RETRYABLE_METHODS, true),
                throw: false,
            );
    }

    /**
     * The API key saved on the site itself, or the one from a connected
     * Bunny DNS provider (stored encrypted) in the site's project.
     */
    public static function resolveApiKeyForSite(Site $site): ?string
    {
        $apiKey = data_get($site->type_data, self::TYPE_DATA_KEY.'.api_key');

        if (! empty($apiKey)) {
            return $apiKey;
        }

        return self::connectedDnsProviderKey($site->server->project_id);
    }

    public static function connectedDnsProviderKey(?int $projectId = null): ?string
    {
        /** @var ?DNSProvider $dnsProvider */
        $dnsProvider = DNSProvider::query()
            ->where('provider', BunnyDNS::id())
            ->where('connected', true)
            ->when($projectId !== null, function ($query) use ($projectId) {
                $query->where(function ($query) use ($projectId) {
                    $query->whereNull('project_id')->orWhere('project_id', $projectId);
                });
            })
            ->first();

        return $dnsProvider?->credentials['api_key'] ?? null;
    }
}
