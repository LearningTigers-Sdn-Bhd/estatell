import type { LucideIcon } from 'lucide-react';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { cn } from '@/lib/utils';

export type Choice = {
    value: string;
    label: string;
    description: string;
    icon: LucideIcon;
};

/**
 * A radio group shown as cards, each with an icon, a label and a description. Use it for a choice of two to four
 * options that needs explaining. With `name`, the chosen value is sent with a native form.
 */
export function ChoiceCards({
    id,
    name,
    legend,
    choices,
    value,
    onValueChange,
    disabled,
    error,
    className,
    radioPosition = 'left',
}: {
    id: string;
    name?: string;
    legend: string;
    choices: Choice[];
    value: string;
    onValueChange: (value: string) => void;
    disabled?: boolean;
    error?: string;
    className?: string;
    radioPosition?: 'left' | 'right';
}) {
    return (
        <FieldSet className={className} data-invalid={!!error}>
            <FieldLegend variant="label">{legend}</FieldLegend>
            <RadioGroup
                name={name}
                value={value}
                onValueChange={(next) => onValueChange(String(next))}
                disabled={disabled}
                className="grid gap-3 sm:grid-cols-2"
            >
                {choices.map((choice) => (
                    <FieldLabel
                        key={choice.value}
                        htmlFor={`${id}-${choice.value}`}
                    >
                        <Field orientation="horizontal" className="items-start">
                            <RadioGroupItem
                                id={`${id}-${choice.value}`}
                                value={choice.value}
                                aria-invalid={!!error}
                                className={cn(
                                    'mt-0.5',
                                    radioPosition === 'right' && 'order-last',
                                )}
                            />
                            <FieldContent>
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <choice.icon className="size-4 text-muted-foreground" />
                                    {choice.label}
                                </span>
                                <FieldDescription>
                                    {choice.description}
                                </FieldDescription>
                            </FieldContent>
                        </Field>
                    </FieldLabel>
                ))}
            </RadioGroup>
            <FieldError>{error}</FieldError>
        </FieldSet>
    );
}
