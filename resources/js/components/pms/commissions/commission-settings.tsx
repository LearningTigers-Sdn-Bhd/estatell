import { useForm } from '@inertiajs/react';
import { Percent, Plus } from 'lucide-react';
import { useState } from 'react';
import { DatePicker } from '@/components/pms/date-picker';
import { DateText } from '@/components/pms/date-text';
import { EnumSelect } from '@/components/pms/enum-select';
import { FormOverlay } from '@/components/pms/form-overlay';
import { StatusBadge } from '@/components/pms/status-badge';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { update } from '@/routes/admin/settings/commissions';
import { store } from '@/routes/admin/settings/commissions/rates';

type Option = { value: string; label: string };

/** Today as `Y-m-d` in the browser's time zone, the earliest start date a form offers. */
function today(): string {
    const now = new Date();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const day = String(now.getDate()).padStart(2, '0');

    return `${now.getFullYear()}-${month}-${day}`;
}

type ChangeRateProps = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    services: Option[];
};

function ChangeRateForm({ open, onOpenChange, services }: ChangeRateProps) {
    const form = useForm({
        service: services[0]?.value ?? '',
        rate: '',
        effective_from: '',
    });
    const { data, errors } = form;

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Change rate"
            description="The new rate applies to bookings made on or after its start date. Past bookings keep their rate."
            errors={errors}
            onSubmit={() =>
                form.post(store.url(), {
                    preserveScroll: true,
                    onSuccess: () => onOpenChange(false),
                })
            }
            submitLabel="Save rate"
            processing={form.processing}
        >
            <Field data-invalid={!!errors.service}>
                <FieldLabel htmlFor="service">Service</FieldLabel>
                <EnumSelect
                    id="service"
                    options={services}
                    value={data.service}
                    onValueChange={(value) => form.setData('service', value)}
                />
                <FieldError>{errors.service}</FieldError>
            </Field>
            <div className="grid grid-cols-2 gap-4">
                <Field data-invalid={!!errors.rate}>
                    <FieldLabel htmlFor="rate">Rate</FieldLabel>
                    <InputGroup>
                        <InputGroupInput
                            id="rate"
                            inputMode="decimal"
                            value={data.rate}
                            onChange={(event) =>
                                form.setData('rate', event.target.value)
                            }
                            placeholder="e.g. 10"
                            autoComplete="off"
                            aria-invalid={!!errors.rate}
                        />
                        <InputGroupAddon align="inline-end">
                            <Percent aria-hidden />
                        </InputGroupAddon>
                    </InputGroup>
                    <FieldError>{errors.rate}</FieldError>
                </Field>
                <Field data-invalid={!!errors.effective_from}>
                    <FieldLabel htmlFor="effective_from">From</FieldLabel>
                    <DatePicker
                        id="effective_from"
                        value={data.effective_from}
                        onChange={(value) =>
                            form.setData('effective_from', value)
                        }
                        min={today()}
                        placeholder="e.g. 1 Jan 2027"
                        aria-invalid={!!errors.effective_from}
                    />
                    <FieldError>{errors.effective_from}</FieldError>
                </Field>
            </div>
        </FormOverlay>
    );
}

function ChangeRateDialog(props: ChangeRateProps) {
    return <ChangeRateForm key={useFreshOnOpen(props.open)} {...props} />;
}

function ChannelManagerStartForm({ value }: { value: string | null }) {
    const form = useForm({ channel_manager_commission_from: value ?? '' });
    const error = form.errors.channel_manager_commission_from;

    return (
        <form
            className="flex flex-col gap-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.put(update.url(), { preserveScroll: true });
            }}
        >
            <FieldSet>
                <FieldLegend>Channel manager commission start</FieldLegend>
                <FieldDescription>
                    Only bookings that check out on or after this date pay
                    channel manager commission. With no date, no channel manager
                    commission is charged.
                </FieldDescription>
                <Field data-invalid={!!error} className="max-w-xs">
                    <FieldLabel htmlFor="channel_manager_commission_from">
                        Start date
                    </FieldLabel>
                    <DatePicker
                        id="channel_manager_commission_from"
                        value={form.data.channel_manager_commission_from}
                        onChange={(next) =>
                            form.setData(
                                'channel_manager_commission_from',
                                next,
                            )
                        }
                        min={today()}
                        placeholder="Not set"
                        aria-invalid={!!error}
                    />
                    <FieldError>{error}</FieldError>
                </Field>
            </FieldSet>
            <div className="flex justify-end">
                <Button
                    type="submit"
                    disabled={form.processing || !form.isDirty}
                    focusableWhenDisabled
                >
                    {form.processing && <Spinner />}
                    Save changes
                </Button>
            </div>
        </form>
    );
}

/**
 * The Commissions tab of platform settings: every rate with its start date, and the channel manager commission start
 * date. See docs/30-commission-fees/05-platform-pages.md#commission-rates.
 */
export function CommissionSettings({
    rates,
    services,
    channelManagerCommissionFrom,
}: {
    rates: App.Data.Commissions.CommissionRateData[];
    services: Option[];
    channelManagerCommissionFrom: string | null;
}) {
    const [changing, setChanging] = useState(false);

    return (
        <div className="flex max-w-3xl flex-col gap-8">
            <FieldSet>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex flex-col gap-1">
                        <FieldLegend>Commission rates</FieldLegend>
                        <FieldDescription>
                            One rate per service for every property, charged on
                            the amount before tax.
                        </FieldDescription>
                    </div>
                    <Button variant="outline" onClick={() => setChanging(true)}>
                        <Plus aria-hidden />
                        Change rate
                    </Button>
                </div>
                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Service</TableHead>
                                <TableHead className="text-right">
                                    Rate
                                </TableHead>
                                <TableHead>From</TableHead>
                                <TableHead>Status</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rates.map((rate) => (
                                <TableRow key={rate.id}>
                                    <TableCell className="font-medium">
                                        {rate.serviceLabel}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {rate.rate}%
                                    </TableCell>
                                    <TableCell>
                                        <DateText date={rate.effectiveFrom} />
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge status={rate.status} />
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </FieldSet>

            <ChannelManagerStartForm value={channelManagerCommissionFrom} />

            <ChangeRateDialog
                open={changing}
                onOpenChange={setChanging}
                services={services}
            />
        </div>
    );
}
