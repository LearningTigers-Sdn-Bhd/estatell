import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import type { CardForm } from '@/components/pms/registration-card/use-card-form';

type TextName =
    | 'full_name'
    | 'date_of_birth'
    | 'phone'
    | 'email'
    | 'vehicle_plate'
    | 'address_line1'
    | 'address_line2'
    | 'city'
    | 'state'
    | 'postal_code'
    | 'signer_name';

/** One text input of the guest screen, with its label and error. Render it inside a FieldGroup grid. */
export function CardTextField({
    form,
    name,
    label,
    type = 'text',
    placeholder,
}: {
    form: CardForm;
    name: TextName;
    label: string;
    type?: string;
    placeholder?: string;
}) {
    const error = (form.errors as Record<string, string>)[name];

    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={`card-${name}`}>{label}</FieldLabel>
            <Input
                id={`card-${name}`}
                type={type}
                placeholder={placeholder}
                value={form.data[name]}
                onChange={(event) => form.setData(name, event.target.value)}
                aria-invalid={!!error}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}
