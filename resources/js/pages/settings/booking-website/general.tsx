import { Form, Head } from '@inertiajs/react';
import { Check, Copy, ExternalLink, X } from 'lucide-react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { PageHeader } from '@/components/pms/page-header';
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
import { Spinner } from '@/components/ui/spinner';
import { toast } from '@/components/ui/toast';
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
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Booking website"
                    description={`Let guests book and pay online. Mode: ${general.modeLabel}.`}
                    badge={<StatusBadge status={general.status} />}
                />

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
                                    <SettingsInput
                                        name={
                                            general.slugLocked
                                                ? 'slug-shown'
                                                : 'slug'
                                        }
                                        label="Web address"
                                        value={general.slug}
                                        disabled={general.slugLocked}
                                        description={
                                            general.slugLocked
                                                ? `${general.baseUrl}/ . Only support can change it after you publish.`
                                                : `${general.baseUrl}/ followed by this name. Lower case letters, digits and hyphens.`
                                        }
                                        error={errors.slug}
                                    />
                                    <SettingsInput
                                        name="google_maps_url"
                                        label="Google Maps link"
                                        value={general.googleMapsUrl}
                                        placeholder="https://www.google.com/maps/place/..."
                                        description="Paste the link of your property on Google Maps."
                                        error={errors.google_maps_url}
                                    />
                                    <div className="flex flex-wrap gap-2">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={copyAddress}
                                        >
                                            <Copy data-icon="inline-start" />
                                            Copy link
                                        </Button>
                                        <a
                                            href={general.webAddress}
                                            target="_blank"
                                            rel="noreferrer"
                                            className={buttonVariants({
                                                variant: 'outline',
                                            })}
                                        >
                                            <ExternalLink data-icon="inline-start" />
                                            Open site
                                        </a>
                                    </div>
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
            </div>
        </>
    );
}
