<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\SiteFeatures\Action;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyApi;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyCredentialResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class Setup extends Action
{
    public function name(): string
    {
        return 'Setup';
    }

    public function active(): bool
    {
        return empty(data_get($this->site->type_data, BunnyCredentialResolver::TYPE_DATA_KEY.'.pull_zone_id'));
    }

    public function form(): ?DynamicForm
    {
        return DynamicForm::make([
            DynamicField::make('pull_zone_id')
                ->text()
                ->label('Pull Zone ID')
                ->description('The numeric ID of the pull zone serving this site (dash.bunny.net → CDN)'),
            DynamicField::make('api_key')
                ->passwordWithToggle()
                ->label('API Key')
                ->description('Leave empty to link your connected Bunny DNS provider and reuse its API key (recommended). A key entered here is stored encrypted.'),
        ]);
    }

    public function handle(Request $request): void
    {
        $input = Validator::make($request->only(['pull_zone_id', 'api_key']), [
            'pull_zone_id' => ['required', 'integer', 'min:1'],
            'api_key' => ['nullable', 'string', 'max:255'],
        ])->validate();

        $pullZoneId = (int) $input['pull_zone_id'];
        $apiKey = isset($input['api_key']) && $input['api_key'] !== '' ? (string) $input['api_key'] : null;
        $dnsProvider = null;

        if ($apiKey === null) {
            $credentials = app(BunnyCredentialResolver::class);
            $dnsProvider = $credentials->findDnsProvider(user(), $this->site->server->project_id);
            $apiKey = $credentials->providerKey($dnsProvider);
        }

        if ($apiKey === null) {
            throw ValidationException::withMessages([
                'api_key' => 'No API key provided and no connected Bunny DNS provider found in this project to borrow one from.',
            ]);
        }

        try {
            $response = app(BunnyApi::class)->client($apiKey)->get('pullzone/'.$pullZoneId);
        } catch (ConnectionException) {
            throw ValidationException::withMessages([
                'pull_zone_id' => 'Could not reach the Bunny API. Please try again later.',
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'pull_zone_id' => 'Could not access this pull zone with the given API key (HTTP '.$response->status().').',
            ]);
        }

        // Replace the whole entry: an explicit key is stored encrypted, a
        // borrowed key is stored as a reference to its DNS provider, and any
        // legacy plain-text key is dropped.
        $typeData = $this->site->type_data ?? [];
        $typeData[BunnyCredentialResolver::TYPE_DATA_KEY] = array_filter([
            BunnyCredentialResolver::KEY_PULL_ZONE_ID => $pullZoneId,
            BunnyCredentialResolver::KEY_PULL_ZONE_NAME => $response->json('Name'),
            BunnyCredentialResolver::KEY_DNS_PROVIDER_ID => $dnsProvider?->id,
            BunnyCredentialResolver::KEY_API_KEY_ENCRYPTED => $dnsProvider === null ? app(BunnyCredentialResolver::class)->encryptApiKey($apiKey) : null,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
        $this->site->type_data = $typeData;
        $this->site->save();

        $request->session()->flash('success', sprintf("Bunny CDN pull zone '%s' has been linked to this site.", $response->json('Name')));
    }
}
