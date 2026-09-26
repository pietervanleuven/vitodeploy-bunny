<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\WorkflowActions;

use App\Models\Site;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support\BunnyApi;
use App\WorkflowActions\AbstractWorkflowAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Validator;

class PurgeCache extends AbstractWorkflowAction
{
    public function inputs(): array
    {
        return [
            'site_id' => 'Optional ID of a site with the Bunny CDN feature set up; its pull zone and API key will be used',
            'pull_zone_id' => 'The ID of the Bunny.net pull zone to purge (required unless site_id or url is set)',
            'api_key' => 'Bunny.net account API key (falls back to the site\'s key or a connected Bunny DNS provider)',
            'url' => 'Purge a single URL from the cache instead of the whole pull zone',
        ];
    }

    public function outputs(): array
    {
        return [
            'success' => 'Whether the purge request succeeded',
            'status_code' => 'HTTP status code returned by the Bunny API',
            'message' => 'Human readable result',
        ];
    }

    public function run(array $input): array
    {
        Validator::make($input, [
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'pull_zone_id' => ['nullable', 'integer', 'required_without_all:site_id,url'],
            'api_key' => ['nullable', 'string'],
            'url' => ['nullable', 'url'],
        ])->validate();

        $apiKey = $input['api_key'] ?? null;
        $pullZoneId = $input['pull_zone_id'] ?? null;

        if (! empty($input['site_id'])) {
            /** @var Site $site */
            $site = Site::findOrFail($input['site_id']);
            $this->authorize('view', [$site, $site->server]);

            $apiKey = $apiKey ?: BunnyApi::resolveApiKeyForSite($site);
            $pullZoneId = $pullZoneId ?: data_get($site->type_data, BunnyApi::TYPE_DATA_KEY.'.pull_zone_id');
        }

        $apiKey = $apiKey ?: BunnyApi::connectedDnsProviderKey();

        if (empty($apiKey)) {
            return $this->failure(0, 'No Bunny.net API key available');
        }

        try {
            if (! empty($input['url'])) {
                $response = BunnyApi::client($apiKey)->withQueryParameters([
                    'url' => $input['url'],
                    'async' => false,
                ])->post('purge');

                return $response->successful()
                    ? $this->success($response->status(), 'URL purged: '.$input['url'])
                    : $this->failure($response->status(), 'Failed to purge URL');
            }

            if (empty($pullZoneId)) {
                return $this->failure(0, 'No pull zone ID available; set pull_zone_id or use a site with the Bunny CDN feature set up');
            }

            $response = BunnyApi::client($apiKey)->post("pullzone/{$pullZoneId}/purgeCache");

            return $response->successful()
                ? $this->success($response->status(), "Pull zone {$pullZoneId} cache purged")
                : $this->failure($response->status(), "Failed to purge pull zone {$pullZoneId}");
        } catch (ConnectionException) {
            return $this->failure(0, 'Could not reach the Bunny API');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function success(int $statusCode, string $message): array
    {
        return [
            'success' => true,
            'status_code' => $statusCode,
            'message' => $message,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failure(int $statusCode, string $message): array
    {
        return [
            'success' => false,
            'status_code' => $statusCode,
            'message' => $message,
        ];
    }
}
