<?php

use App\Exceptions\SSHCommandError;
use App\Facades\SSH;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Plugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class);

beforeEach(function (): void {
    $this->bootPlugin();
    Log::spy();
});

/**
 * Collect the context arrays of every error/warning logged so far.
 */
function loggedContexts(): array
{
    $contexts = [];

    foreach (['error', 'warning'] as $level) {
        try {
            Log::shouldHaveReceived($level)->withArgs(function (string $message, array $context = []) use (&$contexts): bool {
                $contexts[] = $context;

                return true;
            });
        } catch (Throwable) {
            // Level was not logged at all.
        }
    }

    return $contexts;
}

function assertNothingSensitiveLogged(array $contexts, array $forbiddenKeys, array $forbiddenValues): void
{
    expect($contexts)->not->toBeEmpty();

    foreach ($contexts as $context) {
        expect(array_intersect(array_keys($context), $forbiddenKeys))->toBe([]);

        $encoded = json_encode($context);
        foreach ($forbiddenValues as $value) {
            expect($encoded)->not->toContain($value);
        }
    }
}

test('storage failures log the http status, not the command output', function (): void {
    SSH::fake("Some provider detail: {\"HttpCode\":401,\"Message\":\"Unauthorized\"}\nUpload failed with HTTP code 401");

    try {
        $this->bunnyStorage(['access_key' => 'super-secret-key'])->upload('/home/vito/secret-customer-db.sql.gz', '');
    } catch (SSHCommandError) {
    }

    $contexts = loggedContexts();

    assertNothingSensitiveLogged($contexts, ['output'], ['super-secret-key', 'secret-customer-db', 'Unauthorized']);
    expect(collect($contexts)->firstWhere('http_code', '401'))->not->toBeNull();
});

test('the upload script does not echo the api response', function (): void {
    $script = view(Plugin::VIEW_NAMESPACE.'::storage.upload', ['src' => '/tmp/x', 'url' => 'https://storage.bunnycdn.com/z/x', 'accessKey' => 'k'])->render();

    expect($script)->not->toContain('cat ')
        ->and($script)->toContain('-o /dev/null');
});

test('dns failures log the status and message, not the payloads', function (): void {
    Http::fake([
        'api.bunny.net/dnszone/1' => Http::response(['Id' => 1, 'Domain' => 'example.com'], 200),
        'api.bunny.net/dnszone/1/records' => Http::response(['Message' => 'Record is invalid', 'Field' => 'Value', 'Debug' => 'internal-trace'], 400),
    ]);
    $dns = new BunnyDNS($this->bunnyDnsProvider(apiKey: 'dns-secret-key'));

    try {
        $dns->createRecord('1', ['type' => 'TXT', 'name' => 'secret-host', 'content' => 'v=spf1 secret-token']);
    } catch (ValidationException) {
    }

    $contexts = loggedContexts();

    assertNothingSensitiveLogged($contexts, ['response', 'input'], ['dns-secret-key', 'secret-token', 'secret-host', 'internal-trace']);
    expect(collect($contexts)->firstWhere('status', 400))->toMatchArray(['message' => 'Record is invalid']);
});

test('dns listing failures log the status only', function (): void {
    Http::fake(['api.bunny.net/dnszone*' => Http::response(['Message' => 'Unauthorized', 'Debug' => 'internal-trace'], 401)]);
    $dns = new BunnyDNS($this->bunnyDnsProvider(apiKey: 'dns-secret-key'));

    $dns->getDomains();

    assertNothingSensitiveLogged(loggedContexts(), ['response'], ['dns-secret-key', 'internal-trace']);
});
