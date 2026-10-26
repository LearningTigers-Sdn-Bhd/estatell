<?php

namespace App\Services\Reservations;

use App\Data\Reservations\BookingBackData;
use App\Models\Property;

/**
 * The page the booking form goes back to, from `back` in the query. Only known page names count, never a URL, so the
 * link always stays in this property. `back=timeline` and `back=rooms` keep the view's date and filters from
 * `back_query`; the view checks those values again when it opens. Anything else goes back to Reservations.
 * See docs/12-reservations/05-booking-form.md#back-link.
 */
class BookingFormBack
{
    /**
     * The query keys a back link may carry, for each view. The list keys take a list of plain values.
     */
    private const TIMELINE_KEYS = ['from', 'to', 'q', 'group_by'];

    private const TIMELINE_LIST_KEYS = ['groups', 'types', 'statuses'];

    private const ROOMS_KEYS = ['date', 'q', 'group_by'];

    private const ROOMS_LIST_KEYS = ['groups', 'types', 'statuses', 'show'];

    /**
     * @param  array<string, mixed>  $query
     */
    public function fromQuery(Property $property, array $query): BookingBackData
    {
        return match ($query['back'] ?? null) {
            'timeline' => $this->view($property, 'timeline', __('Timeline View'), self::TIMELINE_KEYS, self::TIMELINE_LIST_KEYS, $query['back_query'] ?? null),
            'rooms' => $this->view($property, 'rooms', __('Room View'), self::ROOMS_KEYS, self::ROOMS_LIST_KEYS, $query['back_query'] ?? null),
            'dashboard' => new BookingBackData(route('dashboard', ['property' => $property]), __('Dashboard'), ['back' => 'dashboard']),
            default => new BookingBackData(route('reservations.index', ['property' => $property]), __('Reservations'), []),
        };
    }

    /**
     * @param  list<string>  $keys
     * @param  list<string>  $listKeys
     */
    private function view(Property $property, string $back, string $label, array $keys, array $listKeys, mixed $backQuery): BookingBackData
    {
        $parsed = [];
        if (is_string($backQuery)) {
            parse_str($backQuery, $parsed);
        }

        $kept = [];
        foreach ($keys as $key) {
            if (isset($parsed[$key]) && is_string($parsed[$key]) && $parsed[$key] !== '') {
                $kept[$key] = $parsed[$key];
            }
        }
        foreach ($listKeys as $key) {
            if (isset($parsed[$key]) && is_array($parsed[$key])) {
                $values = array_values(array_filter($parsed[$key], fn (mixed $value): bool => is_string($value) && $value !== ''));
                if ($values !== []) {
                    $kept[$key] = $values;
                }
            }
        }

        return new BookingBackData(
            route('front-desk.'.$back, ['property' => $property, ...$kept]),
            $label,
            ['back' => $back, 'back_query' => http_build_query($kept)],
        );
    }
}
