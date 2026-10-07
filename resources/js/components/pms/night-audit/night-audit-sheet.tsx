import { Link, useHttp } from '@inertiajs/react';
import {
    ChevronRight,
    Circle,
    CircleAlert,
    CircleCheck,
    CircleX,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { Button, buttonVariants } from '@/components/ui/button';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { formatBusinessDay } from '@/lib/business-date';
import { run, show } from '@/routes/night-audit';

type Sheet = App.Data.NightAudit.NightAuditSheetData;
type Group = App.Data.NightAudit.ReadinessGroupData;
type Action = App.Data.NightAudit.ReadinessActionData;

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

type FixHandlers = {
    /** The key of the action that is asking to be confirmed or is running. */
    confirming: string | null;
    busy: string | null;
    onRun: (action: Action) => void;
    onConfirm: (key: string | null) => void;
    onNavigate: () => void;
};

const actionKey = (action: Action) => `${action.url}:${action.unitId ?? 'all'}`;

function ActionButton({
    action,
    handlers,
}: {
    action: Action;
    handlers: FixHandlers;
}) {
    const key = actionKey(action);

    if (action.kind === 'link') {
        return (
            <Link
                href={action.url}
                className={buttonVariants({ size: 'sm', variant: 'outline' })}
                onClick={handlers.onNavigate}
            >
                {action.label}
            </Link>
        );
    }

    return (
        <Button
            type="button"
            size="sm"
            variant="outline"
            disabled={handlers.busy !== null}
            focusableWhenDisabled
            onClick={() =>
                action.confirm
                    ? handlers.onConfirm(key)
                    : handlers.onRun(action)
            }
        >
            {handlers.busy === key && <Spinner data-icon="inline-start" />}
            {action.label}
        </Button>
    );
}

/** The buttons of a row. A fix that needs a yes shows its question in place of the buttons. */
function Actions({
    actions,
    handlers,
}: {
    actions: Action[];
    handlers: FixHandlers;
}) {
    const asking = actions.find(
        (action) => actionKey(action) === handlers.confirming,
    );

    if (asking) {
        return (
            <div className="flex flex-col gap-2 rounded-md bg-muted p-3">
                <p className="text-sm">{asking.confirm}</p>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        onClick={() => {
                            handlers.onConfirm(null);
                            handlers.onRun(asking);
                        }}
                    >
                        Yes, {asking.label.toLowerCase()}
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => handlers.onConfirm(null)}
                    >
                        Back
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="flex flex-wrap gap-2">
            {actions.map((action) => (
                <ActionButton
                    key={actionKey(action)}
                    action={action}
                    handlers={handlers}
                />
            ))}
        </div>
    );
}

/**
 * One reservation with one problem. A group booking is one row, and it opens to show its units. The line "If you
 * leave it" says what the audit does by itself, from the property's own policies.
 */
function GroupRow({
    group,
    handlers,
}: {
    group: Group;
    handlers: FixHandlers;
}) {
    const [open, setOpen] = useState(false);

    return (
        <li className="flex flex-col gap-2 py-3">
            <div className="flex flex-col">
                <span className="text-sm font-medium">{group.guest}</span>
                <span className="text-xs text-muted-foreground">
                    {group.reference}
                    {group.dates && ` · ${group.dates}`}
                </span>
                <span className="text-sm">{group.headline}</span>
            </div>
            {group.reasons.map((reason) => (
                <p key={reason} className="text-sm text-muted-foreground">
                    {reason}
                </p>
            ))}
            {group.consequence && (
                <p className="text-sm text-muted-foreground">
                    {group.consequence}
                </p>
            )}
            <Actions actions={group.actions} handlers={handlers} />
            {group.units.length > 0 && (
                <div className="flex flex-col gap-1">
                    <button
                        type="button"
                        className="flex items-center gap-1 self-start text-xs text-muted-foreground hover:text-foreground"
                        aria-expanded={open}
                        onClick={() => setOpen(!open)}
                    >
                        <ChevronRight
                            className={`size-3 transition-transform ${open ? 'rotate-90' : ''}`}
                        />
                        {open ? 'Hide units' : 'Treat one unit by itself'}
                    </button>
                    {open && (
                        <ul className="flex flex-col gap-2 border-l pl-3">
                            {group.units.map((unit) => (
                                <li
                                    key={unit.unitId}
                                    className="flex flex-col gap-1"
                                >
                                    <span className="text-sm">
                                        {unit.guest}
                                        <span className="text-xs text-muted-foreground">
                                            {' '}
                                            · {unit.detail}
                                        </span>
                                    </span>
                                    {unit.reason && (
                                        <span className="text-xs text-muted-foreground">
                                            {unit.reason}
                                        </span>
                                    )}
                                    <Actions
                                        actions={unit.actions}
                                        handlers={handlers}
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
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
    const fixer = useHttp<{ unit_id: number | null }, unknown>({
        unit_id: null,
    });
    const [sheet, setSheet] = useState<Sheet | null>(null);
    const [ranDate, setRanDate] = useState<string | null>(null);
    const [resumeFrom, setResumeFrom] = useState<string | null>(null);
    const [forcing, setForcing] = useState(false);
    const [confirming, setConfirming] = useState<string | null>(null);
    const [busy, setBusy] = useState<string | null>(null);
    const [fixError, setFixError] = useState<string | null>(null);

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
            setFixError(null);
            setConfirming(null);
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

    const runFix = (action: Action) => {
        setBusy(actionKey(action));
        setFixError(null);
        fixer.transform(() => ({ unit_id: action.unitId }));
        fixer
            .post(action.url, {
                onError: (errors) =>
                    setFixError(errors.form ?? 'This could not be fixed.'),
            })
            .catch(() => undefined)
            .finally(() => {
                setBusy(null);
                refresh();
            });
    };

    const handlers: FixHandlers = {
        confirming,
        busy,
        onRun: runFix,
        onConfirm: setConfirming,
        onNavigate: () => close(false),
    };

    const notEnded = sheet !== null && !sheet.dateEnded;
    const blocked = (sheet?.blockers.length ?? 0) > 0 || notEnded;
    const description =
        sheet?.date && sheet.nextDate
            ? `Closes ${formatBusinessDay(sheet.date, 'short')} and opens ${formatBusinessDay(sheet.nextDate, 'short')}`
            : undefined;
    const errors = {
        ...runner.errors,
        ...(fixError ? { form: fixError } : {}),
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
                    {notEnded && sheet.date && (
                        <section className="flex flex-col gap-1">
                            <h3 className="flex items-center gap-2 text-sm font-medium text-destructive">
                                <CircleAlert className="size-4" />
                                Not over yet
                            </h3>
                            <p className="text-sm text-muted-foreground">
                                {formatBusinessDay(sheet.date, 'short')} has not
                                ended. The audit runs by itself after midnight.
                            </p>
                        </section>
                    )}

                    {!notEnded && <p className="text-sm">{sheet.summary}</p>}

                    {sheet.blockers.length > 0 && (
                        <section className="flex flex-col">
                            <h3 className="flex items-center gap-2 text-sm font-medium text-destructive">
                                <CircleAlert className="size-4" />
                                Fix before running
                            </h3>
                            <ul className="divide-y">
                                {sheet.blockers.map((group) => (
                                    <GroupRow
                                        key={`${group.check}-${group.reservationId}`}
                                        group={group}
                                        handlers={handlers}
                                    />
                                ))}
                            </ul>
                        </section>
                    )}

                    {sheet.warnings.length > 0 && (
                        <section className="flex flex-col">
                            <h3 className="flex items-center gap-2 text-sm font-medium text-warning">
                                <TriangleAlert className="size-4" />
                                Needs a look
                            </h3>
                            <ul className="divide-y">
                                {sheet.warnings.map((group) => (
                                    <GroupRow
                                        key={`${group.check}-${group.reservationId}`}
                                        group={group}
                                        handlers={handlers}
                                    />
                                ))}
                            </ul>
                        </section>
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
                                placeholder="Why the audit runs before it is ready"
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
