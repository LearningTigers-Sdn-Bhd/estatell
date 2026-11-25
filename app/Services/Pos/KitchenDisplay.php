<?php

namespace App\Services\Pos;

use App\Enums\PosDiningKind;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Models\PosKitchenStation;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOutlet;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * What the kitchen screens show, and how a fired line finds its station. Reads only: the version a screen polls is written by
 * `BumpKitchenVersion`. Lines of a combo parent route nowhere, its children carry the work. Voided lines stay on the screen
 * for half an hour, struck through, as the void docket. See docs/34-native-pos/06-kitchen-kds-and-qr.md.
 */
class KitchenDisplay
{
    /** How many bumped tickets the recall drawer keeps. */
    public const RECALL_LIMIT = 20;

    /** How long a voided line stays on the screen as a struck-through docket. */
    public const VOID_VISIBLE_MINUTES = 30;

    /**
     * The station a line is cooked at: its item's station, else its category's. Null when neither is set, or for a combo parent.
     */
    public function stationIdFor(PosOrderLine $line): ?int
    {
        $item = $line->variant?->item;

        if ($item === null || $item->is_combo) {
            return null;
        }

        $stationId = $item->kitchen_station_id ?? $item->category?->kitchen_station_id;

        return $stationId === null ? null : (int) $stationId;
    }

    public static function stationVersionKey(int $stationId): string
    {
        return 'pos:kds:'.$stationId.':version';
    }

    public static function outletVersionKey(int $outletId): string
    {
        return 'pos:ods:'.$outletId.':version';
    }

    public function version(int $stationId): string
    {
        return (string) Cache::get(self::stationVersionKey($stationId), '0');
    }

    public function outletVersion(int $outletId): string
    {
        return (string) Cache::get(self::outletVersionKey($outletId), '0');
    }

    /**
     * The dining kinds a station shows, or null when it shows all of them.
     *
     * @return list<PosDiningKind>|null
     */
    public function diningKinds(PosKitchenStation $station): ?array
    {
        if ($station->filter_dining_kinds === null || trim($station->filter_dining_kinds) === '') {
            return null;
        }

        return array_values(array_filter(array_map(
            fn (string $value): ?PosDiningKind => PosDiningKind::tryFrom(trim($value)),
            explode(',', $station->filter_dining_kinds),
        )));
    }

