import { Form, Head, Link } from '@inertiajs/react';
import {
    Check,
    CloudOff,
    Copy,
    ExternalLink,
    Globe,
    ListChecks,
    Save,
    X,
} from 'lucide-react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import {
    BookingWebsiteShell,
    bookingWebsiteBreadcrumbs,
} from '@/components/pms/booking-website/booking-website-shell';
import { PaymentModeSection } from '@/components/pms/booking-website/payment-mode-section';
import { PublishSiteButton } from '@/components/pms/booking-website/publish-site-button';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import {
    InputGroup,
    InputGroupAddon,
    InputGroupButton,
    InputGroupInput,
    InputGroupText,
} from '@/components/ui/input-group';
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';
import { profile } from '@/routes/settings';

export default function BookingWebsiteGeneral({
    general,
    canChangePaymentMode,
}: {
    general: App.Data.BookingEngine.BookingWebsiteGeneralData;
    canChangePaymentMode: boolean;
}) {
    const [, copy] = useClipboard();
    const apiOnly = general.mode === 'api-only';

    async function copyAddress() {
        if (await copy(general.webAddress)) {
            toast.add({ title: 'Link copied.', type: 'success' });
        }
    }

    return (
        <>
            <Head title="Booking website" />
            <BookingWebsiteShell
                activeTab="general"
                asideLabel="Before you publish"
                aside={
                    <div className="flex flex-col gap-4">
                        <SectionHeader
                            title="Before you publish"
                            icon={ListChecks}
                            description={
                                general.checklist.length === 0
                                    ? 'Nothing is needed yet.'
                                    : 'Finish every step to go online.'
                            }
                        />
                        <ul className="flex flex-col gap-2">
                            {general.checklist.map((item) => (
                                <li
                                    key={item.key}
                                    className="flex items-start gap-3 text-sm"
                                >
                                    <span className="flex w-8 shrink-0 justify-center">
                                        {item.done ? (
                                            <span className="flex size-5 items-center justify-center rounded-full bg-success/10 text-success">
                                                <Check
                                                    aria-hidden
                                                    className="size-3"
                                                />
                                            </span>
                                        ) : (
                                            <span className="flex size-5 items-center justify-center rounded-full bg-muted text-muted-foreground">
                                                <X
                                                    aria-hidden
                                                    className="size-3"
                                                />
                                            </span>
                                        )}
                                    </span>
                                    <span className="flex flex-col">
                                        <span>{item.label}</span>
                                        {item.detail && (
                                            <span className="text-xs text-muted-foreground">
                                                {item.detail}
                                            </span>
                                        )}
                                    </span>
                                </li>
                            ))}
                        </ul>

                        {general.published ? (
                            <Form
                                {...BookingWebsiteController.unpublish.form()}
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        className="w-full"
                                        disabled={processing}
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <CloudOff data-icon="inline-start" />
                                        )}
                                        Unpublish
                                    </Button>
                                )}
                            </Form>
                        ) : (
                            <PublishSiteButton
                                label={apiOnly ? 'Go live' : 'Publish'}
                                canPublish={general.canPublish}
                                testPayments={general.paymentMode === 'test'}
                            />
                        )}
                    </div>
                }
            >
                <div className="flex flex-col gap-6 pb-2">
                    <SettingsSection
                        headerClassName="ps-3 pt-2"
                        submitInHeader
                        submitIcon={Save}
                        guardUnsavedChanges
                        form={BookingWebsiteController.updateGeneral.form()}
                        title="Web address"
                        description="Where guests find your booking website."
                        contentClassName="grid gap-5"
                    >
                        {(errors) => (
                            <>
                                {general.slugLocked && (
                                    <input
                                        type="hidden"
                                        name="slug"
                                        value={general.slug}
                                    />
                                )}
                                <Field data-invalid={!!errors.slug}>
                                    <FieldLabel htmlFor="slug">
                                        Web address
                                    </FieldLabel>
                                    <InputGroup>
                                        <InputGroupAddon>
                                            <Globe aria-hidden />
                                            <InputGroupText className="whitespace-nowrap max-sm:hidden">
                                                {general.baseUrl}/
                                            </InputGroupText>
                                        </InputGroupAddon>
                                        <InputGroupInput
                                            id="slug"
                                            name={
                                                general.slugLocked
                                                    ? 'slug-shown'
                                                    : 'slug'
                                            }
                                            defaultValue={general.slug}
                                            placeholder="e.g. sand-bay"
                                            autoComplete="off"
                                            disabled={general.slugLocked}
                                            aria-invalid={!!errors.slug}
                                        />
                                        <InputGroupAddon align="inline-end">
                                            <Tooltip>
                                                <TooltipTrigger
                                                    render={
                                                        <InputGroupButton
                                                            size="icon-xs"
                                                            aria-label="Copy link"
                                                            onClick={
                                                                copyAddress
                                                            }
                                                        />
                                                    }
                                                >
                                                    <Copy />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Copy link
                                                </TooltipContent>
                                            </Tooltip>
                                            <Tooltip>
                                                <TooltipTrigger
                                                    render={
                                                        <a
                                                            href={
                                                                general.webAddress
                                                            }
                                                            target="_blank"
                                                            rel="noreferrer"
                                                            aria-label="Open site"
                                                            className={buttonVariants(
                                                                {
                                                                    variant:
                                                                        'ghost',
                                                                    size: 'icon-xs',
                                                                },
                                                            )}
                                                        />
                                                    }
                                                >
                                                    <ExternalLink />
                                                </TooltipTrigger>
                                                <TooltipContent>
                                                    Open site
                                                </TooltipContent>
                                            </Tooltip>
                                        </InputGroupAddon>
                                    </InputGroup>
                                    <FieldDescription>
                                        {general.slugLocked
                                            ? 'Only support can change it after you publish.'
                                            : 'Lower case letters, digits and hyphens.'}
                                    </FieldDescription>
                                    <FieldError>{errors.slug}</FieldError>
                                </Field>
                                <SettingsInput
                                    name="google_maps_url"
                                    label="Google Maps link"
                                    value={general.googleMapsUrl}
                                    placeholder="https://www.google.com/maps/place/..."
                                    description="Paste the link of your property on Google Maps."
                                    error={errors.google_maps_url}
                                />
                                <p className="text-sm text-muted-foreground">
                                    The About text on your site comes from the
                                    property profile.{' '}
                                    <Link
                                        href={profile()}
                                        className="font-medium text-foreground underline underline-offset-4"
                                    >
                                        Edit About text
                                    </Link>
                                </p>
                            </>
                        )}
                    </SettingsSection>
                    <PaymentModeSection
                        paymentMode={general.paymentMode}
                        canChange={canChangePaymentMode}
                    />
                </div>
            </BookingWebsiteShell>
        </>
    );
}

BookingWebsiteGeneral.layout = (
    props: Parameters<typeof bookingWebsiteBreadcrumbs>[0],
) => ({
    breadcrumbs: bookingWebsiteBreadcrumbs(props, 'general'),
});
