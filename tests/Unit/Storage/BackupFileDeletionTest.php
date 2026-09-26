<?php

use App\Enums\BackupFileStatus;
use App\Enums\BackupType;
use App\Facades\SSH;
use App\Models\BackupFile;
use App\Models\StorageProvider;
use App\Notifications\FailedToDeleteBackupFileFromProvider;
use App\StorageProviders\Local;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\RunsStorageScripts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class, RunsStorageScripts::class);

beforeEach(function (): void {
    $this->bootPlugin();
    Notification::fake();
});

test('deleting a database backup file removes it from bunny storage', function (): void {
    SSH::fake('Delete successful');
    $file = $this->bunnyBackupFile($this->bunnyStorageProvider(['path' => 'backups']), BackupType::DATABASE, 'db-20260101', BackupFileStatus::DELETING);

    $file->deleteFile();

    $run = $this->runLastScript('200');

    expect($this->curlArg($run['args'], '-X'))->toBe('DELETE')
        ->and(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/backups/db-20260101.sql.gz')
        ->and(BackupFile::find($file->id))->toBeNull();
});

test('deleting a file backup removes the tar archive from bunny storage', function (): void {
    SSH::fake('Delete successful');
    $file = $this->bunnyBackupFile($this->bunnyStorageProvider(['path' => '']), BackupType::FILE, 'files-20260101', BackupFileStatus::DELETING);

    $file->deleteFile();

    $run = $this->runLastScript('200');

    expect(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/files-20260101.tar.gz')
        ->and(BackupFile::find($file->id))->toBeNull();
});

test('a failed remote delete keeps the file and flags it', function (): void {
    SSH::fake('Delete failed with HTTP code 401');
    $file = $this->bunnyBackupFile($this->bunnyStorageProvider(), BackupType::DATABASE, 'db-20260101', BackupFileStatus::DELETING);

    $file->deleteFile();

    $file->refresh();

    expect($file->status)->toBe(BackupFileStatus::DELETE_FAILED)
        ->and($file->message)->toContain('Failed to delete from Bunny Storage');

    Notification::assertSentTo($this->notificationChannel, FailedToDeleteBackupFileFromProvider::class);
});

test('files that are not being deleted are left alone on cascade', function (): void {
    SSH::fake();
    $file = $this->bunnyBackupFile($this->bunnyStorageProvider(), BackupType::DATABASE, 'db-20260101', BackupFileStatus::CREATED);

    $file->delete();

    expect(SSH::getExecutedCommands())->toBeEmpty()
        ->and(BackupFile::find($file->id))->toBeNull();
});

test('files on other storage providers are ignored', function (): void {
    SSH::fake();
    $local = StorageProvider::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
        'provider' => Local::id(),
        'profile' => 'local',
        'credentials' => ['path' => '/home/vito/backups'],
    ]);
    $file = $this->bunnyBackupFile($local, BackupType::DATABASE, 'db-20260101', BackupFileStatus::DELETING);

    $file->delete();

    expect(SSH::getExecutedCommands())->toBeEmpty()
        ->and(BackupFile::find($file->id))->toBeNull();
});
