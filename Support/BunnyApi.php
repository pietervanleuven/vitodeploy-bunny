<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support;

use App\Models\DNSProvider;
use App\Models\Site;
use App\Models\User;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class BunnyApi
{
    /**
     * Key under Site::$type_data holding the CDN feature data. Note that
     * type_data is stored as plain JSON and sent to the frontend, so only
     * encrypted secrets or references may be kept there.
     */
    public const string TYPE_DATA_KEY = 'bunny_cdn';

    public const string KEY_PULL_ZONE_ID = 'pull_zone_id';

    public const string KEY_PULL_ZONE_NAME = 'pull_zone_name';

    /**
     * ID of the Bunny DNS provider whose (encrypted) API key is reused.
     */
    public const string KEY_DNS_PROVIDER_ID = 'dns_provider_id';

    /**
     * An API key entered for the site, encrypted with the application key.
     */
    public const string KEY_API_KEY_ENCRYPTED = 'api_key_encrypted';

    /**
     * Plain-text key written by earlier plugin versions. Still honoured for
     * reading; replaced as soon as Setup is run again.
     */
    public const string KEY_LEGACY_API_KEY = 'api_key';

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
     * The API key configured for a site's CDN feature: the encrypted key
     * saved on the site, a legacy plain-text key, or the key of the Bunny
     * DNS provider linked during Setup.
     */
    public static function resolveApiKeyForSite(Site $site): ?string
    {
        $data = data_get($site->type_data, self::TYPE_DATA_KEY);
        $data = is_array($data) ? $data : [];

        $encrypted = $data[self::KEY_API_KEY_ENCRYPTED] ?? null;

        if (is_string($encrypted) && $encrypted !== '') {
            try {
                return Crypt::decryptString($encrypted);
            } catch (DecryptException) {
                Log::warning('Bunny CDN: the stored API key could not be decrypted; re-run Setup', ['site_id' => $site->id]);
            }
        }

        $legacy = $data[self::KEY_LEGACY_API_KEY] ?? null;

        if (is_string($legacy) && $legacy !== '') {
            return $legacy;
        }

        $dnsProviderId = $data[self::KEY_DNS_PROVIDER_ID] ?? null;

        if (is_int($dnsProviderId) || (is_string($dnsProviderId) && ctype_digit($dnsProviderId))) {
            /** @var ?DNSProvider $dnsProvider */
            $dnsProvider = DNSProvider::query()
                ->whereKey((int) $dnsProviderId)
                ->where('provider', BunnyDNS::id())
                ->where('connected', true)
                ->first();

            return self::providerKey($dnsProvider);
        }

        return null;
    }

    /**
     * The connected Bunny DNS provider a user may use for a project: one
     * scoped to that project first, otherwise one of the user's global
     * providers. Mirrors DNSProviderPolicy::view (owner + project scope).
     */
    public static function findDnsProvider(User $user, ?int $projectId): ?DNSProvider
    {
        /** @var ?DNSProvider $dnsProvider */
        $dnsProvider = DNSProvider::query()
            ->where('user_id', $user->id)
            ->where('provider', BunnyDNS::id())
            ->where('connected', true)
            ->where(function ($query) use ($projectId): void {
                $query->whereNull('project_id');

                if ($projectId !== null) {
                    $query->orWhere('project_id', $projectId);
                }
            })
            ->orderByRaw('project_id IS NULL')
            ->orderBy('id')
            ->first();

        return $dnsProvider;
    }

    public static function providerKey(?DNSProvider $dnsProvider): ?string
    {
        $key = $dnsProvider?->credentials['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function encryptApiKey(string $apiKey): string
    {
        return Crypt::encryptString($apiKey);
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
