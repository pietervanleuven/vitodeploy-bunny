<?php

use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class);

beforeEach(function (): void {
    $this->bootPlugin();
    $this->actingAs($this->user);
});

function cdnActionRoute($test, string $action): string
{
    return route('site-features.action', [
        'server' => $test->server,
        'site' => $test->site,
        'feature' => 'bunny-cdn',
        'action' => $action,
    ]);
}

test('setup reports when the bunny api cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123, 'api_key' => 'site-key'])
        ->assertSessionHasErrors(['pull_zone_id' => 'Could not reach the Bunny API. Please try again later.']);

    $this->site->refresh();

    expect(data_get($this->site->type_data, 'bunny_cdn'))->toBeNull();
});

test('purge reports when the bunny api cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key' => 'site-key']]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))
        ->assertSessionHasErrors(['purge' => 'Could not reach the Bunny API. Please try again later.']);
});
