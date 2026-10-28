import { useEffect, useState } from 'react';
import {
    enteredSwitchNote,
    needsEnteredPrice,
} from '@/components/pms/reservations/day-use';

type Offer = App.Data.Reservations.DayUseOfferData;
type RoomType = App.Data.Reservations.RoomTypeOptionData;

/**
 * Moves a line on a Day use plan to an entered price when the plan sells no hours at its start, or not the hours picked.
 * `onSwitch` gets the room type's per-night plan id. Without one the line cannot switch and `onUnsold` runs instead. The
 * note names the plan the line left and stays until `clear` runs, which the Plan select calls on a pick.
 */
export function useEnteredSwitch({
    roomType,
    planName,
    offers,
    loaded,
    hours,
    onSwitch,
    onUnsold,
}: {
    roomType: RoomType | undefined;
    /** The name of the Day use plan the line is on, or null when it is on an entered price or has no plan. */
    planName: string | null;
    offers: Offer[];
    loaded: boolean;
    hours: string;
    onSwitch: (ratePlanId: string) => void;
    onUnsold: () => void;
}): { note: string | null; clear: () => void } {
    const [note, setNote] = useState<string | null>(null);
    const unsold =
        planName !== null && needsEnteredPrice(offers, loaded, hours);

    useEffect(() => {
        if (!unsold || planName === null) {
            return;
        }

        if (roomType?.enteredPlanId == null) {
            if (hours !== '') {
                onUnsold();
            }

            return;
        }

        setNote(enteredSwitchNote(planName));
        onSwitch(String(roomType.enteredPlanId));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [unsold]);

    return { note, clear: () => setNote(null) };
}
