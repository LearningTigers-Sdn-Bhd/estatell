import { Banknote, CircleOff, UserRound, UsersRound } from 'lucide-react';
import { useState } from 'react';
import RegistrationCardController from '@/actions/App/Http/Controllers/Registration/RegistrationCardController';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { EnumOption } from '@/components/pms/enum-select';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    SettingsInput,
    SettingsSwitch,
} from '@/components/pms/settings/form-fields';
import { FieldGroup } from '@/components/ui/field';

const scopeDetails: Record<
    App.Enums.RegistrationCardScope,
    { description: string; icon: typeof UserRound }
> = {
    primary_only: {
        description: 'One card for the primary guest.',
        icon: UserRound,
    },
    every_adult: {
        description: 'Each adult completes their own card.',
        icon: UsersRound,
    },
};

const depositChoices = [
    {
        value: 'none',
        label: 'Do not collect',
        description: 'No security deposit is requested.',
        icon: CircleOff,
    },
    {
        value: 'collect',
        label: 'Collect a deposit',
        description: 'Set the security deposit amount.',
        icon: Banknote,
    },
];

export function RegistrationCardGeneralForm({
    settings,
    scopes,
}: {
    settings: App.Data.Property.PropertySettingsData;
    scopes: EnumOption[];
}) {
    const [scope, setScope] = useState<string>(settings.registrationCardScope);
    const [depositMode, setDepositMode] = useState(
        Number(settings.securityDepositAmount) > 0 ? 'collect' : 'none',
    );
    const collectsDeposit = depositMode === 'collect';

    return (
        <SettingsSection
            form={RegistrationCardController.update.form()}
            title="General"
            description="Guest signature, ID document, and deposit rules."
            contentClassName="flex flex-col gap-5"
            submitLabel="Save changes"
        >
            {(errors) => (
                <FieldGroup>
                    <ChoiceCards
                        id="registration_card_scope"
                        name="registration_card_scope"
                        legend="Who completes a card"
                        radioPosition="right"
                        choices={scopes.map((option) => ({
                            ...option,
                            ...scopeDetails[
                                option.value as App.Enums.RegistrationCardScope
                            ],
                        }))}
                        value={scope}
                        onValueChange={setScope}
                        error={errors.registration_card_scope}
                    />
                    <SettingsSwitch
                        name="card_required_for_check_in"
                        label="Require card before check-in"
                        description="On: guests must complete the required cards before check-in. Off: staff can check guests in without a card. Staff with override permission can bypass this requirement."
                        checked={settings.cardRequiredForCheckIn}
                        error={errors.card_required_for_check_in}
                    />
                    <SettingsInput
                        name="id_document_retention_days"
                        label="ID document retention (days)"
                        type="number"
                        min={1}
                        max={3650}
                        value={settings.idDocumentRetentionDays}
                        placeholder="e.g. 90"
                        error={errors.id_document_retention_days}
                        description="ID photos are deleted after this many days. Document numbers stay. Leave blank to keep photos."
                    />
                    <ChoiceCards
                        id="security_deposit"
                        legend="Security deposit"
                        radioPosition="right"
                        choices={depositChoices}
                        value={depositMode}
                        onValueChange={setDepositMode}
                        error={
                            collectsDeposit
                                ? undefined
                                : errors.security_deposit_amount
                        }
                    />
                    <div hidden={!collectsDeposit}>
                        <SettingsInput
                            name="security_deposit_amount"
                            label="Deposit amount"
                            type="number"
                            min={0}
                            step="0.01"
                            value={
                                Number(settings.securityDepositAmount) > 0
                                    ? settings.securityDepositAmount
                                    : ''
                            }
                            placeholder="e.g. 100.00"
                            disabled={!collectsDeposit}
                            error={errors.security_deposit_amount}
                        />
                    </div>
                    {!collectsDeposit && (
                        <input
                            type="hidden"
                            name="security_deposit_amount"
                            value="0"
                        />
                    )}
                </FieldGroup>
            )}
        </SettingsSection>
    );
}
