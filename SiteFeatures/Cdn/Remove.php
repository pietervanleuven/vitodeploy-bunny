<?php

namespace App\Vito\Plugins\Pietervanleuven\VitodeployBunny\SiteFeatures\Cdn;

use App\SiteFeatures\Action;
use App\Vito\Plugins\Pietervanleuven\VitodeployBunny\Service\BunnyCredentialResolver;
use Illuminate\Http\Request;

class Remove extends Action
{
    public function name(): string
    {
        return 'Remove';
    }

    public function active(): bool
    {
        return ! empty(data_get($this->site->type_data, BunnyCredentialResolver::TYPE_DATA_KEY.'.pull_zone_id'));
    }

    public function handle(Request $request): void
    {
        $typeData = $this->site->type_data ?? [];
        unset($typeData[BunnyCredentialResolver::TYPE_DATA_KEY]);
        $this->site->type_data = $typeData;
        $this->site->save();

        $request->session()->flash('success', 'The Bunny CDN pull zone has been unlinked from this site.');
    }
}
