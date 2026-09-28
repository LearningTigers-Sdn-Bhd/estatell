import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

export type EnumOption = { value: string; label: string };

type Props = {
    options: EnumOption[];
    /** Controlled value. Leave out and pass `defaultValue` inside a plain `<Form>`. */
    value?: string;
    defaultValue?: string;
    onValueChange?: (value: string) => void;
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
                        <SelectItem key={option.value} value={option.value}>
                            {option.label}
                        </SelectItem>
                    ))}
                </SelectGroup>
            </SelectContent>
        </Select>
    );
}
