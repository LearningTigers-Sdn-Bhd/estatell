import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';

type Settings = App.Data.Property.PrintSettingsData;
type SwitchKey =
    | 'showUnitOnLines'
    | 'nightlyBreakdown'
    | 'taxBreakdown'
    | 'showAmountInWords'
    | 'showCancellationPolicy'
    | 'showSignatureLines';

const lineSwitches: { key: SwitchKey; label: string; description: string }[] = [
    {
        key: 'showUnitOnLines',
        label: 'Room and guest on lines',
        description:
            'Groups charges under the room and guest. Booking summary, folio invoice and AR invoice.',
    },
    {
        key: 'nightlyBreakdown',
        label: 'Nightly breakdown',
        description:
            'One row per night instead of "3 nights × 250.00". Booking summary, folio invoice and AR invoice.',
    },
    {
        key: 'taxBreakdown',
        label: 'Tax breakdown',
        description:
            'Shows each tax on its own line instead of one tax total. Booking summary, folio invoice and AR invoice.',
    },
    {
        key: 'showAmountInWords',
        label: 'Amount in words',
        description:
            'Prints the total in English words. Booking summary, folio invoice and AR invoice.',
    },
];

const sectionSwitches: {
    key: SwitchKey;
    label: string;
    description: string;
}[] = [
    {
        key: 'showCancellationPolicy',
        label: 'Cancellation policy',
        description: 'Booking summary only.',
    },
    {
        key: 'showSignatureLines',
        label: 'Signature lines',
        description: 'Folio invoice only.',
    },
];

function DocumentSwitch({
    option,
    checked,
    onCheckedChange,
}: {
    option: { key: SwitchKey; label: string; description: string };
    checked: boolean;
    onCheckedChange: (checked: boolean) => void;
}) {
    const id = `switch-${option.key}`;

    return (
        <Field orientation="horizontal" className="items-start">
            <Switch
                id={id}
                checked={checked}
                onCheckedChange={onCheckedChange}
                className="mt-0.5"
            />
            <FieldContent>
                <FieldLabel htmlFor={id}>{option.label}</FieldLabel>
                <FieldDescription>{option.description}</FieldDescription>
            </FieldContent>
        </Field>
    );
}

/**
 * The switches the booking summary, the folio invoice and the AR invoice read, and the payment details text.
 * A switch saves by itself; the text saves on blur.
 */
export function PrintDocumentsForm({
    value,
    onChange,
    onDraft,
}: {
    value: Settings;
    onChange: (next: Settings, options?: { delay?: number }) => void;
    onDraft: (next: Settings) => void;
}) {
    const toggle = (key: SwitchKey) => (checked: boolean) =>
        onChange({ ...value, [key]: checked });

    return (
        <div className="flex flex-col gap-6">
            <FieldSet>
                <FieldLegend variant="label">Lines</FieldLegend>
                <div className="flex flex-col gap-4">
                    {lineSwitches.map((option) => (
                        <DocumentSwitch
                            key={option.key}
                            option={option}
                            checked={value[option.key]}
                            onCheckedChange={toggle(option.key)}
                        />
                    ))}
                </div>
            </FieldSet>
            <FieldSet>
                <FieldLegend variant="label">Sections</FieldLegend>
                <div className="flex flex-col gap-4">
                    {sectionSwitches.map((option) => (
                        <DocumentSwitch
                            key={option.key}
                            option={option}
                            checked={value[option.key]}
                            onCheckedChange={toggle(option.key)}
                        />
                    ))}
                </div>
            </FieldSet>
            <Field>
                <FieldLabel htmlFor="bank_details">Payment details</FieldLabel>
                <Textarea
                    id="bank_details"
                    maxLength={500}
                    rows={3}
                    value={value.bankDetails ?? ''}
                    placeholder="Maybank 5141 2233 4455 · Sandbay Resort Sdn Bhd"
                    onChange={(event) =>
                        onDraft({ ...value, bankDetails: event.target.value })
                    }
                    onBlur={() => onChange(value, { delay: 0 })}
                />
                <FieldDescription>
                    Bank account or other payment instructions. Booking summary
                    and AR invoice. Saves when you leave the field.
                </FieldDescription>
            </Field>
        </div>
    );
}
