import { useState } from 'react';
import RegistrationCardController from '@/actions/App/Http/Controllers/Registration/RegistrationCardController';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { formatMoney } from '@/components/pms/money-text';
import {
    RegistrationCardGeneralFields,
    registrationCardGeneralValues,
} from '@/components/pms/registration-card/registration-card-general-fields';
import type { RegistrationCardGeneralValues } from '@/components/pms/registration-card/registration-card-general-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';

/**
 * The General tab: the shared card and deposit fields, then the damage charge item. Only this page offers the damage
 * charge item; the onboarding Check-in essentials step does not.
 */
export function RegistrationCardGeneralForm({
    settings,
    scopes,
    chargeItems,
}: {
    settings: App.Data.Property.PropertySettingsData;
    scopes: EnumOption[];
    chargeItems: App.Data.Reservations.ChargeItemOptionData[];
}) {
    const [values, setValues] = useState<RegistrationCardGeneralValues>(() =>
        registrationCardGeneralValues(settings),
    );
    const damageItemOptions: EnumOption[] = chargeItems.map((item) => ({
        value: String(item.id),
        label: item.name,
        description:
            item.defaultAmount === null
                ? 'Staff type the amount'
                : formatMoney(item.defaultAmount, settings.currency),
    }));
    const savedDamageItem = String(settings.damageChargeItemId ?? '');
    // A saved item that is no longer active is not listed, so the select starts at None.
    const [damageItem, setDamageItem] = useState(() =>
        damageItemOptions.some((option) => option.value === savedDamageItem)
            ? savedDamageItem
            : '',
    );

    return (
        <SettingsSection
            form={RegistrationCardController.update.form()}
            title="General"
            description="Guest signature, ID document, and deposit rules."
            contentClassName="flex flex-col gap-5"
            submitLabel="Save changes"
        >
            {(errors) => (
                <>
                    <RegistrationCardGeneralFields
                        values={values}
                        onChange={(next) =>
                            setValues((current) => ({ ...current, ...next }))
                        }
                        scopes={scopes}
                        errors={errors}
                    />
                    <Field>
                        <FieldLabel htmlFor="damage_charge_item_id">
                            Damage charge item
                        </FieldLabel>
                        <EnumSelect
                            id="damage_charge_item_id"
                            name="damage_charge_item_id"
                            options={damageItemOptions}
                            allLabel="None"
                            value={damageItem}
                            onValueChange={setDamageItem}
                            aria-invalid={!!errors.damage_charge_item_id}
                        />
                        <FieldDescription>
                            At check-out, staff can keep part of the deposit for
                            damages and post it to this item. An item where
                            staff type the amount fits best.
                        </FieldDescription>
                        <FieldError>{errors.damage_charge_item_id}</FieldError>
                    </Field>
                </>
            )}
        </SettingsSection>
    );
}
