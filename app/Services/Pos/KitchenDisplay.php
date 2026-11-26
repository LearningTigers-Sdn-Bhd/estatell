<?php

namespace App\Services\Pos;

use App\Enums\PosDiningKind;
use App\Enums\PosKitchenStatus;
use App\Enums\PosOrderStatus;
use App\Enums\PosOrderType;
use App\Models\PosKitchenStation;
use App\Models\PosOrder;
use App\Models\PosOrderLine;
use App\Models\PosOutlet;
use App\Models\Property;
use App\Services\NightAudit\CurrentBusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /** How long a paid, ready order stays on the pickup board when nobody marks it served. It stays at the window. */
    public const PICKUP_PAID_READY_MINUTES = 30;

    /** A paid order this recent stays even when the night audit has just moved the business date on. */
    private const RECENTLY_PAID_HOURS = 3;

    public function __construct(private CurrentBusinessDate $currentBusinessDate) {}

    /**
     * The orders whose food the kitchen still handles: open checks, and paid sales of the current business date. Payment
     * does not take food off a screen, serving it does. Older paid sales stay hidden, whatever their lines say.
     *
     * @param  Builder<PosOrder>  $query
     * @return Builder<PosOrder>
     */
    public function onScreen(Builder $query, int $propertyId): Builder
    {
        $date = $this->currentBusinessDate->date(Property::query()->findOrFail($propertyId));

        return $query->where(fn (Builder $any) => $any
            ->where('status', PosOrderStatus::Open->value)
            ->orWhere(fn (Builder $paid) => $paid
                ->where('status', PosOrderStatus::Settled->value)
                ->where('order_type', PosOrderType::Sale->value)
                ->where(fn (Builder $fresh) => $fresh
                    ->where('settled_at', '>=', now()->subHours(self::RECENTLY_PAID_HOURS))
                    ->when($date !== null, fn (Builder $today) => $today->orWhereDate('business_date', '>=', $date->toDateString())))));
    }

    public function isOnScreen(PosOrder $order): bool
    {
        return $this->onScreen(PosOrder::query()->whereKey($order->id), $order->property_id)->exists();
    }

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

    /**
     * Also changes every five minutes, so a quiet pickup board still drops the paid orders that have waited too long.
     */
    public function outletVersion(int $outletId): string
    {
        return Cache::get(self::outletVersionKey($outletId), '0').':'.intdiv(now()->getTimestamp(), 300);
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
            ->whereHas('order', fn (Builder $query) => $this->onScreen($query, $station->property_id))
            ->with(['order.table', 'order.reservationUnit.nights.room', 'order.diningOption', 'modifiers', 'variant.item', 'parentComboLine'])
            ->withSum('refundLines as refunded_quantity', 'quantity')
            ->withMax('refundLines as refunded_at', 'created_at')
            ->orderBy('fired_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (PosOrderLine $line): bool => $kinds === null || in_array($line->order->diningOption?->kind, $kinds, true))
            // A fully refunded line is a void docket for the same half hour as a voided one.
            ->reject(fn (PosOrderLine $line): bool => $this->quantityLeft($line) <= 0
                && CarbonImmutable::parse((string) $line->getAttribute('refunded_at'))->lt(now()->subMinutes(self::VOID_VISIBLE_MINUTES)));

        return array_values($lines
            ->groupBy('pos_order_id')
            ->map(fn ($group): array => $this->ticket($station, $group->first()->order, $group))
            ->all());
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
            ->whereHas('order', fn (Builder $query) => $this->onScreen($query, $station->property_id))
            ->whereRaw('quantity > (select coalesce(sum(refunds.quantity), 0) from pos_order_lines as refunds where refunds.refund_of_order_line_id = pos_order_lines.id)')
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

        return array_values($rows->map(fn (PosOrderLine $row): array => [
            'id' => (int) $row->pos_order_id,
            'label' => $this->label($orders[$row->pos_order_id]),
            'bumpedAt' => CarbonImmutable::parse((string) $row->getAttribute('bumped_at'))->toIso8601String(),
        ])->all());
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
            ->whereHas('order', fn (Builder $query) => $this->onScreen($query->where('pos_outlet_id', $outlet->id), $outlet->property_id))
            ->whereHas('kitchenStation', fn (Builder $query) => $query->where('pos_outlet_id', $outlet->id)->whereIn('mode', ['kds', 'both']))
            ->with(['order.table', 'order.reservationUnit.nights.room'])
            ->withSum('refundLines as refunded_quantity', 'quantity')
            ->get()
            ->filter(fn (PosOrderLine $line): bool => $this->quantityLeft($line) > 0)
            ->groupBy('pos_order_id');

        $preparing = [];
        $ready = [];

        foreach ($groups as $group) {
            $order = $group->first()->order;
            $entry = ['id' => $order->id, 'label' => $this->label($order)];

            if ($group->contains(fn (PosOrderLine $line): bool => $line->kitchen_status !== PosKitchenStatus::Ready)) {
                $preparing[] = $entry;
            } else {
                $readyAt = $group->max(fn (PosOrderLine $line): int => $line->ready_at?->getTimestamp() ?? 0);
                if ($order->status === PosOrderStatus::Settled && $readyAt < now()->subMinutes(self::PICKUP_PAID_READY_MINUTES)->getTimestamp()) {
                    continue;
                }

                $ready[] = ['entry' => $entry, 'readyAt' => $readyAt];
            }
        }

        usort($ready, fn (array $a, array $b): int => $b['readyAt'] <=> $a['readyAt'] ?: $b['entry']['id'] <=> $a['entry']['id']);

        return [
            'preparing' => $preparing,
            'ready' => array_column($ready, 'entry'),
        ];
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
     * @param  Collection<int, PosOrderLine>  $lines
     * @return array<string, mixed>
     */
    private function ticket(PosKitchenStation $station, PosOrder $order, Collection $lines): array
    {
        return [
            'id' => $order->id,
            'label' => $this->label($order),
            'type' => $order->table !== null ? 'table' : ($order->reservationUnit !== null ? 'room' : 'walk_in'),
            'diningKind' => $order->diningOption?->kind?->label(),
            'firedAt' => $lines->min(fn (PosOrderLine $line): ?string => $line->fired_at?->toIso8601String()),
            'lines' => array_values($lines->map(fn (PosOrderLine $line): array => $this->line($station, $line))->all()),
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
            'quantity' => (string) ($this->quantityLeft($line) > 0 ? $this->quantityLeft($line) : (float) $line->quantity),
            'modifiers' => $line->modifiers->map(fn ($modifier): string => $station->use_internal_name && $modifier->internal_name ? $modifier->internal_name : $modifier->option_name)->values()->all(),
            'note' => $line->note,
            'status' => $line->kitchen_status->value,
            'course' => $line->course_number,
            'seat' => $line->seat_number,
            'firedAt' => $line->fired_at?->toIso8601String(),
            'readyAt' => $line->ready_at?->toIso8601String(),
            'cookingGuide' => $line->variant?->item?->cooking_guide,
            'combo' => $line->parentComboLine?->item_name,
            'voided' => $line->kitchen_status === PosKitchenStatus::Voided || $this->quantityLeft($line) <= 0,
        ];
    }

    /**
     * What is still to be made or handed over. Expects `refunded_quantity` loaded.
     */
    private function quantityLeft(PosOrderLine $line): float
    {
        return (float) $line->quantity - (float) $line->getAttribute('refunded_quantity');
    }
}
