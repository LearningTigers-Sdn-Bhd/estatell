import { cn } from '@/lib/utils';
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
import { PhoneInput } from '@/components/pms/phone-input';
import { Switch } from '@/components/ui/switch';

export function SettingsPhoneInput({
    name,
    label,
    value,
    error,
    disabled,
    placeholder,
}: {
    name: string;
    label: ReactNode;
    value: string | null;
    error?: string;
    disabled?: boolean;
    placeholder?: string;
}) {
    return (
        <Field>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <PhoneInput
                id={name}
                name={name}
                value={value ?? ''}
                placeholder={placeholder}
                disabled={disabled}
                invalid={!!error}
            />
            <FieldError>{error}</FieldError>
        </Field>
    );
}

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
    onValueChange,
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
    onValueChange?: (value: string) => void;
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
                onChange={(event) => onValueChange?.(event.target.value)}
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
    onValueChange,
}: {
    name: string;
    label: string;
    value: string;
    options: EnumOption[];
    /** Called with the picked value, for a field that changes another field. The select stays uncontrolled. */
    onValueChange?: (value: string) => void;
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
                onValueChange={onValueChange}
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
    centered = false,
}: {
    name: string;
    label: string;
    checked: boolean;
    error?: string;
    description: string;
    disabled?: boolean;
    /** Draws the switch as its own bordered card that fills one grid cell, so two switches can sit in a row. */
    bordered?: boolean;
    /** Centers the switch and its text vertically, for a bordered switch that sits beside a taller box. */
    centered?: boolean;
}) {
    return (
        <Field
            orientation="horizontal"
            className={
                bordered
                    ? cn(
                          'justify-start rounded-lg border p-4',
                          centered ? 'items-center' : 'items-start',
                      )
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
                className={centered ? undefined : 'mt-0.5'}
            />
            <FieldContent className={bordered ? 'min-w-0' : 'flex-none'}>
                <FieldLabel htmlFor={name}>{label}</FieldLabel>
                <FieldDescription>{description}</FieldDescription>
                <FieldError>{error}</FieldError>
            </FieldContent>
        </Field>
    );
}
