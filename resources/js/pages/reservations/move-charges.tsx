import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowRight, ArrowRightLeft, X } from 'lucide-react';
import { useMemo, useState } from 'react';
import { FormActions } from '@/components/pms/form-actions';
import { FormError } from '@/components/pms/form-error';
import {
    MoveChargesFrom,
    type ScheduleChoices,
} from '@/components/pms/folio/move-charges-from';
import { moveButtonLabel } from '@/components/pms/folio/move-charges-format';
import { MoveChargesTo } from '@/components/pms/folio/move-charges-to';
import { useMovePreview } from '@/components/pms/folio/use-move-preview';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { index, show } from '@/routes/reservations';
import { move as moveCharges } from '@/routes/reservations/folio';
import { store } from '@/routes/reservations/folio/move';

type Props = {
    move: App.Data.Folio.MoveChargesPageData;
};

/**
 * Move charges: ticked charges of several folios go to one folio, and each room's scheduled charges can follow. Two
 * columns, From and To (a recorded exception to the one-column form rule). The browser keeps only what is ticked and
 * chosen; the server works out every figure and note in a preview 300 ms after a change.
 */
export default function MoveCharges({ move: page }: Props) {
    const currency = page.currency;
    const [targetId, setTargetId] = useState(page.targetId);
    const [tickedIds, setTicked] = useState<number[]>(page.lineIds);
    const [choices, setChoices] = useState<ScheduleChoices>({});

    const target = page.folios.find((folio) => folio.id === targetId);
    const sources = page.folios.filter((folio) => folio.id !== targetId);
    // After a refusal the page reloads its folios, so a charge that moved or was reversed meanwhile drops out here.
    const ticked = useMemo(
        () =>
            tickedIds.filter((id) =>
                sources.some((folio) =>
                    folio.charges.some((charge) => charge.lineId === id),
                ),
            ),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [tickedIds, page.folios, targetId],
    );
    const payload = useMemo(
        () => ({
            target_folio_id: targetId,
            line_ids: [...ticked].sort((a, b) => a - b),
            schedules: Object.entries(choices)
                .map(([unit, scope]) => ({
                    unit_id: Number(unit),
                    scope,
                }))
                .sort((a, b) => a.unit_id - b.unit_id),
        }),
        [targetId, ticked, choices],
    );
    const { preview, current } = useMovePreview(
        page.reservationId,
        page.preview,
        payload,
    );
    const form = useForm(payload);
    const chargeCount = ticked.length;
    const scheduleCount = Object.keys(choices).length;
    const tooMany = chargeCount > page.maxCharges;
    const canMove =
        (chargeCount > 0 || scheduleCount > 0) && !tooMany && !form.processing;
    const label = moveButtonLabel(chargeCount, scheduleCount);
    const dirty = chargeCount > 0 || scheduleCount > 0;
    const guard = useUnsavedChanges(dirty && !form.processing);

    if (!target) {
        return null;
    }

    const changeTarget = (next: number) => {
        const nextFolio = page.folios.find((folio) => folio.id === next);

        setTargetId(next);
        // The old target's charges are back on the From side; the new target's own charges cannot be ticked.
        setTicked((current) =>
            current.filter(
                (id) =>
                    !nextFolio?.charges.some((charge) => charge.lineId === id),
            ),
        );
        setChoices({});
    };

    const toggle = (lineId: number, checked: boolean) =>
        setTicked((current) =>
            checked
                ? current.includes(lineId)
                    ? current
                    : [...current, lineId]
                : current.filter((id) => id !== lineId),
        );

    const quickSelect = (mode: 'room' | 'all' | 'clear') => {
        if (mode === 'clear') {
            setTicked([]);

            return;
        }

        const ids = sources.flatMap((folio) =>
            folio.charges
                .filter((charge) => mode === 'all' || charge.isRoom)
                .map((charge) => charge.lineId),
        );

        setTicked((current) => [...new Set([...current, ...ids])]);
    };

    const choose = (unitId: number, scope: 'room' | 'all' | undefined) =>
        setChoices((current) => {
            const next = { ...current };

            if (scope === undefined) {
                delete next[unitId];
            } else {
                next[unitId] = scope;
            }

            return next;
        });

    const submit = () => {
        if (!canMove) {
            return;
        }

        form.transform(() => payload);
        form.post(store.url({ reservation: page.reservationId }), {
            preserveScroll: true,
        });
    };

    const cancelUrl = page.cancelUrl;

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
                        title={page.title}
                        description="Charges move now. Scheduled charges follow the rule you choose, until you change it in Routing rules."
                        back={{ href: cancelUrl, label: 'folio' }}
                        actions={
                            <div className="hidden items-center gap-2 lg:flex">
                                <Link
                                    href={cancelUrl}
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                >
                                    <X data-icon="inline-start" />
                                    Cancel
                                </Link>
                                <Button
                                    type="submit"
                                    disabled={!canMove}
                                    focusableWhenDisabled
                                >
                                    {form.processing ? (
                                        <Spinner data-icon="inline-start" />
                                    ) : (
                                        <ArrowRightLeft data-icon="inline-start" />
                                    )}
                                    {label}
                                </Button>
                            </div>
                        }
                    />

                    <FormError errors={form.errors} />
                    {(tooMany || (current && preview.refusal)) && (
                        <Alert variant="destructive">
                            <AlertDescription>
                                {preview.refusal ??
                                    'You can move up to 200 charges at once. Move them in two parts.'}
                            </AlertDescription>
                        </Alert>
                    )}

                    <div className="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)]">
                        <MoveChargesFrom
                            sources={sources}
                            ticked={ticked}
                            onToggle={toggle}
                            onQuickSelect={quickSelect}
                            preview={preview}
                            previewCurrent={
                                current && preview.targetId === targetId
                            }
                            choices={choices}
                            onChoice={choose}
                            currency={currency}
                        />
                        <div
                            aria-hidden="true"
                            className="flex items-center justify-center text-muted-foreground lg:h-full"
                        >
                            <ArrowDown className="lg:hidden" />
                            <ArrowRight className="hidden lg:block" />
                        </div>
                        <MoveChargesTo
                            folios={page.folios}
                            target={target}
                            onTargetChange={changeTarget}
                            ticked={ticked}
                            onUntick={(lineId) => toggle(lineId, false)}
                            preview={preview}
                            previewCurrent={
                                current && preview.targetId === targetId
                            }
                            currency={currency}
                            error={form.errors.target_folio_id}
                        />
                    </div>
                </div>

                <div className="lg:hidden">
                    <FormActions fixed>
                        <Link
                            href={cancelUrl}
                            className={buttonVariants({ variant: 'outline' })}
                        >
                            <X data-icon="inline-start" />
                            Cancel
                        </Link>
                        <Button
                            type="submit"
                            disabled={!canMove}
                            focusableWhenDisabled
                        >
                            {form.processing ? (
                                <Spinner data-icon="inline-start" />
                            ) : (
                                <ArrowRightLeft data-icon="inline-start" />
                            )}
                            {label}
                        </Button>
                    </FormActions>
                </div>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name="this move"
                saving={form.processing}
                onSave={submit}
            />
        </>
    );
}

MoveCharges.layout = (props: {
    move: App.Data.Folio.MoveChargesPageData;
    property: App.Data.Property.PropertySummaryData;
}) => ({
    variant: 'fixed' as const,
    breadcrumbs: [
        { title: 'Reservations', href: index(props.property.code) },
        {
            title: props.move.reference,
            href: show({
                property: props.property.code,
                reservation: props.move.reservationId,
            }),
        },
        {
            title: 'Move charges',
            href: moveCharges({
                property: props.property.code,
                reservation: props.move.reservationId,
            }),
        },
    ],
});
