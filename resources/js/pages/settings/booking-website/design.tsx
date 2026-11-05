import { Head } from '@inertiajs/react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { SiteImageSlotRow } from '@/components/pms/booking-website/site-image-slot-row';
import { EnumSelect } from '@/components/pms/enum-select';
import { SettingsInput } from '@/components/pms/settings/form-fields';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';

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
                description="Pick how your booking website looks, then add your images."
                activeTab="design"
                asideLabel="Images"
                aside={
                    <div className="flex flex-col gap-4">
                        <SectionHeader
                            title="Images"
                            description={`JPEG, PNG or WebP, up to ${Math.round(design.imageMaxKb / 1024)} MB each. Choose a photo from your album, or upload one for the website only. Each image saves as soon as you pick it.`}
                        />
                        {design.siteTemplateId === null ? (
                            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                                Save a template to see its image slots.
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
                    headerClassName="ps-3 pt-2"
                    guardUnsavedChanges
                    form={BookingWebsiteController.updateDesign.form()}
                    title="Template"
                    description="Changing the template keeps images in slots the new one also has. A template that allows it also has a main colour."
                    contentClassName="grid gap-5"
                >
                    {(errors) => (
                        <>
                            <Field data-invalid={!!errors.site_template_id}>
                                <FieldLabel htmlFor="site_template_id">
                                    Template
                                </FieldLabel>
                                <EnumSelect
                                    id="site_template_id"
                                    name="site_template_id"
                                    options={design.templates}
                                    defaultValue={
                                        design.siteTemplateId === null
                                            ? ''
                                            : String(design.siteTemplateId)
                                    }
                                    allLabel="No template"
                                    placeholder="Choose a template"
                                    aria-invalid={!!errors.site_template_id}
                                />
                                {design.templates.length === 0 && (
                                    <FieldDescription>
                                        No template is available yet. Ask
                                        support.
                                    </FieldDescription>
                                )}
                                <FieldError>
                                    {errors.site_template_id}
                                </FieldError>
                            </Field>
                            {design.allowsMainColor && (
                                <SettingsInput
                                    name="main_color"
                                    label="Main colour"
                                    value={design.mainColor}
                                    placeholder="e.g. #0F766E"
                                    description="A hex colour. Leave empty to use the template's own."
                                    error={errors.main_color}
                                />
                            )}
                        </>
                    )}
                </SettingsSection>
            </BookingWebsiteShell>
        </>
    );
}
