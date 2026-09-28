<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service;

use App\Models\DNSProvider;
use App\Models\Site;
use App\Models\User;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class BunnyCredentialResolver
{
    public const string TYPE_DATA_KEY = 'bunny_cdn';

    public const string KEY_PULL_ZONE_ID = 'pull_zone_id';

    public const string KEY_PULL_ZONE_NAME = 'pull_zone_name';

    public const string KEY_DNS_PROVIDER_ID = 'dns_provider_id';

    public const string KEY_API_KEY_ENCRYPTED = 'api_key_encrypted';

    public function resolveApiKeyForSite(Site $site): ?string
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

        $providerId = $data[self::KEY_DNS_PROVIDER_ID] ?? null;

        if (! is_int($providerId) && ! (is_string($providerId) && ctype_digit($providerId))) {
            return null;
        }

        $projectId = $site->server->project_id;
        $ownerId = $site->server->user_id;

        /** @var ?DNSProvider $dnsProvider */
        $dnsProvider = DNSProvider::query()
            ->whereKey((int) $providerId)
            ->where('user_id', $ownerId)
            ->where('project_id', $projectId)
            ->where('provider', BunnyDNS::id())
            ->where('connected', true)
            ->first();

        return $this->providerKey($dnsProvider);
    }

    public function findDnsProvider(User $user, ?int $projectId): ?DNSProvider
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

    public function providerKey(?DNSProvider $dnsProvider): ?string
    {
        $key = $dnsProvider?->credentials['api_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : null;
    }

    public function encryptApiKey(string $apiKey): string
    {
        return Crypt::encryptString($apiKey);
    }
}
