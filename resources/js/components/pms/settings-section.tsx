import { Form } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import type { RouteFormDefinition } from '@/wayfinder';

function focusFirstError(errors: Record<string, string>) {
    const first = Object.keys(errors)[0];

    if (first) {
        document.getElementById(first)?.focus();
    }
}

/**
 * One settings card = one form, saved on its own. Field ids must match field names so the first error gets focus.
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
                <Card>
                    <CardHeader>
                        <CardTitle>{title}</CardTitle>
                        <CardDescription>{description}</CardDescription>
                    </CardHeader>
                    <CardContent className={contentClassName}>
                        {children(errors)}
                    </CardContent>
                    <CardFooter className="justify-end">
                        <Button
                            type="submit"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </CardFooter>
                </Card>
            )}
        </Form>
    );
}
