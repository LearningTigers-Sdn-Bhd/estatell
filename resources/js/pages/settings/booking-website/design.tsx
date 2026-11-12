import { Head } from '@inertiajs/react';
import { Images } from 'lucide-react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import {
    BookingWebsiteShell,
    bookingWebsiteBreadcrumbs,
} from '@/components/pms/booking-website/booking-website-shell';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { SiteImageSlotRow } from '@/components/pms/booking-website/site-image-slot-row';
import { SiteTemplatePicker } from '@/components/pms/booking-website/site-template-picker';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';

export default function BookingWebsiteDesign({
    design,
    canManageAlbum,
}: {
    design: App.Data.BookingEngine.BookingWebsiteDesignData;
    canManageAlbum: boolean;
}) {
    return (
        <>
            <Head title="Booking website design" />
            <BookingWebsiteShell
                activeTab="design"
                asideLabel="Images"
                asideWidth="wide"
                aside={
                    <div className="flex flex-col gap-4">
                        <SectionHeader
                            title="Images"
                            icon={Images}
                            description={`JPEG, PNG or WebP, up to ${Math.round(design.imageMaxKb / 1024)} MB each. Choose a photo from your album, or upload one for the website only. Each image saves as soon as you pick it.`}
                        />
                        {design.siteTemplateId === null ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                Pick a template to see its image slots.
                            </p>
                        ) : design.slots.length === 0 ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                This template has no image slots.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-3">
                                {design.slots.map((slot) => (
                                    <SiteImageSlotRow
                                        key={slot.key}
                                        slot={slot}
                                        albumPhotos={design.albumPhotos}
                                        canManageAlbum={canManageAlbum}
                                    />
                                ))}
                            </ul>
                        )}
                    </div>
                }
            >
                <SettingsSection
                    key={design.siteTemplateId ?? 'none'}
                    headerClassName="ps-3 pt-2"
                    guardUnsavedChanges
                    readOnly={!design.allowsMainColor}
                    form={BookingWebsiteController.updateDesign.form()}
                    title="Template"
                    description="Changing the template keeps images in slots the new one also has. A template that allows it also has a main colour."
                    contentClassName="grid gap-5"
                >
                    {(errors) => (
                        <>
                            <SiteTemplatePicker design={design} />
                            {design.allowsMainColor && (
                                <>
                                    <input
                                        type="hidden"
                                        name="site_template_id"
                                        value={design.siteTemplateId ?? ''}
                                    />
                                    <SettingsInput
                                        name="main_color"
                                        label="Main colour"
                                        value={design.mainColor}
                                        placeholder="e.g. #0F766E"
                                        description="A hex colour. Leave empty to use the template's own."
                                        error={errors.main_color}
                                    />
                                </>
                            )}
                        </>
                    )}
                </SettingsSection>
            </BookingWebsiteShell>
        </>
    );
}

BookingWebsiteDesign.layout = (
    props: Parameters<typeof bookingWebsiteBreadcrumbs>[0],
) => ({
    breadcrumbs: bookingWebsiteBreadcrumbs(props, 'design'),
});
