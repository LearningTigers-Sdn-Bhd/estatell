import { Head, Link, useForm } from '@inertiajs/react';
import {
    History,
    ListPlus,
    PanelRightClose,
    PanelRightOpen,
    Pencil,
    Send,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { FolioRail } from '@/components/pms/folio/folio-rail';
import { FolioRowsTable } from '@/components/pms/folio/folio-rows-table';
import { ManualFolioRail } from '@/components/pms/folio/manual-folio-rail';
import {
    ManualFolioReasonDialog,
    reasonFromText,
    reasonText,
} from '@/components/pms/folio/manual-folio-reason-dialog';
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
import { ReservationWorkspace } from '@/components/pms/reservations/reservation-workspace';
import { useSummaryHidden } from '@/components/pms/reservations/use-summary-hidden';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
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
 * happened, posted together with one reason. The page uses the reservation workspace: the folio rail on the left, the
 * lines of the selected folio in the centre and the summary on the right. Picking a folio in the rail reloads the page
 * for it and keeps the draft rows of every folio; Post sends them all as one batch. The browser holds only what was
 * typed; the server answers each row and the summary after every change and decides everything on post.
 * See docs/13-folio/08-manual-folio.md.
 */
export default function ManualFolio({ manual: page }: Props) {
    const [reason, setReason] = useState(() =>
        reasonFromText(page.reason, page.reasonOptions),
    );
    const reasonLabel = reasonText(reason, page.reasonOptions);
    const [reasonOpen, setReasonOpen] = useState(page.reason === null);
    const [rows, setRows] = useState<ManualDraft[]>(() => [
        newDraft(page, page.folioId),
    ]);
    const [tab, setTab] = useState('new');
    const [closeChoices, setCloseChoices] = useState<Record<number, boolean>>(
        {},
    );
    const [summaryHidden, toggleSummary] = useSummaryHidden();
    const summaryLabel = summaryHidden ? 'Show summary' : 'Hide summary';

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
    const close = closeChoices[page.folioId] ?? defaultClose;
    const drafts = rows.reduce<Record<string, number>>(
        (counts, row) => ({
            ...counts,
            [row.folio_id]: (counts[row.folio_id] ?? 0) + 1,
        }),
        {},
    );
    const answers = new Map(preview.rows.map((one) => [one.key, one]));
    const unfinished = rows.some(
        (row) =>
            isIncomplete(row) ||
            (answers.get(row.key)?.refusal ?? null) !== null,
    );
    const canPost =
        rows.length > 0 &&
        reasonLabel !== '' &&
        !unfinished &&
        current &&
        !form.processing;
    const dirty = rows.some(
        (row) => !isIncomplete(row) || row.description !== '',
    );
    const guard = useUnsavedChanges(dirty && !form.processing);

    const selectFolio = (key: string) =>
        guard.visitWithout(
            manualPage.url({
                reservation: page.reservationId,
                folio: Number(key),
            }),
            { preserveState: true, preserveScroll: true, replace: true },
        );

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
            reason_option: reason.option,
            reason_details: reason.details,
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
                    className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-4 lg:overflow-hidden"
                >
                    <PageHeader
                        title="Manage folio manually"
                        description={
                            <span className="flex flex-wrap items-center gap-2">
                                Reason:{' '}
                                <span className="text-foreground">
                                    {reasonLabel || 'Not given yet'}
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
                        }
                        back={{ href: page.cancelUrl, label: 'folio' }}
                    />

                    <FormError errors={errors} />

                    <ReservationWorkspace
                        list={
                            <FolioRail
                                reservationId={page.reservationId}
                                items={page.rail}
                                activeKey={String(page.folioId)}
                                currency={page.currency}
                                onSelect={selectFolio}
                                drafts={drafts}
                            />
                        }
                        hideAside={summaryHidden}
                        aside={
                            <ManualFolioRail
                                preview={preview}
                                currency={page.currency}
                                current={current}
                                close={close}
                                onCloseChange={(checked) =>
                                    setCloseChoices((all) => ({
                                        ...all,
                                        [page.folioId]: checked,
                                    }))
                                }
                            />
                        }
                    >
                        <Tabs
                            value={tab}
                            onValueChange={setTab}
                            className="flex flex-col gap-1.5"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <TabsList variant="line">
                                    <TabsTrigger value="new">
                                        <ListPlus />
                                        New lines ({drafts[page.folioId] ?? 0})
                                    </TabsTrigger>
                                    <TabsTrigger value="posted">
                                        <History />
                                        Posted lines (
                                        {page.posted?.lineCount ?? 0})
                                    </TabsTrigger>
                                </TabsList>
                                <Tooltip>
                                    <TooltipTrigger
                                        render={
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="icon"
                                                aria-label={summaryLabel}
                                                aria-pressed={summaryHidden}
                                                onClick={toggleSummary}
                                            />
                                        }
                                    >
                                        {summaryHidden ? (
                                            <PanelRightOpen />
                                        ) : (
                                            <PanelRightClose />
                                        )}
                                    </TooltipTrigger>
                                    <TooltipContent>
                                        {summaryLabel}
                                    </TooltipContent>
                                </Tooltip>
                            </div>
                            <TabsContent value="new">
                                <ManualFolioRows
                                    page={page}
                                    folioId={page.folioId}
                                    rows={rows}
                                    onChange={change}
                                    onAdd={() =>
                                        setRows((all) => [
                                            ...all,
                                            newDraft(page, page.folioId),
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
                            <TabsContent value="posted">
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
                    </ReservationWorkspace>
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
                options={page.reasonOptions}
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
