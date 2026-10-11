import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { InputGroup, InputGroupInput } from '@/components/ui/input-group';

type Props = {
    /** Id of the date control, so the field label can point at it. */
    id: string;
    /** The date, `Y-m-d` or empty. */
    date: string;
    /** The time, `H:i`. */
    time: string;
    onDateChange: (value: string) => void;
    onTimeChange: (value: string) => void;
    /** Earliest date that can be picked, `Y-m-d`. */
    min?: string;
    /** Latest date that can be picked, `Y-m-d`. The month and year menus end here. */
    max?: string;
    /** Show the date as text, for a date the form fixes. */
    dateLocked?: boolean;
    'aria-invalid'?: boolean;
    timeInvalid?: boolean;
    timeLabel: string;
    /** Step of the time input in seconds. 1800 limits a start to the hour and the half hour. */
    step?: number;
};

/**
 * A date and a time in one input group. The time is the expected time and uses the browser's own time input, so it
 * shows 12-hour or 24-hour by the device locale and always sends `H:i`.
 */
export function StayDateTime({
    id,
    date,
    time,
    onDateChange,
    onTimeChange,
    min,
    max,
    dateLocked = false,
    timeInvalid,
    timeLabel,
    step,
    ...props
}: Props) {
    return (
        <InputGroup>
            {dateLocked ? (
                <span className="flex-1 px-2.5 text-sm">
                    <DateText date={date || null} />
                </span>
            ) : (
                <DatePicker
                    id={id}
                    value={date}
                    variant="dropdown"
                    min={min}
                    max={max}
                    openOn={min}
                    onChange={onDateChange}
                    aria-invalid={props['aria-invalid']}
                    className="h-full flex-1 rounded-none border-0 bg-transparent shadow-none dark:bg-transparent"
                />
            )}
            <InputGroupInput
                type="time"
                step={step}
                aria-label={timeLabel}
                aria-invalid={timeInvalid}
                value={time}
                onChange={(event) => onTimeChange(event.target.value)}
                className="w-32 flex-none rounded-none border-l tabular-nums"
            />
        </InputGroup>
    );
}
