<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\WorkflowActions;

use App\Models\Site;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support\BunnyApi;
use App\WorkflowActions\AbstractWorkflowAction;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Arr;
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
        // Outputs of earlier workflow actions are merged into the input;
        // only read the keys this action declares.
        $input = Arr::only($input, array_keys($this->inputs()));

        Validator::make($input, [
            'site_id' => ['nullable', 'integer', 'exists:sites,id'],
            'pull_zone_id' => ['nullable', 'integer', 'min:1', 'required_without_all:site_id,url'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'url', 'max:2048'],
        ])->validate();

        $apiKey = isset($input['api_key']) && $input['api_key'] !== '' ? (string) $input['api_key'] : null;
        $pullZoneId = ! empty($input['pull_zone_id']) ? (int) $input['pull_zone_id'] : null;

        if (! empty($input['site_id'])) {
            /** @var Site $site */
            $site = Site::findOrFail($input['site_id']);
            $this->authorize('view', [$site, $site->server]);

            $apiKey ??= BunnyApi::resolveApiKeyForSite($site);
            $pullZoneId ??= ((int) data_get($site->type_data, BunnyApi::TYPE_DATA_KEY.'.'.BunnyApi::KEY_PULL_ZONE_ID)) ?: null;
        }

        // Last resort: a Bunny DNS provider the workflow's user owns, scoped
        // to the workflow's project or global.
        $apiKey ??= BunnyApi::providerKey(BunnyApi::findDnsProvider($this->user, $this->workflow->project_id));

        if ($apiKey === null) {
            return $this->failure(0, 'No Bunny.net API key available: set api_key, use a site with the Bunny CDN feature set up, or connect a Bunny DNS provider in this project');
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
