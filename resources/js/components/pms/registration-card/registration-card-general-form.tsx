import { useState } from 'react';
import RegistrationCardController from '@/actions/App/Http/Controllers/Registration/RegistrationCardController';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    RegistrationCardGeneralFields,
    registrationCardGeneralValues,
} from '@/components/pms/registration-card/registration-card-general-fields';
import type { RegistrationCardGeneralValues } from '@/components/pms/registration-card/registration-card-general-fields';
import { SettingsSection } from '@/components/pms/settings-section';

export function RegistrationCardGeneralForm({
    settings,
    scopes,
}: {
    settings: App.Data.Property.PropertySettingsData;
    scopes: EnumOption[];
}) {
    const [values, setValues] = useState<RegistrationCardGeneralValues>(() =>
        registrationCardGeneralValues(settings),
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
                <RegistrationCardGeneralFields
                    values={values}
                    onChange={(next) =>
                        setValues((current) => ({ ...current, ...next }))
                    }
                    scopes={scopes}
                    errors={errors}
                />
            )}
        </SettingsSection>
    );
}
