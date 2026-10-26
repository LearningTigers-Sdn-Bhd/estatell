import type { ReactNode } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

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
    placeholder,
}: {
    name: string;
    label: ReactNode;
    value: string | number | null;
    error?: string;
    type?: React.HTMLInputTypeAttribute;
    min?: number;
    max?: number;
    step?: string;
    disabled?: boolean;
    description?: string;
    placeholder?: string;
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
                placeholder={placeholder}
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
    allLabel,
}: {
    name: string;
    label: string;
    value: string;
    options: EnumOption[];
    /** Label for the empty value, for example "Not set". */
    allLabel?: string;
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
                allLabel={allLabel}
                disabled={disabled}
                aria-invalid={!!error}
            />
            {description && <FieldDescription>{description}</FieldDescription>}
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/**
 * On/off setting. The description says what happens when it is on and when it is off.
 */
export function SettingsSwitch({
    name,
    label,
    checked,
    error,
    description,
    disabled,
    bordered = false,
}: {
    name: string;
    label: string;
    checked: boolean;
    error?: string;
    description: string;
    disabled?: boolean;
    /** Draws the switch as its own bordered card that fills one grid cell, so two switches can sit in a row. */
    bordered?: boolean;
}) {
    return (
        <Field
            orientation="horizontal"
            className={
                bordered
                    ? 'items-start justify-start rounded-lg border p-4'
                    : 'items-start justify-start md:col-span-2'
            }
        >
            <Switch
                id={name}
                name={name}
                value="1"
                defaultChecked={checked}
                disabled={disabled}
                aria-invalid={!!error}
                className="mt-0.5"
            />
            <FieldContent className={bordered ? 'min-w-0' : 'flex-none'}>
                <FieldLabel htmlFor={name}>{label}</FieldLabel>
                <FieldDescription>{description}</FieldDescription>
                <FieldError>{error}</FieldError>
            </FieldContent>
        </Field>
    );
}
