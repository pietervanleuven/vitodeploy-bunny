<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SSH\Storage;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\SSH\Storage\AbstractStorage;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Plugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\StorageProviders\Bunny;
use Illuminate\Support\Facades\Log;

class BunnyStorage extends AbstractStorage
{
    /**
     * @throws SSHError
     */
    public function upload(string $src, string $dest): array
    {
        $output = $this->server->ssh()->exec(
            app('view')->make(Plugin::VIEW_NAMESPACE.'::storage.upload', [
                'src' => $src,
                'url' => $this->url($dest !== '' ? $dest : $this->defaultPath($src)),
                'accessKey' => $this->credentials()['access_key'],
            ]),
            'upload-to-bunny-storage'
        );

        if (! str_contains($output, 'Upload successful')) {
            Log::error('Failed to upload to Bunny Storage', ['output' => $output]);
            throw new SSHCommandError('Failed to upload to Bunny Storage');
        }

        return [
            'size' => null,
        ];
    }

    /**
     * @throws SSHError
     */
    public function download(string $src, string $dest): void
    {
        $output = $this->server->ssh()->exec(
            app('view')->make(Plugin::VIEW_NAMESPACE.'::storage.download', [
                'url' => $this->url($src !== '' ? $src : $this->defaultPath($dest)),
                'dest' => $dest,
                'accessKey' => $this->credentials()['access_key'],
            ]),
            'download-from-bunny-storage'
        );

        if (! str_contains($output, 'Download successful')) {
            Log::error('Failed to download from Bunny Storage', ['output' => $output]);
            throw new SSHCommandError('Failed to download from Bunny Storage');
        }
    }

    /**
     * @throws SSHError
     */
    public function delete(string $src): void
    {
        // Vito core composes remote paths only for its built-in providers and
        // passes an empty string for plugin providers, so the file cannot be
        // located; skip instead of failing the whole delete operation.
        if (trim($src) === '') {
            Log::warning('Bunny Storage received an empty path for delete; the remote file was not removed');

            return;
        }

        $output = $this->server->ssh()->exec(
            app('view')->make(Plugin::VIEW_NAMESPACE.'::storage.delete-file', [
                'url' => $this->url($src),
                'accessKey' => $this->credentials()['access_key'],
            ]),
            'delete-from-bunny-storage'
        );

        if (! str_contains($output, 'Delete successful')) {
            Log::error('Failed to delete from Bunny Storage', ['output' => $output]);
            throw new SSHCommandError('Failed to delete from Bunny Storage');
        }
    }

    /**
     * Fallback remote path when core passes an empty destination:
     * the configured path prefix plus the local file name.
     */
    private function defaultPath(string $localPath): string
    {
        $prefix = trim($this->credentials()['path'], '/');
        $name = basename($localPath);

        return $prefix === '' ? $name : $prefix.'/'.$name;
    }

    /**
     * The full storage API URL for a remote path. Every component is
     * validated or sanitised here; the Blade scripts wrap the result in
     * escapeshellarg() so it always reaches curl as a single argument.
     *
     * @throws SSHCommandError
     */
    private function url(string $path): string
    {
        $credentials = $this->credentials();

        return sprintf(
            'https://%s/%s/%s',
            $credentials['endpoint'],
            $credentials['storage_zone'],
            $this->preparePath($path)
        );
    }

    private function preparePath(string $path): string
    {
        $path = ltrim(trim($path), '/');
        $path = preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '_', $path);

        return preg_replace('/\/+/', '/', (string) $path);
    }

    /**
     * The provider credentials, validated so that only well-formed values
     * are ever interpolated into a command.
     *
     * @return array{endpoint: string, storage_zone: string, access_key: string, path: string}
     *
     * @throws SSHCommandError
     */
    private function credentials(): array
    {
        $credentials = $this->storageProvider->credentials;

        $endpoint = (string) ($credentials['endpoint'] ?? '');
        $zone = (string) ($credentials['storage_zone'] ?? '');
        $accessKey = (string) ($credentials['access_key'] ?? '');

        if (! in_array($endpoint, Bunny::ENDPOINTS, true)) {
            throw new SSHCommandError('Bunny Storage endpoint is not a known storage endpoint');
        }

        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $zone) !== 1) {
            throw new SSHCommandError('Bunny Storage zone name contains unsupported characters');
        }

        if ($accessKey === '') {
            throw new SSHCommandError('Bunny Storage access key is missing');
        }

        return [
            'endpoint' => $endpoint,
            'storage_zone' => $zone,
            'access_key' => $accessKey,
            'path' => (string) ($credentials['path'] ?? ''),
        ];
    }
}
