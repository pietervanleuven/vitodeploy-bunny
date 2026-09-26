<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Listeners;

use App\Enums\BackupFileStatus;
use App\Enums\BackupType;
use App\Facades\Notifier;
use App\Models\BackupFile;
use App\Notifications\FailedToDeleteBackupFileFromProvider;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SSH\Storage\BunnyStorage;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\StorageProviders\Bunny;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deletes the remote copy of a backup file stored in Bunny Storage.
 *
 * Vito core only composes remote paths for its built-in storage providers
 * (BackupFile::path() returns '' for plugin providers), so the SSH storage
 * handler receives an empty path on delete and cannot act. This listener
 * hooks the BackupFile "deleting" event instead: at that point the model
 * is available and the remote path can be recomposed the same way the
 * upload composed it ({path prefix}/{name}{extension}).
 *
 * Failure mirrors BackupFile::deleteFile(): the file is flagged
 * DELETE_FAILED, the user is notified and the database row is kept by
 * cancelling the delete.
 */
class DeleteRemoteBackupFile
{
    public function __invoke(BackupFile $file): ?bool
    {
        if (! $this->shouldHandle($file)) {
            return null;
        }

        $backup = $file->backup;

        try {
            $storage = $backup->storage->provider()->ssh($backup->server);

            if (! $storage instanceof BunnyStorage) {
                return null;
            }

            $storage->delete($storage->remotePath($this->fileName($file)));
        } catch (Throwable $e) {
            $file->status = BackupFileStatus::DELETE_FAILED;
            $file->message = Str::limit($e->getMessage(), 1000);
            $file->save();

            Notifier::send($backup->server, new FailedToDeleteBackupFileFromProvider($file));

            return false;
        }

        return null;
    }

    /**
     * Only act for files that Vito is explicitly deleting from a Bunny
     * storage provider. Cascading deletes (e.g. removing a backup whose
     * files were never uploaded) leave the status untouched and are ignored.
     */
    private function shouldHandle(BackupFile $file): bool
    {
        return $file->status === BackupFileStatus::DELETING
            && $file->backup->storage->provider === Bunny::id();
    }

    /**
     * The file name Vito used for the upload (see BackupFile::tempPath()).
     */
    private function fileName(BackupFile $file): string
    {
        $extension = $file->backup->type === BackupType::DATABASE ? '.sql.gz' : '.tar.gz';

        return $file->name.$extension;
    }
}
