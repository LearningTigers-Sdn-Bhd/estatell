import { Form, Head, router } from '@inertiajs/react';
import { Check, Copy, Plus, Printer, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { profile } from '@/routes/settings';
import { index, update } from '@/routes/settings/concierge';
import {
    destroy as destroyFaq,
    store as storeFaq,
} from '@/routes/settings/concierge/faqs';
import {
    destroy as destroyWifi,
    store as storeWifi,
} from '@/routes/settings/concierge/wifi';

function SwitchRow({
    id,
    title,
    body,
    checked,
    onChange,
}: {
    id: string;
    title: string;
    body: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="flex items-start justify-between gap-4">
            <div className="flex flex-col gap-1">
                <Label htmlFor={id}>{title}</Label>
                <p className="text-sm text-pretty text-muted-foreground">
                    {body}
                </p>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

/** Staff side of the Concierge page: the lobby QR, what guests may do, and the content they read. */
export default function ConciergeSettings({
    lobbyUrl,
    lobbyQr,
    walkInEnabled,
    refundRequestsEnabled,
    houseRules,
    wifiNetworks,
    faqs,
}: {
    lobbyUrl: string;
    lobbyQr: string;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    houseRules: string | null;
    wifiNetworks: App.Data.Concierge.ConciergeWifiData[];
    faqs: App.Data.Concierge.ConciergeFaqData[];
}) {
    const [copied, copy] = useClipboard();
    const [walkIn, setWalkIn] = useState(walkInEnabled);
    const [refunds, setRefunds] = useState(refundRequestsEnabled);

    return (
        <>
            <Head title="Concierge" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Concierge"
                    description="Guests scan this QR code in the lobby. They check in, read about the property, and use the stay pages on their own phone."
                    actions={
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => window.print()}
                        >
                            <Printer data-icon="inline-start" />
                            Print
                        </Button>
                    }
                />

                <Card className="max-w-md">
                    <CardContent className="flex flex-col items-center gap-4">
                        <img
                            src={lobbyQr}
                            alt={`QR code for ${lobbyUrl}`}
                            width={320}
                            height={320}
                            className="size-64 rounded-lg bg-white p-2"
                        />
                        <p className="text-center text-sm break-all text-muted-foreground">
                            {lobbyUrl}
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            className="print:hidden"
                            onClick={() => copy(lobbyUrl)}
                        >
                            {copied === lobbyUrl ? (
                                <Check data-icon="inline-start" />
                            ) : (
                                <Copy data-icon="inline-start" />
                            )}
                            {copied === lobbyUrl ? 'Copied' : 'Copy link'}
                        </Button>
                    </CardContent>
                </Card>

                <Card className="max-w-md print:hidden">
                    <CardContent>
                        <Form
                            action={update.url()}
                            method="put"
                            className="flex flex-col gap-5"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="walk_in_enabled"
                                        value={walkIn ? '1' : '0'}
                                    />
                                    <input
                                        type="hidden"
                                        name="refund_requests_enabled"
                                        value={refunds ? '1' : '0'}
                                    />
                                    <SwitchRow
                                        id="walk_in_enabled"
                                        title="Guests with no booking"
                                        body="Let a guest book a room for tonight on the lobby page. The room is held for 10 minutes and released if it is not paid for."
                                        checked={walkIn}
                                        onChange={setWalkIn}
                                    />
                                    <SwitchRow
                                        id="refund_requests_enabled"
                                        title="Refund requests"
                                        body="Let a guest ask for a refund after check-out. It reaches the Requests list for staff to answer. Nothing is refunded automatically."
                                        checked={refunds}
                                        onChange={setRefunds}
                                    />
                                    <Field data-invalid={!!errors.house_rules}>
                                        <FieldLabel htmlFor="house_rules">
                                            House rules
                                        </FieldLabel>
                                        <Textarea
                                            id="house_rules"
                                            name="house_rules"
                                            defaultValue={houseRules ?? ''}
                                            maxLength={2000}
                                            rows={5}
                                            placeholder="e.g. Quiet hours from 10 pm. No smoking in the rooms."
                                        />
                                        <FieldError>
                                            {errors.house_rules}
                                        </FieldError>
                                    </Field>
                                    <Button
                                        type="submit"
                                        className="self-end"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing && (
                                            <Spinner data-icon="inline-start" />
                                        )}
                                        Save
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Card className="max-w-md print:hidden">
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-lg font-semibold">Wi-Fi</h2>
                            <p className="text-sm text-pretty text-muted-foreground">
                                Shown only to guests who are checked in.
                            </p>
                        </div>
                        <ul className="flex flex-col gap-2">
                            {wifiNetworks.map((network) => (
                                <li
                                    key={network.id}
                                    className="flex items-center justify-between gap-3 rounded-lg border p-3 text-sm"
                                >
                                    <span className="flex flex-col">
                                        <span className="font-medium">
                                            {network.name}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {network.password ?? 'No password'}
                                            {network.note
                                                ? ` · ${network.note}`
                                                : ''}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`Remove ${network.name}`}
                                        onClick={() =>
                                            router.delete(
                                                destroyWifi.url({
                                                    wifiNetwork: network.id,
                                                }),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <Form
                            action={storeWifi.url()}
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="flex flex-col gap-4">
                                    <FieldGroup>
                                        <Field data-invalid={!!errors.name}>
                                            <FieldLabel htmlFor="wifi_name">
                                                Network name
                                            </FieldLabel>
                                            <Input
                                                id="wifi_name"
                                                name="name"
                                                required
                                                maxLength={100}
                                            />
                                            <FieldError>
                                                {errors.name}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.password}>
                                            <FieldLabel htmlFor="wifi_password">
                                                Password (optional)
                                            </FieldLabel>
                                            <Input
                                                id="wifi_password"
                                                name="password"
                                                maxLength={100}
                                                autoComplete="off"
                                            />
                                            <FieldError>
                                                {errors.password}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.note}>
                                            <FieldLabel htmlFor="wifi_note">
                                                Note (optional)
                                            </FieldLabel>
                                            <Input
                                                id="wifi_note"
                                                name="note"
                                                maxLength={200}
                                                placeholder="e.g. Lobby and rooms"
                                            />
                                            <FieldError>
                                                {errors.note}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="self-end"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Plus data-icon="inline-start" />
                                        )}
                                        Add network
                                    </Button>
                                </div>
                            )}
                        </Form>
                    </CardContent>
                </Card>

                <Card className="max-w-md print:hidden">
                    <CardContent className="flex flex-col gap-4">
                        <div className="flex flex-col gap-1">
                            <h2 className="text-lg font-semibold">
                                Questions guests ask
                            </h2>
                            <p className="text-sm text-pretty text-muted-foreground">
                                Shown in the property guide, before and during a
                                stay.
                            </p>
                        </div>
                        <ul className="flex flex-col gap-2">
                            {faqs.map((faq) => (
                                <li
                                    key={faq.id}
                                    className="flex items-start justify-between gap-3 rounded-lg border p-3 text-sm"
                                >
                                    <span className="flex flex-col gap-1">
                                        <span className="font-medium">
                                            {faq.question}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {faq.answer}
                                        </span>
                                    </span>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon-sm"
                                        aria-label={`Remove question: ${faq.question}`}
                                        onClick={() =>
                                            router.delete(
                                                destroyFaq.url({ faq: faq.id }),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <Form
                            action={storeFaq.url()}
                            method="post"
                            resetOnSuccess
                            options={{ preserveScroll: true }}
                        >
                            {({ processing, errors }) => (
                                <div className="flex flex-col gap-4">
                                    <FieldGroup>
                                        <Field data-invalid={!!errors.question}>
                                            <FieldLabel htmlFor="faq_question">
                                                Question
                                            </FieldLabel>
                                            <Input
                                                id="faq_question"
                                                name="question"
                                                required
                                                maxLength={200}
                                                placeholder="e.g. Is breakfast included?"
                                            />
                                            <FieldError>
                                                {errors.question}
                                            </FieldError>
                                        </Field>
                                        <Field data-invalid={!!errors.answer}>
                                            <FieldLabel htmlFor="faq_answer">
                                                Answer
                                            </FieldLabel>
                                            <Textarea
                                                id="faq_answer"
                                                name="answer"
                                                required
                                                maxLength={1000}
                                            />
                                            <FieldError>
                                                {errors.answer}
                                            </FieldError>
                                        </Field>
                                    </FieldGroup>
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="self-end"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <Plus data-icon="inline-start" />
                                        )}
                                        Add question
                                    </Button>
                                </div>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ConciergeSettings.layout = {
    breadcrumbs: [
        { title: 'Settings', href: profile() },
        { title: 'Concierge', href: index() },
    ],
};
