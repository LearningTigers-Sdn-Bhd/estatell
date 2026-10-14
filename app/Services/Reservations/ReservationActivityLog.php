<?php

namespace App\Services\Reservations;

use App\Data\Reservations\ReservationActivityData;
use App\Enums\BillingMode;
use App\Enums\BoatDirection;
use App\Enums\BoatMode;
use App\Models\ChargeItem;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * The activity log of one reservation in readable form, newest first. The names the entries refer to (room types,
 * charge items, guests) are read for the whole page in three queries, so a page costs the same however many entries it has.
 */
class ReservationActivityLog
{
    public const int PER_PAGE = 25;

    /**
     * @return LengthAwarePaginator<int, ReservationActivityData>
     */
    public function paginate(Reservation $reservation): LengthAwarePaginator
    {
        $page = Activity::query()
            ->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)
            ->with('causer')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $names = [
            'room_type_id' => $this->names(RoomType::class, $reservation, $page->getCollection(), ['room_type_id']),
            'charge_item_id' => $this->names(ChargeItem::class, $reservation, $page->getCollection(), ['charge_item_id']),
            'guest_id' => $this->names(Guest::class, $reservation, $page->getCollection(), ['guest_id'], 'full_name'),
        ];

        $page->setCollection($page->getCollection()->map(fn (Activity $activity): ReservationActivityData => $this->entry($activity, $names)));

