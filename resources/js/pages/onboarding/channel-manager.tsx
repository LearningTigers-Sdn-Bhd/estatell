import { Head, setLayoutProps, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import ChannelManagerController from '@/actions/App/Http/Controllers/Onboarding/ChannelManagerController';
import Heading from '@/components/heading';
import {
    type OtaListChannel,
    type OtaListRow,
    type OtaListSavedRow,
    OtaListsTable,
    otaListRowFromSaved,
    otaListSubmitRows,
} from '@/components/pms/channels/ota-lists-table';
import type { OnboardingLayoutProps } from '@/components/pms/onboarding/onboarding-steps';
import { StepForm } from '@/components/pms/onboarding/step-form';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';

const FORM_ID = 'channel-manager-form';

export default function ChannelManager({
    savedRows,
    channels,
    maxRows,
    locked,
    blockedBy,
}: {
    savedRows: OtaListSavedRow[];
    channels: OtaListChannel[];
    maxRows: number;
    locked: boolean;
    blockedBy: { label: string; url: string | null } | null;
}) {
    const form = useForm<{ rows: OtaListRow[] }>({
        rows: savedRows.map(otaListRowFromSaved),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;

    setLayoutProps<OnboardingLayoutProps>({
        step: 'channel_manager',
        footer: 'auto',
        formId: FORM_ID,
        processing: form.processing,
        width: '7xl',
    });

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({ rows: otaListSubmitRows(data.rows) }));
        form.put(ChannelManagerController.update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    return (
        <>
            <Head title="OTA lists" />
            <Heading
                title="OTA lists"
                description="Which online travel agencies (OTAs) do you sell through? Add the login for each one. The estatell team connects them after you go live. Skip this if you don't sell through an OTA."
            />
            <StepForm
                id={FORM_ID}
                locked={locked}
                blockedBy={blockedBy}
                error={errors.onboarding ?? errors.rows ?? null}
                onSubmit={(event) => {
                    event.preventDefault();
                    save();
                }}
            >
                <Alert>
                    <Info />
                    <AlertDescription>
                        Only the estatell team reads these. Passwords are stored
                        encrypted and are never shown again here. The team
                        connects your channels through our channel manager after
                        approval. Nothing is connected from this page.
                    </AlertDescription>
                </Alert>

                <OtaListsTable
                    rows={rows}
                    channels={channels}
                    maxRows={maxRows}
                    errors={errors}
                    onRowsChange={(next) => form.setData('rows', next)}
                    onRemoveRow={(index) => {
                        form.setData(
                            'rows',
                            rows.filter((_, position) => position !== index),
                        );
                        form.clearErrors();
                    }}
                />
            </StepForm>
            <UnsavedChangesDialog
                guard={guard}
                name="OTA lists"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
