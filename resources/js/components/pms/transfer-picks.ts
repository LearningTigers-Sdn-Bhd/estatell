import type { EnumOption } from '@/components/pms/enum-select';
import { transferPrice } from '@/components/pms/transfer-price';

type Route = App.Data.Transfers.TransferRouteData;

/**
 * The Transfer in or Transfer out of the booking form. `value` is "route id:mode" ("4:shared"), or empty for no
 * transfer. The other fields show once a transfer is picked.
 */
export type TransferChoice = {
    value: string;
    pickup_time: string;
    flight_number: string;
    bags: string;
};

export type TransferPayload = {
    mode: string;
    transfer_route_id: number;
    pickup_time: string;
    flight_number: string | null;
    bags: number | null;
};

export const NO_TRANSFER: TransferChoice = {
    value: '',
    pickup_time: '',
    flight_number: '',
    bags: '',
};

const MODES = [
    { value: 'shared', label: 'Shared' },
    { value: 'private', label: 'Private vehicle' },
    { value: 'included', label: 'In package' },
] as const;

/**
 * One option for each route of the direction and each way to charge it, "Airport to Kopi Lane · Shared", with what it
 * adds as the second line. A route without a private price still offers Private, charged by hand, as on the
 * reservation Transfers tab.
 */
export function transferOptions(
    routes: Route[],
    direction: 'in' | 'out',
    currency: App.Data.Property.CurrencyData | null,
): EnumOption[] {
    return [
        { value: '', label: 'No transfer' },
        ...routes
            .filter((route) => route.direction === direction)
            .flatMap((route) =>
                MODES.map((mode) => ({
                    value: `${route.id}:${mode.value}`,
                    label: `${route.name} · ${mode.label}`,
                    description: transferPrice(route, mode.value, currency),
                })),
            ),
    ];
}

/** The route a choice picks, for its usual drive time. */
export function routeOf(
    choice: TransferChoice,
    routes: Route[],
): Route | undefined {
    const id = Number(choice.value.split(':')[0]);

    return routes.find((route) => route.id === id);
}

/**
 * The pick-up time a new pick starts with: the arrival time less the drive for a transfer in, so the guest arrives on
 * time, and the departure time for a transfer out. Staff change it as needed.
 */
export function defaultPickupTime(
    direction: 'in' | 'out',
    stayTime: string,
    minutes: number | null,
): string {
    if (
        direction === 'out' ||
        minutes === null ||
        !/^\d{2}:\d{2}$/.test(stayTime)
    ) {
        return stayTime;
    }

    const [hours, mins] = stayTime.split(':').map(Number);
    const total = (((hours * 60 + mins - minutes) % 1440) + 1440) % 1440;

    return `${String(Math.floor(total / 60)).padStart(2, '0')}:${String(total % 60).padStart(2, '0')}`;
}

/** The `transfer_in` or `transfer_out` of the booking request; null for no transfer. */
export function transferPayload(
    choice: TransferChoice,
): TransferPayload | null {
    if (choice.value === '') {
        return null;
    }

    const [routeId, mode] = choice.value.split(':');
    const flight = choice.flight_number.trim();

    return {
        mode,
        transfer_route_id: Number(routeId),
        pickup_time: choice.pickup_time,
        flight_number: flight === '' ? null : flight,
        bags: choice.bags === '' ? null : Number(choice.bags),
    };
}
