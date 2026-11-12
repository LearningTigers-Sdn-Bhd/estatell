import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

/** One labelled text box of a Concierge content form, with its validation error. */
export function TextField({
    name,
    label,
    defaultValue,
    error,
    rows,
    type = 'text',
    maxLength,
    placeholder,
}: {
    name: string;
    label: string;
    defaultValue: string | number | null;
    error?: string;
    rows?: number;
    type?: 'text' | 'number' | 'time' | 'tel';
    maxLength?: number;
    placeholder?: string;
}) {
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            {rows ? (
                <Textarea
                    id={name}
                    name={name}
                    defaultValue={defaultValue ?? ''}
                    rows={rows}
                    maxLength={maxLength}
                    placeholder={placeholder}
                />
            ) : (
                <Input
                    id={name}
                    name={name}
                    type={type}
                    min={type === 'number' ? 0 : undefined}
                    defaultValue={defaultValue ?? ''}
                    maxLength={maxLength}
                    placeholder={placeholder}
                />
            )}
            <FieldError>{error}</FieldError>
        </Field>
    );
}
