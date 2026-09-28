import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

export function SettingsInput({
    name,
    label,
    value,
    error,
    type = 'text',
    min,
    max,
    step,
    disabled,
    description,
}: {
    name: string;
    label: string;
    value: string | number | null;
    error?: string;
    type?: React.HTMLInputTypeAttribute;
    min?: number;
    max?: number;
    step?: string;
    disabled?: boolean;
    description?: string;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Input
                id={name}
                name={name}
                type={type}
                defaultValue={value ?? ''}
                min={min}
                max={max}
                step={step}
                disabled={disabled}
                aria-invalid={!!error}
            />
            {description && <FieldDescription>{description}</FieldDescription>}
            <FieldError>{error}</FieldError>
        </Field>
    );
}

export function SettingsSelect({
    name,
    label,
    value,
    options,
    error,
    disabled,
    description,
}: {
    name: string;
    label: string;
    value: string;
    options: EnumOption[];
    error?: string;
    disabled?: boolean;
    description?: string;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <EnumSelect
                id={name}
                name={name}
                options={options}
                defaultValue={value}
                disabled={disabled}
                aria-invalid={!!error}
            />
            {description && <FieldDescription>{description}</FieldDescription>}
            <FieldError>{error}</FieldError>
        </Field>
    );
}

export function SettingsCheckbox({
    name,
    label,
    checked,
    error,
    description,
}: {
    name: string;
    label: string;
    checked: boolean;
    error?: string;
    description?: string;
}) {
    return (
        <Field>
            <div className="flex items-center gap-3">
                <Checkbox
                    id={name}
                    name={name}
                    defaultChecked={checked}
                    aria-invalid={!!error}
                />
                <FieldLabel htmlFor={name}>{label}</FieldLabel>
            </div>
            {description && <FieldDescription>{description}</FieldDescription>}
            <FieldError>{error}</FieldError>
        </Field>
    );
}
