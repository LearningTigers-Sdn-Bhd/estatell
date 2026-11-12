import { router } from '@inertiajs/react';
import { Trash2, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
                    className="min-h-11"
                />
            )}
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
            className="min-h-11 self-end"
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

/** A title and an on/off switch, with an optional line of explanation. */
export function SwitchRow({
    id,
    title,
    body,
    checked,
    onChange,
    className,
}: {
    id: string;
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
            <div className="flex flex-col gap-1">
                <Label htmlFor={id}>{title}</Label>
                {body && (
                    <p className="text-sm text-pretty text-muted-foreground">
                        {body}
                    </p>
                )}
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

/** A list row with a remove button. `label` names the target for screen readers. */
export function RemovableItem({
    url,
    label,
    children,
}: {
    url: string;
    label: string;
    children: ReactNode;
}) {
    return (
        <li className="flex items-start justify-between gap-3 border-b py-4 text-sm first:pt-0 last:border-b-0 last:pb-0">
            <span className="flex min-w-0 flex-col gap-1">{children}</span>
            <Button
                type="button"
                variant="ghost"
                size="icon-lg"
                className="size-11 shrink-0"
                aria-label={label}
                onClick={() => router.delete(url, keepPage)}
            >
                <Trash2 />
            </Button>
        </li>
    );
}
