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

test('get domains follows pagination until there are no more items', function (): void {
    Http::fake([
        'api.bunny.net/dnszone*' => Http::sequence()
            ->push(['Items' => [bunnyZone(1, 'one.com'), bunnyZone(2, 'two.com')], 'CurrentPage' => 1, 'HasMoreItems' => true], 200)
            ->push(['Items' => [bunnyZone(3, 'three.com')], 'CurrentPage' => 2, 'HasMoreItems' => false], 200),
    ]);

    $domains = $this->dns->getDomains();

    expect(collect($domains)->pluck('name')->all())->toBe(['one.com', 'two.com', 'three.com']);

    Http::assertSentCount(2);
    Http::assertSentInOrder([
        fn (Request $request) => str_contains($request->url(), 'page=1') && str_contains($request->url(), 'perPage=1000'),
        fn (Request $request) => str_contains($request->url(), 'page=2') && str_contains($request->url(), 'perPage=1000'),
    ]);
});

test('get domains requests a single page when the api does not report more items', function (): void {
    Http::fake(['api.bunny.net/dnszone*' => Http::response(['Items' => [bunnyZone(1, 'one.com')]], 200)]);

    expect(collect($this->dns->getDomains())->pluck('name')->all())->toBe(['one.com']);

    Http::assertSentCount(1);
});

test('get domains keeps the pages it fetched when a later page fails', function (): void {
    Http::fake([
        'api.bunny.net/dnszone*' => Http::sequence()
            ->push(['Items' => [bunnyZone(1, 'one.com')], 'HasMoreItems' => true], 200)
            ->push(['Message' => 'boom'], 500),
    ]);

    expect(collect($this->dns->getDomains())->pluck('name')->all())->toBe(['one.com']);
});

test('get domains stops paging when a page claims more items but is empty', function (): void {
    Http::fake(['api.bunny.net/dnszone*' => Http::response(['Items' => [], 'HasMoreItems' => true], 200)]);

    expect($this->dns->getDomains())->toBe([]);

    Http::assertSentCount(1);
});

function fakeBunnyRecordWrite(): void
{
    Http::fake([
        'api.bunny.net/dnszone/1' => Http::response(bunnyZone(1, 'example.com'), 200),
        'api.bunny.net/dnszone/1/records' => Http::response(bunnyRecord(42, 0, 'www', '203.0.113.10'), 201),
        'api.bunny.net/dnszone/1/records/*' => Http::response('', 204),
    ]);
}

function sentRecordPayload(): array
{
    $payload = [];
    Http::assertSent(function (Request $request) use (&$payload) {
        if (in_array($request->method(), ['PUT', 'POST'], true)) {
            $payload = $request->data();

            return true;
        }

        return false;
    });

    return $payload;
}

test('record types are accepted case-insensitively', function (): void {
    fakeBunnyRecordWrite();

    $this->dns->createRecord('1', ['type' => 'cname', 'name' => 'www', 'content' => 'example.com.', 'ttl' => 300]);

    expect(sentRecordPayload())->toMatchArray(['Type' => 2, 'Name' => 'www', 'Value' => 'example.com.']);
});

test('unsupported record types are rejected', function (string $type): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => $type, 'name' => 'www', 'content' => 'x']))
        ->toThrow(ValidationException::class, 'does not support');

    Http::assertNothingSent();
})->with(['SOA', 'PULLZONE', 'REDIRECT', 'HTTPS']);

test('a missing record type is rejected', function (): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['name' => 'www', 'content' => 'x']))
        ->toThrow(ValidationException::class, 'type');

    Http::assertNothingSent();
});

test('record content is validated per type', function (string $type, string $content, string $message): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => $type, 'name' => 'www', 'content' => $content, 'priority' => $type === 'MX' ? 10 : null]))
        ->toThrow(ValidationException::class, $message);

    Http::assertNothingSent();
})->with([
    'A with hostname' => ['A', 'example.com', 'IPv4'],
    'A with ipv6' => ['A', '2001:db8::1', 'IPv4'],
    'AAAA with ipv4' => ['AAAA', '203.0.113.10', 'IPv6'],
    'CNAME with spaces' => ['CNAME', 'not a host', 'host name'],
    'CNAME with scheme' => ['CNAME', 'https://example.com', 'host name'],
    'MX with ip' => ['MX', '203.0.113.10', 'not an IP'],
    'CNAME with ipv6' => ['CNAME', '2001:db8::1', 'host name'],
    'NS with underscore label only' => ['NS', 'ns1.example.com/path', 'host name'],
    'CAA without quotes' => ['CAA', '0 issue letsencrypt.org', 'CAA'],
    'CAA unknown tag' => ['CAA', '0 issuefoo "letsencrypt.org"', 'CAA'],
    'empty content' => ['TXT', '   ', 'required'],
]);

