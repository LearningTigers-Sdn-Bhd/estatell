import { usePoll } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Options = {
    /** The page props to reload, for example `['doorPins']`. Other props are left alone. */
    only: string[];
    /** Milliseconds between reloads. */
    every?: number;
    /** Milliseconds after which polling stops and `gaveUp` becomes true, so a stuck background job does not poll forever. */
    giveUpAfter?: number;
};

/**
 * Reload part of the page on a timer while `active` is true, for data a queued job is still changing: the server says it is
 * not settled yet (a flag such as `UnitDoorPinsData.syncing`), the page polls until the flag turns false, then stops.
 *
 * - `polling` is true while it reloads. Use it to show "Working…" states.
 * - `gaveUp` is true when `active` stayed true for `giveUpAfter`. Use it to say the job did not finish.
 *
 * Put the "not settled" rule on the server, next to the data, so the page only follows one flag. See
 * docs/27-door-locks/01-ttlock-integration.md for the first use.
 */
export function usePollWhile(
    active: boolean,
    { only, every = 2000, giveUpAfter = 60000 }: Options,
): { polling: boolean; gaveUp: boolean } {
    const { start, stop } = usePoll(
        every,
        { only },
        { autoStart: false, mode: 'rest' },
    );
    const [gaveUp, setGaveUp] = useState(false);

    useEffect(() => {
        if (!active) {
            setGaveUp(false);
            stop();

            return;
        }

        start();
        const timer = setTimeout(() => {
            setGaveUp(true);
            stop();
        }, giveUpAfter);

        return () => {
            clearTimeout(timer);
            stop();
        };
        // `start` and `stop` change every render; only `active` should restart the timer.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [active]);

    return { polling: active && !gaveUp, gaveUp: active && gaveUp };
}
