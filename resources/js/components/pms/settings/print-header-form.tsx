import { Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    addressLines,
    contactLine,
} from '@/components/pms/settings/print-preview';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { profile as profileTab } from '@/routes/settings';
import { index as taxesIndex } from '@/routes/settings/taxes';

type Settings = App.Data.Property.PrintSettingsData;
type Profile = App.Data.Property.PropertyProfileData;
type Tax = App.Data.Property.PrintTaxData;
type SwitchKey =
    | 'showLogo'
    | 'showAddress'
    | 'showContact'
    | 'showRegistrationNumber'
    | 'showTin';

function MissingLink({
    href,
    children,
}: {
    href: ReturnType<typeof profileTab>;
    children: ReactNode;
}) {
    return (
        <Link
            href={href}
            className="inline-flex items-center gap-0.5 text-sm text-muted-foreground underline-offset-4 hover:underline"
        >
            {children}
            <ArrowUpRight className="size-3.5" />
        </Link>
    );
}

function LineSwitch({
    id,
    label,
    value,
    checked,
    missing,
    onCheckedChange,
}: {
    id: string;
    label: string;
    /** What prints, or null when the property has no value. */
    value: string | null;
    checked: boolean;
    missing: ReactNode;
    onCheckedChange: (checked: boolean) => void;
}) {
    return (
        <Field orientation="horizontal" className="items-start">
            <Switch
                id={id}
                checked={value !== null && checked}
                disabled={value === null}
                onCheckedChange={onCheckedChange}
                className="mt-0.5"
            />
            <FieldContent>
                <FieldLabel htmlFor={id}>{label}</FieldLabel>
                {value !== null ? (
                    <FieldDescription className="truncate">
                        {value}
                    </FieldDescription>
                ) : (
                    missing
                )}
            </FieldContent>
        </Field>
    );
}

/**
 * The letterhead switches and the tax numbers. A line with no value is disabled and links to where it is set.
 */
export function PrintHeaderForm({
    value,
    profile,
    taxes,
    onChange,
}: {
    value: Settings;
    profile: Profile;
    taxes: Tax[];
    onChange: (next: Settings) => void;
}) {
    const toggle = (key: SwitchKey) => (checked: boolean) =>
        onChange({ ...value, [key]: checked });
    const notSet = <MissingLink href={profileTab()}>Not set</MissingLink>;
    const address = addressLines(profile);

    return (
        <div className="flex flex-col gap-6">
            <FieldSet>
                <FieldLegend variant="label">Header lines</FieldLegend>
                <div className="flex flex-col gap-4">
                    <LineSwitch
                        id="show_logo"
                        label="Logo"
                        value={profile.logoUrl ? 'Shown before the name' : null}
                        checked={value.showLogo}
                        missing={
                            <MissingLink href={profileTab()}>
                                No logo
                            </MissingLink>
                        }
                        onCheckedChange={toggle('showLogo')}
                    />
                    <LineSwitch
                        id="show_address"
                        label="Address"
                        value={address.length > 0 ? address.join(', ') : null}
                        checked={value.showAddress}
                        missing={notSet}
                        onCheckedChange={toggle('showAddress')}
                    />
                    <LineSwitch
                        id="show_contact"
                        label="Phone and email"
                        value={contactLine(profile)}
                        checked={value.showContact}
                        missing={notSet}
                        onCheckedChange={toggle('showContact')}
                    />
                    <LineSwitch
                        id="show_registration_number"
                        label="Business registration no."
                        value={profile.registrationNumber}
                        checked={value.showRegistrationNumber}
                        missing={notSet}
                        onCheckedChange={toggle('showRegistrationNumber')}
                    />
                    <LineSwitch
                        id="show_tin"
                        label="TIN"
                        value={profile.tin}
                        checked={value.showTin}
                        missing={notSet}
                        onCheckedChange={toggle('showTin')}
                    />
                </div>
            </FieldSet>
            <FieldSet>
                <FieldLegend variant="label">Tax numbers</FieldLegend>
                {taxes.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        This property has no taxes.
                    </p>
                ) : (
                    <div className="flex flex-col gap-4">
                        {taxes.map((tax) => (
                            <LineSwitch
                                key={tax.id}
                                id={`tax-${tax.id}`}
                                label={
                                    tax.enabled ? tax.name : `${tax.name} (off)`
                                }
                                value={tax.registrationNumber}
                                checked={value.taxIds.includes(tax.id)}
                                missing={
                                    <MissingLink href={taxesIndex()}>
                                        No number
                                    </MissingLink>
                                }
                                onCheckedChange={(checked) =>
                                    onChange({
                                        ...value,
                                        taxIds: checked
                                            ? [...value.taxIds, tax.id]
                                            : value.taxIds.filter(
                                                  (id) => id !== tax.id,
                                              ),
                                    })
                                }
                            />
                        ))}
                    </div>
                )}
            </FieldSet>
        </div>
    );
}
