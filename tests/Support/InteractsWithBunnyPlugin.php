<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support;

use App\Enums\BackupFileStatus;
use App\Enums\BackupType;
use App\Models\Backup;
use App\Models\BackupFile;
use App\Models\Database;
use App\Models\DNSProvider;
use App\Models\StorageProvider;
use App\Models\User;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Plugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\StorageProviders\Bunny as BunnyStorageProvider;
use Illuminate\Support\Facades\View;

/**
 * Shared helpers for the plugin test-suite.
 *
 * The suite runs inside a VitoDeploy checkout (see README, "Development"),
 * so Vito's own Tests\TestCase provides $this->user, $this->server and
 * $this->site. Vito's PluginsServiceProvider only boots plugins that are
 * enabled in the database, which is never the case under RefreshDatabase,
 * so every test boots the plugin by hand.
 */
trait InteractsWithBunnyPlugin
{
    protected function bootPlugin(): void
    {
        (new Plugin)->boot();

        // PluginsServiceProvider registers view namespaces from config before
        // this manual boot() runs, so register ours directly.
        View::addNamespace(Plugin::VIEW_NAMESPACE, dirname(__DIR__, 2).'/views');
    }

    /**
     * @return array<string, string>
     */
    protected function bunnyStorageCredentials(array $overrides = []): array
    {
        return array_merge([
            'storage_zone' => 'vito-backups',
            'access_key' => 'storage-access-key',
            'endpoint' => 'storage.bunnycdn.com',
            'path' => 'backups',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $attributes
     */
    protected function bunnyStorageProvider(array $credentials = [], array $attributes = []): StorageProvider
    {
        return StorageProvider::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'project_id' => $this->user->current_project_id,
            'provider' => BunnyStorageProvider::id(),
            'profile' => 'bunny',
            'credentials' => $this->bunnyStorageCredentials($credentials),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function bunnyDnsProvider(?User $user = null, ?int $projectId = null, string $apiKey = 'dns-api-key', array $attributes = []): DNSProvider
    {
        return DNSProvider::factory()->create(array_merge([
            'user_id' => ($user ?? $this->user)->id,
            'project_id' => $projectId,
            'provider' => BunnyDNS::id(),
            'name' => 'bunny',
            'credentials' => ['api_key' => $apiKey],
            'connected' => true,
        ], $attributes));
    }

    /**
     * Create a backup + backup file that uses the given (Bunny) storage provider.
     */
    protected function bunnyBackupFile(StorageProvider $storage, BackupType $type = BackupType::DATABASE, string $name = 'backup-20260101000000', BackupFileStatus $status = BackupFileStatus::CREATED): BackupFile
    {
        $attributes = [
            'server_id' => $this->server->id,
            'storage_id' => $storage->id,
            'type' => $type->value,
        ];

        if ($type === BackupType::DATABASE) {
            $attributes['database_id'] = Database::factory()->create(['server_id' => $this->server->id])->id;
        } else {
            $attributes['path'] = '/home/vito/site';
        }

        $backup = Backup::factory()->create($attributes);

        return BackupFile::factory()->create([
            'backup_id' => $backup->id,
            'name' => $name,
            'status' => $status,
        ]);
    }
}
