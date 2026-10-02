import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type EnumOption = {
    value: string;
    label: string;
    /** Shown but not pickable, for example a room already picked on another line. */
    disabled?: boolean;
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
    name?: string;
    id?: string;
    disabled?: boolean;
    'aria-label'?: string;
    'aria-invalid'?: boolean;
    className?: string;
};

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
    name,
    id,
    disabled,
    className,
    ...props
}: Props) {
    const items = allLabel
        ? [{ value: '', label: allLabel }, ...options]
        : options;

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
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                <SelectGroup>
                    {items.map((option) => (
                        <SelectItem
                            key={option.value}
                            value={option.value}
                            disabled={option.disabled}
                        >
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
