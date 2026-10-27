import { Head, router, useHttp } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { FormActions } from '@/components/pms/form-actions';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { MoneyInput } from '@/components/pms/money-input';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { taxOptions } from '@/components/pms/room-revenue/tax-options';
import { RoomRevenueTabs } from '@/components/pms/room-revenue/room-revenue-tabs';
import type { RoomRevenueTab } from '@/components/pms/room-revenue/room-revenue-tabs';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardHeader,
    CardTitle,
    CardDescription,
    CardAction,
    CardContent,
} from '@/components/ui/card';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
    FieldLegend,
    FieldSet,
    FieldTitle,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupText,
    InputGroupTextarea,
} from '@/components/ui/input-group';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import { cn } from '@/lib/utils';
import {
    preview as previewRoute,
    update,
} from '@/routes/settings/room-revenue/policies';

type Policy = App.Data.Catalogues.RoomRevenuePolicyData;
type Preview = App.Data.Catalogues.RoomRevenuePolicyPreviewData;

export type PolicyEditorProps = {
    tab: Exclude<RoomRevenueTab, 'taxes' | 'day-use'>;
    policy: Policy;
    preview: Preview;
    currency: App.Data.Property.CurrencyData;
    chargeModes: EnumOption[];
    rateBases: EnumOption[];
    scopes: EnumOption[];
    locales: EnumOption[];
    taxes: App.Data.Catalogues.ChargeItemTaxData[];
    roomTaxIds: number[];
};

type PolicyForm = {
    charge_mode: App.Enums.PolicyChargeMode;
    amount: string;
    percent: string;
    percent_basis: App.Enums.PolicyRateBasis;
    applies_to: App.Enums.PolicyScope;
    free_until_days: string;
    own_taxes: boolean;
    tax_ids: number[];
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

const modeHints: Record<string, string> = {
    free: 'No charge.',
    fixed_amount: 'One amount, whatever the number of nights.',
    percentage: 'A percent of the room rate.',
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
        own_taxes: policy.ownTaxes,
        tax_ids: policy.taxIds,
        text_en: policy.textEn,
        text_ms: policy.textMs,
    };
}

