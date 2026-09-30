import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { RouteFormDefinition } from '@/wayfinder';

function focusFirstError(errors: Record<string, string>) {
    const first = Object.keys(errors)[0];

    if (first) {
        document.getElementById(first)?.focus();
    }
}

/**
 * One settings section = one form, saved on its own. Field ids must match field names so the first error gets focus.
 */
export function SettingsSection({
    form,
    title,
    description,
    submitLabel = 'Save settings',
    contentClassName = 'grid gap-5 md:grid-cols-2',
    children,
}: {
    form: RouteFormDefinition<'post'>;
    title: string;
    description: string;
    submitLabel?: string;
    contentClassName?: string;
    children: (errors: Record<string, string>) => ReactNode;
}) {
    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            onError={focusFirstError}
        >
            {({ processing, errors }) => (
                <section className="flex flex-col gap-5">
                    <header className="space-y-1">
                        <h3 className="text-base font-medium">{title}</h3>
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    </header>
                    <div className={contentClassName}>{children(errors)}</div>
                    <footer className="flex justify-end">
                        <Button
                            type="submit"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </footer>
                </section>
            )}
        </Form>
    );
}
