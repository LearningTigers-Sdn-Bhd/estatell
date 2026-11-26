<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\PlaceQrOrder;
use App\Data\Property\CurrencyData;
use App\Enums\PosDiningKind;
use App\Enums\PosQrSessionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PlaceQrOrderRequest;
use App\Models\PosQrSession;
use App\Services\Pos\OutletMenuSnapshot;
use App\Services\Pos\PosRefusalMessages;
use App\Services\Pos\QrMenu;
use App\Services\Pos\QrSessionState;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The guest's table menu, opened from the table QR. No login: the token is the table. The menu is shown only while the token
 * is live. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class QrTableController extends Controller
{
    public function show(string $token, OutletMenuSnapshot $menu, QrMenu $qrMenu, QrSessionState $state): Response
    {
        $session = $this->session($token);
        $status = $state($session);
        $outlet = $session->outlet;
        // QR table orders are Dine-In checks, so the guest sees the total with that option's service charge.
        $dineIn = $outlet->diningOptions()->where('kind', PosDiningKind::DineIn->value)->where('active', true)->first();

        return Inertia::render('qr/table', [
            'token' => $token,
            'status' => $status,
            'property' => $session->property->name,
            'logoUrl' => QrLogoController::urlFor($session),
            'outlet' => $outlet->name,
            'table' => $session->table->code,
            'currency' => CurrencyData::forProperty($session->property),
            'allowRemark' => $outlet->allow_qr_customer_remark,
            'serviceChargeRate' => $dineIn?->apply_service_charge ? (string) $dineIn->service_charge_rate : null,
            'categories' => $status === QrSessionState::ACTIVE ? $qrMenu($menu($outlet)['categories']) : [],
        ]);
    }

    public function orders(PlaceQrOrderRequest $request, string $token, PlaceQrOrder $placeQrOrder): RedirectResponse
    {
        $session = $this->session($token);
        $result = $placeQrOrder($session, null, $request->cartLines(), (bool) $session->outlet->allow_qr_customer_remark);

        if ($result->isRefused()) {
            Inertia::flash('toast', ['type' => 'error', 'message' => PosRefusalMessages::message((string) $result->refusal)]);
        } else {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Your order was sent to the cashier.')]);
        }

        return to_route('qr.table.show', ['token' => $token]);
    }

    private function session(string $token): PosQrSession
    {
        return PosQrSession::query()
            ->where('token', $token)
            ->where('kind', PosQrSessionKind::Table->value)
            ->with(['property.settings', 'outlet', 'table'])
            ->firstOrFail();
    }
}
