<?php

use App\Models\Project;
use App\Models\User;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
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

function fakePullZone(int $id = 123, string $name = 'my-zone'): void
{
    Http::fake([
        "api.bunny.net/pullzone/{$id}" => Http::response(['Id' => $id, 'Name' => $name], 200),
        "api.bunny.net/pullzone/{$id}/purgeCache" => Http::response('', 204),
        'api.bunny.net/*' => Http::response(['Message' => 'Not found'], 404),
    ]);
}

function cdnData($test): array
{
    $test->site->refresh();

    return data_get($test->site->type_data, 'bunny_cdn') ?? [];
}

test('setup with an explicit key stores it encrypted', function (): void {
    fakePullZone();

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123, 'api_key' => 'site-key'])
        ->assertSessionDoesntHaveErrors()
        ->assertRedirect();

    $data = cdnData($this);

    expect($data)->toHaveKeys(['pull_zone_id', 'pull_zone_name', 'api_key_encrypted'])
        ->and($data)->not->toHaveKeys(['api_key', 'dns_provider_id'])
        ->and($data['pull_zone_id'])->toBe(123)
        ->and($data['pull_zone_name'])->toBe('my-zone')
        ->and($data['api_key_encrypted'])->not->toContain('site-key')
        ->and(Crypt::decryptString($data['api_key_encrypted']))->toBe('site-key')
        ->and(json_encode($this->site->type_data))->not->toContain('site-key');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/pullzone/123')
        && $request->header('AccessKey')[0] === 'site-key');
});

test('setup without a key links the bunny dns provider of the project', function (): void {
    fakePullZone();
    $provider = $this->bunnyDnsProvider(projectId: $this->user->current_project_id, apiKey: 'dns-key');

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123])
        ->assertSessionDoesntHaveErrors();

    $data = cdnData($this);

    expect($data['dns_provider_id'])->toBe($provider->id)
        ->and($data)->not->toHaveKeys(['api_key', 'api_key_encrypted'])
        ->and(json_encode($this->site->type_data))->not->toContain('dns-key');

    Http::assertSent(fn (Request $request) => $request->header('AccessKey')[0] === 'dns-key');
});

test('setup prefers a project provider over a global one', function (): void {
    fakePullZone();
    $this->bunnyDnsProvider(projectId: null, apiKey: 'global-key');
    $project = $this->bunnyDnsProvider(projectId: $this->user->current_project_id, apiKey: 'project-key');

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123])->assertSessionDoesntHaveErrors();

    expect(cdnData($this)['dns_provider_id'])->toBe($project->id);
    Http::assertSent(fn (Request $request) => $request->header('AccessKey')[0] === 'project-key');
});

test('setup falls back to a global provider', function (): void {
    fakePullZone();
    $global = $this->bunnyDnsProvider(projectId: null, apiKey: 'global-key');

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123])->assertSessionDoesntHaveErrors();

    expect(cdnData($this)['dns_provider_id'])->toBe($global->id);
});

test('setup ignores providers of other users and other projects', function (): void {
    fakePullZone();
    $this->bunnyDnsProvider(user: User::factory()->create(), projectId: null, apiKey: 'other-user-key');
    $this->bunnyDnsProvider(projectId: Project::factory()->create()->id, apiKey: 'other-project-key');
    $this->bunnyDnsProvider(projectId: null, apiKey: 'disconnected-key', attributes: ['connected' => false]);

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123])
        ->assertSessionHasErrors(['api_key']);

    expect(cdnData($this))->toBe([]);
    Http::assertNothingSent();
});

test('setup rejects a pull zone the key cannot access', function (): void {
    fakePullZone(123);

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 999, 'api_key' => 'site-key'])
        ->assertSessionHasErrors(['pull_zone_id']);

    expect(cdnData($this))->toBe([]);
});

