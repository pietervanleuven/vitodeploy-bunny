<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn;

use App\SiteFeatures\Action;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support\BunnyApi;
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
        return ! empty(data_get($this->site->type_data, BunnyApi::TYPE_DATA_KEY.'.pull_zone_id'));
    }

    public function handle(Request $request): void
    {
        $pullZoneId = data_get($this->site->type_data, BunnyApi::TYPE_DATA_KEY.'.pull_zone_id');
        $apiKey = BunnyApi::resolveApiKeyForSite($this->site);

        if (empty($apiKey)) {
            throw ValidationException::withMessages([
                'purge' => 'No API key found. Re-run Setup or connect a Bunny DNS provider.',
            ]);
        }

        try {
            $response = BunnyApi::client($apiKey)->post("pullzone/{$pullZoneId}/purgeCache");
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
