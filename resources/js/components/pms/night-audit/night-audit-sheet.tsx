import { useHttp } from '@inertiajs/react';
import {
    Circle,
    CircleAlert,
    CircleCheck,
    CircleX,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatBusinessDay } from '@/lib/business-date';
import { run, show } from '@/routes/night-audit';

type Sheet = App.Data.NightAudit.NightAuditSheetData;
type Item = App.Data.NightAudit.ReadinessItemData;

type View = 'loading' | 'ready' | 'running' | 'completed' | 'failed' | 'none';

const POLL_INTERVAL = 3000;

/**
 * What the sheet shows. A run this browser started stays "running" until the audit of that date has finished, even
 * before the queue has picked it up. Otherwise the server decides: an Auditing date is running or failed.
 */
function viewOf(
    sheet: Sheet | null,
    ranDate: string | null,
    resumeFrom: string | null,
): View {
    if (sheet === null) {
        return 'loading';
    }

    if (sheet.date === null) {
        return 'none';
    }

    const audit = sheet.audit;

    if (ranDate !== null) {
        if (audit?.status === 'failed' && audit.updatedAt === resumeFrom) {
            return 'running';
        }

        if (audit?.date === ranDate && audit.status !== 'running') {
            return audit.status === 'completed' ? 'completed' : 'failed';
        }

        return 'running';
    }

    if (sheet.status === 'auditing') {
        return audit?.status === 'failed' ? 'failed' : 'running';
    }

    return 'ready';
}

function ItemRow({
    item,
    assigning,
    onAssign,
}: {
    item: Item;
    assigning: boolean;
    onAssign: (url: string) => void;
}) {
    return (
        <li className="flex items-center justify-between gap-3 py-2">
            <div className="flex min-w-0 flex-col">
                <span className="truncate text-sm font-medium">
                    {item.guest ?? item.reference}
                </span>
                <span className="truncate text-xs text-muted-foreground">
                    {item.checkLabel} · {item.reference} · {item.detail}
                </span>
            </div>
            {item.assignUrl ? (
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    disabled={assigning}
                    focusableWhenDisabled
                    onClick={() => onAssign(item.assignUrl as string)}
                >
                    {assigning && <Spinner data-icon="inline-start" />}
                    Assign
                </Button>
            ) : (
                <Button
                    size="sm"
                    variant="outline"
                    render={<a href={item.url} />}
                >
                    Open
                </Button>
            )}
        </li>
    );
}

function Steps({ audit }: { audit: NonNullable<Sheet['audit']> }) {
    return (
        <ul className="flex flex-col gap-2">
            {audit.steps.map((step) => (
                <li key={step.step} className="flex items-center gap-2 text-sm">
                    {step.status === 'completed' && (
                        <CircleCheck className="size-4 text-success" />
                    )}
                    {step.status === 'running' && (
                        <Spinner className="size-4 text-info" />
                    )}
                    {step.status === 'failed' && (
                        <CircleX className="size-4 text-destructive" />
                    )}
                    {step.status === null && (
                        <Circle className="size-4 text-muted-foreground" />
                    )}
                    <span>{step.label}</span>
                    {step.status === 'completed' && step.processed > 0 && (
                        <span className="text-muted-foreground">
                            {step.processed}
                        </span>
                    )}
                    {step.failed > 0 && (
                        <span className="text-warning">
                            {step.failed} left for you
                        </span>
                    )}
                </li>
            ))}
        </ul>
    );
}

/**
 * The Run Night Audit sheet, opened from the Front desk sidebar. It lists what to fix before the audit and what needs
 * a look, runs the audit on the queue and follows its steps. The server decides what is a blocker and what the audit
 * does: the browser only shows it. See docs/20-night-audit/02-readiness-panel.md.
 */
