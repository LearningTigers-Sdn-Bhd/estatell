import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
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
 * `saveBar` replaces the save button with a bar that floats at the bottom of the window while it has unsaved changes,
 * with Discard and Save. Use it with `guardUnsavedChanges` for a long form.
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
    saveBar = false,
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
    /** Shows Discard and Save in a floating bar while the form has unsaved changes. */
    saveBar?: boolean;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    const afterSave = useRef<(() => void) | null>(null);
    // Remounting the fields on Discard also resets their React state, such as switches and selects.
    const [fieldsKey, setFieldsKey] = useState(0);

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
            {({ processing, errors, isDirty, submit, resetAndClearErrors }) => (
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
                    saveBar={saveBar}
                    processing={processing}
                    isDirty={isDirty}
                    onSave={(onSaved) => {
                        afterSave.current = onSaved;
                        submit();
                    }}
                    onDiscard={() => {
                        setFieldsKey((key) => key + 1);
                        resetAndClearErrors();
                    }}
                >
                    <div key={fieldsKey} className="contents">
                        {children(errors)}
                    </div>
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
    saveBar,
    processing,
    isDirty,
    onSave,
    onDiscard,
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
    saveBar: boolean;
    processing: boolean;
    isDirty: boolean;
    onSave: (onSaved: () => void) => void;
    onDiscard: () => void;
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
    const showSaveInHeader = submitInHeader && !saveBar && !readOnly;

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
            {!readOnly && !submitInHeader && !saveBar && (
                <footer className="flex justify-end">{saveButton}</footer>
            )}
            {!readOnly && saveBar && isDirty && (
                <div className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex justify-center">
                    <div
                        role="region"
                        aria-label={`Unsaved changes to ${title}`}
                        aria-busy={processing}
                        className="pointer-events-auto flex w-full animate-in items-center gap-2 rounded-xl border bg-popover py-2 pr-2 pl-4 text-popover-foreground shadow-lg duration-200 fade-in-0 slide-in-from-bottom-4 sm:w-auto"
                    >
                        <span className="mr-2 text-sm font-medium">
                            Unsaved changes
                        </span>
                        <div className="ml-auto flex items-center gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onDiscard}
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                Discard
                            </Button>
                            {saveButton}
                        </div>
                    </div>
                </div>
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
