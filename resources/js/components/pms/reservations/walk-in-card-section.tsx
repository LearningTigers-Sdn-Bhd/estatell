import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';

/**
 * The Registration card block of the walk-in summary rail, above Payment. A walk-in guest has not signed a card yet,
 * so a unit would stay Confirmed (`card_missing`). The box checks every unit in without a card, as the check-in Dialog
 * does. The caller shows it only when the property requires a card and the user may check in without one.
 */
export function WalkInCardSection({
    checked,
    onChange,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div
            className="flex flex-col gap-2"
            data-slot="walk-in-registration-card"
        >
            <span className="text-sm font-medium">Registration card</span>
            <FieldLabel htmlFor="walk-in-without_card">
                <Field orientation="horizontal">
                    <Checkbox
                        id="walk-in-without_card"
                        checked={checked}
                        onCheckedChange={onChange}
                    />
                    <FieldContent>
                        <span className="text-sm font-medium">
                            Check in without card
                        </span>
                        <FieldDescription>
                            Unticked, a unit without a signed card stays
                            Confirmed. This is logged with your name.
                        </FieldDescription>
                    </FieldContent>
                </Field>
            </FieldLabel>
        </div>
    );
}
