import { Form, Head, Link, router } from '@inertiajs/react';
import { Check, Copy, Plus, Printer, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { AmenityDetails } from '@/components/pms/concierge/amenity-details';
import { ContactsForm } from '@/components/pms/concierge/contacts-form';
import { HotelInfoForm } from '@/components/pms/concierge/hotel-info-form';
import { InfoTopics } from '@/components/pms/concierge/info-topics';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import { Button, buttonVariants } from '@/components/ui/button';
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
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { profile } from '@/routes/settings';
import {
    amenities as amenitiesPage,
    nearbyPlaces as nearbyPlacesPage,
} from '@/routes/settings/booking-website';
import { index, update } from '@/routes/settings/concierge';
import {
    destroy as destroyFaq,
    store as storeFaq,
} from '@/routes/settings/concierge/faqs';
import {
    destroy as destroyWifi,
    store as storeWifi,
} from '@/routes/settings/concierge/wifi';
import { index as roomRevenue } from '@/routes/settings/room-revenue';

type Sections = {
    hasAddress: boolean;
    hasContact: boolean;
    amenities: number;
    nearbyPlaces: number;
};

/** Saving a list item keeps the tab and the scroll position. */
const keepPage = { preserveScroll: true, preserveState: true };

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

function Status({ ready }: { ready: boolean }) {
    return (
        <Badge variant={ready ? 'secondary' : 'outline'}>
            {ready ? 'Ready' : 'Needs attention'}
        </Badge>
    );
}

function summary(n: number, one: string, many: string): string {
    return n === 0
        ? `No ${many} yet.`
        : `${n} ${n === 1 ? one : many} shown to guests.`;
}

/** A section edited on another settings page: what guests see now, and a link to change it. */
function LinkedSection({
    title,
    body,
    ready,
    href,
    action,
}: {
    title: string;
    body: string;
    ready: boolean;
    href: string | null;
    action: string;
}) {
    return (
        <Card className="max-w-md print:hidden">
            <CardContent className="flex flex-col gap-3">
                <div className="flex items-center justify-between gap-3">
                    <h2 className="text-lg font-semibold">{title}</h2>
                    <Status ready={ready} />
                </div>
                <p className="text-sm text-pretty text-muted-foreground">
                    {body}
                </p>
                {href && (
                    <Link
                        href={href}
                        className={buttonVariants({ variant: 'outline' })}
                    >
                        {action}
                    </Link>
                )}
            </CardContent>
        </Card>
    );
}

/** The switches and house rules travel together, because one request saves all three. */
function SettingsForm({
    walkIn,
    refunds,
    children,
}: {
    walkIn: boolean;
    refunds: boolean;
    children: (state: {
        processing: boolean;
        errors: Record<string, string>;
    }) => React.ReactNode;
}) {
    return (
        <Form
            action={update.url()}
            method="put"
            options={keepPage}
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
                    {children({ processing, errors })}
                    <Button
                        type="submit"
                        className="self-end"
                        disabled={processing}
                        focusableWhenDisabled
                    >
                        {processing && <Spinner data-icon="inline-start" />}
                        Save
                    </Button>
                </>
            )}
        </Form>
    );
}

