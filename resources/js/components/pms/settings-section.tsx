import { Form } from '@inertiajs/react';
import { useRef, type ReactNode } from 'react';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import type { RouteFormDefinition } from '@/wayfinder';

function focusFirstError(errors: Record<string, string>) {
    const first = Object.keys(errors)[0];

    if (first) {
        document.getElementById(first)?.focus();
    }
}

/**
 * One settings section = one form, saved on its own. Field ids must match field names so the first error gets focus.
 * `readOnly` hides the save button for users who may only view; disable the fields as well.
 */
export function SettingsSection({
    form,
    title,
    description,
    submitLabel = 'Save settings',
    contentClassName = 'grid gap-5 md:grid-cols-2',
    readOnly = false,
    guardUnsavedChanges = false,
    children,
}: {
    form: RouteFormDefinition<'post'>;
    title: string;
    description: string;
    submitLabel?: string;
    contentClassName?: string;
    readOnly?: boolean;
    guardUnsavedChanges?: boolean;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    const afterSave = useRef<(() => void) | null>(null);

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onError={focusFirstError}
            setDefaultsOnSuccess={guardUnsavedChanges}
            onSuccess={() => afterSave.current?.()}
            onFinish={() => {
                afterSave.current = null;
            }}
        >
            {({ processing, errors, isDirty, submit }) => (
                <SettingsSectionContent
                    title={title}
                    description={description}
                    submitLabel={submitLabel}
                    contentClassName={contentClassName}
                    readOnly={readOnly}
                    guardUnsavedChanges={guardUnsavedChanges}
                    processing={processing}
                    isDirty={isDirty}
                    onSave={(onSaved) => {
                        afterSave.current = onSaved;
                        submit();
                    }}
                >
                    {children(errors)}
                </SettingsSectionContent>
            )}
        </Form>
    );
}

function SettingsSectionContent({
    title,
    description,
    submitLabel,
    contentClassName,
    readOnly,
    guardUnsavedChanges,
    processing,
    isDirty,
    onSave,
    children,
}: {
    title: string;
    description: string;
    submitLabel: string;
    contentClassName: string;
    readOnly: boolean;
    guardUnsavedChanges: boolean;
    processing: boolean;
    isDirty: boolean;
    onSave: (onSaved: () => void) => void;
    children: ReactNode;
}) {
    const guard = useUnsavedChanges(
        guardUnsavedChanges && !readOnly && isDirty,
    );

    return (
        <section className="flex flex-col gap-5">
            <header className="space-y-1">
                <h3 className="text-base font-medium">{title}</h3>
                <p className="text-sm text-muted-foreground">{description}</p>
            </header>
            <div className={contentClassName}>{children}</div>
            {!readOnly && (
                <footer className="flex justify-end">
                    <Button
                        type="submit"
                        disabled={
                            processing || (guardUnsavedChanges && !isDirty)
                        }
                        focusableWhenDisabled
                    >
                        {processing && <Spinner />}
                        {submitLabel}
                    </Button>
                </footer>
            )}
            {guardUnsavedChanges && !readOnly && (
                <UnsavedChangesDialog
                    guard={guard}
                    name={title}
                    saving={processing}
                    onSave={() => onSave(guard.proceed)}
                />
            )}
        </section>
    );
}
