<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\HomepageColorSchemeGenerator;
use Illuminate\View\View;

class ClientHomepageController extends Controller
{
    public function show(Client $client, HomepageColorSchemeGenerator $colorSchemeGenerator): View
    {
        abort_unless($client->is_published, 404);

        return view('clients.show', [
            'client' => $client,
            'homepageColorCss' => $colorSchemeGenerator->activeCssVariablesForHomepage($client->id),
        ]);
    }
}
