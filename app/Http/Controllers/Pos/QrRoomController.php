<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\PlaceQrOrder;
use App\Actions\Pos\UnlockRoomQrSession;
use App\Data\Property\CurrencyData;
use App\Enums\PosDiningKind;
use App\Enums\PosQrSessionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PlaceRoomQrOrderRequest;
use App\Http\Requests\Pos\UnlockRoomQrSessionRequest;
use App\Models\PosOutlet;
use App\Models\PosQrSession;
use App\Services\Pos\OutletMenuSnapshot;
use App\Services\Pos\PosRefusalMessages;
use App\Services\Pos\QrMenu;
use App\Services\Pos\QrSessionState;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-room dining QR. A guest proves the stay with the 4-digit PIN and the primary guest's last name, then orders from
 * an outlet that serves rooms. The proof is kept in the session, so a reload does not ask again. The token locks after five
 * wrong tries. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class QrRoomController extends Controller
{
    public function show(Request $request, string $token, OutletMenuSnapshot $menu, QrMenu $qrMenu, QrSessionState $state): Response
    {
        $session = $this->session($token);
        $status = $state($session);
        $unlocked = $request->session()->get($this->key($token)) === true;
        $outlets = $this->roomOutlets($session);
        $outlet = $outlets->firstWhere('id', (int) $request->query('outlet')) ?? $outlets->first();
        $live = $status === QrSessionState::ACTIVE && $unlocked && $outlet !== null;

        return Inertia::render('qr/room', [
            'token' => $token,
            'status' => $status,
            'unlocked' => $unlocked,
            'property' => $session->property->name,
            'logoUrl' => QrLogoController::urlFor($session),
            'currency' => CurrencyData::forProperty($session->property),
            'outlets' => $outlets->map(fn (PosOutlet $row): array => ['id' => $row->id, 'name' => $row->name])->values()->all(),
            'outlet' => $live ? ['id' => $outlet->id, 'name' => $outlet->name] : null,
            'allowRemark' => $live && $outlet->allow_qr_customer_remark,
            'categories' => $live ? $qrMenu($menu($outlet)['categories']) : [],
        ]);
    }

    public function unlock(UnlockRoomQrSessionRequest $request, string $token, UnlockRoomQrSession $unlock): RedirectResponse
    {
        $result = $unlock($this->session($token), (string) $request->validated('pin'), (string) $request->validated('last_name'));

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]);
        } else {
            $request->session()->put($this->key($token), true);
        }

        return to_route('qr.room.show', ['token' => $token]);
    }

    public function orders(PlaceRoomQrOrderRequest $request, string $token, PlaceQrOrder $placeQrOrder): RedirectResponse
    {
        if ($request->session()->get($this->key($token)) !== true) {
            return to_route('qr.room.show', ['token' => $token]);
        }

        $session = $this->session($token);
        $outlet = $this->roomOutlets($session)->firstWhere('id', (int) $request->validated('outlet_id'));

        if ($outlet === null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => PosRefusalMessages::message('outlet_invalid')]);

            return to_route('qr.room.show', ['token' => $token]);
        }

        $result = $placeQrOrder($session, $outlet, $request->cartLines(), (bool) $outlet->allow_qr_customer_remark);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Your order was sent to the cashier.')]);
        }

        return to_route('qr.room.show', ['token' => $token, 'outlet' => $outlet->id]);
    }

    private function session(string $token): PosQrSession
    {
        return PosQrSession::query()
            ->where('token', $token)
            ->where('kind', PosQrSessionKind::Room->value)
            ->with(['property.settings', 'reservationUnit'])
            ->firstOrFail();
    }

    /**
     * Outlets of the session's property that take room orders: room service or poolside.
     *
     * @return Collection<int, PosOutlet>
     */
    private function roomOutlets(PosQrSession $session): Collection
    {
        return PosOutlet::query()
            ->where('property_id', $session->property_id)
            ->where('pos_enabled', true)
            ->where('active', true)
            ->whereHas('diningOptions', fn ($query) => $query->whereIn('kind', [PosDiningKind::RoomService->value, PosDiningKind::Poolside->value])->where('active', true))
            ->orderBy('name')
            ->get();
    }

    private function key(string $token): string
    {
        return 'pos_qr_room.'.hash('sha256', $token);
    }
}
