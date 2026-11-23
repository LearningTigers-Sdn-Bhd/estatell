<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\CreatePosApiClient;
use App\Actions\Pos\RevokePosApiClient;
use App\Data\Pos\PosApiClientRowData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\StorePosApiClientRequest;
use App\Models\PosApiClient;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PosApiClientController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/pos-api-clients/index', [
            'clients' => PosApiClient::query()->where('property_id', $property->id)->latest('id')->get()->map(PosApiClientRowData::fromModel(...))->values(),
        ]);
    }

    /**
     * The token is flashed once. It is not stored in plain text and is never in the URL.
     */
    public function store(StorePosApiClientRequest $request, Property $property, CreatePosApiClient $createPosApiClient): RedirectResponse
    {
        ['client' => $client, 'token' => $token] = $createPosApiClient($request->user(), $property, (string) $request->validated('name'));

        Inertia::flash('posToken', ['name' => $client->name, 'token' => $token]);

        return to_route('settings.pos-api-clients.index', $property);
    }

    public function revoke(Request $request, Property $property, int $client, RevokePosApiClient $revokePosApiClient): RedirectResponse
    {
        $model = PosApiClient::query()->where('property_id', $property->id)->findOrFail($client);

        Inertia::flash('toast', $revokePosApiClient($request->user(), $model)
            ? ['type' => 'success', 'message' => __('API client revoked.')]
            : ['type' => 'error', 'message' => __('That API client is already revoked.')]);

        return to_route('settings.pos-api-clients.index', $property);
    }
}
