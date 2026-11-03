import { Form, Head } from '@inertiajs/react';
import { Check, Copy, ExternalLink, Globe, X } from 'lucide-react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
                description={`Let guests book and pay online. Mode: ${general.modeLabel}.`}
                badge={<StatusBadge status={general.status} />}
                activeTab="general"
            >
                <div className="grid gap-6 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <SettingsSection
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
                                </>
                            )}
                        </SettingsSection>
                    </div>

                    <Card>
                        <CardHeader>
                            <CardTitle>Before you publish</CardTitle>
                            <CardDescription>
                                {general.checklist.length === 0
                                    ? 'Nothing is needed yet.'
                                    : 'Finish every step to go online.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="flex flex-col gap-4">
                            <ul className="flex flex-col gap-2">
                                {general.checklist.map((item) => (
                                    <li
                                        key={item.key}
                                        className="flex items-start gap-2 text-sm"
                                    >
                                        {item.done ? (
                                            <Check
                                                aria-hidden
                                                className="mt-0.5 size-4 text-success"
                                            />
                                        ) : (
                                            <X
                                                aria-hidden
                                                className="mt-0.5 size-4 text-muted-foreground"
                                            />
                                        )}
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
                                    className="flex justify-end"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            variant="outline"
                                            disabled={processing}
                                            focusableWhenDisabled
                                        >
                                            {processing && <Spinner />}
                                            Unpublish
                                        </Button>
                                    )}
                                </Form>
                            ) : (
                                <Form
                                    {...BookingWebsiteController.publish.form()}
                                    className="flex justify-end"
                                >
                                    {({ processing }) => (
                                        <Button
                                            type="submit"
                                            disabled={
                                                !general.canPublish ||
                                                processing
                                            }
                                            focusableWhenDisabled
                                        >
                                            {processing && <Spinner />}
                                            {apiOnly ? 'Go live' : 'Publish'}
                                        </Button>
                                    )}
                                </Form>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </BookingWebsiteShell>
        </>
    );
}
