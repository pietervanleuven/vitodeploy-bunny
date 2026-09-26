<?php

use App\Models\Workflow;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\WorkflowActions\PurgeCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
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
