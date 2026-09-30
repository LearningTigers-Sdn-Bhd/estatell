import { CalendarDays } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Calendar } from '@/components/ui/calendar';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useIsMobile } from '@/hooks/use-mobile';

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
    /** Months shown side by side. Two falls back to one on a phone. */
    months?: 1 | 2;
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
    months = 1,
    unit = 'day',
    placeholder = 'Pick dates',
    id,
    ...props
}: Props) {
    const [open, setOpen] = useState(false);
    const isMobile = useIsMobile();
    const [picking, setPicking] = useState(false);
    const [hovered, setHovered] = useState<Date>();
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

    const changeOpen = (next: boolean) => {
        setOpen(next);
        setPicking(false);
        setHovered(undefined);
    };

    const select = (triggerDate: Date) => {
        setHovered(undefined);

        if (fromLocked) {
            onChange(from, toValue(triggerDate));
            changeOpen(false);

            return;
        }

        if (picking && fromDate && triggerDate > fromDate) {
            onChange(from, toValue(triggerDate));
            changeOpen(false);

            return;
        }

        onChange(toValue(triggerDate), toValue(triggerDate));
        setPicking(true);
    };

    const lastAllowed =
        maxDays && fromDate && (picking || fromLocked)
            ? new Date(
                  fromDate.getFullYear(),
                  fromDate.getMonth(),
                  fromDate.getDate() + maxDays - 1,
              )
            : undefined;
    const disabledDays = [
        ...(earliest ? [{ before: earliest }] : []),
        ...(lastAllowed ? [{ after: lastAllowed }] : []),
    ];
    const previewing = fromLocked || picking;
    const preview =
        previewing && fromDate && hovered && hovered > fromDate
            ? { from: fromDate, to: hovered }
            : { from: fromDate, to: toDateValue };

    return (
        <Popover open={open} onOpenChange={changeOpen}>
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
                    selected={preview}
                    onSelect={(_, day) => select(day)}
                    onDayMouseEnter={(day, modifiers) =>
                        setHovered(modifiers.disabled ? undefined : day)
                    }
                    onDayMouseLeave={() => setHovered(undefined)}
                    defaultMonth={fromDate ?? earliest}
                    disabled={disabledDays}
                    numberOfMonths={isMobile ? 1 : months}
                />
            </PopoverContent>
        </Popover>
    );
}
