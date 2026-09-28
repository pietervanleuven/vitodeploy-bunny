<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn;

use App\SiteFeatures\Action;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyApi;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurgeCache extends Action
{
    public function name(): string
    {
        return 'Purge Cache';
    }

    public function active(): bool
    {
        return ! empty(data_get($this->site->type_data, BunnyCredentialResolver::TYPE_DATA_KEY.'.pull_zone_id'));
    }

    public function handle(Request $request): void
    {
        $pullZoneId = (int) data_get($this->site->type_data, BunnyCredentialResolver::TYPE_DATA_KEY.'.'.BunnyCredentialResolver::KEY_PULL_ZONE_ID);

        // active() only drives the UI; the action can still be posted to.
        if ($pullZoneId < 1) {
            throw ValidationException::withMessages([
                'purge' => 'Bunny CDN is not set up for this site.',
            ]);
        }

        $apiKey = app(BunnyCredentialResolver::class)->resolveApiKeyForSite($this->site);

        if ($apiKey === null) {
            throw ValidationException::withMessages([
                'purge' => 'No API key found. Re-run Setup or reconnect the linked Bunny DNS provider.',
            ]);
        }

        try {
            $response = app(BunnyApi::class)->client($apiKey)->post("pullzone/{$pullZoneId}/purgeCache");
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'purge' => 'Could not reach the Bunny API. Please try again later.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'purge' => 'Failed to purge the pull zone cache (HTTP '.$response->status().').',
            ]);
        }

        $request->session()->flash('success', 'The Bunny CDN cache has been purged.');
    }
}
