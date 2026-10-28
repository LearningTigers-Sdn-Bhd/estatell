import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';

const time = (value: string | null | undefined) => value?.slice(0, 5) ?? '—';

/** Start · Hours · End in one group. Hours is read-only and follows the two times. */
export function DayUseTimeGroup({
    start,
    end,
    onStart,
    onEnd,
    preview,
    current,
    errors,
}: {
    start: string;
    end: string;
    onStart: (value: string) => void;
    onEnd: (value: string) => void;
    preview: App.Data.Reservations.CheckOutAsDayUseData | null;
    current: boolean;
    errors: Record<string, string>;
}) {
    const charged =
        preview?.blockEnd != null &&
        preview.blockEnd.slice(0, 5) !== end &&
        !preview.midnightLimited;

    return (
        <Field data-invalid={!!errors.start || !!errors.end}>
            <FieldLabel htmlFor="day-use-start">Start · Hours · End</FieldLabel>
            <InputGroup>
                <InputGroupInput
                    id="day-use-start"
                    name="start"
                    type="time"
                    aria-label="Start"
                    value={start}
                    onChange={(event) => onStart(event.target.value)}
                    aria-invalid={!!errors.start}
                />
                <InputGroupAddon align="inline-end">
                    <InputGroupText
                        className="px-2 tabular-nums"
                        aria-live="polite"
                    >
                        {current && preview?.hours != null
                            ? `${preview.hours} h`
                            : '…'}
                    </InputGroupText>
                </InputGroupAddon>
                <InputGroupInput
                    id="day-use-end"
                    name="end"
                    type="time"
                    aria-label="End"
                    value={end}
                    onChange={(event) => onEnd(event.target.value)}
                    aria-invalid={!!errors.end}
                />
            </InputGroup>
            {preview?.midnightLimited ? (
                <p className="text-sm text-muted-foreground">
                    Ends at {time(preview.blockEnd)}: a day use must end before
                    midnight. The last part hour is not charged.
                </p>
            ) : (
                charged && (
                    <p className="text-sm text-muted-foreground">
                        Charged {time(preview?.start)} –{' '}
                        {time(preview?.blockEnd)}
                    </p>
                )
            )}
            <FieldError>{errors.start ?? errors.end}</FieldError>
        </Field>
    );
}
