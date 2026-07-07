<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SSH\Storage;

use App\Exceptions\SSHCommandError;
use App\Exceptions\SSHError;
use App\SSH\Storage\AbstractStorage;
use Illuminate\Support\Facades\Log;

class BunnyStorage extends AbstractStorage
{
    /**
     * @throws SSHError
     */
    public function upload(string $src, string $dest): array
    {
        $output = $this->server->ssh()->exec(
            app('view')->make('vitodeploy-bunny::storage.upload', [
                'src' => $src,
                'dest' => $this->preparePath($dest !== '' ? $dest : $this->defaultPath($src)),
                ...$this->credentialVars(),
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
            app('view')->make('vitodeploy-bunny::storage.download', [
                'src' => $this->preparePath($src !== '' ? $src : $this->defaultPath($dest)),
                'dest' => $dest,
                ...$this->credentialVars(),
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
            app('view')->make('vitodeploy-bunny::storage.delete-file', [
                'src' => $this->preparePath($src),
                ...$this->credentialVars(),
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
        $prefix = trim((string) ($this->storageProvider->credentials['path'] ?? ''), '/');
        $name = basename($localPath);

        return $prefix === '' ? $name : $prefix.'/'.$name;
    }

    private function preparePath(string $path): string
    {
        $path = ltrim(trim($path), '/');
        $path = preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '_', $path);

        return preg_replace('/\/+/', '/', (string) $path);
    }

    /**
     * @return array<string, string>
     */
    private function credentialVars(): array
    {
        return [
            'endpoint' => $this->storageProvider->credentials['endpoint'],
            'zone' => $this->storageProvider->credentials['storage_zone'],
            'accessKey' => $this->storageProvider->credentials['access_key'],
        ];
    }
}