    /**
     * The open tickets of a station: its fired, preparing and ready lines, plus recently voided ones, grouped by order.
     *
     * @return list<array<string, mixed>>
     */
    public function tickets(PosKitchenStation $station): array
    {
        $kinds = $this->diningKinds($station);

        $lines = PosOrderLine::query()
            ->where('kitchen_station_id', $station->id)
            ->where(function (Builder $query): void {
                $query->whereIn('kitchen_status', [PosKitchenStatus::Fired->value, PosKitchenStatus::Preparing->value, PosKitchenStatus::Ready->value])
                    ->orWhere(fn (Builder $voided) => $voided->where('kitchen_status', PosKitchenStatus::Voided->value)
                        ->where('updated_at', '>=', now()->subMinutes(self::VOID_VISIBLE_MINUTES))
                        ->whereNotNull('fired_at'));
            })
            ->whereHas('order', fn (Builder $query) => $query->where('status', PosOrderStatus::Open->value))
            ->with(['order.table', 'order.reservationUnit.nights.room', 'order.diningOption', 'modifiers', 'variant.item', 'parentComboLine'])
            ->orderBy('fired_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (PosOrderLine $line): bool => $kinds === null || in_array($line->order->diningOption?->kind, $kinds, true));

        return $lines
            ->groupBy('pos_order_id')
            ->map(fn ($group): array => $this->ticket($station, $group->first()->order, $group->values()->all()))
            ->values()
            ->all();
    }

    /**
     * The last tickets this station bumped, newest first, for the recall drawer. A ticket is listed while it still has ready lines.
     *
     * @return list<array{id: int, label: string, bumpedAt: string}>
     */
    public function recallable(PosKitchenStation $station): array
    {
        $rows = PosOrderLine::query()
            ->where('kitchen_station_id', $station->id)
            ->where('kitchen_status', PosKitchenStatus::Ready->value)
            ->whereHas('order', fn (Builder $query) => $query->where('status', PosOrderStatus::Open->value))
            ->selectRaw('pos_order_id, max(ready_at) as bumped_at')
            ->groupBy('pos_order_id')
            ->orderByDesc('bumped_at')
            ->limit(self::RECALL_LIMIT)
            ->get();

        $orders = PosOrder::query()
            ->whereIn('id', $rows->pluck('pos_order_id'))
            ->with(['table', 'reservationUnit.nights.room'])
            ->get()
            ->keyBy('id');

        return $rows->map(fn (PosOrderLine $row): array => [
            'id' => (int) $row->pos_order_id,
            'label' => $this->label($orders[$row->pos_order_id]),
            'bumpedAt' => CarbonImmutable::parse($row->bumped_at)->toIso8601String(),
        ])->values()->all();
    }

    /**
     * The pickup board of an outlet: orders still being cooked at its display stations, and orders whose food is all ready.
     *
     * @return array{preparing: list<array{id: int, label: string}>, ready: list<array{id: int, label: string}>}
     */
    public function pickup(PosOutlet $outlet): array
    {
        $groups = PosOrderLine::query()
            ->whereIn('kitchen_status', [PosKitchenStatus::Fired->value, PosKitchenStatus::Preparing->value, PosKitchenStatus::Ready->value])
            ->whereHas('order', fn (Builder $query) => $query->where('pos_outlet_id', $outlet->id)->where('status', PosOrderStatus::Open->value))
            ->whereHas('kitchenStation', fn (Builder $query) => $query->where('pos_outlet_id', $outlet->id)->whereIn('mode', ['kds', 'both']))
            ->with(['order.table', 'order.reservationUnit.nights.room'])
            ->get()
            ->groupBy('pos_order_id');

        $preparing = [];
        $ready = [];

        foreach ($groups as $group) {
            $order = $group->first()->order;
            $entry = ['id' => $order->id, 'label' => $this->label($order)];

            if ($group->contains(fn (PosOrderLine $line): bool => $line->kitchen_status !== PosKitchenStatus::Ready)) {
                $preparing[] = $entry;
            } else {
                $ready[] = $entry;
            }
        }

        return ['preparing' => $preparing, 'ready' => $ready];
    }

    /**
     * What a ticket is called on a screen: its table, its room, else its order number.
     */
    public function label(PosOrder $order): string
    {
        if ($order->table !== null) {
            return __('Table :code', ['code' => $order->table->code]);
        }

        if ($order->reservationUnit !== null) {
            $room = $order->reservationUnit->nights->first(fn ($night): bool => $night->room_id !== null)?->room?->number;

            if ($room !== null) {
                return __('Room :number', ['number' => $room]);
            }
        }

        return $order->order_number ?? __('Order #:id', ['id' => $order->id]);
    }

    /**
     * @param  list<PosOrderLine>  $lines
     * @return array<string, mixed>
     */
    private function ticket(PosKitchenStation $station, PosOrder $order, array $lines): array
    {
        return [
            'id' => $order->id,
            'label' => $this->label($order),
            'type' => $order->table !== null ? 'table' : ($order->reservationUnit !== null ? 'room' : 'walk_in'),
            'diningKind' => $order->diningOption?->kind?->label(),
            'firedAt' => collect($lines)->min(fn (PosOrderLine $line): ?string => $line->fired_at?->toIso8601String()),
            'lines' => array_map(fn (PosOrderLine $line): array => $this->line($station, $line), $lines),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function line(PosKitchenStation $station, PosOrderLine $line): array
    {
        $name = $station->use_internal_name && $line->internal_name ? $line->internal_name : $line->item_name;

        return [
            'id' => $line->id,
            'name' => $name,
            'variant' => $line->variant_name,
            'quantity' => (string) (float) $line->quantity,
            'modifiers' => $line->modifiers->map(fn ($modifier): string => $station->use_internal_name && $modifier->internal_name ? $modifier->internal_name : $modifier->option_name)->values()->all(),
            'note' => $line->note,
            'status' => $line->kitchen_status->value,
            'course' => $line->course_number,
            'seat' => $line->seat_number,
            'firedAt' => $line->fired_at?->toIso8601String(),
            'readyAt' => $line->ready_at?->toIso8601String(),
            'cookingGuide' => $line->variant?->item?->cooking_guide,
            'combo' => $line->parentComboLine?->item_name,
            'voided' => $line->kitchen_status === PosKitchenStatus::Voided,
        ];
    }
}
