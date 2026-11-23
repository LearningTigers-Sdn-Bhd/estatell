import { useEffect, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import { freeUnits } from '@/routes/reservations';

type WholeRoom = App.Data.Reservations.WholeRoomData;

/**
 * The Whole dorm select of a dorm line: "Beds only", or one of the dorms whose every bed is free on every night of the
 * stay. Picking a dorm books all its beds (docs/12-reservations/03-bed-bookings.md). A picked dorm that is no longer
 * wholly free is cleared.
 */
export function WholeDormPicker({
    id,
    roomTypeId,
    arrival,
    departure,
    value,
    onChange,
    invalid,
}: {
    id: string;
    roomTypeId: string;
    arrival: string;
    departure: string;
    value: string;
    /** The dorm id and its bed count, or '' and 0 for Beds only. */
    onChange: (roomId: string, beds: number) => void;
    invalid?: boolean;
}) {
    const ready = roomTypeId !== '' && arrival !== '' && departure > arrival;
    const key = `${roomTypeId}|${arrival}|${departure}`;
    const [loaded, setLoaded] = useState<{
        key: string;
        rooms: WholeRoom[];
    } | null>(null);

    useEffect(() => {
        if (!ready || loaded?.key === key) {
            return;
        }

        let cancelled = false;

        void (async () => {
            try {
                const response = await fetch(
                    freeUnits.url(
                        {},
                        {
                            query: {
                                room_type_id: roomTypeId,
                                arrival_date: arrival,
                                departure_date: departure,
                            },
                        },
                    ),
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                    },
                );
                const body = (await response.json()) as {
                    wholeRooms?: WholeRoom[];
                };

                if (!cancelled) {
                    setLoaded({ key, rooms: body.wholeRooms ?? [] });
                }
            } catch {
                if (!cancelled) {
                    setLoaded(null);
                }
            }
        })();

        return () => {
            cancelled = true;
        };
    }, [ready, key, loaded?.key, roomTypeId, arrival, departure]);

    const rooms = loaded?.key === key ? loaded.rooms : [];

    useEffect(() => {
        if (value === '') {
            return;
        }

        if (!ready) {
            onChange('', 0);
        } else if (
            loaded?.key === key &&
            !loaded.rooms.some((room) => String(room.id) === value)
        ) {
            onChange('', 0);
        }
    }, [ready, loaded, key, value, onChange]);

    const options = [
        { value: '', label: 'Beds only' },
        ...rooms.map((room) => ({
            value: String(room.id),
            label: `Whole dorm ${room.label} · ${room.bedIds.length} beds`,
        })),
    ];

    if (value !== '' && !options.some((option) => option.value === value)) {
        options.push({ value, label: '…' });
    }

    return (
        <EnumSelect
            id={id}
            aria-label="Whole dorm"
            options={options}
            value={value}
            onValueChange={(next) =>
                onChange(
                    next,
                    rooms.find((room) => String(room.id) === next)?.bedIds
                        .length ?? 0,
                )
            }
            disabled={!ready}
            aria-invalid={invalid}
        />
    );
}
