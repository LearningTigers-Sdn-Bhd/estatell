import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import type { ChangeEvent } from 'react';
import type { DropdownProps } from 'react-day-picker';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';

/** Parse `1990-01-01` as a local date so it never shifts a day. */
function toDate(value: string): Date | undefined {
    if (!value) {
        return undefined;
    }

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day);
}

function toValue(date: Date): string {
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${date.getFullYear()}-${month}-${day}`;
}

/**
 * Month and year menus for the calendar caption, built on the Select instead of a native `<select>`.
 * The trigger is positioned so it sits above the calendar's absolutely placed arrow row, which would otherwise take the clicks.
 */
function CaptionSelect({
    options = [],
    value,
    onChange,
    'aria-label': ariaLabel,
}: DropdownProps) {
    const items = options.map((option) => ({
        value: String(option.value),
        label: option.label,
    }));

    return (
        <Select
            items={items}
            value={String(value)}
            onValueChange={(next) =>
                onChange?.({
                    target: { value: next },
                } as ChangeEvent<HTMLSelectElement>)
            }
        >
            <SelectTrigger
                size="sm"
                aria-label={ariaLabel}
                className="relative z-10"
            >
                <SelectValue />
            </SelectTrigger>
            <SelectContent
                alignItemWithTrigger={false}
                className="max-h-60 min-w-24"
            >
                <SelectGroup>
                    {options.map((option) => (
                        <SelectItem
                            key={option.value}
                            value={String(option.value)}
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

type Props = {
    /** The date, `Y-m-d` or empty. */
    value: string;
    /** Called with `Y-m-d`, or an empty string when the picked date is clicked again. */
    onChange: (value: string) => void;
    /**
     * `default` has arrows to move month by month. `dropdown` adds month and year select menus,
     * for dates far from today such as a date of birth.
     */
    variant?: 'default' | 'dropdown';
    /** Earliest date that can be picked, `Y-m-d`. */
    min?: string;
    /** Latest date that can be picked, `Y-m-d`. */
    max?: string;
    placeholder?: string;
    id?: string;
    /** Width of the trigger. Full width when left out. */
    className?: string;
    'aria-invalid'?: boolean;
};

/**
 * Pick one date from a calendar popover.
 */
export function DatePicker({
    value,
    onChange,
    variant = 'default',
    min,
    max,
    placeholder = 'Pick a date',
    id,
    className,
    ...props
}: Props) {
    const [open, setOpen] = useState(false);
    const date = toDate(value);
    const minDate = toDate(min ?? '');
    const maxDate = toDate(max ?? '');
    const dropdown = variant === 'dropdown';

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        className={cn(
                            'justify-start font-normal',
                            className ?? 'w-full',
                        )}
                        aria-invalid={props['aria-invalid']}
                    />
                }
            >
                <CalendarDays data-icon="inline-start" />
                {date ? (
                    <span className="tabular-nums">
                        {date.toLocaleDateString('en-GB', {
                            day: '2-digit',
                            month: 'short',
                            year: 'numeric',
                        })}
                    </span>
                ) : (
                    <span className="text-muted-foreground">{placeholder}</span>
                )}
            </PopoverTrigger>
            <PopoverContent className="w-auto" align="start">
                <Calendar
                    mode="single"
                    selected={date}
                    defaultMonth={date ?? maxDate}
                    captionLayout={dropdown ? 'dropdown' : 'label'}
                    components={
                        dropdown ? { Dropdown: CaptionSelect } : undefined
                    }
                    startMonth={
                        dropdown ? (minDate ?? new Date(1900, 0)) : undefined
                    }
                    endMonth={dropdown ? (maxDate ?? new Date()) : undefined}
                    disabled={[
                        ...(minDate ? [{ before: minDate }] : []),
                        ...(maxDate ? [{ after: maxDate }] : []),
                    ]}
                    onSelect={(next) => {
                        onChange(next ? toValue(next) : '');
                        setOpen(false);
                    }}
                />
            </PopoverContent>
        </Popover>
    );
}