        return $page;
    }

    /**
     * The latest price override of a unit: the one logged when the unit was added, or a later Change price. Its reason
     * and who set it. Null when the unit has none.
     *
     * @return array{reason: string, by: ?string}|null
     */
    public function priceOverride(Reservation $reservation, int $unitId): ?array
    {
        $activity = Activity::query()
            ->where('subject_type', $reservation->getMorphClass())
            ->where('subject_id', $reservation->id)
            ->where(fn ($query) => $query
                ->where(fn ($added) => $added->where('event', 'reservation.unit_added')->whereNotNull('properties->override_reason'))
                ->orWhere('event', 'reservation.night_price_changed'))
            ->where('properties->reservation_unit_id', $unitId)
            ->with('causer')
            ->latest('id')
            ->first();
        $reason = $activity?->properties->get($activity->event === 'reservation.unit_added' ? 'override_reason' : 'reason');

        return $reason === null ? null : ['reason' => (string) $reason, 'by' => $this->causerName($activity->causer)];
    }

    /**
     * @param  array<string, array<int, string>>  $names
     */
    private function entry(Activity $activity, array $names): ReservationActivityData
    {
        $properties = $activity->properties;
        $name = fn (string $key): string => $names[$key][(int) $properties->get($key)] ?? __('unknown');
        $reason = null;

        $text = match ($activity->event) {
            'reservation.created' => __('Reservation created with :count units', ['count' => $properties->get('units')]),
            'reservation.unit_added' => __('Unit added: :roomType, :arrival to :departure', [
                'roomType' => $name('room_type_id'),
                'arrival' => $properties->get('arrival_date'),
                'departure' => $properties->get('departure_date'),
            ]),
            'reservation.add_on_added' => __('Add-on added: :item, :quantity × :amount', [
                'item' => $name('charge_item_id'),
                'quantity' => $properties->get('quantity'),
                'amount' => $properties->get('unit_amount'),
            ]),
            'reservation.add_on_removed' => __('Add-on removed: :item, :quantity × :amount', [
                'item' => $name('charge_item_id'),
                'quantity' => $properties->get('quantity'),
                'amount' => $properties->get('unit_amount'),
            ]),
            'reservation.auto_add_on_declined' => __(':item was not added (removed on the booking form)', [
                'item' => $name('charge_item_id'),
            ]),
            'reservation.group_updated' => $this->groupText($properties->get('old', []), $properties->get('new', [])),
            'reservation.guest_attached' => __(':guest added to a unit', ['guest' => $name('guest_id')]),
            'reservation.night_price_changed' => trans_choice('Price of :count night changed to :amount|Price of :count nights changed to :amount', count($properties->get('nights', [])), [
                'amount' => data_get($properties->get('nights', []), '0.new_amount'),
            ]),
            'reservation.boat_trip_set' => $this->boatTripSetText($properties),
            'reservation.boat_trip_cleared' => __(':direction: not set.', ['direction' => BoatDirection::from((string) $properties->get('direction'))->label()]),
            default => (string) $activity->description,
        };

        if ($activity->event === 'reservation.unit_added') {
            $reason = $this->overrideReason($properties);
        }

        if ($activity->event === 'reservation.night_price_changed') {
            $reason = (string) $properties->get('reason');
        }

        return new ReservationActivityData(
            id: $activity->id,
            kind: $activity->event,
            text: $text,
            reason: $reason,
            causerName: $this->causerName($activity->causer),
            createdAt: $activity->created_at->toIso8601String(),
        );
    }

    /**
     * "Boat in: Scheduled boat 09:00 (Kuala Besut jetty)." for a scheduled trip, "Boat out: Own boat 11:30." otherwise.
     *
     * @param  Collection<string, mixed>  $properties
     */
    private function boatTripSetText(Collection $properties): string
    {
        $direction = BoatDirection::from((string) $properties->get('direction'))->label();
        $mode = BoatMode::from((string) $properties->get('mode'));
        $pickup = (string) $properties->get('pickup_point');

        if ($mode->needsDeparture()) {
            return $pickup === ''
                ? __(':direction: :mode :time.', ['direction' => $direction, 'mode' => $mode->label(), 'time' => $properties->get('boat_time')])
                : __(':direction: :mode :time (:pickup).', ['direction' => $direction, 'mode' => $mode->label(), 'time' => $properties->get('boat_time'), 'pickup' => $pickup]);
        }

        return __(':direction: :mode :time.', ['direction' => $direction, 'mode' => $mode->label(), 'time' => $properties->get('unit_time')]);
    }

    /**
     * @param  Collection<string, mixed>  $properties
     */
    private function overrideReason(Collection $properties): ?string
    {
        $parts = array_filter([
            $properties->get('override_reason') === null ? null : __('Price override to :amount: :reason', ['amount' => $properties->get('override_total'), 'reason' => $properties->get('override_reason')]),
            $properties->get('restriction_override_reason') === null ? null : __('Restriction override: :reason', ['reason' => $properties->get('restriction_override_reason')]),
        ]);

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /**
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    private function groupText(array $old, array $new): string
    {
        $changes = [];

        if (($old['group_name'] ?? null) !== ($new['group_name'] ?? null)) {
            $changes[] = __('group name :old → :new', ['old' => $old['group_name'] ?? __('none'), 'new' => $new['group_name'] ?? __('none')]);
        }

        if (($old['billing_mode'] ?? null) !== ($new['billing_mode'] ?? null)) {
            $changes[] = __('billing :old → :new', [
                'old' => BillingMode::tryFrom((string) ($old['billing_mode'] ?? ''))?->label() ?? __('none'),
                'new' => BillingMode::tryFrom((string) ($new['billing_mode'] ?? ''))?->label() ?? __('none'),
            ]);
        }

        return __('Group updated: :changes', ['changes' => implode(', ', $changes)]);
    }

    /**
     * Names of the records the page's entries refer to, read with one query and limited to the reservation's property.
     *
     * @param  class-string<RoomType|ChargeItem|Guest>  $model
     * @param  Collection<int, Activity>  $activities
     * @param  list<string>  $keys
     * @return array<int, string>
     */
    private function names(string $model, Reservation $reservation, Collection $activities, array $keys, string $column = 'name'): array
    {
        $ids = $activities
            ->flatMap(fn (Activity $activity): array => array_map(fn (string $key) => $activity->properties->get($key), $keys))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return $model::query()
            ->where('property_id', $reservation->property_id)
            ->whereIn('id', $ids)
            ->pluck($column, 'id')
            ->all();
    }

    /**
     * The name of the staff user who caused the entry. Any other causer has no name here.
     */
    private function causerName(?Model $causer): ?string
    {
        return $causer instanceof User ? $causer->name : null;
    }
}
