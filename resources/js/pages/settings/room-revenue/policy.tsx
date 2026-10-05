import { Head, router, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MoneyInput } from '@/components/pms/money-input';
import { PageHeader } from '@/components/pms/page-header';
import { RoomRevenueTabs } from '@/components/pms/room-revenue/room-revenue-tabs';
import type { RoomRevenueTab } from '@/components/pms/room-revenue/room-revenue-tabs';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { index } from '@/routes/settings/room-revenue';
import {
    preview as previewRoute,
    update,
} from '@/routes/settings/room-revenue/policies';

type Policy = App.Data.Catalogues.RoomRevenuePolicyData;
type Preview = App.Data.Catalogues.RoomRevenuePolicyPreviewData;

type PolicyForm = {
    charge_mode: App.Enums.PolicyChargeMode;
    amount: string;
    percent: string;
    percent_basis: App.Enums.PolicyRateBasis;
    applies_to: App.Enums.PolicyScope;
    free_until_days: string;
    text_en: string;
    text_ms: string;
};

const titles: Record<string, { title: string; description: string }> = {
    'no-show': {
        title: 'No-show',
        description: 'What a guest pays when they do not arrive.',
    },
    cancellation: {
        title: 'Cancellation',
        description: 'What a guest pays when a booking is cancelled.',
    },
    'early-departure': {
        title: 'Early departure',
        description:
            'What a guest pays when they leave before the booked date.',
    },
    'late-checkout': {
        title: 'Late check-out',
        description: 'What a guest pays to stay past the check-out time.',
    },
};

function formFrom(policy: Policy): PolicyForm {
    return {
        charge_mode: policy.chargeMode,
        amount: policy.amount ?? '',
        percent: policy.percent ?? '',
        percent_basis: policy.percentBasis ?? 'booked_rate',
        applies_to: policy.appliesTo,
        free_until_days:
            policy.freeUntilDays === null ? '' : String(policy.freeUntilDays),
        text_en: policy.textEn,
        text_ms: policy.textMs,
    };
}