export function NightAuditSheet({
    open,
    onOpenChange,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const loader = useHttp<Record<string, never>, Sheet>('get', show.url(), {});
    const runner = useHttp<{ force: boolean; reason: string }, unknown>({
        force: false,
        reason: '',
    });
    const assigner = useHttp<Record<string, never>, unknown>({});
    const [sheet, setSheet] = useState<Sheet | null>(null);
    const [ranDate, setRanDate] = useState<string | null>(null);
    const [resumeFrom, setResumeFrom] = useState<string | null>(null);
    const [forcing, setForcing] = useState(false);
    const [assigning, setAssigning] = useState<string | null>(null);
    const [assignError, setAssignError] = useState<string | null>(null);

    const view = viewOf(sheet, ranDate, resumeFrom);

    const refresh = useCallback(() => {
        loader
            .submit()
            .then(setSheet)
            .catch(() => undefined);
        // `loader` is stable for the life of the sheet.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    useEffect(() => {
        if (open) {
            refresh();
        }
    }, [open, refresh]);

    useEffect(() => {
        if (!open || view !== 'running') {
            return;
        }

        const timer = window.setInterval(refresh, POLL_INTERVAL);

        return () => window.clearInterval(timer);
    }, [open, view, refresh]);

    const close = (next: boolean) => {
        onOpenChange(next);

        if (!next) {
            setRanDate(null);
            setResumeFrom(null);
            setForcing(false);
            setAssignError(null);
            runner.resetAndClearErrors();
        }
    };

    const start = () => {
        if (sheet?.date == null) {
            return;
        }

        const date = sheet.date;
        const failedAt = view === 'failed' ? sheet.audit?.updatedAt : null;

        runner.transform((data) => ({
            force: forcing && data.force,
            reason: data.reason,
        }));
        runner
            .post(run.url(), {
                onSuccess: () => {
                    setResumeFrom(failedAt ?? null);
                    setRanDate(date);
                },
            })
            .catch(() => undefined);
    };

    const assign = (url: string) => {
        setAssigning(url);
        setAssignError(null);
        assigner
            .post(url, {
                onSuccess: refresh,
                onError: (errors) =>
                    setAssignError(
                        errors.form ?? 'The room could not be assigned.',
                    ),
            })
            .catch(() => undefined)
            .finally(() => setAssigning(null));
    };

    const blocked = (sheet?.blockers.length ?? 0) > 0;
    const description =
        sheet?.date && sheet.nextDate
            ? `Closes ${formatBusinessDay(sheet.date, 'short')} and opens ${formatBusinessDay(sheet.nextDate, 'short')}`
            : undefined;
    const errors = {
        ...runner.errors,
        ...(assignError ? { form: assignError } : {}),
    };

    let footer: React.ReactNode;

    if (view === 'running') {
        footer = (
            <Button
                type="button"
                variant="outline"
                onClick={() => close(false)}
            >
                Close
            </Button>
        );
    } else if (view === 'completed' || view === 'none') {
        footer = (
            <Button type="button" onClick={() => close(false)}>
                Done
            </Button>
        );
    } else if (view === 'failed') {
        footer = (
            <>
                <Button
                    type="button"
                    variant="outline"
                    onClick={() => close(false)}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={runner.processing}
                    focusableWhenDisabled
                >
                    {runner.processing && <Spinner data-icon="inline-start" />}
                    Resume audit
                </Button>
            </>
        );
    }

    return (
        <FormOverlay
            variant="sheet"
            size="md"
            open={open}
            onOpenChange={close}
            title="Run Night Audit"
            description={description}
            errors={errors}
            onSubmit={start}
            submitLabel={forcing ? 'Force close' : 'Run audit'}
            submitVariant={forcing ? 'destructive' : 'default'}
            processing={runner.processing}
            submitDisabled={
                (blocked && !forcing) || (forcing && !runner.data.reason.trim())
            }
            loading={view === 'loading'}
            footer={footer}
        >
            {view === 'none' && (
                <p className="text-sm text-muted-foreground">
                    This property has no business date yet. Go live first.
                </p>
            )}

            {view === 'ready' && sheet && (
                <div className="flex flex-col gap-5">
                    {sheet.blockers.length > 0 && (
                        <section className="flex flex-col gap-1">
                            <h3 className="flex items-center gap-2 text-sm font-medium text-destructive">
                                <CircleAlert className="size-4" />
                                Fix before running
                            </h3>
                            <ul className="divide-y">
                                {sheet.blockers.map((item) => (
                                    <ItemRow
                                        key={`${item.reference}-${item.check}-${item.detail}`}
                                        item={item}
                                        assigning={assigning === item.assignUrl}
                                        onAssign={assign}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}

                    {sheet.warnings.length > 0 && (
                        <section className="flex flex-col gap-1">
                            <h3 className="flex items-center gap-2 text-sm font-medium text-warning">
                                <TriangleAlert className="size-4" />
                                Needs a look
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                These will not stop the audit.
                            </p>
                            <ul className="divide-y">
                                {sheet.warnings.map((item) => (
                                    <ItemRow
                                        key={`${item.reference}-${item.check}-${item.detail}-${item.message}`}
                                        item={item}
                                        assigning={false}
                                        onAssign={assign}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}

                    {!blocked && sheet.warnings.length === 0 && (
                        <p className="flex items-center gap-2 text-sm">
                            <CircleCheck className="size-4 text-success" />
                            Everything is ready.
                        </p>
                    )}

                    {blocked && sheet.canForce && !forcing && (
                        <Button
                            type="button"
                            variant="link"
                            className="self-start px-0"
                            onClick={() => {
                                setForcing(true);
                                runner.setData('force', true);
                            }}
                        >
                            Force close…
                        </Button>
                    )}

                    {forcing && (
                        <Field>
                            <FieldLabel htmlFor="night-audit-reason">
                                Reason
                            </FieldLabel>
                            <Textarea
                                id="night-audit-reason"
                                placeholder="Why the audit runs with a blocker open"
                                value={runner.data.reason}
                                onChange={(event) =>
                                    runner.setData('reason', event.target.value)
                                }
                                aria-invalid={!!runner.errors.reason}
                            />
                            <FieldError>{runner.errors.reason}</FieldError>
                            <Button
                                type="button"
                                variant="link"
                                className="self-start px-0"
                                onClick={() => {
                                    setForcing(false);
                                    runner.setData('force', false);
                                }}
                            >
                                Do not force
                            </Button>
                        </Field>
                    )}
                </div>
            )}

            {view === 'running' && (
                <div className="flex flex-col gap-3">
                    <p className="text-sm font-medium">Running the audit…</p>
                    {sheet?.audit ? (
                        <Steps audit={sheet.audit} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Waiting for the queue to start it.
                        </p>
                    )}
                </div>
            )}

            {view === 'completed' && sheet && (
                <div className="flex flex-col gap-2">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <CircleCheck className="size-4 text-success" />
                        Night audit completed
                    </p>
                    {sheet.date && (
                        <p className="text-sm text-muted-foreground">
                            Now trading on{' '}
                            {formatBusinessDay(sheet.date, 'short')}.
                        </p>
                    )}
                    {sheet.audit && sheet.audit.openExceptions > 0 && (
                        <p className="text-sm text-warning">
                            {sheet.audit.openExceptions} item
                            {sheet.audit.openExceptions === 1 ? '' : 's'} need a
                            look.
                        </p>
                    )}
                </div>
            )}

            {view === 'failed' && sheet?.audit && (
                <div className="flex flex-col gap-3">
                    <p className="flex items-center gap-2 text-sm font-medium text-destructive">
                        <CircleX className="size-4" />
                        The audit stopped at “
                        {sheet.audit.steps.find((s) => s.status === 'failed')
                            ?.label ?? 'a step'}
                        ”
                    </p>
                    <p className="text-sm text-muted-foreground">
                        Finished steps are kept and will not post again.
                    </p>
                    <Steps audit={sheet.audit} />
                </div>
            )}
        </FormOverlay>
    );
}
