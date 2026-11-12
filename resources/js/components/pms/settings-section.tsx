import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useRef, type ReactNode } from 'react';
import { UnsavedChangesDialog } from '@/components/pms/unsaved-changes-dialog';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useUnsavedChanges } from '@/hooks/use-unsaved-changes';
import type { RouteFormDefinition } from '@/wayfinder';
import { cn } from '@/lib/utils';

function focusFirstError(errors: Record<string, string>) {
    const first = Object.keys(errors)[0];

    if (first) {
        document.getElementById(first)?.focus();
    }
}

/**
 * Buttons for the section header. A function receives the form state, for example to disable a button while the form is dirty.
 */
type SectionActions = ReactNode | ((state: { isDirty: boolean }) => ReactNode);

/**
 * One settings section = one form, saved on its own. Field ids must match field names so the first error gets focus.
 * `readOnly` hides the save button for users who may only view; disable the fields as well.
 * `actions` sit on the right of the header, and wrap below the description on a phone.
 * `hideHeader` leaves the title and description out when the page header already says them; the title still names
 * the unsaved changes dialog.
 */
export function SettingsSection({
    form,
    title,
    description,
    submitLabel = 'Save settings',
    contentClassName = 'grid gap-5 md:grid-cols-2',
    readOnly = false,
    guardUnsavedChanges = false,
    actions,
    headerClassName,
    submitInHeader = false,
    submitIcon,
    hideHeader = false,
    children,
}: {
    form: RouteFormDefinition<'post'>;
    title: string;
    description: string;
    submitLabel?: string;
    contentClassName?: string;
    readOnly?: boolean;
    guardUnsavedChanges?: boolean;
    actions?: SectionActions;
    /** Extra classes for the header, for example the heading inset inside a Workspace panel. */
    headerClassName?: string;
    /** Puts the save button on the right of the header instead of below the fields. */
    submitInHeader?: boolean;
    /** An icon before the save button label. */
    submitIcon?: LucideIcon;
    /** Leaves out the title and description, for a section that repeats the page header. */
    hideHeader?: boolean;
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
                    actions={actions}
                    headerClassName={headerClassName}
                    submitInHeader={submitInHeader}
                    submitIcon={submitIcon}
                    hideHeader={hideHeader}
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
    actions,
    headerClassName,
    submitInHeader,
    submitIcon: SubmitIcon,
    hideHeader,
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
    actions?: SectionActions;
    headerClassName?: string;
    submitInHeader: boolean;
    submitIcon?: LucideIcon;
    hideHeader: boolean;
    processing: boolean;
    isDirty: boolean;
    onSave: (onSaved: () => void) => void;
    children: ReactNode;
}) {
    const guard = useUnsavedChanges(
        guardUnsavedChanges && !readOnly && isDirty,
    );

    const saveButton = (
        <Button
            type="submit"
            disabled={processing || (guardUnsavedChanges && !isDirty)}
            focusableWhenDisabled
        >
            {processing ? (
                <Spinner data-icon={SubmitIcon ? 'inline-start' : undefined} />
            ) : (
                SubmitIcon && <SubmitIcon data-icon="inline-start" />
            )}
            {submitLabel}
        </Button>
    );
    const showSaveInHeader = submitInHeader && !readOnly;

    return (
        <section className="flex flex-col gap-5">
            {actions || showSaveInHeader ? (
                <header
                    className={cn(
                        'flex flex-wrap items-start justify-between gap-x-4 gap-y-3',
                        headerClassName,
                    )}
                >
                    <div className="min-w-0 space-y-1">
                        <h3 className="text-base font-medium">{title}</h3>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        {typeof actions === 'function'
                            ? actions({ isDirty })
                            : actions}
                        {showSaveInHeader && saveButton}
                    </div>
                </header>
            ) : hideHeader ? null : (
                <header className={cn('space-y-1', headerClassName)}>
                    <h3 className="text-base font-medium">{title}</h3>
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                </header>
            )}
            <div className={contentClassName}>{children}</div>
            {!readOnly && !submitInHeader && (
                <footer className="flex justify-end">{saveButton}</footer>
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