function Section({
    id,
    title,
    description,
    action,
    busy,
    className,
    children,
}: {
    id: string;
    title: string;
    description: string;
    action?: ReactNode;
    busy?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={id}
            aria-busy={busy}
            className={cn('flex min-w-0 flex-col gap-4', className)}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="flex flex-col gap-1">
                    <h2 id={id} className="text-sm font-medium">
                        {title}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

function wordCount(text: string): number {
    const trimmed = text.trim();

    return trimmed === '' ? 0 : trimmed.split(/\s+/).length;
}

function PolicyPreview({
    preview,
    money,
    loading,
    failed,
    className,
}: {
    preview: Preview;
    money: Intl.NumberFormat;
    loading: boolean;
    failed: boolean;
    className?: string;
}) {
    const amount = (value: string | null) =>
        value === null ? '—' : money.format(Number(value));

    return (
        <Card
            role="region"
            aria-labelledby="policy-preview"
            aria-busy={loading}
            className={cn('min-w-0', className)}
        >
            <CardHeader>
                <CardTitle>
                    <h2 id="policy-preview">Example</h2>
                </CardTitle>
                <CardDescription>
                    A stay of {preview.sampleNights} nights at{' '}
                    {money.format(Number(preview.sampleNight))} a night.
                </CardDescription>
                {loading && (
                    <CardAction>
                        <Spinner aria-label="Updating example" />
                    </CardAction>
                )}
            </CardHeader>
            <CardContent aria-live="polite" aria-atomic="true">
                {failed ? (
                    <Alert>
                        <AlertTitle>Could not update the example</AlertTitle>
                        <AlertDescription>
                            Check the charge fields and both policy languages,
                            then edit a field to try again.
                        </AlertDescription>
                    </Alert>
                ) : loading ? (
                    <p className="text-sm text-muted-foreground">Updating…</p>
                ) : (
                    <div className="flex flex-col gap-5">
                        {preview.lines.map((line) => (
                            <div
                                key={line.label}
                                className="flex flex-col gap-4"
                            >
                                <h3 className="text-sm font-medium">
                                    {line.label}
                                </h3>
                                <ol className="flex list-inside list-decimal flex-col gap-2 text-sm text-muted-foreground tabular-nums">
                                    {line.steps.map((step) => (
                                        <li key={step}>{step}</li>
                                    ))}
                                </ol>
                                {line.free ? (
                                    <p className="text-sm">No charge</p>
                                ) : (
                                    <div className="flex flex-col gap-5">
                                        {(['local', 'foreign'] as const).map(
                                            (guest) => (
                                                <div
                                                    key={guest}
                                                    className="flex flex-col gap-2"
                                                >
                                                    <h4 className="text-sm font-medium">
                                                        {guest === 'local'
                                                            ? 'Local guest'
                                                            : 'Foreign guest'}
                                                    </h4>
                                                    <dl className="flex flex-col gap-2 text-sm">
                                                        <div className="flex flex-wrap justify-between gap-2">
                                                            <dt className="text-muted-foreground">
                                                                Charge excluding
                                                                tax
                                                            </dt>
                                                            <dd className="tabular-nums">
                                                                {amount(
                                                                    guest ===
                                                                        'local'
                                                                        ? (line
                                                                              .taxBreakdown
                                                                              ?.localNet ??
                                                                              null)
                                                                        : (line
                                                                              .taxBreakdown
                                                                              ?.foreignNet ??
                                                                              null),
                                                                )}
                                                            </dd>
                                                        </div>
                                                        {line.taxBreakdown?.lines.map(
                                                            (tax) => {
                                                                const value =
                                                                    guest ===
                                                                    'local'
                                                                        ? tax.localAmount
                                                                        : tax.foreignAmount;

                                                                return (
                                                                    <div
                                                                        key={
                                                                            tax.taxId
                                                                        }
                                                                        className="flex flex-wrap justify-between gap-2"
                                                                    >
                                                                        <dt className="text-muted-foreground">
                                                                            {
                                                                                tax.name
                                                                            }
                                                                            {tax.inclusive
                                                                                ? ' (included)'
                                                                                : ' (added)'}
                                                                        </dt>
                                                                        <dd className="tabular-nums">
                                                                            {value ===
                                                                            null
                                                                                ? 'Not applicable'
                                                                                : `+ ${amount(value)}`}
                                                                        </dd>
                                                                    </div>
                                                                );
                                                            },
                                                        )}
                                                        <div className="flex flex-wrap justify-between gap-2 font-medium">
                                                            <dt>Guest pays</dt>
                                                            <dd className="tabular-nums">
                                                                ={' '}
                                                                {amount(
                                                                    guest ===
                                                                        'local'
                                                                        ? line.localTotal
                                                                        : line.foreignTotal,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                </div>
                                            ),
                                        )}
                                    </div>
                                )}
                            </div>
                        ))}
                        {preview.lines.some(
                            (line) =>
                                !line.free &&
                                (line.localTotal === null ||
                                    line.foreignTotal === null),
                        ) && (
                            <p className="text-sm text-muted-foreground">
                                A total is unavailable because the selected
                                taxes cannot be calculated for this example.
                            </p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export function PolicyEditor({
    tab,
    policy,
    preview: savedPreview,
    currency,
    chargeModes,
    rateBases,
    scopes,
    locales,
    taxes,
    roomTaxIds,
}: PolicyEditorProps) {
    const http = useHttp<PolicyForm, Preview>(formFrom(policy));
    const guard = useUnsavedChanges(http.isDirty);
    const [preview, setPreview] = useState(savedPreview);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [language, setLanguage] = useState(locales[0]?.value ?? 'en');
    const data = http.data;
    const textField = `text_${language}` as 'text_en' | 'text_ms';
    const languageLabel =
        locales.find((locale) => locale.value === language)?.label ?? language;
    const key = JSON.stringify(data);
    const previewedKey = useRef(key);
    const [resolvedKey, setResolvedKey] = useState(key);
    const [failedKey, setFailedKey] = useState<string | null>(null);
    const previewFailed = failedKey === key;
    const previewLoading = resolvedKey !== key && !previewFailed;
    const money = new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: currency.code,
    });
    const heading = titles[tab];

    useEffect(() => {
        if (previewedKey.current === key) {
            return;
        }

        let active = true;
        const timer = window.setTimeout(() => {
            http.post(previewRoute.url({ tab }), {
                onSuccess: (response) => {
                    if (active) {
                        previewedKey.current = key;
                        setPreview(response);
                        setResolvedKey(key);
                        setFailedKey(null);
                    }
                },
            }).catch(() => {
                if (active) {
                    setFailedKey(key);
                }
            });
        }, 250);

        return () => {
            active = false;
            window.clearTimeout(timer);
            http.cancel();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [key]);

    const hasChargeDetails = data.charge_mode !== 'free';

    const followTaxes = (follow: boolean) => {
        http.setData((current) => ({
            ...current,
            own_taxes: !follow,
            tax_ids: follow ? roomTaxIds : current.tax_ids,
        }));
    };

    const save = (onSaved?: () => void) => {
        router.put(update.url({ tab }), data, {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => {
                setErrors({});
                http.setDefaults();
                onSaved?.();
            },
            onError: (next) => {
                setErrors(next);
                guard.stay();
            },
        });
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        save();
    };

    return (
        <>
            <Head title={`Room revenue · ${heading.title}`} />

            <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
                <div
                    scroll-region=""
                    className="flex min-h-0 flex-1 flex-col gap-6 overflow-y-auto p-4 pb-12"
                >
                    <PageHeader
                        title="Room revenue"
                        description="How room nights and stay changes are taxed and charged."
                    />

                    <RoomRevenueTabs activeTab={tab}>
                        <div className="grid min-w-0 grid-cols-1 items-start gap-8 lg:grid-cols-3 lg:gap-x-10">
                            <Section
                                className="lg:col-span-2"
                                id="policy-charge"
                                title={heading.title}
                                description={heading.description}
                            >
                                <FieldGroup className="gap-5">
                                    <FieldSet>
                                        <FieldLegend variant="label">
                                            Charge
                                        </FieldLegend>
                                        <RadioGroup
                                            name="charge_mode"
                                            className="grid-cols-1 sm:grid-cols-3"
                                            value={data.charge_mode}
                                            onValueChange={(value) =>
                                                http.setData(
                                                    'charge_mode',
                                                    value as App.Enums.PolicyChargeMode,
                                                )
                                            }
                                        >
                                            {chargeModes.map((mode) => (
                                                <FieldLabel
                                                    key={mode.value}
                                                    htmlFor={`charge-mode-${mode.value}`}
                                                >
                                                    <Field
                                                        orientation="horizontal"
                                                        className="h-full items-start"
                                                    >
                                                        <RadioGroupItem
                                                            id={`charge-mode-${mode.value}`}
                                                            value={mode.value}
                                                        />
                                                        <FieldContent>
                                                            <FieldTitle>
                                                                {mode.label}
                                                            </FieldTitle>
                                                            <FieldDescription>
                                                                {
                                                                    modeHints[
                                                                        mode
                                                                            .value
                                                                    ]
                                                                }
                                                            </FieldDescription>
                                                        </FieldContent>
                                                    </Field>
                                                </FieldLabel>
                                            ))}
                                        </RadioGroup>
                                        <FieldError>
                                            {errors.charge_mode}
                                        </FieldError>
                                    </FieldSet>

                                    {hasChargeDetails && (
                                        <FieldGroup className="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
                                            {data.charge_mode ===
                                                'fixed_amount' && (
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
                                                        invalid={
                                                            !!errors.amount
                                                        }
                                                    />
                                                    <FieldDescription>
                                                        One charge, whatever the
                                                        number of nights.
                                                    </FieldDescription>
                                                    <FieldError>
                                                        {errors.amount}
                                                    </FieldError>
                                                </Field>
                                            )}

                                            {data.charge_mode ===
                                                'percentage' && (
                                                <>
                                                    <Field>
                                                        <FieldLabel htmlFor="percent">
                                                            Percent
                                                        </FieldLabel>
                                                        <Input
                                                            id="percent"
                                                            name="percent"
                                                            placeholder="e.g. 50"
                                                            inputMode="decimal"
                                                            autoComplete="off"
                                                            className="tabular-nums"
                                                            value={data.percent}
                                                            onChange={(event) =>
                                                                http.setData(
                                                                    'percent',
                                                                    event.target
                                                                        .value,
                                                                )
                                                            }
                                                            aria-invalid={
                                                                !!errors.percent
                                                            }
                                                        />
                                                        {scopes.length ===
                                                            1 && (
                                                            <FieldDescription>
                                                                {tab ===
                                                                'early-departure'
                                                                    ? 'Applies to unused nights.'
                                                                    : 'Applies to one night.'}
                                                            </FieldDescription>
                                                        )}
                                                        <FieldError>
                                                            {errors.percent}
                                                        </FieldError>
                                                    </Field>
                                                    <Field>
                                                        <FieldLabel htmlFor="percent_basis">
                                                            Rate basis
                                                        </FieldLabel>
                                                        <EnumSelect
                                                            id="percent_basis"
                                                            name="percent_basis"
                                                            placeholder="Choose a rate basis…"
                                                            options={rateBases}
                                                            value={
                                                                data.percent_basis
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
                                                                http.setData(
                                                                    'percent_basis',
                                                                    value as App.Enums.PolicyRateBasis,
                                                                )
                                                            }
                                                            aria-invalid={
                                                                !!errors.percent_basis
                                                            }
                                                        />
                                                        {data.percent_basis ===
                                                            'current_rate' && (
                                                            <FieldDescription>
                                                                The current rate
                                                                is a new price
                                                                for the same
                                                                plan, room type
                                                                and dates. It
                                                                falls back to
                                                                the booked rate
                                                                when none
                                                                exists.
                                                            </FieldDescription>
                                                        )}
                                                        <FieldError>
                                                            {
                                                                errors.percent_basis
                                                            }
                                                        </FieldError>
                                                    </Field>
                                                </>
                                            )}

                                            {data.charge_mode ===
                                                'percentage' &&
                                                scopes.length > 1 && (
                                                    <Field>
                                                        <FieldLabel htmlFor="applies_to">
                                                            Applies to
                                                        </FieldLabel>
                                                        <EnumSelect
                                                            id="applies_to"
                                                            name="applies_to"
                                                            placeholder="Choose which nights…"
                                                            options={scopes}
                                                            value={
                                                                data.applies_to
                                                            }
                                                            onValueChange={(
                                                                value,
                                                            ) =>
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
                                                        Free cancellation window
                                                    </FieldLabel>
                                                    <Input
                                                        id="free_until_days"
                                                        name="free_until_days"
                                                        placeholder="e.g. 2"
                                                        type="number"
                                                        min={0}
                                                        max={365}
                                                        className="tabular-nums"
                                                        value={
                                                            data.free_until_days
                                                        }
                                                        onChange={(event) =>
                                                            http.setData(
                                                                'free_until_days',
                                                                event.target
                                                                    .value,
                                                            )
                                                        }
                                                        aria-invalid={
                                                            !!errors.free_until_days
                                                        }
                                                    />
                                                    <FieldDescription>
                                                        A cancellation this many
                                                        days or more before
                                                        arrival is free. Leave
                                                        empty to charge every
                                                        cancellation.
                                                    </FieldDescription>
                                                    <FieldError>
                                                        {errors.free_until_days}
                                                    </FieldError>
                                                </Field>
                                            )}
                                        </FieldGroup>
                                    )}
                                </FieldGroup>
                            </Section>

                            <Section
                                className="lg:col-span-2"
                                id="policy-taxes"
                                title="Taxes"
                                description="The taxes added to this charge."
                            >
                                <FieldGroup className="gap-4">
                                    <Field
                                        orientation="horizontal"
                                        className="items-start justify-start"
                                    >
                                        <Switch
                                            id="follow_taxes"
                                            checked={!data.own_taxes}
                                            onCheckedChange={followTaxes}
                                            className="mt-0.5"
                                        />
                                        <FieldContent className="min-w-0">
                                            <FieldLabel htmlFor="follow_taxes">
                                                Use room revenue taxes
                                            </FieldLabel>
                                            <FieldDescription>
                                                Turn off to choose taxes for
                                                this policy only.
                                            </FieldDescription>
                                        </FieldContent>
                                    </Field>
                                    {!data.own_taxes ? (
                                        <div className="flex flex-col gap-1 text-sm">
                                            <p>
                                                {taxes
                                                    .filter((tax) =>
                                                        roomTaxIds.includes(
                                                            tax.id,
                                                        ),
                                                    )
                                                    .map(
                                                        (tax) =>
                                                            `${tax.name}${tax.enabled ? '' : ' (off)'}`,
                                                    )
                                                    .join(' · ') || 'No taxes'}
                                            </p>
                                            <p className="text-muted-foreground">
                                                Inherited from the Taxes tab.
                                                Taxes that are off are not
                                                added.
                                            </p>
                                        </div>
                                    ) : (
                                        <Field data-invalid={!!errors.tax_ids}>
                                            <FieldLabel htmlFor="policy-taxes-select">
                                                Taxes
                                            </FieldLabel>
                                            <MultiCombobox
                                                id="policy-taxes-select"
                                                name="tax_ids"
                                                items={taxOptions(taxes, money)}
                                                value={data.tax_ids.map(String)}
                                                onValueChange={(value) =>
                                                    http.setData(
                                                        'tax_ids',
                                                        value.map(Number),
                                                    )
                                                }
                                                placeholder="Choose taxes…"
                                                emptyLabel="No matching taxes."
                                                invalid={!!errors.tax_ids}
                                            />
                                            {data.own_taxes && (
                                                <FieldDescription>
                                                    Own taxes. Changing the
                                                    Taxes tab does not change
                                                    them.
                                                </FieldDescription>
                                            )}
                                            <FieldError>
                                                {errors.tax_ids ??
                                                    errors['tax_ids.0']}
                                            </FieldError>
                                        </Field>
                                    )}
                                </FieldGroup>
                            </Section>

                            <PolicyPreview
                                className="lg:col-start-3 lg:row-span-3 lg:row-start-1"
                                preview={preview}
                                money={money}
                                loading={previewLoading}
                                failed={previewFailed}
                            />
                            <Section
                                className="lg:col-span-2"
                                id="policy-text"
                                title="Guest-facing policy"
                                description="The words guests read on their documents. Write one for each language."
                            >
                                <Field>
                                    <InputGroup>
                                        <InputGroupAddon
                                            align="block-start"
                                            className="justify-end border-b"
                                        >
                                            <EnumSelect
                                                options={locales}
                                                value={language}
                                                onValueChange={(value) =>
                                                    setLanguage(value)
                                                }
                                                name="policy_language"
                                                placeholder="Choose a language…"
                                                aria-label="Language"
                                                className="w-full sm:w-56"
                                                icon={
                                                    <Globe
                                                        aria-hidden
                                                        className="text-muted-foreground"
                                                    />
                                                }
                                            />
                                        </InputGroupAddon>
                                        <InputGroupTextarea
                                            key={textField}
                                            id={textField}
                                            name={textField}
                                            placeholder={`Enter the ${heading.title.toLowerCase()} policy in ${languageLabel}…`}
                                            rows={5}
                                            aria-label={`Policy text in ${languageLabel}`}
                                            value={data[textField]}
                                            onChange={(event) =>
                                                http.setData(
                                                    textField,
                                                    event.target.value,
                                                )
                                            }
                                            aria-invalid={!!errors[textField]}
                                        />
                                        <InputGroupAddon
                                            align="block-end"
                                            className="border-t"
                                        >
                                            <InputGroupText className="tabular-nums">
                                                {wordCount(data[textField])}{' '}
                                                words
                                            </InputGroupText>
                                            <InputGroupText className="ml-auto">
                                                Shown to the guest
                                            </InputGroupText>
                                        </InputGroupAddon>
                                    </InputGroup>
                                    <FieldDescription>
                                        This text does not update automatically
                                        when you change the charge.
                                    </FieldDescription>
                                    <FieldError>
                                        {errors.text_en &&
                                            `English: ${errors.text_en}`}
                                        {errors.text_ms &&
                                            `Malay: ${errors.text_ms}`}
                                    </FieldError>
                                </Field>
                            </Section>
                        </div>
                    </RoomRevenueTabs>
                </div>
                <FormActions fixed>
                    <div className="flex w-full flex-wrap items-center justify-between gap-3">
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            {http.isDirty ? 'Unsaved changes' : ''}
                        </p>
                        <Button
                            type="submit"
                            disabled={saving || !http.isDirty}
                            focusableWhenDisabled
                        >
                            {saving && <Spinner data-icon="inline-start" />}
                            Save policy
                        </Button>
                    </div>
                </FormActions>
            </form>

            <UnsavedChangesDialog
                guard={guard}
                name={`${heading.title} policy`}
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}
