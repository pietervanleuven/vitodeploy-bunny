<?php

use App\Exceptions\SSHCommandError;
use App\Facades\SSH;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\RunsStorageScripts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class, RunsStorageScripts::class);

beforeEach(function (): void {
    $this->bootPlugin();
});

/**
 * Values an attacker could place in a provider credential, a backup path or
 * a file name. None of them may escape their argument.
 */
dataset('hostile values', [
    'single quote and command' => ["'; echo INJECTED; #"],
    'command substitution' => ['$(echo INJECTED)'],
    'backticks' => ['`echo INJECTED`'],
    'and operator' => ['x && echo INJECTED'],
    'pipe' => ['x | echo INJECTED'],
    'double quotes and spaces' => ['a "quoted" value with spaces'],
    'newline' => ["line\necho INJECTED"],
    'html characters' => ['a&b<c>d'],
]);

test('upload passes every value as a single curl argument', function (): void {
    SSH::fake('Upload successful');

    $this->bunnyStorage()->upload('/home/vito/backup-1.sql.gz', 'backups/db/backup-1.sql.gz');

    $run = $this->runLastScript('201');

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Upload successful')
        ->and($this->curlArg($run['args'], '-T'))->toBe('/home/vito/backup-1.sql.gz')
        ->and($this->curlArg($run['args'], '-H'))->toBe('AccessKey: storage-access-key')
        ->and(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/backups/db/backup-1.sql.gz');
});

test('download passes every value as a single curl argument', function (): void {
    SSH::fake('Download successful');

    $this->bunnyStorage()->download('backups/db/backup-1.sql.gz', '/home/vito/backup-1.sql.gz');

    $run = $this->runLastScript('200');

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Download successful')
        ->and($this->curlArg($run['args'], '-o'))->toBe('/home/vito/backup-1.sql.gz')
        ->and($this->curlArg($run['args'], '-H'))->toBe('AccessKey: storage-access-key')
        ->and(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/backups/db/backup-1.sql.gz');
});

test('delete passes every value as a single curl argument', function (): void {
    SSH::fake('Delete successful');

    $this->bunnyStorage()->delete('backups/db/backup-1.sql.gz');

    $run = $this->runLastScript('200');

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Delete successful')
        ->and($this->curlArg($run['args'], '-X'))->toBe('DELETE')
        ->and($this->curlArg($run['args'], '-H'))->toBe('AccessKey: storage-access-key')
        ->and(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/backups/db/backup-1.sql.gz');
});

test('a hostile access key cannot break out of the header argument', function (string $value): void {
    SSH::fake('Upload successful');

    $this->bunnyStorage(['access_key' => $value])->upload('/home/vito/backup.sql.gz', 'backups/backup.sql.gz');

    $run = $this->runLastScript('201');

    expect($run['output'])->not->toContain('INJECTED')
        ->and($this->curlArg($run['args'], '-H'))->toBe('AccessKey: '.$value);
})->with('hostile values');

test('a hostile local file name cannot break out of its argument', function (string $value): void {
    SSH::fake('Upload successful');

    $this->bunnyStorage()->upload('/home/vito/'.$value, 'backups/backup.sql.gz');

    $run = $this->runLastScript('201');

    expect($run['output'])->not->toContain('INJECTED')
        ->and($this->curlArg($run['args'], '-T'))->toBe('/home/vito/'.$value);
})->with('hostile values');

test('a hostile download destination cannot break out of its argument', function (string $value): void {
    SSH::fake('Download failed');

    try {
        $this->bunnyStorage()->download('backups/backup.sql.gz', '/home/vito/'.$value);
    } catch (SSHCommandError) {
        // The fake output makes the PHP side fail; the script itself is what we test.
    }

    $run = $this->runLastScript('500');

    expect($run['output'])->not->toContain('INJECTED')
        ->and($run['exit'])->not->toBe(0)
        ->and($this->curlArg($run['args'], '-o'))->toBe('/home/vito/'.$value);
})->with('hostile values');

test('a hostile path prefix cannot break out of the url argument', function (string $value): void {
    SSH::fake('Upload successful');

    $this->bunnyStorage(['path' => $value])->upload('/home/vito/backup.sql.gz', '');

    $run = $this->runLastScript('201');

    expect($run['output'])->not->toContain('INJECTED')
        ->and(end($run['args']))->toStartWith('https://storage.bunnycdn.com/vito-backups/');
})->with('hostile values');

test('an unknown endpoint is rejected before any command runs', function (): void {
    SSH::fake();

    expect(fn () => $this->bunnyStorage(['endpoint' => 'evil.example.com/$(echo INJECTED)'])->upload('/home/vito/backup.sql.gz', ''))
        ->toThrow(SSHCommandError::class, 'endpoint');

    expect(SSH::getExecutedCommands())->toBeEmpty();
});

test('a malformed zone name is rejected before any command runs', function (string $value): void {
    SSH::fake();

    expect(fn () => $this->bunnyStorage(['storage_zone' => $value])->upload('/home/vito/backup.sql.gz', ''))
        ->toThrow(SSHCommandError::class, 'zone');

    expect(SSH::getExecutedCommands())->toBeEmpty();
})->with('hostile values');

test('an empty destination falls back to the path prefix and local file name', function (): void {
    SSH::fake('Upload successful');

    $this->bunnyStorage(['path' => '/nested/prefix/'])->upload('/home/vito/backup-1.sql.gz', '');

    $run = $this->runLastScript('201');

    expect(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/nested/prefix/backup-1.sql.gz');
});

test('an empty source falls back to the path prefix and local file name on download', function (): void {
    SSH::fake('Download successful');

    $this->bunnyStorage(['path' => ''])->download('', '/home/vito/backup-1.sql.gz');

    $run = $this->runLastScript('200');

    expect(end($run['args']))->toBe('https://storage.bunnycdn.com/vito-backups/backup-1.sql.gz');
});

test('upload fails when the script does not report success', function (): void {
    SSH::fake('Upload failed with HTTP code 401');

    expect(fn () => $this->bunnyStorage()->upload('/home/vito/backup.sql.gz', ''))
        ->toThrow(SSHCommandError::class, 'Failed to upload');
});

test('the scripts fail when curl reports an unexpected status', function (): void {
    SSH::fake('Upload successful');

    $this->bunnyStorage()->upload('/home/vito/backup.sql.gz', '');

    $run = $this->runLastScript('403');

    expect($run['exit'])->not->toBe(0)
        ->and($run['output'])->toContain('Upload failed with HTTP code 403');
});

test('delete treats a missing remote file as success', function (): void {
    SSH::fake('Delete successful');

    $this->bunnyStorage()->delete('backups/backup.sql.gz');

    $run = $this->runLastScript('404');

    expect($run['exit'])->toBe(0)
        ->and($run['output'])->toContain('Delete successful');
});
