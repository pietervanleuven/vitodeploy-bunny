<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\StorageProviders;

use App\Models\Server;
use App\SSH\Storage\Storage;
use App\StorageProviders\AbstractStorageProvider;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SSH\Storage\BunnyStorage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class Bunny extends AbstractStorageProvider
{
    /**
     * Storage endpoints per main region.
     *
     * @var array<int, string>
     */
    public const array ENDPOINTS = [
        'storage.bunnycdn.com',
        'uk.storage.bunnycdn.com',
        'ny.storage.bunnycdn.com',
        'la.storage.bunnycdn.com',
        'sg.storage.bunnycdn.com',
        'se.storage.bunnycdn.com',
        'br.storage.bunnycdn.com',
        'jh.storage.bunnycdn.com',
        'syd.storage.bunnycdn.com',
    ];

    public static function id(): string
    {
        return 'bunny';
    }

    /**
     * @return array<string, mixed>
     */
    public function validationRules(): array
    {
        return [
            'storage_zone' => 'required|string',
            'access_key' => 'required|string',
            'endpoint' => 'required|in:'.implode(',', self::ENDPOINTS),
            'path' => 'nullable|string',
        ];
    }

    public function credentialData(array $input): array
    {
        return [
            'storage_zone' => $input['storage_zone'],
            'access_key' => $input['access_key'],
            'endpoint' => $input['endpoint'],
            'path' => trim($input['path'] ?? '', '/'),
        ];
    }

    public function connect(): bool
    {
        $credentials = $this->storageProvider->credentials;

        try {
            $response = Http::withHeaders([
                'AccessKey' => $credentials['access_key'],
                'Accept' => 'application/json',
            ])->get(sprintf('https://%s/%s/', $credentials['endpoint'], $credentials['storage_zone']));

            if ($response->successful()) {
                return true;
            }

            Log::error('Bunny Storage connection failed', ['status' => $response->status()]);

            return false;
        } catch (Throwable $e) {
            Log::error('Bunny Storage connection exception', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public function ssh(Server $server): Storage
    {
        return new BunnyStorage($server, $this->storageProvider);
    }
}
