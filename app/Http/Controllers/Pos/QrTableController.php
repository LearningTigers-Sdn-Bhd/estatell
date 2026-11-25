<?php

namespace App\Http\Controllers\Pos;

use App\Actions\Pos\PlaceQrOrder;
use App\Enums\PosQrSessionKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pos\PlaceQrOrderRequest;
use App\Models\PosQrSession;
use App\Services\Pos\OutletMenuSnapshot;
use App\Services\Pos\PosRefusalMessages;
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
    public function show(string $token, OutletMenuSnapshot $menu, QrSessionState $state): Response
    {
        $session = $this->session($token);
        $status = $state($session);
        $outlet = $session->outlet;

        return Inertia::render('qr/table', [
            'token' => $token,
            'status' => $status,
            'property' => $session->property->name,
            'outlet' => $outlet->name,
            'table' => $session->table->code,
            'allowRemark' => $outlet->allow_qr_customer_remark,
            'categories' => $status === QrSessionState::ACTIVE ? $this->orderable($menu($outlet)['categories']) : [],
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
            ->with(['property', 'outlet', 'table'])
            ->firstOrFail();
    }

    /**
     * Items a guest can order alone: no combo, and no modifier group the guest must pick from.
     *
     * @param  list<array<string, mixed>>  $categories
     * @return list<array<string, mixed>>
     */
    private function orderable(array $categories): array
    {
        return collect($categories)
            ->map(fn (array $category): array => [
                'id' => $category['id'],
                'name' => $category['name'],
                'items' => collect($category['items'])
                    ->reject(fn (array $item): bool => $item['isCombo'] || collect($item['modifierGroups'])->contains(fn (array $group): bool => $group['minSelect'] > 0))
                    ->map(fn (array $item): array => [
                        'id' => $item['id'],
                        'name' => $item['name'],
                        'variants' => collect($item['variants'])->map(fn (array $variant): array => [
                            'id' => $variant['id'],
                            'name' => $variant['name'],
                            'price' => $variant['price'],
                            'soldOut' => $variant['soldOut'],
                        ])->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ])
            ->filter(fn (array $category): bool => $category['items'] !== [])
            ->values()
            ->all();
    }
}
