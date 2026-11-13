import { MultiCombobox } from '@/components/pms/multi-combobox';

const ALL = 'all';

export type Choice = { id: number; label: string };

/**
 * A multi-select whose first item picks every choice and shows as one chip, for example **All units (5)** or
 * **All nights (3)**. From the all chip, picking one choice keeps only that choice, and removing the chip clears the
 * pick. Used by the group add-on, the group price, the Change price nights and the Block room rooms.
 */
export function MultiSelectWithAll({
    id,
    name,
    choices,
    value,
    onValueChange,
    allLabel,
    placeholder,
    emptyLabel,
    invalid,
}: {
    id: string;
    name: string;
    choices: Choice[];
    value: number[];
    onValueChange: (value: number[]) => void;
    /** The all item without its count, for example "All units". */
    allLabel: string;
    placeholder: string;
    emptyLabel: string;
    invalid?: boolean;
}) {
    const allIds = choices.map((choice) => choice.id);
    const allPicked = choices.length > 1 && value.length === choices.length;
    const picked = value.map(String);

    const change = (next: string[]) => {
        if (next.includes(ALL) && !allPicked) {
            onValueChange(allIds);

            return;
        }

        if (allPicked) {
            onValueChange(
                next.includes(ALL)
                    ? next
                          .filter((one) => one !== ALL && !picked.includes(one))
                          .map(Number)
                    : [],
            );

            return;
        }

        onValueChange(next.filter((one) => one !== ALL).map(Number));
    };

    return (
        <MultiCombobox
            id={id}
            name={name}
            items={[
                ...(choices.length > 1
                    ? [{ value: ALL, label: `${allLabel} (${choices.length})` }]
                    : []),
                ...choices.map((choice) => ({
                    value: String(choice.id),
                    label: choice.label,
                })),
            ]}
            value={allPicked ? [ALL] : picked}
            onValueChange={change}
            placeholder={placeholder}
            emptyLabel={emptyLabel}
            invalid={invalid}
            maxChips={2}
        />
    );
}
