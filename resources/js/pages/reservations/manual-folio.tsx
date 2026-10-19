import { Head, Link, useForm } from '@inertiajs/react';
import { History, ListPlus, Pencil, Send, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { FolioRowsTable } from '@/components/pms/folio/folio-rows-table';
import { ManualFolioRail } from '@/components/pms/folio/manual-folio-rail';
import { ManualFolioReasonDialog } from '@/components/pms/folio/manual-folio-reason-dialog';
import {
    isIncomplete,
    ManualFolioRows,
    newDraft,
    toPayload,
} from '@/components/pms/folio/manual-folio-rows';
import type { ManualDraft } from '@/components/pms/folio/manual-folio-rows';
import { useManualPreview } from '@/components/pms/folio/use-manual-preview';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { folio as folioTab, index, show } from '@/routes/reservations';
import { manual as manualPage } from '@/routes/reservations/folios';
import { store } from '@/routes/reservations/folios/manual';

type Props = {
    manual: App.Data.Folio.ManualFolioPageData;
};

const noop = () => undefined;

/**
 * Manage folio manually: lines for charges, payments and refunds that were missed, each with the day it really
 * happened, posted together with one reason. The browser holds only what was typed; the server answers each row and the
 * summary after every change and decides everything on post. See docs/13-folio/08-manual-folio.md.
 */
export default function ManualFolio({ manual: page }: Props) {
    const [reason, setReason] = useState(page.reason ?? '');
    const [reasonOpen, setReasonOpen] = useState(page.reason === null);
    const [rows, setRows] = useState<ManualDraft[]>(() => [newDraft(page)]);
    const [tab, setTab] = useState('new');
    const [closeChoice, setCloseChoice] = useState<boolean | null>(null);

    const payload = useMemo(() => rows.map(toPayload), [rows]);
    const { preview, current } = useManualPreview(
        page.reservationId,
        page.folioId,
        page.preview,
        payload,
    );
    const form = useForm({});
    const errors = form.errors as Record<string, string | undefined>;
    const defaultClose =
        preview.closeBlocked === null && preview.balanceAfter === '0.00';
    const close = closeChoice ?? defaultClose;
    const answers = new Map(preview.rows.map((one) => [one.key, one]));
    const unfinished = rows.some(
        (row) =>
            isIncomplete(row) ||
            (answers.get(row.key)?.refusal ?? null) !== null,
    );
    const canPost =
        rows.length > 0 &&
        reason.trim() !== '' &&
        !unfinished &&
        current &&
        !form.processing;
    const dirty = rows.some(
        (row) => !isIncomplete(row) || row.description !== '',
    );
    const guard = useUnsavedChanges(dirty && !form.processing);

    const change = (key: string, patch: Partial<ManualDraft>) =>
        setRows((current) =>
            current.map((row) =>
                row.key === key ? { ...row, ...patch } : row,
            ),
        );

    const submit = () => {
        if (!canPost) {
            return;
        }

        form.transform(() => ({
            rows: payload,
            reason,
            close: close && preview.closeBlocked === null,
            idempotency_key: page.batchKey,
        }));
        form.post(
            store.url({ reservation: page.reservationId, folio: page.folioId }),
            {
                preserveScroll: true,
                onSuccess: () => guard.proceed(),
            },
        );
    };

    const count = rows.length;

    return (
        <>
            <Head title={page.title} />

            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    submit();
                }}
                className="flex min-h-0 flex-1 flex-col"
            >
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title="Manage folio manually"
                        description={
                            <span className="flex flex-col gap-1">
                                <span className="flex flex-wrap items-center gap-2">
                                    Reason:{' '}
                                    <span className="text-foreground">
                                        {reason || 'Not given yet'}
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="xs"
                                        onClick={() => setReasonOpen(true)}
                                    >
                                        <Pencil data-icon="inline-start" />
                                        Edit
                                    </Button>
                                </span>
                                <span>
                                    Lines post today ({page.businessDate}) and
                                    keep the date you enter.
                                </span>
                            </span>
                        }
                        back={{ href: page.cancelUrl, label: 'folio' }}
                    />

                    <FormError errors={errors} />

                    <div className="grid items-start gap-6 xl:grid-cols-[minmax(0,1fr)_22rem]">
                        <Tabs value={tab} onValueChange={setTab}>
                            <TabsList variant="line">
                                <TabsTrigger value="new">
                                    <ListPlus />
                                    New lines ({count})
                                </TabsTrigger>
                                <TabsTrigger value="posted">
                                    <History />
                                    Posted lines ({page.posted?.lineCount ?? 0})
                                </TabsTrigger>
                            </TabsList>
                            <TabsContent value="new" className="pt-4">
                                <ManualFolioRows
                                    page={page}
                                    rows={rows}
                                    onChange={change}
                                    onAdd={() =>
                                        setRows((all) => [
                                            ...all,
                                            newDraft(page),
                                        ])
                                    }
                                    onRemove={(key) =>
                                        setRows((all) =>
                                            all.filter(
                                                (row) => row.key !== key,
                                            ),
                                        )
                                    }
                                    preview={preview}
                                    serverErrors={errors}
                                    processing={form.processing}
                                />
                            </TabsContent>
                            <TabsContent value="posted" className="pt-4">
                                {page.posted && (
                                    <FolioRowsTable
                                        reservationId={page.reservationId}
                                        folioId={page.folioId}
                                        rows={page.posted.rows.filter(
                                            (row) => row.state === 'posted',
                                        )}
                                        currency={page.currency}
                                        onAction={noop}
                                        onPrintVoucher={noop}
                                    />
                                )}
                            </TabsContent>
                        </Tabs>
                        <aside className="h-fit xl:sticky xl:top-4">
                            <ManualFolioRail
                                preview={preview}
                                currency={page.currency}
                                current={current}
                                close={close}
                                onCloseChange={setCloseChoice}
                            />
                        </aside>
                    </div>
                </div>

                <FormActions fixed>
                    <Link
                        href={page.cancelUrl}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        <X data-icon="inline-start" />
                        Discard
                    </Link>
                    <Button
                        type="submit"
                        disabled={!canPost}
                        focusableWhenDisabled
                    >
                        {form.processing ? (
                            <Spinner data-icon="inline-start" />
                        ) : (
                            <Send data-icon="inline-start" />
                        )}
                        {count === 1 ? 'Post 1 line' : `Post ${count} lines`}
                    </Button>
                </FormActions>
            </form>

            <ManualFolioReasonDialog
                open={reasonOpen}
                onOpenChange={setReasonOpen}
                initial={reason}
                onConfirm={setReason}
                reservationId={page.reservationId}
                folioId={page.folioId}
            />

            <UnsavedChangesDialog
                guard={guard}
                name="these manual lines"
                saving={form.processing}
                onSave={submit}
            />
        </>
    );
}

ManualFolio.layout = (props: {
    manual: App.Data.Folio.ManualFolioPageData;
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    breadcrumbs: [
        { title: 'Reservations', href: index(props.property.code) },
        {
            title: props.manual.reference,
            href: show({
                property: props.property.code,
                reservation: props.manual.reservationId,
            }),
        },
        {
            title: 'Folio',
            href: folioTab({
                property: props.property.code,
                reservation: props.manual.reservationId,
            }),
        },
        {
            title: 'Manage manually',
            href: manualPage({
                property: props.property.code,
                reservation: props.manual.reservationId,
                folio: props.manual.folioId,
            }),
        },
    ],
});
