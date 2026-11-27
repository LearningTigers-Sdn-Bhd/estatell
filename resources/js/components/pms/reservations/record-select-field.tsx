import { EnumSelect } from '@/components/pms/enum-select';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';

type NamedRecord = { id: number; name: string };

/**
 * An optional pick of one named record of the booking, with None to clear it: the Sales person and Market segment
 * fields of the booking form and of their Change Dialogs. The list holds the records that can be picked now; the one
 * the booking has (`current`) is kept in it when it can no longer be picked, so the pick still shows.
 */
export function RecordSelectField({
    id,
    label,
    value,
    onValueChange,
    records,
    current = null,
    error,
}: {
    id: string;
    label: string;
    /** The record id as text, or an empty string for none. */
    value: string;
    onValueChange: (value: string) => void;
    records: NamedRecord[];
    current?: NamedRecord | null;
    error?: string;
}) {
    const options =
        current !== null && !records.some((record) => record.id === current.id)
            ? [current, ...records]
            : records;

    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={id}>{label}</FieldLabel>
            <EnumSelect
                id={id}
                options={options.map((record) => ({
                    value: String(record.id),
                    label: record.name,
                }))}
                allLabel="None"
                value={value}
                onValueChange={onValueChange}
                aria-invalid={!!error}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}
