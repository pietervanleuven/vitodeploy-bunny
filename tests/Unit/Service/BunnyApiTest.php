<?php

use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyApi;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class);

beforeEach(function (): void {
    $this->api = app(BunnyApi::class);
});

beforeEach(function (): void {
    $this->bootPlugin();
});

test('the api client sets connection and request timeouts', function (): void {
    $options = $this->api->client('key')->getOptions();

    expect($options['connect_timeout'])->toBe(BunnyApi::CONNECT_TIMEOUT)
        ->and($options['timeout'])->toBe(BunnyApi::TIMEOUT)
        ->and($options['headers']['AccessKey'])->toBe('key');
});

test('the storage client targets the zone endpoint with the access key', function (): void {
    Http::fake(['ny.storage.bunnycdn.com/*' => Http::response([], 200)]);

    $this->api->storageClient('ny.storage.bunnycdn.com', 'zone-key')->get('my-zone/');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://ny.storage.bunnycdn.com/my-zone/'
        && $request->header('AccessKey')[0] === 'zone-key');

    $options = $this->api->storageClient('ny.storage.bunnycdn.com', 'zone-key')->getOptions();

    expect($options['connect_timeout'])->toBe(BunnyApi::CONNECT_TIMEOUT)
        ->and($options['timeout'])->toBe(BunnyApi::TIMEOUT);
});

test('a get request is retried after a server error', function (): void {
    Http::fake(['api.bunny.net/*' => Http::sequence()->push([], 503)->push(['ok' => true], 200)]);

    $response = $this->api->client('key')->get('dnszone');

    expect($response->status())->toBe(200);
    Http::assertSentCount(2);
});

test('a get request is retried when rate limited', function (): void {
    Http::fake(['api.bunny.net/*' => Http::sequence()->push([], 429)->push(['ok' => true], 200)]);

    expect($this->api->client('key')->get('dnszone')->status())->toBe(200);
    Http::assertSentCount(2);
});

test('a client error is not retried', function (): void {
    Http::fake(['api.bunny.net/*' => Http::sequence()->push(['Message' => 'nope'], 401)->push([], 200)]);

    $response = $this->api->client('key')->get('dnszone');

    expect($response->status())->toBe(401);
    Http::assertSentCount(1);
});

test('the last response is returned when every attempt fails', function (): void {
    Http::fake(['api.bunny.net/*' => Http::sequence()->push([], 503)->push([], 502)->push([], 500)->push([], 200)]);

    $response = $this->api->client('key')->get('dnszone');

    expect($response->status())->toBe(500);
    Http::assertSentCount(BunnyApi::ATTEMPTS);
});

test('a get request is retried after a connection failure', function (): void {
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        if ($attempt++ === 0) {
            throw new ConnectionException('Connection refused');
        }

        return Http::response(['ok' => true], 200);
    });

    expect($this->api->client('key')->get('dnszone')->status())->toBe(200)
        ->and($attempt)->toBe(2);
});

test('a persistent connection failure is thrown after the retries', function (): void {
    $attempt = 0;
    Http::fake(function () use (&$attempt) {
        $attempt++;
        throw new ConnectionException('Connection refused');
    });

    expect(fn () => $this->api->client('key')->get('dnszone'))->toThrow(ConnectionException::class)
        ->and($attempt)->toBe(BunnyApi::ATTEMPTS);
});

test('write requests are not retried', function (string $method): void {
    Http::fake(['api.bunny.net/*' => Http::sequence()->push([], 503)->push([], 200)]);

    $response = $this->api->client('key')->{$method}('pullzone/1/purgeCache', []);

    expect($response->status())->toBe(503);
    Http::assertSentCount(1);
})->with(['post', 'put', 'patch']);

test('delete requests are retried', function (): void {
    Http::fake(['api.bunny.net/*' => Http::sequence()->push([], 503)->push([], 204)]);

    expect($this->api->client('key')->delete('dnszone/1/records/2')->status())->toBe(204);
    Http::assertSentCount(2);
});
