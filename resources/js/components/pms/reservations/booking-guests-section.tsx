import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import type { EnumOption } from '@/components/pms/enum-select';
import { EnumSelect } from '@/components/pms/enum-select';
import { DuplicateWarning } from '@/components/pms/guests/duplicate-warning';
import { GuestAutocompleteField } from '@/components/pms/guests/guest-autocomplete-field';
import { GuestDetailsFields } from '@/components/pms/guests/guest-details-fields';
import type { GuestDetails } from '@/components/pms/guests/guest-details-fields';
import {
    emptyGuest,
    guestTabLabel,
    hasDetails,
    linkGuest,
    takenBedUnits,
    unlinkGuest,
} from '@/components/pms/reservations/booking-guests';
import type { BookingGuest } from '@/components/pms/reservations/booking-guests';
import type { UnitOption } from '@/components/pms/reservations/booking-lines';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import type { GuestMatchCriteria } from '@/hooks/use-guest-matches';

type Errors = Record<string, string | undefined>;

const NOT_STAYING = '';

function GuestTab({
    index,
    tab,
    tabs,
    units,
    canManageGuests,
    countries,
    genders,
    locales,
    errors,
    onChange,
}: {
    index: number;
    tab: BookingGuest;
    tabs: BookingGuest[];
    units: UnitOption[];
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    errors: Errors;
    onChange: (patch: Partial<BookingGuest> | BookingGuest) => void;
}) {
    const prefix = `guests.${index}.`;
    const error = (field: string) => errors[`${prefix}${field}`];
    const [checked, setChecked] = useState<GuestMatchCriteria>({});
    const [dismissed, setDismissed] = useState('');
    const [detailsOpen, setDetailsOpen] = useState(false);
    const taken = takenBedUnits(tabs, units, index);
    const criteria = JSON.stringify(checked);

    const checkForDuplicates = () => {
        if (tab.guest === null) {
            setChecked({
                name: tab.full_name,
                email: tab.email,
                phone: tab.phone,
                date_of_birth: tab.date_of_birth,
            });
        }
    };

    return (
        <div className="flex flex-col gap-4">
            <GuestAutocompleteField
                idPrefix={`guest-${index}`}
                values={tab}
                linked={tab.guest}
                existingOnly={!canManageGuests}
                errors={{
                    full_name: error('full_name'),
                    phone: error('phone'),
                    email: error('email'),
                    guest_id: error('guest_id'),
                }}
                onChange={(field, value) => onChange({ [field]: value })}
                onPick={(guest) => {
                    onChange(linkGuest(tab, guest));
                    setDetailsOpen(true);
                }}
                onUnlink={() => onChange(unlinkGuest(tab))}
                onLeave={checkForDuplicates}
            />

            {tab.guest === null &&
                canManageGuests &&
                criteria !== dismissed && (
                    <DuplicateWarning
                        criteria={checked}
                        onUse={(match) => {
                            onChange(linkGuest(tab, match));
                            setDetailsOpen(true);
                        }}
                        onDismiss={() => setDismissed(criteria)}
                    />
                )}

            <Field data-invalid={!!error('stays_in')} className="max-w-sm">
                <FieldLabel htmlFor={`guest-${index}-stays_in`}>
                    Stays in
                </FieldLabel>
                <EnumSelect
                    id={`guest-${index}-stays_in`}
                    options={[
                        ...(index === 0
                            ? [{ value: NOT_STAYING, label: 'Not staying' }]
                            : []),
                        ...units.map((unit) => ({
                            value: unit.value,
                            label: unit.label,
                            disabled: unit.isBed && taken.has(unit.value),
                        })),
                    ]}
                    value={tab.stays_in}
                    onValueChange={(value) => onChange({ stays_in: value })}
                    aria-invalid={!!error('stays_in')}
                />
                {units.length === 0 && (
                    <FieldDescription>
                        Add a room or bed line first.
                    </FieldDescription>
                )}
                <FieldError>{error('stays_in')}</FieldError>
            </Field>

            {tab.guest !== null && canManageGuests && (
                <Field orientation="horizontal">
                    <Switch
                        id={`guest-${index}-update`}
                        checked={tab.update_profile}
                        onCheckedChange={(checked) =>
                            onChange({ update_profile: checked })
                        }
                    />
                    <FieldLabel htmlFor={`guest-${index}-update`}>
                        Update guest profile with my changes
                    </FieldLabel>
                </Field>
            )}
            <FieldError>{error('update_profile')}</FieldError>

            {canManageGuests && (
                <Collapsible
                    open={detailsOpen || hasDetails(tab)}
                    onOpenChange={setDetailsOpen}
                    className="flex flex-col gap-4"
                >
                    <CollapsibleTrigger
                        render={
                            <Button
                                type="button"
                                variant="ghost"
                                className="w-fit px-0"
                            />
                        }
                    >
                        More guest details
                    </CollapsibleTrigger>
                    <CollapsibleContent>
                        <GuestDetailsFields
                            idPrefix={`guest-${index}-`}
                            errorPrefix={prefix}
                            values={tab}
                            errors={errors}
                            onChange={(
                                field: keyof GuestDetails,
                                value: string,
                            ) => onChange({ [field]: value })}
                            countries={countries}
                            genders={genders}
                            locales={locales}
                        />
                    </CollapsibleContent>
                </Collapsible>
            )}
        </div>
    );
}

