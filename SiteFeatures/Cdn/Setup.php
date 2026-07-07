<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn;

use App\DTOs\DynamicField;
use App\DTOs\DynamicForm;
use App\SiteFeatures\Action;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Support\BunnyApi;
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
        return empty(data_get($this->site->type_data, BunnyApi::TYPE_DATA_KEY.'.pull_zone_id'));
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
                ->description('Leave empty to use the API key of your connected Bunny DNS provider. If provided, the key is stored unencrypted in Vito\'s database.'),
        ]);
    }

    public function handle(Request $request): void
    {
        Validator::make($request->all(), [
            'pull_zone_id' => 'required|integer',
            'api_key' => 'nullable|string',
        ])->validate();

        $apiKey = $request->input('api_key') ?: BunnyApi::resolveApiKeyForSite($this->site);

        if (empty($apiKey)) {
            throw ValidationException::withMessages([
                'api_key' => 'No API key provided and no connected Bunny DNS provider found to borrow one from.',
            ]);
        }

        $response = BunnyApi::client($apiKey)->get('pullzone/'.$request->integer('pull_zone_id'));

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'pull_zone_id' => 'Could not access this pull zone with the given API key (HTTP '.$response->status().').',
            ]);
        }

        $typeData = $this->site->type_data ?? [];
        data_set($typeData, BunnyApi::TYPE_DATA_KEY, array_filter([
            'pull_zone_id' => $request->integer('pull_zone_id'),
            'pull_zone_name' => $response->json('Name'),
            'api_key' => $request->input('api_key'),
        ]));
        $this->site->type_data = $typeData;
        $this->site->save();

        $request->session()->flash('success', sprintf("Bunny CDN pull zone '%s' has been linked to this site.", $response->json('Name')));
    }
}
