import { Head, useForm } from '@inertiajs/react';
import { Info } from 'lucide-react';
import { useState } from 'react';
import {
    type OtaListChannel,
    type OtaListRow,
    type OtaListSavedRow,
    OtaListsTable,
    otaListChannelLabel,
    otaListRowFromSaved,
    otaListSubmitRows,
} from '@/components/pms/channels/ota-lists-table';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { FieldError } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { update } from '@/routes/settings/ota-lists';

const FORM_ID = 'ota-lists-form';

export default function OtaListsSettings({
    savedRows,
    channels,
    maxRows,
}: {
    savedRows: OtaListSavedRow[];
    channels: OtaListChannel[];
    maxRows: number;
}) {
    const form = useForm<{ rows: OtaListRow[] }>({
        rows: savedRows.map(otaListRowFromSaved),
    });
    const guard = useUnsavedChanges(form.isDirty);
    const rows = form.data.rows;
    const errors = form.errors as Record<string, string | undefined>;
    /** The row whose removal waits for the confirm. Only a Processed row asks. */
    const [removing, setRemoving] = useState<number | null>(null);
    const removingRow = removing === null ? null : (rows[removing] ?? null);
    const removingLabel =
        removingRow === null
            ? ''
            : (otaListChannelLabel(removingRow, channels) ?? 'this channel');

    const removeRow = (index: number) => {
        form.setData(
            'rows',
            rows.filter((_, position) => position !== index),
        );
        form.clearErrors();
    };

    const save = (onSuccess?: () => void) => {
        form.transform((data) => ({ rows: otaListSubmitRows(data.rows) }));
        form.put(update.url(), {
            preserveScroll: true,
            onSuccess,
        });
    };

    return (
        <>
            <Head title="OTA lists" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="OTA lists"
                    description="The online travel agencies you sell through, and the login for each one."
                />

                <form
                    id={FORM_ID}
                    onSubmit={(event) => {
                        event.preventDefault();
                        save();
                    }}
                    className="flex flex-col gap-6"
                >
                    <Alert>
                        <Info />
                        <AlertDescription>
                            Only the estatell team reads these. Passwords are
                            stored encrypted and are never shown again here. A
                            new password sends the channel back to Pending until
                            the team connects it again. A saved row keeps its
                            channel: remove the row and add a new one to change
                            it.
                        </AlertDescription>
                    </Alert>

                    <OtaListsTable
                        rows={rows}
                        channels={channels}
                        maxRows={maxRows}
                        errors={errors}
                        showStatus
                        lockSavedChannels
                        onRowsChange={(next) => form.setData('rows', next)}
                        onRemoveRow={(index) => {
                            if (rows[index]?.status?.value === 'processed') {
                                setRemoving(index);

                                return;
                            }

                            removeRow(index);
                        }}
                    />
                    <FieldError>{errors.rows}</FieldError>

                    <div className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={form.processing || !form.isDirty}
                            focusableWhenDisabled
                        >
                            {form.processing && <Spinner />}
                            Save OTA lists
                        </Button>
                    </div>
                </form>
            </div>

            <AlertDialog
                open={removing !== null}
                onOpenChange={(open) => {
                    if (!open) {
                        setRemoving(null);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            Remove {removingLabel}?
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            Tell the estatell team to disconnect {removingLabel}
                            . The row is removed when you save.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancel</AlertDialogCancel>
                        <Button
                            type="button"
                            variant="destructive"
                            onClick={() => {
                                if (removing !== null) {
                                    removeRow(removing);
                                }

                                setRemoving(null);
                            }}
                        >
                            Remove row
                        </Button>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <UnsavedChangesDialog
                guard={guard}
                name="OTA lists"
                saving={form.processing}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