/**
 * The Guests section: a tab for the booker and one for each other guest, each with the autocomplete fields, the unit
 * the guest stays in and the optional details. Units without a guest are allowed.
 */
export function BookingGuestsSection({
    tabs,
    units,
    canManageGuests,
    countries,
    genders,
    locales,
    errors,
    onChange,
}: {
    tabs: BookingGuest[];
    units: UnitOption[];
    canManageGuests: boolean;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    errors: Errors;
    onChange: (tabs: BookingGuest[]) => void;
}) {
    const [active, setActive] = useState(tabs[0].key);
    const current = tabs.find((tab) => tab.key === active) ?? tabs[0];
    const index = tabs.indexOf(current);
    const hasError = (position: number) =>
        Object.keys(errors).some((key) =>
            key.startsWith(`guests.${position}.`),
        );

    const update = (patch: Partial<BookingGuest> | BookingGuest) =>
        onChange(
            tabs.map((tab) =>
                tab.key === current.key ? { ...tab, ...patch } : tab,
            ),
        );

    return (
        <div className="flex flex-col gap-4">
            <Tabs
                value={current.key}
                onValueChange={(value) => setActive(String(value))}
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <TabsList className="h-auto flex-wrap">
                        {tabs.map((tab, position) => (
                            <span key={tab.key} className="flex items-center">
                                <TabsTrigger
                                    value={tab.key}
                                    className={
                                        hasError(position)
                                            ? 'text-destructive'
                                            : undefined
                                    }
                                >
                                    {guestTabLabel(tab, position)}
                                </TabsTrigger>
                                {position > 0 && (
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-xs"
                                        aria-label={`Remove ${guestTabLabel(tab, position)}`}
                                        onClick={() => {
                                            setActive(tabs[0].key);
                                            onChange(
                                                tabs.filter(
                                                    (one) =>
                                                        one.key !== tab.key,
                                                ),
                                            );
                                        }}
                                    >
                                        <X />
                                    </Button>
                                )}
                            </span>
                        ))}
                    </TabsList>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={tabs.length >= 20}
                        onClick={() => {
                            const added = emptyGuest();

                            onChange([...tabs, added]);
                            setActive(added.key);
                        }}
                    >
                        <Plus data-icon="inline-start" />
                        Add guest
                    </Button>
                </div>
            </Tabs>
            <GuestTab
                key={current.key}
                index={index}
                tab={current}
                tabs={tabs}
                units={units}
                canManageGuests={canManageGuests}
                countries={countries}
                genders={genders}
                locales={locales}
                errors={errors}
                onChange={update}
            />
        </div>
    );
}
