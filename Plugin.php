<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\Plugins\AbstractPlugin;
use App\Plugins\RegisterDNSProvider;
use App\Plugins\RegisterSiteFeature;
use App\Plugins\RegisterSiteFeatureAction;
use App\Plugins\RegisterStorageProvider;
use App\Plugins\RegisterViews;
use App\Plugins\RegisterWorkflowAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\DNSProviders\Bunny as BunnyDNS;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn\PurgeCache as PurgeCacheAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn\Remove as RemoveAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn\Setup as SetupAction;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\StorageProviders\Bunny as BunnyStorage;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\WorkflowActions\PurgeCache as PurgeCacheWorkflowAction;

class Plugin extends AbstractPlugin
{
    public const string VIEW_NAMESPACE = 'vitodeploy-bunny';

    protected string $name = 'Bunny.net';

    protected string $description = 'Bunny.net integration for VitoDeploy: DNS provider, Edge Storage backups and CDN cache purging';

    public function boot(): void
    {
        RegisterViews::make(self::VIEW_NAMESPACE)
            ->path(__DIR__.'/views')
            ->register();

        $this->registerDnsProvider();
        $this->registerStorageProvider();
        $this->registerCdnSiteFeature();
        $this->registerWorkflowActions();
    }

    private function registerDnsProvider(): void
    {
        RegisterDNSProvider::make(BunnyDNS::id())
            ->label('Bunny DNS')
            ->handler(BunnyDNS::class)
            ->form(
                DynamicForm::make([
                    DynamicField::make('api_key')
                        ->text()
                        ->label('API Key')
                        ->description('Your Bunny.net account API key (dash.bunny.net → Account Settings → API)'),
                ])
            )
            ->editForm(
                DynamicForm::make([
                    DynamicField::make('api_key')
                        ->passwordWithToggle()
                        ->label('API Key')
                        ->description('Leave empty to keep the current API key'),
                ])
            )
            ->proxyTypes([])
            ->supportsCreatedAt(false)
            ->register();
    }

    private function registerStorageProvider(): void
    {
        RegisterStorageProvider::make(BunnyStorage::id())
            ->label('Bunny Storage')
            ->handler(BunnyStorage::class)
            ->form(
                DynamicForm::make([
                    DynamicField::make('storage_zone')
                        ->text()
                        ->label('Storage Zone Name'),
                    DynamicField::make('access_key')
                        ->text()
                        ->label('Access Key')
                        ->description('The password of the storage zone (FTP & API Access → Password)'),
                    DynamicField::make('endpoint')
                        ->select()
                        ->options(BunnyStorage::ENDPOINTS)
                        ->default('storage.bunnycdn.com')
                        ->label('Endpoint')
                        ->description('Must match the main region of your storage zone (Falkenstein = storage.bunnycdn.com)'),
                    DynamicField::make('path')
                        ->text()
                        ->label('Path')
                        ->placeholder('backups')
                        ->description('Optional directory inside the storage zone used when Vito does not provide a full path'),
                ])
            )
            ->register();
    }

    private function registerCdnSiteFeature(): void
    {
        /** @var array<string, mixed> $siteTypes */
        $siteTypes = config('site.types', []);

        foreach (array_keys($siteTypes) as $type) {
            RegisterSiteFeature::make($type, 'bunny-cdn')
                ->label('Bunny CDN')
                ->description('Purge the Bunny.net pull zone cache for this site')
                ->register();

            RegisterSiteFeatureAction::make($type, 'bunny-cdn', 'setup')
                ->label('Setup')
                ->handler(SetupAction::class)
                ->register();

            RegisterSiteFeatureAction::make($type, 'bunny-cdn', 'purge-cache')
                ->label('Purge Cache')
                ->handler(PurgeCacheAction::class)
                ->register();

            RegisterSiteFeatureAction::make($type, 'bunny-cdn', 'remove')
                ->label('Remove')
                ->handler(RemoveAction::class)
                ->register();
        }
    }

    private function registerWorkflowActions(): void
    {
        RegisterWorkflowAction::make('bunny-purge-cache')
            ->label('Bunny CDN Purge Cache')
            ->description('Purge a Bunny.net pull zone cache or a single URL')
            ->category('general')
            ->handler(PurgeCacheWorkflowAction::class)
            ->register();
    }
}
