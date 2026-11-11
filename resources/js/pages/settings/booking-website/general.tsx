import { Form, Head } from '@inertiajs/react';
import {
    Check,
    CloudOff,
    CloudUpload,
    Copy,
    ExternalLink,
    Globe,
    ListChecks,
    Save,
    X,
} from 'lucide-react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';
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
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';

const ABOUT_MAX = 1000;

export default function BookingWebsiteGeneral({
    general,
}: {
    general: App.Data.BookingEngine.BookingWebsiteGeneralData;
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
                            <Form {...BookingWebsiteController.publish.form()}>
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        className="w-full"
                                        disabled={
                                            !general.canPublish || processing
                                        }
                                        focusableWhenDisabled
                                    >
                                        {processing ? (
                                            <Spinner data-icon="inline-start" />
                                        ) : (
                                            <CloudUpload data-icon="inline-start" />
                                        )}
                                        {apiOnly ? 'Go live' : 'Publish'}
                                    </Button>
                                )}
                            </Form>
                        )}
                    </div>
                }
            >
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
                                                        onClick={copyAddress}
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
                            <Field data-invalid={!!errors.about_text}>
                                <FieldLabel htmlFor="about_text">
                                    About
                                </FieldLabel>
                                <Textarea
                                    id="about_text"
                                    name="about_text"
                                    rows={5}
                                    maxLength={ABOUT_MAX}
                                    defaultValue={general.aboutText ?? ''}
                                    placeholder="e.g. A quiet beach resort in Pangkor"
                                    aria-invalid={!!errors.about_text}
                                />
                                <FieldDescription>
                                    Guests read this on your booking website. Up
                                    to {ABOUT_MAX} characters.
                                </FieldDescription>
                                <FieldError>{errors.about_text}</FieldError>
                            </Field>
                        </>
                    )}
                </SettingsSection>
            </BookingWebsiteShell>
        </>
    );
}
