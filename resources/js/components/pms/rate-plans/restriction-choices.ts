/** What a bulk edit does to one on-or-off restriction: leave it, turn it on, or turn it off. */
export type FlagChoice = 'keep' | 'on' | 'off';

/** What a bulk edit does to a stay limit: leave it, set it to a number, or remove it. */
export type LimitChoice = { mode: 'keep' | 'set' | 'clear'; value: string };

export type RestrictionChoices = {
    stopSell: FlagChoice;
    closedToArrival: FlagChoice;
    closedToDeparture: FlagChoice;
    minStay: LimitChoice;
    maxStay: LimitChoice;
};

/** The restriction columns a bulk edit can change. Only the columns it changes are sent. */
export type RestrictionsPayload = {
    stop_sell?: boolean;
    closed_to_arrival?: boolean;
    closed_to_departure?: boolean;
    min_stay?: number | null;
    max_stay?: number | null;
};

export function emptyRestrictionChoices(): RestrictionChoices {
    return {
        stopSell: 'keep',
        closedToArrival: 'keep',
        closedToDeparture: 'keep',
        minStay: { mode: 'keep', value: '' },
        maxStay: { mode: 'keep', value: '' },
    };
}

/** Whether any restriction is set to change. */
export function hasRestrictionChanges(choices: RestrictionChoices): boolean {
    return (
        choices.stopSell !== 'keep' ||
        choices.closedToArrival !== 'keep' ||
        choices.closedToDeparture !== 'keep' ||
        choices.minStay.mode !== 'keep' ||
        choices.maxStay.mode !== 'keep'
    );
}

function limitValue(choice: LimitChoice): number | null | undefined {
    if (choice.mode === 'keep') {
        return undefined;
    }

    return choice.mode === 'clear' || choice.value.trim() === ''
        ? null
        : Number(choice.value);
}

/** The request part: only the restrictions set to change, so the others keep their saved values. */
export function restrictionsToPayload(
    choices: RestrictionChoices,
): RestrictionsPayload {
    const payload: RestrictionsPayload = {};
    const flags = [
        ['stop_sell', choices.stopSell],
        ['closed_to_arrival', choices.closedToArrival],
        ['closed_to_departure', choices.closedToDeparture],
    ] as const;

    for (const [key, choice] of flags) {
        if (choice !== 'keep') {
            payload[key] = choice === 'on';
        }
    }

    const min = limitValue(choices.minStay);
    const max = limitValue(choices.maxStay);

    if (min !== undefined) {
        payload.min_stay = min;
    }

    if (max !== undefined) {
        payload.max_stay = max;
    }

    return payload;
}

/**
 * A reason the choices cannot be saved, or null. A stay limit set with no number is a problem, and so is a max
 * stay below the min stay when both are set. The server checks again.
 */
export function restrictionsProblem(
    choices: RestrictionChoices,
): string | null {
    for (const [name, choice] of [
        ['min stay', choices.minStay],
        ['max stay', choices.maxStay],
    ] as const) {
        if (choice.mode === 'set') {
            const number = Number(choice.value);

            if (
                choice.value.trim() === '' ||
                !Number.isInteger(number) ||
                number < 1
            ) {
                return `Enter the ${name} as a whole number of nights, 1 or more.`;
            }
        }
    }

    const min = limitValue(choices.minStay);
    const max = limitValue(choices.maxStay);

    if (typeof min === 'number' && typeof max === 'number' && max < min) {
        return 'The max stay cannot be below the min stay.';
    }

    return null;
}
