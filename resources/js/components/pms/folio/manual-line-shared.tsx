import { useState } from 'react';
import { KEEP_OPTIONS } from '@/components/pms/use-form-options';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';

/** The reason of a row menu action. It starts as the page's reason and can be edited. */
export function ReasonField({
    id,
    value,
    onChange,
    error,
}: {
    id: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
}) {
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={id}>Reason</FieldLabel>
            <Textarea
                id={id}
                rows={2}
                maxLength={500}
                placeholder="e.g. Guest paid cash to the night staff"
                value={value}
                onChange={(event) => onChange(event.target.value)}
                aria-invalid={!!error}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * The Dialog's UUID is the batch key, so a second click writes nothing twice.
 */
export function useBatchKey(): string {
    const [key] = useState(() => crypto.randomUUID());

    return key;
}

/** Posts at once and keeps the page's draft rows. */
export function postOptions(onClose: () => void) {
    return {
        preserveScroll: true,
        preserveState: true,
        except: KEEP_OPTIONS,
        onSuccess: onClose,
    };
}
