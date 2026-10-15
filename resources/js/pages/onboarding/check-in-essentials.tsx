import { Head, router, setLayoutProps, useHttp } from '@inertiajs/react';
import { useState } from 'react';
import CheckInEssentialsController from '@/actions/App/Http/Controllers/Onboarding/CheckInEssentialsController';
import Heading from '@/components/heading';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import {
    RegistrationCardGeneralFields,
    registrationCardGeneralValues,
} from '@/components/pms/registration-card/registration-card-general-fields';
import type { RegistrationCardGeneralValues } from '@/components/pms/registration-card/registration-card-general-fields';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

type Payload = RegistrationCardGeneralValues & {
    checkInTime: string;
    checkOutTime: string;
};

const FORM_ID = 'check-in-essentials-form';

export default function CheckInEssentials({
    settings,
    times,
    scopes,
    locked,
    blockedBy,
}: {
    settings: App.Data.Property.PropertySettingsData | null;
    times: EnumOption[];
    scopes: EnumOption[];
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const http = useHttp<Payload>({
        checkInTime: settings?.checkInTime ?? '15:00',
        checkOutTime: settings?.checkOutTime ?? '11:00',
        ...registrationCardGeneralValues(settings),
    });
    const guard = useUnsavedChanges(http.isDirty);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const { data } = http;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'check_in_essentials',
        footer: 'auto',
        formId: FORM_ID,
        processing: saving,
        width: '5xl',
    });

    const save = (onSaved?: () => void) =>
        router.put(
            CheckInEssentialsController.update.url(),
            {
                check_in_time: data.checkInTime,
                check_out_time: data.checkOutTime,
                registration_card_scope: data.registrationCardScope,
                card_required_for_check_in: data.cardRequiredForCheckIn,
                id_document_retention_days: data.idDocumentRetentionDays,
                collect_deposit: data.collectsDeposit,
                security_deposit_amount: data.securityDepositAmount,
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => {
                    setErrors({});
                    http.setDefaults();
                    onSaved?.();
                },
                onError: (next) => {
                    setErrors(next);
                    guard.stay();
                },
            },
        );

    return (
        <>
            <Head title="Check-in essentials" />
            <Heading
                title="Check-in essentials"
                description="When guests arrive and leave, and what the registration card asks."
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <section className="flex flex-col gap-4">
                    <h2 className="font-heading text-base font-semibold">
                        Times
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <Field>
                            <FieldLabel htmlFor="check_in_time">
                                Check-in from
                            </FieldLabel>
                            <EnumSelect
                                id="check_in_time"
                                name="check_in_time"
                                className="w-full"
                                options={times}
                                value={data.checkInTime}
                                onValueChange={(value) =>
                                    http.setData('checkInTime', value)
                                }
                                aria-invalid={!!errors.check_in_time}
                            />
                            <FieldError>{errors.check_in_time}</FieldError>
                        </Field>
                        <Field>
                            <FieldLabel htmlFor="check_out_time">
                                Check-out by
                            </FieldLabel>
                            <EnumSelect
                                id="check_out_time"
                                name="check_out_time"
                                className="w-full"
                                options={times}
                                value={data.checkOutTime}
                                onValueChange={(value) =>
                                    http.setData('checkOutTime', value)
                                }
                                aria-invalid={!!errors.check_out_time}
                            />
                            <FieldError>{errors.check_out_time}</FieldError>
                        </Field>
                    </div>
                </section>

                <section className="flex flex-col gap-4">
                    <h2 className="font-heading text-base font-semibold">
                        Registration card
                    </h2>
                    <RegistrationCardGeneralFields
                        values={data}
                        onChange={(next) => http.setData({ ...data, ...next })}
                        scopes={scopes}
                        errors={errors}
                    />
                    <p className="text-sm text-muted-foreground">
                        House terms are set after go-live, in Settings under
                        Registration card.
                    </p>
                </section>
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="Check-in essentials"
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
