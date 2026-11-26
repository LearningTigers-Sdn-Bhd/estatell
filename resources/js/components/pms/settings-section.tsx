import { Form } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { SaveBar } from '@/components/pms/save-bar';
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
 * `submitInField` leaves the save button out, so the fields render their own submit button (for example an
 * `InputGroupButton` at the end of a single input); `children` gets `processing` for its spinner.
 * `saveBar` replaces the save button with a bar that floats at the bottom of the window while it has unsaved changes,
 * with Discard and Save. Use it with `guardUnsavedChanges` for a long form.
 */
export function SettingsSection({
    form,
    title,
    icon,
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
    submitInField = false,
    children,
}: {
    form: RouteFormDefinition<'post'>;
    title: string;
    /** An icon box before the title, like `PanelHeading`. Inside a Workspace panel, pair it with `headerClassName="ps-2 pt-2"`. */
    icon?: LucideIcon;
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
    /** The fields hold their own submit button; no save button below them. */
    submitInField?: boolean;
    children: (
        errors: Record<string, string>,
        state: { processing: boolean },
    ) => ReactNode;
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
                    icon={icon}
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
                    submitInField={submitInField}
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
                        {children(errors, { processing })}
                    </div>
                </SettingsSectionContent>
            )}
        </Form>
    );
}

/** The section title and description, with an icon box before them when `icon` is set. */
function SectionTitle({
    title,
    icon: Icon,
    description,
}: {
    title: string;
    icon?: LucideIcon;
    description: string;
}) {
    const text = (
        <div className="min-w-0 space-y-1">
            <h3 className="text-base font-medium">{title}</h3>
            <p className="text-sm text-muted-foreground">{description}</p>
        </div>
    );

    if (!Icon) {
        return text;
    }

    return (
        <div className="flex min-w-0 items-start gap-3">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                <Icon aria-hidden className="size-4" />
            </span>
            {text}
        </div>
    );
}

function SettingsSectionContent({
    title,
    icon,
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
    submitInField,
    processing,
    isDirty,
    onSave,
    onDiscard,
    children,
}: {
    title: string;
    icon?: LucideIcon;
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
    submitInField: boolean;
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
                    <SectionTitle
                        title={title}
                        icon={icon}
                        description={description}
                    />
                    <div className="flex flex-wrap items-center gap-2">
                        {typeof actions === 'function'
                            ? actions({ isDirty })
                            : actions}
                        {showSaveInHeader && saveButton}
                    </div>
                </header>
            ) : hideHeader ? null : (
                <header className={headerClassName}>
                    <SectionTitle
                        title={title}
                        icon={icon}
                        description={description}
                    />
                </header>
            )}
            <div className={contentClassName}>{children}</div>
            {!readOnly && !submitInHeader && !saveBar && !submitInField && (
                <footer className="flex justify-end">{saveButton}</footer>
            )}
            {!readOnly && saveBar && isDirty && (
                <SaveBar
                    name={title}
                    processing={processing}
                    onDiscard={onDiscard}
                    saveButton={saveButton}
                />
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