/** Staff side of the Concierge page: the lobby QR and switches, and each section of the guide guests read. */
export default function ConciergeSettings({
    lobbyUrl,
    lobbyQr,
    walkInEnabled,
    refundRequestsEnabled,
    houseRules,
    wifiNetworks,
    faqs,
    policyTerms,
    sections,
    bookingEngine,
    hotelInfo,
    contacts,
    topics,
    amenities,
}: {
    lobbyUrl: string;
    lobbyQr: string;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    houseRules: string | null;
    wifiNetworks: App.Data.Concierge.ConciergeWifiData[];
    faqs: App.Data.Concierge.ConciergeFaqData[];
    policyTerms: App.Data.Concierge.ConciergePolicyTermData[];
    sections: Sections;
    bookingEngine: boolean;
    hotelInfo: App.Data.Concierge.ConciergeHotelInfoSettingsData;
    contacts: App.Data.Concierge.ConciergeContactSettingsData;
    topics: App.Data.Concierge.ConciergeTopicData[];
    amenities: App.Data.Concierge.ConciergeAmenitySettingsData[];
}) {
    const [copied, copy] = useClipboard();
    const [walkIn, setWalkIn] = useState(walkInEnabled);
    const [refunds, setRefunds] = useState(refundRequestsEnabled);
    const [tab, setTab] = useState(
        () =>
            new URLSearchParams(window.location.search).get('tab') ??
            'overview',
    );

    const changeTab = (next: string) => {
        setTab(next);
        window.history.replaceState(
            null,
            '',
            `${window.location.pathname}?tab=${next}`,
        );
    };

    const overview = [
        {
            key: 'hotel-info',
            label: 'Hotel Info',
            ready: sections.hasAddress,
            detail: sections.hasAddress
                ? 'The address is set.'
                : 'Add the property address.',
        },
        {
            key: 'policies',
            label: 'Policies',
            ready: !!houseRules,
            detail: houseRules
                ? 'House rules are written.'
                : 'No house rules yet.',
        },
        {
            key: 'faqs',
            label: 'FAQs',
            ready: faqs.length > 0,
            detail: summary(faqs.length, 'question', 'questions'),
        },
        {
            key: 'amenities',
            label: 'Amenities',
            ready: sections.amenities > 0,
            detail: summary(sections.amenities, 'facility', 'facilities'),
        },
        {
            key: 'nearby',
            label: 'Nearby places',
            ready: sections.nearbyPlaces > 0,
            detail: summary(sections.nearbyPlaces, 'place', 'places'),
        },
        {
            key: 'wifi',
            label: 'Wi-Fi',
            ready: wifiNetworks.length > 0,
            detail: summary(wifiNetworks.length, 'network', 'networks'),
        },
        {
            key: 'contact',
            label: 'Contact',
            ready: sections.hasContact,
            detail: sections.hasContact
                ? 'A phone number or email is set.'
                : 'Add a phone number or email.',
        },
    ];
    const readyCount = overview.filter((section) => section.ready).length;

    return (
        <>
            <Head title="Concierge" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Concierge"
                    description="Guests scan the lobby QR code. They check in, read about the property, and use the stay pages on their own phone. Set what they read here."
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

                <Tabs value={tab} onValueChange={changeTab}>
                    <TabsList className="print:hidden">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="hotel-info">Hotel Info</TabsTrigger>
                        <TabsTrigger value="policies">Policies</TabsTrigger>
                        <TabsTrigger value="faqs">FAQs</TabsTrigger>
                        <TabsTrigger value="amenities">Amenities</TabsTrigger>
                        <TabsTrigger value="nearby">Nearby places</TabsTrigger>
                        <TabsTrigger value="wifi">Wi-Fi</TabsTrigger>
                        <TabsTrigger value="contact">Contact</TabsTrigger>
                        <TabsTrigger value="lobby">Lobby</TabsTrigger>
                    </TabsList>

                    <TabsContent
                        value="overview"
                        className="flex flex-col gap-4 pt-4"
                    >
                        <p className="text-sm text-muted-foreground">
                            {readyCount} of {overview.length} sections are ready
                            for guests.
                        </p>
                        <ul className="flex max-w-md flex-col gap-2">
                            {overview.map((section) => (
                                <li key={section.key}>
                                    <button
                                        type="button"
                                        onClick={() => changeTab(section.key)}
                                        className="flex w-full items-center justify-between gap-3 rounded-lg border p-3 text-left text-sm transition-colors hover:bg-muted"
                                    >
                                        <span className="flex flex-col gap-0.5">
                                            <span className="font-medium">
                                                {section.label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {section.detail}
                                            </span>
                                        </span>
                                        <Status ready={section.ready} />
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </TabsContent>

                    <TabsContent
                        value="hotel-info"
                        className="flex flex-col gap-4 pt-4"
                    >
                        <HotelInfoForm info={hotelInfo} />
                        <InfoTopics topics={topics} />
                        <LinkedSection
                            title="Property profile"
                            body="The property name, address and check-in and check-out times come from the property profile."
                            ready={sections.hasAddress}
                            href={profile.url()}
                            action="Edit property profile"
                        />
                    </TabsContent>

                    <TabsContent
                        value="policies"
                        className="flex flex-col gap-4 pt-4"
                    >
                        <Card className="max-w-md print:hidden">
                            <CardContent>
                                <SettingsForm walkIn={walkIn} refunds={refunds}>
                                    {({ errors }) => (
                                        <Field
                                            data-invalid={!!errors.house_rules}
                                        >
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
                                    )}
                                </SettingsForm>
                            </CardContent>
                        </Card>
                        <Card className="max-w-md print:hidden">
                            <CardContent className="flex flex-col gap-3">
                                <h2 className="text-lg font-semibold">
                                    Charge policies
                                </h2>
                                <p className="text-sm text-pretty text-muted-foreground">
                                    Guests read the wording of each charge
                                    policy. Change it in Room revenue.
                                </p>
                                <ul className="flex flex-col gap-2">
                                    {policyTerms.map((term) => (
                                        <li
                                            key={term.label}
                                            className="flex flex-col gap-0.5 rounded-lg border p-3 text-sm"
                                        >
                                            <span className="font-medium">
                                                {term.label}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {term.text}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                                <Link
                                    href={roomRevenue.url()}
                                    className={buttonVariants({
                                        variant: 'outline',
                                    })}
                                >
                                    Edit in Room revenue
                                </Link>
                            </CardContent>
                        </Card>
                    </TabsContent>

                    <TabsContent value="faqs" className="pt-4">
                        <Card className="max-w-md print:hidden">
                            <CardContent className="flex flex-col gap-4">
                                <div className="flex flex-col gap-1">
                                    <h2 className="text-lg font-semibold">
                                        Questions guests ask
                                    </h2>
                                    <p className="text-sm text-pretty text-muted-foreground">
                                        Shown in the property guide, before and
                                        during a stay.
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
                                                        destroyFaq.url({
                                                            faq: faq.id,
                                                        }),
                                                        keepPage,
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
                                    options={keepPage}
                                >
                                    {({ processing, errors }) => (
                                        <div className="flex flex-col gap-4">
                                            <FieldGroup>
                                                <Field
                                                    data-invalid={
                                                        !!errors.question
                                                    }
                                                >
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
                                                <Field
                                                    data-invalid={
                                                        !!errors.answer
                                                    }
                                                >
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
                    </TabsContent>

                    <TabsContent
                        value="amenities"
                        className="flex flex-col gap-4 pt-4"
                    >
                        {amenities.length > 0 && (
                            <AmenityDetails amenities={amenities} />
                        )}
                        <LinkedSection
                            title="Amenities"
                            body={`${summary(sections.amenities, 'facility', 'facilities')} The list is the one on the booking website.`}
                            ready={sections.amenities > 0}
                            href={bookingEngine ? amenitiesPage.url() : null}
                            action="Edit amenities"
                        />
                    </TabsContent>

                    <TabsContent value="nearby" className="pt-4">
                        <LinkedSection
                            title="Nearby places"
                            body={`${summary(sections.nearbyPlaces, 'place', 'places')} The list is the one on the booking website.`}
                            ready={sections.nearbyPlaces > 0}
                            href={bookingEngine ? nearbyPlacesPage.url() : null}
                            action="Edit nearby places"
                        />
                    </TabsContent>

                    <TabsContent value="wifi" className="pt-4">
                        <Card className="max-w-md print:hidden">
                            <CardContent className="flex flex-col gap-4">
                                <div className="flex flex-col gap-1">
                                    <h2 className="text-lg font-semibold">
                                        Wi-Fi
                                    </h2>
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
                                                    {network.password ??
                                                        'No password'}
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
                                                            wifiNetwork:
                                                                network.id,
                                                        }),
                                                        keepPage,
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
                                    options={keepPage}
                                >
                                    {({ processing, errors }) => (
                                        <div className="flex flex-col gap-4">
                                            <FieldGroup>
                                                <Field
                                                    data-invalid={!!errors.name}
                                                >
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
                                                <Field
                                                    data-invalid={
                                                        !!errors.password
                                                    }
                                                >
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
                                                <Field
                                                    data-invalid={!!errors.note}
                                                >
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
                    </TabsContent>

                    <TabsContent
                        value="contact"
                        className="flex flex-col gap-4 pt-4"
                    >
                        <ContactsForm contacts={contacts} />
                        <LinkedSection
                            title="Phone and email"
                            body="The phone number and email guests see on the Contact page come from the property profile."
                            ready={sections.hasContact}
                            href={profile.url()}
                            action="Edit property profile"
                        />
                    </TabsContent>

                    <TabsContent
                        value="lobby"
                        className="flex flex-col gap-4 pt-4"
                    >
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
                                    {copied === lobbyUrl
                                        ? 'Copied'
                                        : 'Copy link'}
                                </Button>
                            </CardContent>
                        </Card>
                        <Card className="max-w-md print:hidden">
                            <CardContent>
                                <SettingsForm walkIn={walkIn} refunds={refunds}>
                                    {() => (
                                        <>
                                            <input
                                                type="hidden"
                                                name="house_rules"
                                                value={houseRules ?? ''}
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
                                        </>
                                    )}
                                </SettingsForm>
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
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
