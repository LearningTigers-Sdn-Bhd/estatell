<?php

namespace App\Http\Controllers\ApiAccess;

use App\Actions\ApiAccess\CreateKioskPairing;
use App\Actions\ApiAccess\RevokeApiToken;
use App\Data\ApiAccess\KioskData;
use App\Enums\ApiTokenKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiAccess\StoreKioskRequest;
use App\Models\ApiToken;
use App\Models\Property;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class KioskController extends Controller
{
    public function index(Property $property): Response
    {
        return Inertia::render('settings/kiosks/index', [
            'kiosks' => $property->apiTokens()->where('kind', ApiTokenKind::Kiosk)->latest('id')->get()->map(KioskData::fromModel(...)),
        ]);
    }

    /**
     * The pairing code is flashed once, not stored in plaintext and not in the URL.
     */
    public function store(StoreKioskRequest $request, Property $property, CreateKioskPairing $createKioskPairing): RedirectResponse
    {
        ['token' => $token, 'code' => $code] = $createKioskPairing($request->user(), $property, (string) $request->input('name'));

        Inertia::flash('pairing', [
            'name' => $token->name,
            'code' => substr($code, 0, 4).'-'.substr($code, 4),
            'expiresAt' => $token->pairing_expires_at?->toIso8601String(),
        ]);

        return to_route('settings.kiosks.index', $property);
    }

    public function revoke(Request $request, Property $property, ApiToken $apiToken, RevokeApiToken $revokeApiToken): RedirectResponse
    {
        abort_unless($apiToken->kind === ApiTokenKind::Kiosk, 404);

        Inertia::flash('toast', $revokeApiToken($request->user(), $apiToken)
            ? ['type' => 'success', 'message' => __('Kiosk revoked.')]
            : ['type' => 'error', 'message' => __('That kiosk is already revoked.')]);

        return to_route('settings.kiosks.index', $property);
    }
}
