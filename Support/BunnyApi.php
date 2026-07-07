<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support;

use App\Models\DNSProvider;
use App\Models\Site;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class BunnyApi
{
    public const string TYPE_DATA_KEY = 'bunny_cdn';

    public static function client(string $apiKey): PendingRequest
    {
        return Http::withHeaders([
            'AccessKey' => $apiKey,
            'Accept' => 'application/json',
        ])->baseUrl('https://api.bunny.net/');
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
