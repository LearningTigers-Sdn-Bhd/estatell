import { Head, Link, useForm, useHttp } from '@inertiajs/react';
import { CircleCheck, CircleX } from 'lucide-react';
import { useState } from 'react';
import { ConnectRail } from '@/components/pms/channels/connect-rail';
import { FormActions } from '@/components/pms/form-actions';
import { PageHeader } from '@/components/pms/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button, buttonVariants } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { dashboard } from '@/routes/admin';
import {
    index,
    channels as channelsTab,
    show,
} from '@/routes/admin/properties';
import { test } from '@/routes/admin/properties/channels';
import { store } from '@/routes/admin/properties/channels/connections';

type Connect = App.Data.Channels.ChannelConnectPageData;
type Field = App.Data.Channels.ChannelConnectFieldData;
type Values = Record<string, string | boolean | number | null>;
type TestResult = { ok: boolean; message: string };

const STEPS = ['Connect', 'Map', 'Activate'];

export default function ChannelConnect({
    property,
    connect,
}: {
    property: { code: string; name: string };
    connect: Connect;
}) {
    const form = useForm<{ ota_channel_id: number; settings: Values }>({
        ota_channel_id: connect.otaChannelId,
        settings: connect.values,
    });
    const check = useHttp<{ settings: Values }, TestResult>(
        test({ property: property.code, otaChannel: connect.otaChannelId }),
        { settings: connect.values },
    );
    const [result, setResult] = useState<TestResult | null>(null);
    const values = form.data.settings;
    const hotelId = String(values.hotel_id ?? '');

    const setValue = (name: string, value: string | boolean) => {
        setResult(null);
        form.setData('settings', { ...values, [name]: value });
    };

    const runTest = () => {
        setResult(null);
        check.transform(() => ({ settings: values }));
        check
            .submit()
            .then(setResult)
            .catch(() =>
                setResult({
                    ok: false,
                    message:
                        'The test could not run. Check the hotel ID and try again.',
                }),
            );
    };

    const isHidden = (field: Field) =>
        field.hiddenWhenField !== null &&
        values[field.hiddenWhenField] === field.hiddenWhenValue;

    return (
        <>
            <Head title={`Connect ${connect.otaName}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={`Connect ${connect.otaName}`}
                    description={property.name}
                    back={{
                        href: channelsTab(property.code),
                        label: 'Channels',
                    }}
                    badge={
                        <span className="text-sm text-muted-foreground">
                            {STEPS.map((step, position) =>
                                position === 0 ? (
                                    <strong
                                        key={step}
                                        className="text-foreground"
                                    >
                                        1 {step}
                                    </strong>
                                ) : (
                                    <span key={step}>
                                        {' · '}
                                        {position + 1} {step}
                                    </span>
                                ),
                            )}
                        </span>
                    }
                />

                <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <form
                        className="flex flex-col gap-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(store(property.code).url);
                        }}
                    >
                        <Card>
                            <CardContent className="flex flex-col gap-6">
                                <FieldGroup>
                                    {connect.fields.map((field) =>
                                        isHidden(field) ? null : (
                                            <ConnectField
                                                key={field.name}
                                                field={field}
                                                value={values[field.name]}
                                                error={
                                                    (
                                                        form.errors as Record<
                                                            string,
                                                            string | undefined
                                                        >
                                                    )[`settings.${field.name}`]
                                                }
                                                onChange={(value) =>
                                                    setValue(field.name, value)
                                                }
                                            />
                                        ),
                                    )}
                                </FieldGroup>

                                <p className="text-sm text-muted-foreground">
                                    Before testing: sign in to the{' '}
                                    {connect.otaName} extranet with the login in
                                    the rail, and choose Channex as the
                                    connectivity provider.
                                </p>

                                <div className="flex flex-col items-start gap-3">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={
                                            check.processing ||
                                            hotelId.trim() === ''
                                        }
                                        focusableWhenDisabled
                                        onClick={runTest}
                                    >
                                        {check.processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Test connection
                                    </Button>
                                    {result && (
                                        <Alert
                                            variant={
                                                result.ok
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {result.ok ? (
                                                <CircleCheck />
                                            ) : (
                                                <CircleX />
                                            )}
                                            <AlertDescription>
                                                {result.message}
                                            </AlertDescription>
                                        </Alert>
                                    )}
                                </div>
                            </CardContent>
                        </Card>

                        <FormActions>
                            <Link
                                href={channelsTab(property.code)}
                                className={buttonVariants({
                                    variant: 'outline',
                                })}
                            >
                                Cancel
                            </Link>
                            <Button
                                type="submit"
                                disabled={
                                    form.processing || hotelId.trim() === ''
                                }
                                focusableWhenDisabled
                            >
                                {form.processing && (
                                    <Spinner data-icon="inline-start" />
                                )}
                                Save connection
                            </Button>
                        </FormActions>
                    </form>

                    <ConnectRail
                        code={property.code}
                        connect={connect}
                        hotelId={hotelId}
                    />
                </div>
            </div>
        </>
    );
}

function ConnectField({
    field,
    value,
    error,
    onChange,
}: {
    field: Field;
    value: string | boolean | number | null | undefined;
    error: string | undefined;
    onChange: (value: string | boolean) => void;
}) {
    const id = `connect-${field.name}`;

    if (field.type === 'boolean') {
        return (
            <Field orientation="horizontal">
                <Switch
                    id={id}
                    name={field.name}
                    checked={value === true}
                    onCheckedChange={onChange}
                />
                <div className="flex flex-col gap-1">
                    <FieldLabel htmlFor={id}>{field.label}</FieldLabel>
                    {field.hint && (
                        <FieldDescription>{field.hint}</FieldDescription>
                    )}
                </div>
            </Field>
        );
    }

    if (field.type === 'select') {
        const items = field.options.map((option) => ({
            value: option,
            label: option,
        }));

        return (
            <Field>
                <FieldLabel htmlFor={id}>{field.label}</FieldLabel>
                <Select
                    name={field.name}
                    items={items}
                    value={String(value ?? field.default ?? '')}
                    onValueChange={(next) => onChange(next as string)}
                >
                    <SelectTrigger id={id} className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectGroup>
                            {items.map((item) => (
                                <SelectItem key={item.value} value={item.value}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    </SelectContent>
                </Select>
                {field.hint && (
                    <FieldDescription>{field.hint}</FieldDescription>
                )}
                <FieldError>{error}</FieldError>
            </Field>
        );
    }

    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor={id}>
                {field.label}
                {field.required && (
                    <span aria-hidden className="text-destructive">
                        *
                    </span>
                )}
            </FieldLabel>
            <Input
                id={id}
                name={field.name}
                value={String(value ?? '')}
                placeholder={field.placeholder ?? undefined}
                aria-invalid={!!error}
                onChange={(event) => onChange(event.target.value)}
            />
            {field.hint && <FieldDescription>{field.hint}</FieldDescription>}
            <FieldError>{error}</FieldError>
        </Field>
    );
}

ChannelConnect.layout = (props: {
    property: { code: string; name: string };
    connect: Connect;
}) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Properties', href: index() },
        { title: props.property.name, href: show(props.property.code) },
        { title: 'Channels', href: channelsTab(props.property.code) },
        { title: `Connect ${props.connect.otaName}` },
    ],
});
