import { router } from '@inertiajs/react';
import { useState } from 'react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { EnumSelect } from '@/components/pms/enum-select';
import {
    Field,
    FieldDescription,
    FieldError,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';

type Design = App.Data.BookingEngine.BookingWebsiteDesignData;

/**
 * The template picker on the Design tab. Picking a template saves it at once, like the image slots, so the slots of the new
 * template show straight away. The saved main colour goes along; the server drops it when the new template has none.
 */
export function SiteTemplatePicker({ design }: { design: Design }) {
    const saved =
        design.siteTemplateId === null ? '' : String(design.siteTemplateId);
    const [picked, setPicked] = useState<string>();
    const [error, setError] = useState<string>();

    const save = (next: string) => {
        if (next === saved) {
            return;
        }

        setError(undefined);
        router.put(
            BookingWebsiteController.updateDesign.url(),
            { site_template_id: next, main_color: design.mainColor ?? '' },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setPicked(next),
                onError: (errors) =>
                    setError(
                        errors.site_template_id ??
                            Object.values(errors)[0] ??
                            'The template could not be saved.',
                    ),
                onFinish: () => setPicked(undefined),
            },
        );
    };

    return (
        <Field data-invalid={!!error}>
            <FieldLabel htmlFor="site_template_id">Template</FieldLabel>
            <div className="flex items-center gap-2">
                <EnumSelect
                    id="site_template_id"
                    options={design.templates}
                    value={picked ?? saved}
                    onValueChange={save}
                    disabled={picked !== undefined}
                    allLabel="No template"
                    placeholder="Choose a template"
                    aria-invalid={!!error}
                    className="min-w-0 flex-1"
                />
                {picked !== undefined && <Spinner aria-label="Saving" />}
            </div>
            <FieldDescription>
                {design.templates.length === 0
                    ? 'No template is available yet. Ask support.'
                    : 'Saves as soon as you pick it.'}
            </FieldDescription>
            <FieldError>{error}</FieldError>
        </Field>
    );
}
