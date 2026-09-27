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
    value: string;
    onValueChange: (value: string) => void;
    /** Label for the empty value, for example "All statuses". Adds a first option with value "". */
    allLabel?: string;
    name?: string;
    'aria-label'?: string;
    className?: string;
};

/**
 * A Select filled from a PHP enum's `options()`.
 */
export function EnumSelect({
    options,
    value,
    onValueChange,
    allLabel,
    name,
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
            onValueChange={(next) =>
                onValueChange((next as string | null) ?? '')
            }
        >
            <SelectTrigger
                className={className}
                aria-label={props['aria-label']}
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
