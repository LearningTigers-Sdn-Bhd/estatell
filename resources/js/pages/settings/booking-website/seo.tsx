import { Head } from '@inertiajs/react';
import { useState } from 'react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { BookingWebsiteShell } from '@/components/pms/booking-website/booking-website-shell';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import { SettingsSection } from '@/components/pms/settings-section';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';

const TITLE_MAX = 70;
const DESCRIPTION_MAX = 160;

function SeoFields({
    seo,
    errors,
    title,
    description,
    onTitleChange,
    onDescriptionChange,
}: {
    seo: App.Data.BookingEngine.BookingWebsiteSeoData;
    errors: Record<string, string>;
    title: string;
    description: string;
    onTitleChange: (title: string) => void;
    onDescriptionChange: (description: string) => void;
}) {
    return (
        <>
            <Field data-invalid={!!errors.seo_title}>
                <FieldLabel htmlFor="seo_title">Page title</FieldLabel>
                <Input
                    id="seo_title"
                    name="seo_title"
                    value={title}
                    maxLength={TITLE_MAX}
                    placeholder={`e.g. ${seo.propertyName}, Pangkor`}
                    onChange={(event) => onTitleChange(event.target.value)}
                    aria-invalid={!!errors.seo_title}
                />
                <FieldDescription>
                    {title.length} of {TITLE_MAX} characters. Leave empty to use
                    the property name.
                </FieldDescription>
                <FieldError>{errors.seo_title}</FieldError>
            </Field>
            <Field data-invalid={!!errors.seo_description}>
                <FieldLabel htmlFor="seo_description">Description</FieldLabel>
                <Textarea
                    id="seo_description"
                    name="seo_description"
                    value={description}
                    maxLength={DESCRIPTION_MAX}
                    placeholder="e.g. Beachfront rooms with breakfast"
                    onChange={(event) =>
                        onDescriptionChange(event.target.value)
                    }
                    aria-invalid={!!errors.seo_description}
                />
                <FieldDescription>
                    {description.length} of {DESCRIPTION_MAX} characters.
                </FieldDescription>
                <FieldError>{errors.seo_description}</FieldError>
            </Field>
        </>
    );
}

export default function BookingWebsiteSeo({
    seo,
}: {
    seo: App.Data.BookingEngine.BookingWebsiteSeoData;
}) {
    const [title, setTitle] = useState(seo.seoTitle ?? '');
    const [description, setDescription] = useState(seo.seoDescription ?? '');

    return (
        <>
            <Head title="Booking website SEO" />
            <BookingWebsiteShell
                activeTab="seo"
                asideLabel="Google preview"
                aside={
                    <div className="flex flex-col gap-4">
                        <SectionHeader
                            title="Google preview"
                            description="How your website may look in search results."
                        />
                        <div className="flex flex-col gap-1 rounded-lg border bg-muted/40 p-4">
                            <span className="text-lg text-info">
                                {title || seo.propertyName}
                            </span>
                            <span className="text-sm break-all text-success">
                                {seo.webAddress}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {description || 'No description yet.'}
                            </span>
                        </div>
                    </div>
                }
            >
                <SettingsSection
                    headerClassName="ps-3 pt-2"
                    guardUnsavedChanges
                    form={BookingWebsiteController.updateSeo.form()}
                    title="Google results"
                    description="The title and description guests see in search results."
                    contentClassName="grid gap-5"
                >
                    {(errors) => (
                        <SeoFields
                            seo={seo}
                            errors={errors}
                            title={title}
                            description={description}
                            onTitleChange={setTitle}
                            onDescriptionChange={setDescription}
                        />
                    )}
                </SettingsSection>
            </BookingWebsiteShell>
        </>
    );
}
