import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import type { DateRange } from 'react-day-picker';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';

/** Parse `2026-09-12` as a local date so it never shifts a day. */
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

function label(date: Date): string {
    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

type Props = {
    /** First date, `Y-m-d` or empty. */
    from: string;
    /** Last date, `Y-m-d` or empty. Both dates are included. */
    to: string;
    onChange: (from: string, to: string) => void;
    /** Earliest date that can be picked, `Y-m-d`. */
    min?: string;
    /** Keep `from` fixed and pick only the last date, for example on a block that has started. */
    fromLocked?: boolean;
    /** Longest range, counting both ends. */
    maxDays?: number;
    /** Unit shown after the count, for example "night". */
    unit?: string;
    placeholder?: string;
    id?: string;
    'aria-invalid'?: boolean;
};

/**
 * Pick a first and last date (both included) from one calendar popover.
 */
export function DateRangePicker({
    from,
    to,
    onChange,
    min,
    fromLocked = false,
    maxDays,
    unit = 'day',
    placeholder = 'Pick dates',
    id,
    ...props
}: Props) {
    const [open, setOpen] = useState(false);
    const fromDate = toDate(from);
    const toDateValue = toDate(to);
    const minDate = toDate(min ?? '');
    const earliest =
        fromLocked && fromDate && (!minDate || fromDate > minDate)
            ? fromDate
            : minDate;
    const count =
        fromDate && toDateValue
            ? Math.round(
                  (toDateValue.getTime() - fromDate.getTime()) / 86_400_000,
              ) + 1
            : 0;

    const select = (range: DateRange | undefined, triggerDate: Date) => {
        if (fromLocked) {
            onChange(from, toValue(triggerDate));
            setOpen(false);

            return;
        }

        const first = range?.from ?? triggerDate;
        const last = range?.to ?? first;
        onChange(toValue(first), toValue(last));

        if (range?.from && range.to && range.from < range.to) {
            setOpen(false);
        }
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger
                render={
                    <Button
                        id={id}
                        type="button"
                        variant="outline"
                        className="w-full justify-start font-normal"
                        aria-invalid={props['aria-invalid']}
                    />
                }
            >
                <CalendarDays data-icon="inline-start" />
                {fromDate && toDateValue ? (
                    <span className="tabular-nums">
                        {label(fromDate)} – {label(toDateValue)}
                        <span className="text-muted-foreground">
                            {' '}
                            · {count} {count === 1 ? unit : `${unit}s`}
                        </span>
                    </span>
                ) : (
                    <span className="text-muted-foreground">{placeholder}</span>
                )}
            </PopoverTrigger>
            <PopoverContent className="w-auto" align="start">
                <Calendar
                    mode="range"
                    selected={{ from: fromDate, to: toDateValue }}
                    onSelect={select}
                    defaultMonth={fromDate ?? earliest}
                    disabled={earliest ? { before: earliest } : undefined}
                    max={maxDays ? maxDays - 1 : undefined}
                />
            </PopoverContent>
        </Popover>
    );
}
