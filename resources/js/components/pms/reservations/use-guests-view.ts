import { useViewChoice } from '@/components/pms/use-view-choice';
import type { ViewChoice } from '@/components/pms/use-view-choice';

export type GuestsView = ViewChoice;

/**
 * All or Grouped on the Guests tab, remembered in the browser.
 */
export function useGuestsView(): [GuestsView, (view: GuestsView) => void] {
    return useViewChoice('reservations.guests-view');
}
