import { EnumSelect } from '@/components/pms/enum-select';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import type {
    FlagChoice,
    LimitChoice,
    RestrictionChoices,
} from './restriction-choices';
import { restrictionsProblem } from './restriction-choices';

const FLAG_OPTIONS = [
    { value: 'keep', label: 'Leave as is' },
    { value: 'on', label: 'Turn on' },
    { value: 'off', label: 'Turn off' },
];

const LIMIT_OPTIONS = [
    { value: 'keep', label: 'Leave as is' },
    { value: 'set', label: 'Set to' },
    { value: 'clear', label: 'No limit' },
];

function FlagField({
    id,
    label,
    description,
    value,
    onChange,
}: {
    id: string;
    label: string;
    description: string;
    value: FlagChoice;
    onChange: (value: FlagChoice) => void;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <EnumSelect
                id={id}
                options={FLAG_OPTIONS}
                value={value}
                onValueChange={(next) => onChange(next as FlagChoice)}
                className="w-full"
            />
            <FieldDescription>{description}</FieldDescription>
        </Field>
    );
}

function LimitField({
    id,
    label,
    value,
    onChange,
}: {
    id: string;
    label: string;
    value: LimitChoice;
    onChange: (value: LimitChoice) => void;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <div className="flex items-center gap-2">
                <EnumSelect
                    id={id}
                    options={LIMIT_OPTIONS}
                    value={value.mode}
                    onValueChange={(mode) =>
                        onChange({
                            ...value,
                            mode: mode as LimitChoice['mode'],
                        })
                    }
                    className="w-40"
                />
                {value.mode === 'set' && (
                    <InputGroup className="w-32">
                        <InputGroupInput
                            aria-label={`${label} in nights`}
                            type="number"
                            min="1"
                            max="365"
                            step="1"
                            inputMode="numeric"
                            value={value.value}
                            onChange={(event) =>
                                onChange({
                                    ...value,
                                    value: event.target.value,
                                })
                            }
                        />
                        <InputGroupAddon align="inline-end">
                            nights
                        </InputGroupAddon>
                    </InputGroup>
                )}
            </div>
        </Field>
    );
}

/**
 * The restrictions a bulk edit can change over a date range. Each one is left as is until it is set, so a range
 * keeps the restrictions it already has.
 */
export function RestrictionsSection({
    choices,
    onChange,
    errors,
    stopSellOnly = false,
}: {
    /** A Day use plan has no nights, so only Stop sell applies. */
    stopSellOnly?: boolean;
    choices: RestrictionChoices;
    onChange: (choices: RestrictionChoices) => void;
    errors: Record<string, string | undefined>;
}) {
    const problem = restrictionsProblem(choices);
    const serverError =
        errors['restrictions.min_stay'] ?? errors['restrictions.max_stay'];

    return (
        <FieldSet>
            <FieldLegend>Restrictions</FieldLegend>
            <FieldDescription>
                Only the restrictions you change are applied to every date in
                the range. The rest keep what is saved.
            </FieldDescription>
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <FlagField
                    id="bulk-stop-sell"
                    label="Stop sell"
                    description="Nothing can be sold on these nights."
                    value={choices.stopSell}
                    onChange={(stopSell) => onChange({ ...choices, stopSell })}
                />
                {!stopSellOnly && (
                    <>
                        <FlagField
                            id="bulk-cta"
                            label="Closed to arrival"
                            description="No stay can start on these dates."
                            value={choices.closedToArrival}
                            onChange={(closedToArrival) =>
                                onChange({ ...choices, closedToArrival })
                            }
                        />
                        <FlagField
                            id="bulk-ctd"
                            label="Closed to departure"
                            description="No stay can end on these dates."
                            value={choices.closedToDeparture}
                            onChange={(closedToDeparture) =>
                                onChange({ ...choices, closedToDeparture })
                            }
                        />
                        <LimitField
                            id="bulk-min-stay"
                            label="Min stay"
                            value={choices.minStay}
                            onChange={(minStay) =>
                                onChange({ ...choices, minStay })
                            }
                        />
                        <LimitField
                            id="bulk-max-stay"
                            label="Max stay"
                            value={choices.maxStay}
                            onChange={(maxStay) =>
                                onChange({ ...choices, maxStay })
                            }
                        />
                    </>
                )}
            </div>
            {!stopSellOnly && (
                <FieldDescription>
                    Min and max stay are checked from the arrival date.
                </FieldDescription>
            )}
            {(problem ?? serverError) && (
                <FieldError>{problem ?? serverError}</FieldError>
            )}
        </FieldSet>
    );
}
