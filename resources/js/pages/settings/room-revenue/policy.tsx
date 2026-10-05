import { Head, router, useHttp } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { MultiCombobox } from '@/components/pms/multi-combobox';
import { MoneyInput } from '@/components/pms/money-input';
import { PageHeader } from '@/components/pms/page-header';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { taxOptions } from '@/components/pms/room-revenue/tax-options';
import { RoomRevenueTabs } from '@/components/pms/room-revenue/room-revenue-tabs';
import type { RoomRevenueTab } from '@/components/pms/room-revenue/room-revenue-tabs';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
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
    children,
}: {
    id: string;
    title: string;
    description: string;
    action?: ReactNode;
    busy?: boolean;
    children: ReactNode;
}) {
    return (
        <section
            aria-labelledby={id}
            aria-busy={busy}
            className="flex w-full min-w-0 flex-col gap-4"
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
}: {
    preview: Preview;
    money: Intl.NumberFormat;
    loading: boolean;
}) {
    const amount = (value: string | null) =>
        value === null ? '—' : money.format(Number(value));

    return (
        <Section
            id="policy-preview"
            title="Preview"
            description={`A stay of ${preview.sampleNights} nights at ${money.format(Number(preview.sampleNight))} a night, with the taxes on this charge.`}
            action={loading ? <Spinner /> : null}
            busy={loading}
        >
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
        </Section>
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
    locales,
    taxes,
    roomTaxIds,
}: {
    tab: Exclude<RoomRevenueTab, 'taxes'>;
    policy: Policy;
    preview: Preview;
    currency: App.Enums.Currency;
    chargeModes: EnumOption[];
    rateBases: EnumOption[];
    scopes: EnumOption[];
    locales: EnumOption[];
    taxes: App.Data.Catalogues.ChargeItemTaxData[];
    roomTaxIds: number[];
}) {
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

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Room revenue"
                    description="How room nights and stay changes are taxed and charged."
                />

                <RoomRevenueTabs activeTab={tab}>
                    <form onSubmit={submit} className="flex flex-col gap-10">
                        <div className="grid items-start gap-10 lg:grid-cols-3">
                            <div className="lg:col-span-2">
                                <Section
                                    id="policy-charge"
                                    title="How it charges"
                                    description={heading.description}
                                >
                                    <div className="grid items-start gap-6 md:grid-cols-2">
                                        <Field>
                                            <FieldLabel>Charge</FieldLabel>
                                            <RadioGroup
                                                className="grid-cols-1"
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
                                                        <Field orientation="horizontal">
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
                                                            <RadioGroupItem
                                                                id={`charge-mode-${mode.value}`}
                                                                value={
                                                                    mode.value
                                                                }
                                                            />
                                                        </Field>
                                                    </FieldLabel>
                                                ))}
                                            </RadioGroup>
                                            <FieldError>
                                                {errors.charge_mode}
                                            </FieldError>
                                        </Field>

                                        <FieldGroup className="gap-4">
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
                                                        <FieldDescription>
                                                            The current rate is
                                                            a new price for the
                                                            same plan, room type
                                                            and dates. It falls
                                                            back to the booked
                                                            rate when none
                                                            exists.
                                                        </FieldDescription>
                                                        <FieldError>
                                                            {
                                                                errors.percent_basis
                                                            }
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
                                                        disabled={
                                                            scopes.length === 1
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
                                                        Free until (days before
                                                        arrival)
                                                    </FieldLabel>
                                                    <Input
                                                        id="free_until_days"
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
                                    </div>
                                </Section>
                            </div>

                            <Section
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
                                        <FieldContent className="flex-none">
                                            <FieldLabel htmlFor="follow_taxes">
                                                Follow the Taxes tab
                                            </FieldLabel>
                                            <FieldDescription>
                                                On: this charge uses the taxes
                                                chosen on the Taxes tab. Off:
                                                choose taxes for this charge
                                                only.
                                            </FieldDescription>
                                        </FieldContent>
                                    </Field>
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
                                            placeholder="No taxes"
                                            emptyLabel="No matching taxes."
                                            disabled={!data.own_taxes}
                                            invalid={!!errors.tax_ids}
                                        />
                                        {data.own_taxes && (
                                            <FieldDescription>
                                                Own taxes. Changing the Taxes
                                                tab does not change them.
                                            </FieldDescription>
                                        )}
                                        <FieldError>
                                            {errors.tax_ids ??
                                                errors['tax_ids.0']}
                                        </FieldError>
                                    </Field>
                                </FieldGroup>
                            </Section>
                        </div>

                        <div className="grid items-start gap-10 md:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                            <Section
                                id="policy-text"
                                title="Policy text"
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
                                                    setLanguage(
                                                        value as
                                                            | 'text_en'
                                                            | 'text_ms',
                                                    )
                                                }
                                                aria-label="Language"
                                                className="w-56"
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
                                    <FieldError>
                                        {errors.text_en ?? errors.text_ms}
                                    </FieldError>
                                </Field>
                            </Section>

                            <PolicyPreview
                                preview={preview}
                                money={money}
                                loading={http.processing}
                            />
                        </div>

                        <div className="flex justify-end">
                            <Button
                                type="submit"
                                disabled={saving || !http.isDirty}
                                focusableWhenDisabled
                            >
                                {saving && <Spinner data-icon="inline-start" />}
                                Save policy
                            </Button>
                        </div>
                    </form>
                </RoomRevenueTabs>
            </div>

            <UnsavedChangesDialog
                guard={guard}
                name={`${heading.title} policy`}
                saving={saving}
                onSave={() => save(guard.proceed)}
            />
        </>
    );
}

RoomRevenuePolicy.layout = {
    breadcrumbs: [{ title: 'Room revenue', href: index() }],
};
