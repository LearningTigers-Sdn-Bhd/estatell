import { router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { FormOverlay } from '@/components/pms/form-overlay';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { today } from '@/components/pms/commissions/today';
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
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { cancel, start, stop } from '@/routes/admin/properties/commissions';

type Service = App.Data.Commissions.PropertyCommissionServiceData;
type Period = App.Data.Commissions.PropertyCommissionPeriodData;
type Action =
    | { kind: 'start'; service: Service }
    | { kind: 'stop'; service: Service; period: Period }
    | { kind: 'cancel'; service: Service; period: Period };

/** What the service's period means today, in words. */
function Charged({ period }: { period: Period | null }) {
    if (period === null) {
        return <span className="text-muted-foreground">Not charged</span>;
    }

    if (period.hasStarted) {
        return period.endsOn === null ? (
            <>
                Since <DateText date={period.startsOn} />
            </>
        ) : (
            <>
                Until <DateText date={period.endsOn} />
            </>
        );
    }

    return period.endsOn === null ? (
        <>
            From <DateText date={period.startsOn} />
        </>
    ) : (
        <>
            <DateText date={period.startsOn} /> –{' '}
            <DateText date={period.endsOn} />
        </>
    );
}

/** The day after a `Y-m-d` date. */
function nextDay(date: string): string {
    const day = new Date(`${date}T00:00:00Z`);
    day.setUTCDate(day.getUTCDate() + 1);

    return day.toISOString().slice(0, 10);
}

/** The earliest date the dialog offers: today, or later when the period or a stop says so. */
function earliest(action: Extract<Action, { kind: 'start' | 'stop' }>): string {
    const after =
        action.kind === 'stop'
            ? action.period.startsOn
            : action.service.period?.endsOn
              ? nextDay(action.service.period.endsOn)
              : today();

    return after > today() ? after : today();
}

type DateFormProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    code: string;
    action: Extract<Action, { kind: 'start' | 'stop' }>;
};

function DateForm({ open, onOpenChange, code, action }: DateFormProps) {
    const minimum = earliest(action);
    const starting = action.kind === 'start';
    const field = starting ? 'starts_on' : 'ends_on';
    const form = useForm({
        service: action.service.service,
        starts_on: minimum,
        ends_on: minimum,
    });
    const { data, errors } = form;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title={
                starting
                    ? `Start ${action.service.label.toLowerCase()} commission`
                    : `Stop ${action.service.label.toLowerCase()} commission`
            }
            description={
                starting
                    ? `Bookings that check out on or after this date pay ${action.service.rate ?? '0'}% commission.`
                    : 'Bookings that check out after this date are not charged. Commission already recorded stays.'
            }
            errors={errors}
            onSubmit={() => {
                const options = {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                };

                if (action.kind === 'start') {
                    form.post(start.url(code), options);
                } else {
                    form.put(
                        stop.url({
                            property: code,
                            commissionPeriod: action.period.id,
                        }),
                        options,
                    );
                }
            }}
            submitLabel={starting ? 'Start commission' : 'Stop commission'}
            processing={form.processing}
        >
            <Field data-invalid={!!errors[field]}>
                <FieldLabel htmlFor="commission_date">
                    {starting ? 'Charge from' : 'Last day charged'}
                </FieldLabel>
                <DatePicker
                    id="commission_date"
                    value={data[field]}
                    onChange={(value) => form.setData(field, value)}
                    min={minimum}
                    aria-invalid={!!errors[field]}
                />
                <FieldDescription>
                    {starting
                        ? 'Today or a later date. The service must be on for this property to have bookings.'
                        : 'Today or a later date. A booking that checks out on this day is still charged.'}
                </FieldDescription>
                <FieldError>{errors[field]}</FieldError>
            </Field>
        </FormOverlay>
    );
}

function DateDialog(props: DateFormProps) {
    return <DateForm key={useFreshOnOpen(props.open)} {...props} />;
}

