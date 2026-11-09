import { CalendarClock, Moon } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';

/**
 * The question both Check in Dialogs ask for a stay that was not logged in time. The server decides when it shows and
 * sends the dates and amounts inside the text.
 */
export function EndedStayQuestion({
    id,
    plural = false,
    intro,
    stayedDescription,
    arrivingDescription,
    arrivingDisabled,
    choice,
    onChoiceChange,
    arrivedAt,
    onArrivedAtChange,
    errors,
}: {
    id: string;
    plural?: boolean;
    intro: string;
    stayedDescription: string;
    arrivingDescription: string;
    arrivingDisabled: boolean;
    choice: string;
    onChoiceChange: (value: string) => void;
    arrivedAt: string;
    onArrivedAtChange: (value: string) => void;
    errors: { ended_stay_choice?: string; arrived_at?: string };
}) {
    return (
        <>
            <p className="text-sm text-muted-foreground">{intro}</p>
            <ChoiceCards
                id={`${id}-ended-stay`}
                legend={`Did the ${plural ? 'guests' : 'guest'} sleep here last night?`}
                choices={[
                    {
                        value: 'arrived_last_night',
                        label: 'Yes, they stayed last night',
                        description: stayedDescription,
                        icon: Moon,
                    },
                    {
                        value: 'arriving_today',
                        label: 'No, they arrive today',
                        description: arrivingDescription,
                        icon: CalendarClock,
                        disabled: arrivingDisabled,
                    },
                ]}
                value={choice}
                onValueChange={onChoiceChange}
                error={errors.ended_stay_choice}
            />
            {choice === 'arrived_last_night' && (
                <Field data-invalid={!!errors.arrived_at}>
                    <FieldLabel htmlFor={`${id}-arrived_at`}>
                        What time did they arrive?
                    </FieldLabel>
                    <Input
                        id={`${id}-arrived_at`}
                        type="time"
                        value={arrivedAt}
                        onChange={(event) =>
                            onArrivedAtChange(event.target.value)
                        }
                        aria-invalid={!!errors.arrived_at}
                    />
                    <FieldDescription>
                        A time before noon counts as this morning.
                    </FieldDescription>
                    <FieldError>{errors.arrived_at}</FieldError>
                </Field>
            )}
            <p className="text-sm text-muted-foreground">
                {plural ? 'Guests' : 'Guest'} never came? Close this and use
                No-show.
            </p>
        </>
    );
}
