import { router, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { MoneyText } from '@/components/pms/money-text';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Textarea } from '@/components/ui/textarea';
import { dismiss, fixPrice } from '@/routes/night-audit/exceptions';
import { preview } from '@/routes/night-audit/exceptions/fix-price';
import { CheckInAllDialog } from '@/components/pms/reservations/check-in-all-dialog';
import { CheckInDialog } from '@/components/pms/reservations/check-in-dialog';
import { CheckOutDialog } from '@/components/pms/reservations/check-out-dialog';
import { show } from '@/routes/reservations';
import { toast } from '@/components/ui/toast';

type Action = App.Data.NightAudit.ReadinessActionData;
type Data = App.Data.NightAudit.NightAuditReservationData;

/**
 * Opens the check-in, check-in-all or check-out Dialog over the dashboard or night audit sheet. The Dialog
 * loads its reservation, units and payment options from the sheet's own route when it opens, and the sheet reloads when
 * it closes. This is the one place a Dialog may open over another overlay. See docs/design-system/05-form-containers.md.
 */
function ReservationAuditDialogHost({
    action,
    onLoaded,
    onClose,
    onNavigate,
}: {
    action: Action | null;
    /** Called when the Dialog's data has arrived or failed, so the button stops spinning. */
    onLoaded: () => void;
    onClose: () => void;
    onNavigate: () => void;
}) {
    const http = useHttp<Record<string, never>, Data>({});
    const [loaded, setLoaded] = useState<{ url: string; data: Data } | null>(
        null,
    );
    const [open, setOpen] = useState(false);
    const url = action?.url ?? null;

    useEffect(() => {
        if (url === null) {
            return;
        }

        let current = true;

        http.get(url, {
            onSuccess: (data) => {
                if (current) {
                    setLoaded({ url, data });
                    setOpen(true);
                }
            },
        })
            .catch(() => {
                if (current) {
                    toast.add({
                        title: 'The reservation could not be loaded. Try again.',
                        type: 'error',
                    });
                    onClose();
                }
            })
            .finally(() => current && onLoaded());

        return () => {
            current = false;
        };
        // The Dialog loads once for each time it is asked for.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [action]);

    if (action === null || loaded === null || loaded.url !== action.url) {
        return null;
    }

    const { data } = loaded;
    const unit = data.units.find((item) => item.id === action.unitId);
    const change = (next: boolean) => {
        setOpen(next);

        if (!next) {
            onClose();
        }
    };

    return (
        // A submit inside a Dialog bubbles through React to the sheet's own form, so it stops here.
        // `contents` keeps the wrapper out of the sheet's layout, so opening a Dialog never moves the rows.
        <div className="contents" onSubmit={(event) => event.stopPropagation()}>
            {action.dialog === 'check-in-all' && (
                <CheckInAllDialog
                    open={open}
                    onOpenChange={change}
                    reservation={data.reservation}
                />
            )}
            {action.dialog === 'check-in' && unit && (
                <CheckInDialog
                    open={open}
                    onOpenChange={change}
                    reservationId={data.reservation.id}
                    unit={unit}
                    paymentOptions={data.paymentOptions ?? undefined}
                    canAssignRoom={data.canAssignRoom}
                    onAssignRoom={() => {
                        onNavigate();
                        router.visit(
                            show.url({ reservation: data.reservation.id }),
                        );
                    }}
                    currency={data.currency}
                />
            )}
            {action.dialog === 'check-out' && unit && (
                <CheckOutDialog
                    open={open}
                    onOpenChange={change}
                    reservationId={data.reservation.id}
                    unit={unit}
                    paymentOptions={data.paymentOptions ?? undefined}
                    canWaiveFees={data.canWaiveFees}
                    currency={data.currency}
                />
            )}
        </div>
    );
}

type HostProps = {
    action: Action | null;
    onLoaded: () => void;
    onClose: () => void;
    onNavigate: () => void;
};

function FixPackagePriceDialog({
    action,
    onLoaded,
    onClose,
}: HostProps & { action: Action }) {
    const loader = useHttp<
        Record<string, never>,
        App.Data.NightAudit.PackageNightRespreadData
    >({});
    const form = useHttp<Record<string, never>, { done: number }>({});
    const [data, setData] =
        useState<App.Data.NightAudit.PackageNightRespreadData | null>(null);
    const [previewError, setPreviewError] = useState<string | null>(null);
    const exception = { nightAuditException: action.exceptionId! };

    useEffect(() => {
        let current = true;
        loader
            .post(preview.url({ nightAuditException: action.exceptionId! }), {
                onSuccess: (result) => current && setData(result),
                onError: (errors) => {
                    if (current) {
                        setData(null);
                        setPreviewError(
                            errors.form ??
                                'The package price could not be previewed.',
                        );
                    }
                },
            })
            .catch(() => undefined)
            .finally(() => current && onLoaded());
        return () => {
            current = false;
        };
        // Load once when this exception Dialog opens.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [action]);

    const submit = () => {
        form.post(fixPrice.url(exception), {
            onSuccess: onClose,
        }).catch(() => undefined);
    };

    return (
        <FormOverlay
            variant="dialog"
            open
            onOpenChange={(open) => !open && onClose()}
            title={data?.title ?? 'Fix package price'}
            loading={data === null && previewError === null}
            errors={{
                ...form.errors,
                ...(previewError === null ? {} : { form: previewError }),
                ...(data?.blockedReason ? { form: data.blockedReason } : {}),
            }}
            onSubmit={submit}
            submitLabel="Fix price"
            processing={form.processing}
            submitDisabled={data === null || data.blockedReason !== null}
        >
            {data && (
                <div className="flex flex-col gap-3 text-sm">
                    <div className="flex justify-between gap-4">
                        <span>Package price</span>
                        <span>
                            <MoneyText
                                amount={data.total}
                                currency={data.currency}
                            />{' '}
                            (kept)
                        </span>
                    </div>
                    {data.chargedNights.length > 0 && (
                        <div className="flex flex-col gap-1">
                            <span className="text-muted-foreground">
                                Already charged
                            </span>
                            {data.chargedNights.map((night) => (
                                <div
                                    key={night.id}
                                    className="flex justify-between gap-4"
                                >
                                    <DateText date={night.date} />
                                    <MoneyText
                                        amount={night.oldAmount}
                                        currency={data.currency}
                                        plain
                                    />
                                </div>
                            ))}
                        </div>
                    )}
                    <div className="flex flex-col gap-1">
                        <p className="text-muted-foreground">
                            {data.wholeStay
                                ? 'Spread again over the whole stay:'
                                : 'Spread again over the nights not charged yet:'}
                        </p>
                        {data.nights.map((night) => (
                            <div
                                key={night.id}
                                className="flex justify-between gap-4"
                            >
                                <DateText date={night.date} />
                                <span>
                                    <MoneyText
                                        amount={night.oldAmount}
                                        currency={data.currency}
                                        plain
                                    />{' '}
                                    →{' '}
                                    <MoneyText
                                        amount={night.newAmount!}
                                        currency={data.currency}
                                        plain
                                    />
                                    {night.charged &&
                                        night.oldAmount !== night.newAmount && (
                                            <span className="text-xs text-muted-foreground">
                                                {' '}
                                                · charged, corrected
                                            </span>
                                        )}
                                </span>
                            </div>
                        ))}
                    </div>
                    {data.wholeStay && (
                        <p className="text-muted-foreground">
                            The nights left could not cover their inclusions.
                            Charged nights are reversed and posted again at
                            their new price.
                        </p>
                    )}
                    <p>
                        The <DateText date={data.retryNight} /> night is charged
                        after this.
                    </p>
                </div>
            )}
        </FormOverlay>
    );
}

function DismissExceptionDialog({
    action,
    onLoaded,
    onClose,
}: HostProps & { action: Action }) {
    const form = useHttp<{ reason: string }, { done: number }>({ reason: '' });
    useEffect(onLoaded, [onLoaded]);
    const submit = () => {
        form.post(dismiss.url({ nightAuditException: action.exceptionId! }), {
            onSuccess: onClose,
        }).catch(() => undefined);
    };

    return (
        <FormOverlay
            variant="dialog"
            size="sm"
            open
            onOpenChange={(open) => !open && onClose()}
            title="Dismiss audit exception"
            description="Resolve an exception you have handled another way. Nothing is charged."
            errors={form.errors}
            onSubmit={submit}
            submitLabel="Dismiss"
            processing={form.processing}
            submitDisabled={form.data.reason.trim() === ''}
        >
            <Field data-invalid={!!form.errors.reason}>
                <FieldLabel htmlFor="dismiss-exception-reason">
                    Reason
                </FieldLabel>
                <Textarea
                    id="dismiss-exception-reason"
                    name="reason"
                    required
                    autoFocus
                    rows={3}
                    maxLength={500}
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    aria-invalid={!!form.errors.reason}
                />
                <FieldError>{form.errors.reason}</FieldError>
            </Field>
        </FormOverlay>
    );
}

export function AuditDialogHost(props: HostProps) {
    const { action } = props;
    if (action?.dialog === 'fix-price' || action?.dialog === 'dismiss') {
        return (
            <div
                className="contents"
                onSubmit={(event) => event.stopPropagation()}
            >
                {action.dialog === 'fix-price' ? (
                    <FixPackagePriceDialog
                        key={action.exceptionId}
                        {...props}
                        action={action}
                    />
                ) : (
                    <DismissExceptionDialog
                        key={action.exceptionId}
                        {...props}
                        action={action}
                    />
                )}
            </div>
        );
    }
    return <ReservationAuditDialogHost {...props} />;
}
