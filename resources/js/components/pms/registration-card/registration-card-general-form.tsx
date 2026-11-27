import { Save, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';
import RegistrationCardController from '@/actions/App/Http/Controllers/Registration/RegistrationCardController';
import type { EnumOption } from '@/components/pms/enum-select';
import {
    RegistrationCardGeneralFields,
    registrationCardGeneralValues,
} from '@/components/pms/registration-card/registration-card-general-fields';
import type { RegistrationCardGeneralValues } from '@/components/pms/registration-card/registration-card-general-fields';
import { SettingsSection } from '@/components/pms/settings-section';

/**
 * Settings › Registration card › General. Saves from the floating Save bar. The values live inside the section's
 * fields, so Discard (which remounts the fields) also resets them.
 */
export function RegistrationCardGeneralForm({
    settings,
    scopes,
}: {
    settings: App.Data.Property.PropertySettingsData;
    scopes: EnumOption[];
}) {
    return (
        <SettingsSection
            guardUnsavedChanges
            saveBar
            form={RegistrationCardController.update.form()}
            title="General"
            icon={SlidersHorizontal}
            description="Guest signature, ID document, and deposit rules."
            headerClassName="ps-2 pt-2"
            contentClassName="flex flex-col gap-5 pb-6"
            submitLabel="Save"
            submitIcon={Save}
        >
            {(errors) => (
                <GeneralFields
                    settings={settings}
                    scopes={scopes}
                    errors={errors}
                />
            )}
        </SettingsSection>
    );
}

function GeneralFields({
    settings,
    scopes,
    errors,
}: {
    settings: App.Data.Property.PropertySettingsData;
    scopes: EnumOption[];
    errors: Record<string, string>;
}) {
    const [values, setValues] = useState<RegistrationCardGeneralValues>(() =>
        registrationCardGeneralValues(settings),
    );

    return (
        <RegistrationCardGeneralFields
            values={values}
            onChange={(next) =>
                setValues((current) => ({ ...current, ...next }))
            }
            scopes={scopes}
            errors={errors}
        />
    );
}