function CancelDialog({
    open,
    onOpenChange,
    code,
    action,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    code: string;
    action: Extract<Action, { kind: 'cancel' }>;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>
                        Cancel {action.service.label.toLowerCase()} commission?
                    </AlertDialogTitle>
                    <AlertDialogDescription>
                        The period from{' '}
                        <DateText date={action.period.startsOn} /> is removed
                        and this property is not charged for{' '}
                        {action.service.label.toLowerCase()}.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Keep it</AlertDialogCancel>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        focusableWhenDisabled
                        onClick={() =>
                            router.delete(
                                cancel.url({
                                    property: code,
                                    commissionPeriod: action.period.id,
                                }),
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => {
                                        setProcessing(false);
                                        onOpenChange(false);
                                    },
                                },
                            )
                        }
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Cancel commission
                    </Button>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

/** The button for a service's next step: start, stop or cancel a period that has not started. */
function ServiceAction({
    service,
    onAction,
}: {
    service: Service;
    onAction: (action: Action) => void;
}) {
    const period = service.period;
    const next: Action =
        period === null || (period.hasStarted && period.endsOn !== null)
            ? { kind: 'start', service }
            : period.hasStarted
              ? { kind: 'stop', service, period }
              : { kind: 'cancel', service, period };
    const labels = { start: 'Start', stop: 'Stop', cancel: 'Cancel' };

    return (
        <Button variant="outline" size="sm" onClick={() => onAction(next)}>
            {labels[next.kind]}
        </Button>
    );
}

/**
 * The Commissions tab of the admin property page: each service's rate and charging period, and every period so far.
 * See docs/30-commission-fees/05-platform-pages.md#property-commissions-tab.
 */
export function PropertyCommissions({
    code,
    services,
    history,
}: {
    code: string;
    services: Service[];
    history: Period[];
}) {
    const [action, setAction] = useState<Action | null>(null);
    const [open, setOpen] = useState(false);

    const onAction = (next: Action) => {
        setAction(next);
        setOpen(true);
    };

    return (
        <div className="flex max-w-3xl flex-col gap-8">
            <FieldSet>
                <FieldLegend>Commissions</FieldLegend>
                <FieldDescription>
                    A booking pays commission when it checks out inside a
                    period. With no period, the service is not charged. Rates
                    are set for every property in Platform settings.
                </FieldDescription>
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Service</TableHead>
                                <TableHead className="text-right">
                                    Rate
                                </TableHead>
                                <TableHead>Charged</TableHead>
                                <TableHead className="w-0">
                                    <span className="sr-only">Actions</span>
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {services.map((service) => (
                                <TableRow key={service.service}>
                                    <TableCell className="font-medium">
                                        {service.label}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {service.rate === null
                                            ? '—'
                                            : `${service.rate}%`}
                                    </TableCell>
                                    <TableCell>
                                        <Charged period={service.period} />
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <ServiceAction
                                            service={service}
                                            onAction={onAction}
                                        />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </FieldSet>

            {history.length > 0 && (
                <FieldSet>
                    <FieldLegend>History</FieldLegend>
                    <div className="overflow-auto rounded-lg border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Service</TableHead>
                                    <TableHead>From</TableHead>
                                    <TableHead>To</TableHead>
                                    <TableHead>By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {history.map((period) => (
                                    <TableRow key={period.id}>
                                        <TableCell className="font-medium">
                                            {period.serviceLabel}
                                        </TableCell>
                                        <TableCell>
                                            <DateText date={period.startsOn} />
                                        </TableCell>
                                        <TableCell>
                                            {period.endsOn === null ? (
                                                <span className="text-muted-foreground">
                                                    Open
                                                </span>
                                            ) : (
                                                <DateText
                                                    date={period.endsOn}
                                                />
                                            )}
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {period.createdBy ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </FieldSet>
            )}

            {action !== null && action.kind !== 'cancel' && (
                <DateDialog
                    open={open}
                    onOpenChange={setOpen}
                    code={code}
                    action={action}
                />
            )}
            {action !== null && action.kind === 'cancel' && (
                <CancelDialog
                    open={open}
                    onOpenChange={setOpen}
                    code={code}
                    action={action}
                />
            )}
        </div>
    );
}
