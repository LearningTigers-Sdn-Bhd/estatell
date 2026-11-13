import { CalendarClock, Moon } from 'lucide-react';
import type { ReactNode } from 'react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import { Button } from '@/components/ui/button';
import { FieldDescription, FieldError } from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * Puts the ended stay question above the rest of the form, in one column: both Check in forms are Sheets, too narrow
 * for two columns. Without a question the form shows on its own.
 */
export function EndedStayColumns({
    question,
    children,
}: {
    question: ReactNode;
    children: ReactNode;
}) {
    if (question === null) {
        return children;
    }

    return (
        <div className="flex flex-col gap-5">
            <div className="flex min-w-0 flex-col gap-5">{question}</div>
            <div className="flex min-w-0 flex-col gap-5">{children}</div>
        </div>
    );
}

/**
 * The question both Check in Sheets ask for a stay that was not logged in time. The server decides when it shows and
 * sends the dates and amounts inside the text.
 */
export function EndedStayQuestion({
    id,
    plural = false,
    intro,
    legend,
    keepLabel,
    moveLabel,
    stayedDescription,
    arrivingDescription,
    arrivingDisabled,
    choice,
    onChoiceChange,
    arrivedAt,
    onArrivedAtChange,
    errors,
    onNoShow,
}: {
    id: string;
    plural?: boolean;
    intro: string;
    legend: string;
    keepLabel: string;
    moveLabel: string;
    stayedDescription: string;
    arrivingDescription: string;
    arrivingDisabled: boolean;
    choice: string;
    onChoiceChange: (value: string) => void;
    arrivedAt: string;
    onArrivedAtChange: (value: string) => void;
    errors: { ended_stay_choice?: string; arrived_at?: string };
    /** Opens the No-show form. Without it, the text tells staff where to go. */
    onNoShow?: () => void;
}) {
    return (
        <>
            <p className="text-sm text-muted-foreground">{intro}</p>
            <ChoiceCards
                id={`${id}-ended-stay`}
                legend={legend}
                columns={2}
                choices={[
                    {
                        value: 'arrived_last_night',
                        label: keepLabel,
                        description: stayedDescription,
                        icon: Moon,
                        content: choice === 'arrived_last_night' && (
                            <div className="mt-2 flex flex-col gap-1.5">
                                <span
                                    id={`${id}-arrived_at-label`}
                                    className="text-sm font-medium"
                                >
                                    What time did they arrive?
                                </span>
                                <Input
                                    id={`${id}-arrived_at`}
                                    type="time"
                                    value={arrivedAt}
                                    onChange={(event) =>
                                        onArrivedAtChange(event.target.value)
                                    }
                                    aria-labelledby={`${id}-arrived_at-label`}
                                    aria-invalid={!!errors.arrived_at}
                                />
                                <FieldDescription>
                                    Arrivals after midnight are fine, for
                                    example 2:00 a.m.
                                </FieldDescription>
                                <FieldError>{errors.arrived_at}</FieldError>
                            </div>
                        ),
                    },
                    {
                        value: 'arriving_today',
                        label: moveLabel,
                        description: arrivingDescription,
                        icon: CalendarClock,
                        disabled: arrivingDisabled,
                    },
                ]}
                value={choice}
                onValueChange={onChoiceChange}
                error={errors.ended_stay_choice}
            />
            {onNoShow !== undefined ? (
                <div className="flex items-center justify-between gap-4 text-sm text-muted-foreground">
                    Guest never came?
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={onNoShow}
                    >
                        Mark as no-show
                    </Button>
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    {plural ? 'Guests' : 'Guest'} never came? Cancel this box
                    and select No-show to conclude this booking.
                </p>
            )}
        </>
    );
}
