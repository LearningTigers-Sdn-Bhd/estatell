import { Banknote, CircleOff, UserRound, UsersRound } from 'lucide-react';
import { ChoiceCards } from '@/components/pms/choice-cards';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

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

/** What the fields show. The deposit amount is empty while no deposit is collected. */
export type RegistrationCardGeneralValues = {
    registrationCardScope: string;
    cardRequiredForCheckIn: boolean;
    idDocumentRetentionDays: string;
    collectsDeposit: boolean;
    securityDepositAmount: string;
};

/** The values the form starts with, from the saved property settings. */
export function registrationCardGeneralValues(
    settings: App.Data.Property.PropertySettingsData | null,
): RegistrationCardGeneralValues {
    const deposit = Number(settings?.securityDepositAmount ?? 0);

    return {
        registrationCardScope:
            settings?.registrationCardScope ?? 'primary_only',
        cardRequiredForCheckIn: settings?.cardRequiredForCheckIn ?? true,
        idDocumentRetentionDays:
            settings?.idDocumentRetentionDays?.toString() ?? '',
        collectsDeposit: deposit > 0,
        securityDepositAmount:
            deposit > 0 ? settings!.securityDepositAmount : '',
    };
}

/**
 * The guest signature, ID document and deposit fields of the registration card. Settings (Registration card, General)
 * and the onboarding Check-in essentials step both show them, so they look and read the same. The fields have names,
 * so a native form can send them; a page that keeps the values itself can ignore the names.
 */
export function RegistrationCardGeneralFields({
    values,
    onChange,
    scopes,
    errors,
}: {
    values: RegistrationCardGeneralValues;
    onChange: (values: Partial<RegistrationCardGeneralValues>) => void;
    scopes: EnumOption[];
    errors: Record<string, string>;
}) {
    return (
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
                value={values.registrationCardScope}
                onValueChange={(value) =>
                    onChange({ registrationCardScope: value })
                }
                error={errors.registration_card_scope}
            />
            <Field
                orientation="horizontal"
                className="items-start justify-start md:col-span-2"
            >
                <Switch
                    id="card_required_for_check_in"
                    name="card_required_for_check_in"
                    checked={values.cardRequiredForCheckIn}
                    onCheckedChange={(checked) =>
                        onChange({ cardRequiredForCheckIn: checked })
                    }
                    aria-invalid={!!errors.card_required_for_check_in}
                    className="mt-0.5"
                />
                <FieldContent className="flex-none">
                    <FieldLabel htmlFor="card_required_for_check_in">
                        Require card before check-in
                    </FieldLabel>
                    <FieldDescription>
                        On: guests must complete the required cards before
                        check-in. Off: staff can check guests in without a card.
                        Staff with override permission can bypass this
                        requirement.
                    </FieldDescription>
                    <FieldError>{errors.card_required_for_check_in}</FieldError>
                </FieldContent>
            </Field>
            <Field>
                <FieldLabel htmlFor="id_document_retention_days">
                    ID document retention (days)
                </FieldLabel>
                <Input
                    id="id_document_retention_days"
                    name="id_document_retention_days"
                    type="number"
                    min={1}
                    max={3650}
                    placeholder="e.g. 90"
                    value={values.idDocumentRetentionDays}
                    onChange={(event) =>
                        onChange({
                            idDocumentRetentionDays: event.target.value,
                        })
                    }
                    aria-invalid={!!errors.id_document_retention_days}
                />
                <FieldDescription>
                    ID photos are deleted after this many days. Document numbers
                    stay. Leave blank to keep photos.
                </FieldDescription>
                <FieldError>{errors.id_document_retention_days}</FieldError>
            </Field>
            <ChoiceCards
                id="security_deposit"
                legend="Security deposit"
                radioPosition="right"
                choices={depositChoices}
                value={values.collectsDeposit ? 'collect' : 'none'}
                onValueChange={(value) =>
                    onChange({ collectsDeposit: value === 'collect' })
                }
                error={
                    values.collectsDeposit
                        ? undefined
                        : errors.security_deposit_amount
                }
            />
            <div hidden={!values.collectsDeposit}>
                <Field>
                    <FieldLabel htmlFor="security_deposit_amount">
                        Deposit amount
                    </FieldLabel>
                    <Input
                        id="security_deposit_amount"
                        name="security_deposit_amount"
                        type="number"
                        min={0}
                        step="0.01"
                        placeholder="e.g. 100.00"
                        value={values.securityDepositAmount}
                        onChange={(event) =>
                            onChange({
                                securityDepositAmount: event.target.value,
                            })
                        }
                        disabled={!values.collectsDeposit}
                        aria-invalid={!!errors.security_deposit_amount}
                    />
                    <FieldError>{errors.security_deposit_amount}</FieldError>
                </Field>
            </div>
            {!values.collectsDeposit && (
                <input type="hidden" name="security_deposit_amount" value="0" />
            )}
        </FieldGroup>
    );
}
