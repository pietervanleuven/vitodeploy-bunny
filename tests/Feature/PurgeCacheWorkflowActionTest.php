<?php

use App\Models\Project;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Models\Workflow;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\WorkflowActions\PurgeCache;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class);

beforeEach(function (): void {
    $this->bootPlugin();
    $this->workflow = Workflow::factory()->create([
        'user_id' => $this->user->id,
        'project_id' => $this->user->current_project_id,
    ]);
    $this->action = new PurgeCache($this->user, $this->workflow);
});

test('the action reports when the bunny api cannot be reached', function (): void {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    $result = $this->action->run(['pull_zone_id' => 123, 'api_key' => 'key']);

    expect($result)->toMatchArray(['success' => false, 'status_code' => 0])
        ->and($result['message'])->toContain('Could not reach');
});

function fakePurge(): void
{
    Http::fake([
        'api.bunny.net/pullzone/*/purgeCache' => Http::response('', 204),
        'api.bunny.net/purge*' => Http::response('', 200),
        'api.bunny.net/*' => Http::response(['Message' => 'Not found'], 404),
    ]);
}

function purgedWith(string $apiKey, ?string $urlSuffix = null): void
{
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->header('AccessKey')[0] === $apiKey
        && ($urlSuffix === null || str_contains($request->url(), $urlSuffix)));
}

test('purges a pull zone with an explicit key', function (): void {
    fakePurge();

    $result = $this->action->run(['pull_zone_id' => 123, 'api_key' => 'explicit-key']);

    expect($result)->toMatchArray(['success' => true, 'status_code' => 204])
        ->and($result['message'])->toContain('123');

    purgedWith('explicit-key', '/pullzone/123/purgeCache');
});

test('purges a single url', function (): void {
    fakePurge();

    $result = $this->action->run(['url' => 'https://cdn.example.com/app.css', 'api_key' => 'explicit-key']);

    expect($result['success'])->toBeTrue()
        ->and($result['message'])->toContain('https://cdn.example.com/app.css');

    purgedWith('explicit-key', 'purge?url=https%3A%2F%2Fcdn.example.com%2Fapp.css');
});

test('uses the site credentials and pull zone', function (): void {
    fakePurge();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 555, 'api_key_encrypted' => Crypt::encryptString('site-key')]]]);

    $result = $this->action->run(['site_id' => $this->site->id]);

    expect($result['success'])->toBeTrue();
    purgedWith('site-key', '/pullzone/555/purgeCache');
});

test('an explicit key and pull zone win over the site values', function (): void {
    fakePurge();
    $this->site->update(['type_data' => ['bunny_cdn' => ['pull_zone_id' => 555, 'api_key_encrypted' => Crypt::encryptString('site-key')]]]);

    $this->action->run(['site_id' => $this->site->id, 'pull_zone_id' => 777, 'api_key' => 'explicit-key']);

    purgedWith('explicit-key', '/pullzone/777/purgeCache');
});

test('falls back to the dns provider of the workflow project', function (): void {
    fakePurge();
    $this->bunnyDnsProvider(projectId: null, apiKey: 'global-key');
    $this->bunnyDnsProvider(projectId: $this->user->current_project_id, apiKey: 'project-key');

    $result = $this->action->run(['pull_zone_id' => 123]);

    expect($result['success'])->toBeTrue();
    purgedWith('project-key');
});

test('falls back to a global dns provider', function (): void {
    fakePurge();
    $this->bunnyDnsProvider(projectId: null, apiKey: 'global-key');

    expect($this->action->run(['pull_zone_id' => 123])['success'])->toBeTrue();
    purgedWith('global-key');
});

test('never uses providers of other users or other projects', function (): void {
    fakePurge();
    $this->bunnyDnsProvider(user: User::factory()->create(), projectId: null, apiKey: 'other-user-key');
    $this->bunnyDnsProvider(projectId: Project::factory()->create()->id, apiKey: 'other-project-key');
    $this->bunnyDnsProvider(projectId: null, apiKey: 'disconnected-key', attributes: ['connected' => false]);

    $result = $this->action->run(['pull_zone_id' => 123]);

    expect($result['success'])->toBeFalse();
    Http::assertNothingSent();
});

test('a site without setup and no provider fails clearly', function (): void {
    fakePurge();

    $result = $this->action->run(['site_id' => $this->site->id]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('No Bunny.net API key available');
});

test('a site without setup but with a provider needs a pull zone', function (): void {
    fakePurge();
    $this->bunnyDnsProvider(projectId: null, apiKey: 'global-key');

    $result = $this->action->run(['site_id' => $this->site->id]);

    expect($result['success'])->toBeFalse()
        ->and($result['message'])->toContain('No pull zone ID available');
    Http::assertNothingSent();
});

test('a failed purge is reported', function (): void {
    Http::fake(['api.bunny.net/*' => Http::response(['Message' => 'nope'], 401)]);

    $result = $this->action->run(['pull_zone_id' => 123, 'api_key' => 'bad-key']);

    expect($result)->toMatchArray(['success' => false, 'status_code' => 401]);
});

test('sites the user cannot view are refused', function (): void {
    fakePurge();
    $other = User::factory()->create();
    $server = Server::factory()->create(['user_id' => $other->id, 'project_id' => Project::factory()->create()->id]);
    $site = Site::factory()->create(['server_id' => $server->id, 'type_data' => ['bunny_cdn' => ['pull_zone_id' => 1, 'api_key_encrypted' => Crypt::encryptString('k')]]]);
    $this->user->update(['is_admin' => false]);

    expect(fn () => $this->action->run(['site_id' => $site->id]))->toThrow(AuthorizationException::class);
    Http::assertNothingSent();
});

test('input is validated', function (array $input): void {
    fakePurge();

    expect(fn () => $this->action->run($input))->toThrow(ValidationException::class);
    Http::assertNothingSent();
})->with([
    'nothing to purge' => [['api_key' => 'k']],
    'unknown site' => [['site_id' => 999999]],
    'bad url' => [['url' => 'not a url', 'api_key' => 'k']],
    'bad pull zone' => [['pull_zone_id' => 'abc', 'api_key' => 'k']],
]);

test('upstream output keys are ignored', function (): void {
    fakePurge();

    $result = $this->action->run(['pull_zone_id' => 123, 'api_key' => 'explicit-key', 'success' => false, 'site_domain' => 'x', 'nested' => ['a' => 'b']]);

    expect($result['success'])->toBeTrue();
});
