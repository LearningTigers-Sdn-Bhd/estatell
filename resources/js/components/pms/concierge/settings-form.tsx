import { Save } from 'lucide-react';
import type { ReactNode } from 'react';
import { SettingsSection } from '@/components/pms/settings-section';
import type { RouteFormDefinition } from '@/wayfinder';
import { update } from '@/routes/settings/concierge';

/**
 * One Concierge form inside a Workspace panel, saved on its own: the `SettingsSection` used by the other settings
 * pages, with Save in the header and the unsaved changes guard.
 */
export function ConciergeFormSection({
    form,
    title,
    description,
    contentClassName = 'flex flex-col gap-5',
    children,
}: {
    form: RouteFormDefinition<'post'>;
    title: string;
    description: string;
    contentClassName?: string;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    return (
        <SettingsSection
            form={form}
            title={title}
            description={description}
            headerClassName="ps-3 pt-2"
            contentClassName={contentClassName}
            submitLabel="Save"
            submitIcon={Save}
            submitInHeader
            guardUnsavedChanges
        >
            {children}
        </SettingsSection>
    );
}

/**
 * House rules and the guest switches are saved by one request. Each tab edits its own part and sends the saved
 * values of the rest in `kept`, so saving one never overwrites the other.
 */
export function SettingsForm({
    title,
    description,
    kept,
    children,
}: {
    title: string;
    description: string;
    kept: Record<string, string | boolean>;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    return (
        <ConciergeFormSection
            form={update.form()}
            title={title}
            description={description}
        >
            {(errors) => (
                <>
                    {Object.entries(kept).map(([name, value]) => (
                        <input
                            key={name}
                            type="hidden"
                            name={name}
                            value={typeof value === 'boolean' ? +value : value}
                        />
                    ))}
                    {children(errors)}
                </>
            )}
        </ConciergeFormSection>
    );
}
