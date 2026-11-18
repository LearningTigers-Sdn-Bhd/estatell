import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

/** Saving or removing a list item keeps the tab and the scroll position. */
export const keepPage = { preserveScroll: true, preserveState: true };

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
    required,
    autoComplete,
}: {
    name: string;
    label: string;
    defaultValue?: string | number | null;
    error?: string;
    rows?: number;
    type?: 'text' | 'number' | 'time' | 'tel';
    maxLength?: number;
    placeholder?: string;
    required?: boolean;
    autoComplete?: string;
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
                    required={required}
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
                    required={required}
                    autoComplete={autoComplete}
                />
            )}
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/** One labelled dropdown of a Concierge content form. A blank choice is sent as an empty value. */
export function SelectField({
    name,
    label,
    options,
    defaultValue,
    error,
    placeholder = 'Not set',
}: {
    name: string;
    label: string;
    options: { value: string; label: string }[];
    defaultValue?: string | null;
    error?: string;
    placeholder?: string;
}) {
    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={name}>{label}</FieldLabel>
            <Select
                name={name}
                items={options}
                defaultValue={defaultValue ?? undefined}
            >
                <SelectTrigger id={name} className="w-full">
                    <SelectValue placeholder={placeholder} />
                </SelectTrigger>
                <SelectContent>
                    <SelectGroup>
                        {options.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
            <FieldError>{error}</FieldError>
        </Field>
    );
}

/** The submit button of a Concierge form. Shows a spinner while saving, otherwise the optional icon. */
export function SubmitButton({
    processing,
    icon: Icon,
    variant,
    children,
}: {
    processing: boolean;
    icon?: LucideIcon;
    variant?: 'outline';
    children: ReactNode;
}) {
    return (
        <Button
            type="submit"
            variant={variant}
            className="self-end"
            disabled={processing}
            focusableWhenDisabled
        >
            {processing ? (
                <Spinner data-icon="inline-start" />
            ) : (
                Icon && <Icon data-icon="inline-start" />
            )}
            {children}
        </Button>
    );
}

/** A title and an on/off switch, with an optional icon and line of explanation. */
export function SwitchRow({
    id,
    icon: Icon,
    title,
    body,
    checked,
    onChange,
    className,
}: {
    id: string;
    icon?: LucideIcon;
    title: string;
    body?: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
    className?: string;
}) {
    return (
        <div
            className={cn('flex items-start justify-between gap-4', className)}
        >
            <div className="flex min-w-0 items-start gap-2">
                {Icon && (
                    <Icon
                        aria-hidden
                        className="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    />
                )}
                <div className="flex flex-col gap-1">
                    <Label htmlFor={id}>{title}</Label>
                    {body && (
                        <p className="text-sm text-pretty text-muted-foreground">
                            {body}
                        </p>
                    )}
                </div>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}
