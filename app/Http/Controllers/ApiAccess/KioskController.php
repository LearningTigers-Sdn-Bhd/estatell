<?php

namespace App\Http\Controllers\ApiAccess;

use App\Actions\ApiAccess\CreateKioskPairing;
use App\Actions\ApiAccess\RevokeKiosk;
use App\Actions\ApiAccess\UpdateKioskSettings;
use App\Data\ApiAccess\KioskData;
use App\Data\ApiAccess\KioskSettingsData;
use App\Enums\KioskKeyMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ApiAccess\StoreKioskRequest;
use App\Http\Requests\ApiAccess\UpdateKioskSettingsRequest;
use App\Models\Kiosk;
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
            'kiosks' => $property->kiosks()->latest('id')->get()->map(KioskData::fromModel(...)),
            'settings' => KioskSettingsData::fromSettings($property->settings),
            'keyModes' => KioskKeyMode::options(),
        ]);
    }

    public function update(UpdateKioskSettingsRequest $request, Property $property, UpdateKioskSettings $updateKioskSettings): RedirectResponse
    {
        $updateKioskSettings(
            $request->user(),
            $property,
            KioskKeyMode::from($request->validated('key_mode')),
            $request->boolean('walk_in_enabled'),
            (int) $request->validated('idle_timeout_seconds'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Kiosk settings saved.')]);

        return to_route('settings.kiosks.index', $property);
    }

    /**
     * The pairing code is flashed once, not stored in plaintext and not in the URL.
     */
    public function store(StoreKioskRequest $request, Property $property, CreateKioskPairing $createKioskPairing): RedirectResponse
    {
        ['kiosk' => $kiosk, 'code' => $code] = $createKioskPairing($request->user(), $property, (string) $request->input('name'));

        Inertia::flash('pairing', [
            'name' => $kiosk->name,
            'code' => substr($code, 0, 4).'-'.substr($code, 4),
            'expiresAt' => $kiosk->pairing_expires_at?->toIso8601String(),
        ]);

        return to_route('settings.kiosks.index', $property);
    }

    public function revoke(Request $request, Property $property, Kiosk $kiosk, RevokeKiosk $revokeKiosk): RedirectResponse
    {
        Inertia::flash('toast', $revokeKiosk($request->user(), $kiosk)
            ? ['type' => 'success', 'message' => __('Kiosk revoked.')]
            : ['type' => 'error', 'message' => __('That kiosk is already revoked.')]);

        return to_route('settings.kiosks.index', $property);
    }
}
