<?php

use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Plugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn\PurgeCache as PurgeCacheAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn\Remove as RemoveAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn\Setup as SetupAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\StorageProviders\Bunny as BunnyStorageProvider;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Tests\Support\InteractsWithBunnyPlugin;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\WorkflowActions\PurgeCache as PurgeCacheWorkflowAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class, InteractsWithBunnyPlugin::class);

beforeEach(function (): void {
    $this->bootPlugin();
});

test('registers the dns provider', function (): void {
    expect(config('dns-provider.providers.bunny.handler'))->toBe(BunnyDNS::class)
        ->and(config('dns-provider.providers.bunny.label'))->toBe('Bunny DNS')
        ->and(collect(config('dns-provider.providers.bunny.form'))->pluck('name')->all())->toBe(['api_key']);
});

test('registers the storage provider', function (): void {
    expect(config('storage-provider.providers.bunny.handler'))->toBe(BunnyStorageProvider::class)
        ->and(collect(config('storage-provider.providers.bunny.form'))->pluck('name')->all())
        ->toBe(['storage_zone', 'access_key', 'endpoint', 'path']);
});

test('registers the cdn site feature for every site type', function (): void {
    $types = array_keys(config('site.types'));

    expect($types)->not->toBeEmpty();

    foreach ($types as $type) {
        $actions = config("site.types.{$type}.features.bunny-cdn.actions");

        expect(config("site.types.{$type}.features.bunny-cdn.label"))->toBe('Bunny CDN')
            ->and($actions['setup']['handler'])->toBe(SetupAction::class)
            ->and($actions['purge-cache']['handler'])->toBe(PurgeCacheAction::class)
            ->and($actions['remove']['handler'])->toBe(RemoveAction::class);
    }
});

test('registers the purge cache workflow action', function (): void {
    expect(config('workflow.actions.bunny-purge-cache.handler'))->toBe(PurgeCacheWorkflowAction::class);
});

test('registers the view namespace', function (): void {
    expect(config('plugins.views.'.Plugin::VIEW_NAMESPACE))->toEndWith('/views')
        ->and(view()->exists(Plugin::VIEW_NAMESPACE.'::storage.upload'))->toBeTrue();
});
