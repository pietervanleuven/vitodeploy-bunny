<?php

use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
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

test('get records fails when the payload has no record list', function (mixed $body): void {
    Http::fake(['api.bunny.net/dnszone/1' => Http::response($body, 200)]);

    expect(fn () => $this->dns->getRecords('1'))
        ->toThrow(RuntimeException::class, 'unexpected response');
})->with([
    'no records key' => [['Id' => 1, 'Domain' => 'example.com']],
    'records is not a list' => [['Records' => 'nope']],
    'plain text body' => ['not json'],
]);

test('get records skips malformed records', function (): void {
    Http::fake([
        'api.bunny.net/dnszone/1' => Http::response(bunnyZone(records: [
            bunnyRecord(10, 0, '', '203.0.113.10'),
            ['Id' => 11, 'Type' => 0],
            ['Id' => 12, 'Type' => 'A', 'Name' => 'x', 'Value' => 'y', 'Ttl' => 300],
            'garbage',
            bunnyRecord(13, 3, 'txt', 'hello'),
        ]), 200),
    ]);

    $records = $this->dns->getRecords('1');

    expect(collect($records)->pluck('id')->all())->toBe(['10', '13']);
});

test('get domains returns an empty list for an unexpected payload', function (mixed $body): void {
    Http::fake(['api.bunny.net/dnszone*' => Http::response($body, 200)]);

    expect($this->dns->getDomains())->toBe([]);
})->with([
    'no items key' => [['TotalItems' => 0]],
    'items is not a list' => [['Items' => 'nope']],
    'plain text body' => ['not json'],
]);

test('get domains skips zones without an id or domain', function (): void {
    Http::fake([
        'api.bunny.net/dnszone*' => Http::response(['Items' => [
            bunnyZone(1, 'one.com'),
            ['Domain' => 'no-id.com'],
            ['Id' => 3],
            bunnyZone(4, 'four.com'),
        ], 'HasMoreItems' => false], 200),
    ]);

    expect(collect($this->dns->getDomains())->pluck('name')->all())->toBe(['one.com', 'four.com']);
});

test('get domain returns an empty array for an unexpected payload', function (): void {
    Http::fake(['api.bunny.net/dnszone/1' => Http::response(['Message' => 'weird'], 200)]);

    expect($this->dns->getDomain('1'))->toBe([]);
});

test('get domain formats the zone', function (): void {
    Http::fake(['api.bunny.net/dnszone/1' => Http::response(bunnyZone(1, 'example.com'), 200)]);

    expect($this->dns->getDomain('1'))->toMatchArray(['id' => '1', 'name' => 'example.com', 'status' => 'active']);
});

test('create record fails when the response has no record id', function (): void {
    Http::fake([
        'api.bunny.net/dnszone/1' => Http::response(bunnyZone(1, 'example.com'), 200),
        'api.bunny.net/dnszone/1/records' => Http::response(['Message' => 'ok'], 201),
    ]);

    expect(fn () => $this->dns->createRecord('1', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => 300]))
        ->toThrow(ValidationException::class, 'unexpected response');
});

test('create record returns the created record', function (): void {
    Http::fake([
        'api.bunny.net/dnszone/1' => Http::response(bunnyZone(1, 'example.com'), 200),
        'api.bunny.net/dnszone/1/records' => Http::response(bunnyRecord(42, 0, 'www', '203.0.113.10'), 201),
    ]);

    $record = $this->dns->createRecord('1', ['type' => 'A', 'name' => 'www.example.com', 'content' => '203.0.113.10', 'ttl' => 300]);

    expect($record)->toMatchArray(['id' => '42', 'type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => 300]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && str_ends_with($request->url(), '/dnszone/1/records')
        && $request['Type'] === 0
        && $request['Name'] === 'www'
        && $request['Value'] === '203.0.113.10'
        && $request['Ttl'] === 300);
});
