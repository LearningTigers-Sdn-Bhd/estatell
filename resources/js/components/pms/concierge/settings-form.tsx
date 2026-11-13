import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import {
    keepPage,
    SubmitButton,
} from '@/components/pms/concierge/content-fields';
import { update } from '@/routes/settings/concierge';

/**
 * House rules and the two guest switches are saved by one request. Each tab edits its own part and sends the saved
 * values of the rest in `kept`, so saving one never overwrites the other.
 */
export function SettingsForm({
    kept,
    children,
}: {
    kept: Record<string, string | boolean>;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    return (
        <Form
            action={update.url()}
            method="put"
            options={keepPage}
            className="flex flex-col gap-5"
        >
            {({ processing, errors }) => (
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
                    <SubmitButton processing={processing}>
                        Save settings
                    </SubmitButton>
                </>
            )}
        </Form>
    );
}
