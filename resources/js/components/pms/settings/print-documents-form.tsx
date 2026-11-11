import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
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
import { index as bankAccountsIndex } from '@/routes/settings/bank-accounts';

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
    bankAccounts,
}: {
    value: Settings;
    onChange: (next: Settings, options?: { delay?: number }) => void;
    onDraft: (next: Settings) => void;
    /** The available bank accounts, as the documents print them. */
    bankAccounts: string[];
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
                <FieldLabel>Bank accounts</FieldLabel>
                {bankAccounts.length > 0 ? (
                    <ul className="flex flex-col gap-1 text-sm">
                        {bankAccounts.map((line) => (
                            <li key={line}>{line}</li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-sm text-muted-foreground">Not set</p>
                )}
                <FieldDescription>
                    The available accounts print under Payment details on the
                    booking summary and AR invoice.{' '}
                    <Link
                        href={bankAccountsIndex()}
                        className="inline-flex items-center gap-0.5 underline-offset-4 hover:underline"
                    >
                        Manage bank accounts
                        <ArrowUpRight className="size-3.5" />
                    </Link>
                </FieldDescription>
            </Field>
            <Field>
                <FieldLabel htmlFor="bank_details">Payment notes</FieldLabel>
                <Textarea
                    id="bank_details"
                    maxLength={500}
                    rows={3}
                    value={value.bankDetails ?? ''}
                    placeholder="e.g. DuitNow QR at the front desk"
                    onChange={(event) =>
                        onDraft({ ...value, bankDetails: event.target.value })
                    }
                    onBlur={() => onChange(value, { delay: 0 })}
                />
                <FieldDescription>
                    Other payment instructions, printed after the bank accounts.
                    Booking summary and AR invoice. Saves when you leave the
                    field.
                </FieldDescription>
            </Field>
        </div>
    );
}