test('valid record content is accepted per type', function (string $type, string $content, ?int $priority): void {
    fakeBunnyRecordWrite();

    $this->dns->createRecord('1', ['type' => $type, 'name' => 'www', 'content' => $content, 'priority' => $priority]);

    expect(sentRecordPayload()['Value'])->toBe($content);
})->with([
    'A' => ['A', '203.0.113.10', null],
    'AAAA' => ['AAAA', '2001:db8::1', null],
    'CNAME' => ['CNAME', 'target.example.com', null],
    'CNAME trailing dot' => ['CNAME', 'target.example.com.', null],
    'MX' => ['MX', 'mail.example.com', 10],
    'SRV' => ['SRV', 'sip.example.com', null],
    'SRV with priority' => ['SRV', 'sip.example.com', 5],
    'NS' => ['NS', 'ns1.example.com', null],
    'PTR' => ['PTR', 'host.example.com', null],
    'CAA' => ['CAA', '0 issue "letsencrypt.org"', null],
    'TXT' => ['TXT', 'v=spf1 include:_spf.example.com ~all', null],
    'DKIM label' => ['CNAME', 'dkim._domainkey.example.com', null],
]);

test('ttl must be within range', function (mixed $ttl): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'ttl' => $ttl]))
        ->toThrow(ValidationException::class, 'ttl');

    Http::assertNothingSent();
})->with([0, -5, 86401, 'soon']);

test('a missing ttl defaults to 300 seconds', function (): void {
    fakeBunnyRecordWrite();

    $this->dns->createRecord('1', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10']);

    expect(sentRecordPayload()['Ttl'])->toBe(300);
});

test('mx records require a priority', function (): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com']))
        ->toThrow(ValidationException::class, 'priority');

    Http::assertNothingSent();
});

test('mx records send their priority', function (): void {
    fakeBunnyRecordWrite();

    $this->dns->createRecord('1', ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => '20']);

    expect(sentRecordPayload())->toMatchArray(['Type' => 4, 'Name' => '', 'Priority' => 20]);
});

test('other record types reject a priority', function (): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'priority' => 5]))
        ->toThrow(ValidationException::class, 'priority');

    Http::assertNothingSent();
});

test('priority must be within range', function (mixed $priority): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => 'MX', 'name' => '@', 'content' => 'mail.example.com', 'priority' => $priority]))
        ->toThrow(ValidationException::class, 'priority');
})->with([-1, 65536, 'high']);

test('record names must be present and short', function (mixed $name): void {
    fakeBunnyRecordWrite();

    expect(fn () => $this->dns->createRecord('1', ['type' => 'A', 'name' => $name, 'content' => '203.0.113.10']))
        ->toThrow(ValidationException::class, 'name');
})->with(['', str_repeat('a', 256), [['x']]]);

test('only known keys reach the api payload', function (): void {
    fakeBunnyRecordWrite();

    $this->dns->createRecord('1', ['type' => 'A', 'name' => 'www', 'content' => '203.0.113.10', 'Weight' => 5, 'Disabled' => true, 'proxied' => true, '_token' => 'x']);

    expect(array_keys(sentRecordPayload()))->toBe(['Type', 'Name', 'Value', 'Ttl']);
});

test('update record validates the same way and posts to the record', function (): void {
    fakeBunnyRecordWrite();

    $record = $this->dns->updateRecord('1', '42', ['type' => 'a', 'name' => 'www.example.com', 'content' => '203.0.113.11', 'ttl' => 600]);

    expect($record)->toMatchArray(['id' => '42', 'type' => 'a', 'content' => '203.0.113.11', 'ttl' => 600]);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/dnszone/1/records/42')
        && $request['Type'] === 0
        && $request['Name'] === 'www'
        && $request['Ttl'] === 600);

    expect(fn () => $this->dns->updateRecord('1', '42', ['type' => 'A', 'name' => 'www', 'content' => 'nope']))
        ->toThrow(ValidationException::class, 'IPv4');
});
