import { Checkbox } from '@/components/ui/checkbox';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';

/**
 * One room type choice: a checkbox with the name as title and a description.
 * It is disabled, with the reason in the description, when the plan's pricing basis cannot sell it.
 */
export function RoomTypeCheckboxItem({
    id,
    name,
    sellUnitLabel,
    fits,
    pricingLabel,
    checked,
    onCheckedChange,
}: {
    id: string;
    name: string;
    sellUnitLabel: string;
    fits: boolean;
    /** The plan's pricing basis label, for example "Per room", shown when the room type does not fit. */
    pricingLabel: string;
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    const soldBy = `Sold by ${sellUnitLabel.toLowerCase()}`;

    return (
        <FieldLabel
            htmlFor={id}
            className="has-[>[data-slot=field]]:rounded-md"
        >
            <Field orientation="horizontal" data-disabled={!fits}>
                <Checkbox
                    id={id}
                    checked={checked}
                    disabled={!fits}
                    onCheckedChange={onCheckedChange}
                />
                <FieldContent>
                    <span className="text-sm font-medium">{name}</span>
                    <FieldDescription>
                        {fits
                            ? soldBy
                            : `${soldBy}. Not available with ${pricingLabel.toLowerCase()} pricing.`}
                    </FieldDescription>
                </FieldContent>
            </Field>
        </FieldLabel>
    );
}
