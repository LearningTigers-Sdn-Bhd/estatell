import { useCallback, useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { freeUnits } from '@/routes/reservations';

type FreeUnit = App.Data.Reservations.FreeUnitData;

/**
 * The Room / Bed picker of one unit. It lists the rooms or beds free on every night of the stay. The list loads the first
 * time the picker opens, and again when the room type or dates change. A pick that is no longer free is cleared.
 */
export function BookingUnitPicker({
    id,
    roomTypeId,
    arrival,
    departure,
    value,
    onChange,
    emptyLabel,
    takenIds,
    invalid,
    label,
    dayUse = null,
    walkIn = false,
}: {
    id: string;
    roomTypeId: string;
    arrival: string;
    departure: string;
    value: string;
    onChange: (value: string) => void;
    /** What the empty option says: "Select later", "Auto-assign" or "Select room". */
    emptyLabel: string;
    /** Rooms or beds picked on other units, shown but not pickable. */
    takenIds: Set<string>;
    invalid?: boolean;
    label: string;
    /** A day use block: the start time and hours. The rooms listed are free for those hours on the arrival date. */
    dayUse?: { start: string; hours: string } | null;
    /** A walk-in: the guest takes the room now, and the list also shows rooms or beds that are not ready, disabled. */
    walkIn?: boolean;
}) {
    const ready =
        roomTypeId !== '' &&
        arrival !== '' &&
        (dayUse === null
            ? departure > arrival
            : dayUse.start !== '' && dayUse.hours !== '');
    const key =
        (dayUse === null
            ? `${roomTypeId}|${arrival}|${departure}`
            : `${roomTypeId}|${arrival}|${dayUse.start}|${dayUse.hours}`) +
        (walkIn ? '|walk-in' : '');
    const [loaded, setLoaded] = useState<{
        key: string;
        units: FreeUnit[];
    } | null>(null);

    const load = useCallback(async () => {
        try {
            const response = await fetch(
                freeUnits.url(
                    {},
                    {
                        query:
                            dayUse === null
                                ? {
                                      room_type_id: roomTypeId,
                                      arrival_date: arrival,
                                      departure_date: departure,
                                      ...(walkIn ? { walk_in: '1' } : {}),
                                  }
                                : {
                                      room_type_id: roomTypeId,
                                      stay_type: 'day_use',
                                      arrival_date: arrival,
                                      start_time: dayUse.start,
                                      hours: dayUse.hours,
                                  },
                    },
                ),
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                },
            );
            const body = (await response.json()) as { units: FreeUnit[] };

            setLoaded({ key, units: body.units });
        } catch {
            setLoaded(null);
        }
    }, [
        roomTypeId,
        arrival,
        departure,
        dayUse?.start,
        dayUse?.hours,
        walkIn,
        key,
    ]);

    useEffect(() => {
        if (ready && value !== '' && loaded?.key !== key) {
            void load();
        }
    }, [ready, value, loaded?.key, key, load]);

    useEffect(() => {
        if (value === '') {
            return;
        }

        if (!ready) {
            onChange('');
        } else if (loaded?.key === key) {
            const picked = loaded.units.find(
                (unit) => String(unit.id) === value,
            );

            if (picked === undefined || picked.reason !== null) {
                onChange('');
            }
        }
    }, [ready, loaded, key, value, onChange]);

    const units = loaded?.key === key ? loaded.units : [];
    const options = [
        { value: '', label: emptyLabel },
        ...units.map((unit) => ({
            value: String(unit.id),
            label:
                unit.reason === null
                    ? unit.label
                    : `${unit.label} · ${unit.reason}`,
            disabled:
                unit.reason !== null ||
                (takenIds.has(String(unit.id)) && String(unit.id) !== value),
        })),
    ];

    if (value !== '' && !options.some((option) => option.value === value)) {
        options.push({ value, label: '…', disabled: false });
    }

    return (
        <EnumSelect
            id={id}
            aria-label={label}
            options={options}
            value={value}
            onValueChange={onChange}
            onOpenChange={(open) => {
                if (open && ready && loaded?.key !== key) {
                    void load();
                }
            }}
            disabled={!ready}
            aria-invalid={invalid}
        />
    );
}
