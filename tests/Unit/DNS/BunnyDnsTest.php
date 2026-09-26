<?php

use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class);

beforeEach(function (): void {
    $this->bootPlugin();
    $this->dns = new BunnyDNS($this->bunnyDnsProvider(apiKey: 'test-api-key'));
});

function bunnyZone(int $id = 1, string $domain = 'example.com', array $records = []): array
{
    return [
        'Id' => $id,
        'Domain' => $domain,
        'NameserversDetected' => true,
        'DateCreated' => '2026-01-01T00:00:00',
        'DateModified' => '2026-01-02T00:00:00',
        'Records' => $records,
    ];
}

function bunnyRecord(int $id, int $type, string $name, string $value, array $extra = []): array
{
    return array_merge([
        'Id' => $id,
        'Type' => $type,
        'Name' => $name,
        'Value' => $value,
        'Ttl' => 300,
        'Accelerated' => false,
    ], $extra);
}

test('connect sends the api key header', function (): void {
    Http::fake(['api.bunny.net/dnszone*' => Http::response(['Items' => []], 200)]);

    expect($this->dns->connect(['api_key' => 'test-api-key']))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->header('AccessKey')[0] === 'test-api-key');
});

test('connect fails on an error response', function (): void {
    Http::fake(['api.bunny.net/dnszone*' => Http::response(['Message' => 'Unauthorized'], 401)]);

    expect($this->dns->connect(['api_key' => 'wrong']))->toBeFalse();
});

test('get records maps bunny types, root names and priorities', function (): void {
    Http::fake([
        'api.bunny.net/dnszone/1' => Http::response(bunnyZone(records: [
            bunnyRecord(10, 0, '', '203.0.113.10'),
            bunnyRecord(11, 2, 'www', 'example.com'),
            bunnyRecord(12, 4, '', 'mail.example.com', ['Priority' => 10]),
            bunnyRecord(13, 7, 'cdn', 'pullzone', ['Accelerated' => true]),
        ]), 200),
    ]);

    $records = $this->dns->getRecords('1');

    expect($records)->toHaveCount(4)
        ->and($records[0])->toMatchArray(['id' => '10', 'type' => 'A', 'name' => '@', 'content' => '203.0.113.10', 'ttl' => 300, 'priority' => null])
        ->and($records[1])->toMatchArray(['id' => '11', 'type' => 'CNAME', 'name' => 'www'])
        ->and($records[2])->toMatchArray(['type' => 'MX', 'priority' => 10])
        ->and($records[3])->toMatchArray(['type' => 'PULLZONE', 'proxied' => true]);
});

test('get records throws a runtime exception on an error response', function (): void {
    Http::fake(['api.bunny.net/dnszone/1' => Http::response(['Message' => 'Zone not found'], 404)]);

    expect(fn () => $this->dns->getRecords('1'))
        ->toThrow(RuntimeException::class, 'Zone not found');
});

test('get records throws a runtime exception when the api is unreachable', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));

    expect(fn () => $this->dns->getRecords('1'))
        ->toThrow(RuntimeException::class, 'could not reach the Bunny API');
});