function PolicyPreview({
    preview,
    money,
    loading,
}: {
    preview: Preview;
    money: Intl.NumberFormat;
    loading: boolean;
}) {
    const amount = (value: string | null) =>
        value === null ? '—' : money.format(Number(value));

    return (
        <section
            aria-labelledby="policy-preview"
            aria-busy={loading}
            className="flex w-full min-w-0 flex-col gap-3 lg:col-span-2"
        >
            <div className="flex items-center justify-between gap-2">
                <div className="flex flex-col gap-1">
                    <h2 id="policy-preview" className="text-sm font-medium">
                        Preview
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        A stay of {preview.sampleNights} nights at{' '}
                        {money.format(Number(preview.sampleNight))} a night,
                        with the taxes on this charge.
                    </p>
                </div>
                {loading && <Spinner />}
            </div>
            <div className="overflow-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Case</TableHead>
                            <TableHead className="text-right">Charge</TableHead>
                            <TableHead className="text-right">
                                Malaysian guest pays
                            </TableHead>
                            <TableHead className="text-right">
                                Foreign guest pays
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {preview.lines.map((line) => (
                            <TableRow key={line.label}>
                                <TableCell>{line.label}</TableCell>
                                {line.free ? (
                                    <TableCell
                                        colSpan={3}
                                        className="text-right text-muted-foreground"
                                    >
                                        Free
                                    </TableCell>
                                ) : (
                                    <>
                                        <TableCell className="text-right tabular-nums">
                                            {amount(line.net)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {amount(line.localTotal)}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {amount(line.foreignTotal)}
                                        </TableCell>
                                    </>
                                )}
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

export default function RoomRevenuePolicy({
    tab,
    policy,
    preview: savedPreview,
    currency,
    chargeModes,
    rateBases,
    scopes,
}: {
    tab: Exclude<RoomRevenueTab, 'taxes'>;
    policy: Policy;
    preview: Preview;
    currency: App.Enums.Currency;
    chargeModes: EnumOption[];
    rateBases: EnumOption[];
    scopes: EnumOption[];
}) {
    const http = useHttp<PolicyForm, Preview>(formFrom(policy));
    const [preview, setPreview] = useState(savedPreview);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const data = http.data;
    const key = JSON.stringify(data);
    const previewedKey = useRef(key);
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency,
    });
    const heading = titles[tab];

    useEffect(() => {
        if (previewedKey.current === key) {
            return;
        }

        const timer = window.setTimeout(() => {
            previewedKey.current = key;
            http.post(previewRoute.url({ tab }), {
                onSuccess: (response) => setPreview(response),
            }).catch(() => undefined);
        }, 250);

        return () => window.clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        router.put(update.url({ tab }), data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => setErrors({}),
            onError: (next) => setErrors(next),
        });
    };

    return (
        <>
            <Head title={`Room revenue · ${heading.title}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room revenue"
                    description="How room nights and stay changes are taxed and charged."
                />

                <RoomRevenueTabs activeTab={tab}>
                    <form onSubmit={submit} className="flex flex-col gap-6">
                        <div className="grid items-start gap-8 lg:w-4/5 lg:grid-cols-3">
                            <FieldSet className="min-w-0">
                                <FieldLegend variant="label">
                                    {heading.title} policy
                                </FieldLegend>
                                <FieldDescription>
                                    {heading.description}
                                </FieldDescription>
                                <FieldGroup className="gap-4">
                                    <Field>
                                        <FieldLabel>Charge</FieldLabel>
                                        <RadioGroup
                                            value={data.charge_mode}
                                            onValueChange={(value) =>
                                                http.setData(
                                                    'charge_mode',
                                                    value as App.Enums.PolicyChargeMode,
                                                )
                                            }
                                        >
                                            {chargeModes.map((mode) => (
                                                <Field
                                                    key={mode.value}
                                                    orientation="horizontal"
                                                >
                                                    <RadioGroupItem
                                                        id={`charge-mode-${mode.value}`}
                                                        value={mode.value}
                                                    />
                                                    <FieldLabel
                                                        htmlFor={`charge-mode-${mode.value}`}
                                                    >
                                                        {mode.label}
                                                    </FieldLabel>
                                                </Field>
                                            ))}
                                        </RadioGroup>
                                        <FieldError>
                                            {errors.charge_mode}
                                        </FieldError>
                                    </Field>

                                    {data.charge_mode === 'fixed_amount' && (
                                        <Field>
                                            <FieldLabel htmlFor="amount">
                                                Amount
                                            </FieldLabel>
                                            <MoneyInput
                                                id="amount"
                                                value={data.amount}
                                                onChange={(value) =>
                                                    http.setData(
                                                        'amount',
                                                        value,
                                                    )
                                                }
                                                currency={currency}
                                                invalid={!!errors.amount}
                                            />
                                            <FieldDescription>
                                                One charge, whatever the number
                                                of nights.
                                            </FieldDescription>
                                            <FieldError>
                                                {errors.amount}
                                            </FieldError>
                                        </Field>
                                    )}

                                    {data.charge_mode === 'percentage' && (
                                        <>
                                            <Field>
                                                <FieldLabel htmlFor="percent">
                                                    Percent
                                                </FieldLabel>
                                                <Input
                                                    id="percent"
                                                    inputMode="decimal"
                                                    autoComplete="off"
                                                    className="tabular-nums"
                                                    value={data.percent}
                                                    onChange={(event) =>
                                                        http.setData(
                                                            'percent',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.percent
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.percent}
                                                </FieldError>
                                            </Field>
                                            <Field>
                                                <FieldLabel htmlFor="percent_basis">
                                                    Percent of the
                                                </FieldLabel>
                                                <EnumSelect
                                                    id="percent_basis"
                                                    options={rateBases}
                                                    value={data.percent_basis}
                                                    onValueChange={(value) =>
                                                        http.setData(
                                                            'percent_basis',
                                                            value as App.Enums.PolicyRateBasis,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.percent_basis
                                                    }
                                                />
                                                <FieldDescription>
                                                    The current rate is a new
                                                    price for the same plan,
                                                    room type and dates. It
                                                    falls back to the booked
                                                    rate when none exists.
                                                </FieldDescription>
                                                <FieldError>
                                                    {errors.percent_basis}
                                                </FieldError>
                                            </Field>
                                        </>
                                    )}

                                    {data.charge_mode !== 'free' && (
                                        <Field>
                                            <FieldLabel htmlFor="applies_to">
                                                Applies to
                                            </FieldLabel>
                                            <EnumSelect
                                                id="applies_to"
                                                options={scopes}
                                                value={data.applies_to}
                                                disabled={scopes.length === 1}
                                                onValueChange={(value) =>
                                                    http.setData(
                                                        'applies_to',
                                                        value as App.Enums.PolicyScope,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.applies_to
                                                }
                                            />
                                            <FieldError>
                                                {errors.applies_to}
                                            </FieldError>
                                        </Field>
                                    )}

                                    {tab === 'cancellation' && (
                                        <Field>
                                            <FieldLabel htmlFor="free_until_days">
                                                Free until (days before arrival)
                                            </FieldLabel>
                                            <Input
                                                id="free_until_days"
                                                type="number"
                                                min={0}
                                                max={365}
                                                className="tabular-nums"
                                                value={data.free_until_days}
                                                onChange={(event) =>
                                                    http.setData(
                                                        'free_until_days',
                                                        event.target.value,
                                                    )
                                                }
                                                aria-invalid={
                                                    !!errors.free_until_days
                                                }
                                            />
                                            <FieldDescription>
                                                A cancellation this many days or
                                                more before arrival is free.
                                                Leave empty to charge every
                                                cancellation.
                                            </FieldDescription>
                                            <FieldError>
                                                {errors.free_until_days}
                                            </FieldError>
                                        </Field>
                                    )}

                                    <Field>
                                        <FieldLabel>Policy text</FieldLabel>
                                        <Tabs defaultValue="en">
                                            <TabsList variant="line">
                                                <TabsTrigger value="en">
                                                    English
                                                </TabsTrigger>
                                                <TabsTrigger value="ms">
                                                    Malay
                                                </TabsTrigger>
                                            </TabsList>
                                            <TabsContent value="en">
                                                <Textarea
                                                    id="text_en"
                                                    aria-label="Policy text in English"
                                                    value={data.text_en}
                                                    onChange={(event) =>
                                                        http.setData(
                                                            'text_en',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.text_en
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.text_en}
                                                </FieldError>
                                            </TabsContent>
                                            <TabsContent value="ms">
                                                <Textarea
                                                    id="text_ms"
                                                    aria-label="Policy text in Malay"
                                                    value={data.text_ms}
                                                    onChange={(event) =>
                                                        http.setData(
                                                            'text_ms',
                                                            event.target.value,
                                                        )
                                                    }
                                                    aria-invalid={
                                                        !!errors.text_ms
                                                    }
                                                />
                                                <FieldError>
                                                    {errors.text_ms}
                                                </FieldError>
                                            </TabsContent>
                                        </Tabs>
                                        <FieldDescription>
                                            Shown to guests on documents.
                                        </FieldDescription>
                                    </Field>
                                </FieldGroup>
                            </FieldSet>

                            <PolicyPreview
                                preview={preview}
                                money={money}
                                loading={http.processing}
                            />
                        </div>
                        <div className="flex justify-end lg:w-4/5">
                            <Button
                                type="submit"
                                disabled={saving}
                                focusableWhenDisabled
                            >
                                {saving && <Spinner data-icon="inline-start" />}
                                Save policy
                            </Button>
                        </div>
                    </form>
                </RoomRevenueTabs>
            </div>
        </>
    );
}

RoomRevenuePolicy.layout = {
    breadcrumbs: [{ title: 'Room revenue', href: index() }],
};