test('setup validates its input', function (array $input, string $field): void {
    fakePullZone();

    $this->post(cdnActionRoute($this, 'setup'), $input)->assertSessionHasErrors([$field]);

    Http::assertNothingSent();
})->with([
    'missing pull zone' => [['api_key' => 'site-key'], 'pull_zone_id'],
    'non numeric pull zone' => [['pull_zone_id' => 'abc', 'api_key' => 'site-key'], 'pull_zone_id'],
    'negative pull zone' => [['pull_zone_id' => -1, 'api_key' => 'site-key'], 'pull_zone_id'],
    'array api key' => [['pull_zone_id' => 123, 'api_key' => ['x']], 'api_key'],
]);

test('re-running setup drops a legacy plain-text key', function (): void {
    fakePullZone();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key' => 'legacy-key'], 'other' => 'kept']]);

    $this->post(cdnActionRoute($this, 'setup'), ['pull_zone_id' => 123, 'api_key' => 'new-key'])->assertSessionDoesntHaveErrors();

    $this->site->refresh();

    expect(cdnData($this))->not->toHaveKey('api_key')
        ->and(Crypt::decryptString(cdnData($this)['api_key_encrypted']))->toBe('new-key')
        ->and($this->site->type_data['other'])->toBe('kept');
});

test('purge uses the encrypted site key', function (): void {
    fakePullZone();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key_encrypted' => Crypt::encryptString('site-key')]]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionDoesntHaveErrors()->assertSessionHas('success');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && str_ends_with($request->url(), '/pullzone/123/purgeCache')
        && $request->header('AccessKey')[0] === 'site-key');
});

test('purge uses the linked dns provider key', function (): void {
    fakePullZone();
    $provider = $this->bunnyDnsProvider(projectId: $this->user->current_project_id, apiKey: 'dns-key');
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'dns_provider_id' => $provider->id]]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionDoesntHaveErrors();

    Http::assertSent(fn (Request $request) => $request->header('AccessKey')[0] === 'dns-key');
});

test('purge still honours a legacy plain-text key', function (): void {
    fakePullZone();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key' => 'legacy-key']]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionDoesntHaveErrors();

    Http::assertSent(fn (Request $request) => $request->header('AccessKey')[0] === 'legacy-key');
});

test('purge fails when the linked provider is gone', function (): void {
    fakePullZone();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'dns_provider_id' => 999]]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionHasErrors(['purge']);

    Http::assertNothingSent();
});

test('purge fails when the stored key cannot be decrypted', function (): void {
    fakePullZone();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key_encrypted' => 'garbage']]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionHasErrors(['purge']);

    Http::assertNothingSent();
});

test('purge refuses to run on a site without setup', function (): void {
    fakePullZone();

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionHasErrors(['purge']);

    Http::assertNothingSent();
});

test('purge reports an api failure', function (): void {
    Http::fake(['api.bunny.net/*' => Http::response(['Message' => 'nope'], 401)]);
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key_encrypted' => Crypt::encryptString('site-key')]]]);

    $this->post(cdnActionRoute($this, 'purge-cache'))->assertSessionHasErrors(['purge']);
});

test('remove clears the feature data', function (): void {
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key_encrypted' => 'x'], 'other' => 'kept']]);

    $this->post(cdnActionRoute($this, 'remove'))->assertSessionDoesntHaveErrors();

    $this->site->refresh();

    expect($this->site->type_data)->not->toHaveKey('bunny_cdn')
        ->and($this->site->type_data['other'])->toBe('kept');
});

test('the feature actions reflect the setup state', function (): void {
    $active = fn (): array => collect($this->site->fresh()->features()['bunny-cdn']['actions'])->map(fn (array $action): bool => $action['active'])->all();

    expect($active())->toBe(['setup' => true, 'purge-cache' => false, 'remove' => false]);

    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 123, 'api_key_encrypted' => 'x']]]);

    expect($active())->toBe(['setup' => false, 'purge-cache' => true, 'remove' => true]);
});
