import type { ReactNode } from 'react';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type EnumOption = {
    value: string;
    label: string;
    /** Shown but not pickable, for example a room already picked on another line. */
    disabled?: boolean;
    /** A heading to list the option under. Options that share a group sit together, in the order they first appear. */
    group?: string;
};

type Props = {
    options: EnumOption[];
    /** Controlled value. Leave out and pass `defaultValue` inside a plain `<Form>`. */
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
    /** Called when the menu opens or closes, for example to load the options on first open. */
    onOpenChange?: (open: boolean) => void;
    /** Label for the empty value, for example "All statuses". Adds a first option with value "". */
    allLabel?: string;
    /** Shown while nothing is picked. */
    placeholder?: string;
    /** An icon shown at the start of the trigger. The menu then opens below the trigger instead of over it. */
    icon?: ReactNode;
    name?: string;
    id?: string;
    disabled?: boolean;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    className?: string;
};

/** Splits the options into groups by their `group`, keeping the order. Options without a group form one group. */
function groupOptions(
    items: EnumOption[],
): { name?: string; options: EnumOption[] }[] {
    const groups: { name?: string; options: EnumOption[] }[] = [];

    for (const option of items) {
        const group = groups.find((existing) => existing.name === option.group);

        if (group) {
            group.options.push(option);
        } else {
            groups.push({ name: option.group, options: [option] });
        }
    }

    return groups;
}

/**
 * A Select filled from a PHP enum's `options()`.
 */
export function EnumSelect({
    options,
    value,
    defaultValue,
    onValueChange,
    onOpenChange,
    allLabel,
    placeholder,
    icon,
    name,
    id,
    disabled,
    className,
    ...props
}: Props) {
    const items = allLabel
        ? [{ value: '', label: allLabel }, ...options]
        : options;
    const groups = groupOptions(items);

    return (
        <Select
            name={name}
            items={items}
            value={value}
            defaultValue={defaultValue}
            disabled={disabled}
            onOpenChange={onOpenChange}
            onValueChange={(next) =>
                onValueChange?.((next as string | null) ?? '')
            }
        >
            <SelectTrigger
                id={id}
                className={className}
                aria-label={props['aria-label']}
                aria-invalid={props['aria-invalid']}
            >
                {icon}
                <SelectValue placeholder={placeholder} className="min-w-0">
                    {(picked: string | null) => {
                        const label = items.find(
                            (option) => option.value === picked,
                        )?.label;

                        return (
                            <span className="min-w-0 truncate">
                                {label ?? placeholder}
                            </span>
                        );
                    }}
                </SelectValue>
            </SelectTrigger>
            <SelectContent
                alignItemWithTrigger={!icon}
                className="max-h-72 w-max max-w-sm min-w-(--anchor-width)"
            >
                {groups.map((group) => (
                    <SelectGroup key={group.name ?? ''}>
                        {group.name && <SelectLabel>{group.name}</SelectLabel>}
                        {group.options.map((option) => (
                            <SelectItem
                                key={option.value}
                                value={option.value}
                                disabled={option.disabled}
                                className="*:first:min-w-0 *:first:shrink"
                            >
                                <span className="truncate">{option.label}</span>
                            </SelectItem>
                        ))}
                    </SelectGroup>
                ))}
            </SelectContent>
        </Select>
    );
}
