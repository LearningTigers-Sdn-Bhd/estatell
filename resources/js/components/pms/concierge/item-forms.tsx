import { Form } from '@inertiajs/react';
import { Plus, type LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    keepPage,
    SubmitButton,
} from '@/components/pms/concierge/content-fields';
import { countLabel } from '@/components/pms/concierge/guide-sections';
import { EmptyState } from '@/components/pms/empty-state';
import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardAction,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';

type Errors = Record<string, string>;

/** A form that adds one list item and clears itself. `fields` receives the validation errors. */
export function AddItemForm({
    action,
    submitLabel,
    variant,
    className,
    fields,
}: {
    action: string;
    submitLabel: string;
    variant?: 'outline';
    className?: string;
    fields: (errors: Errors) => ReactNode;
}) {
    return (
        <Form
            action={action}
            method="post"
            resetOnSuccess
            options={keepPage}
            className={className}
        >
            {({ processing, errors }) => (
                <div className="flex flex-col gap-5">
                    <FieldGroup>{fields(errors)}</FieldGroup>
                    <SubmitButton
                        processing={processing}
                        icon={Plus}
                        variant={variant}
                    >
                        {submitLabel}
                    </SubmitButton>
                </div>
            )}
        </Form>
    );
}

/** A card holding an AddItemForm. */
export function AddItemCard({
    title,
    description,
    ...form
}: {
    title: string;
    description: string;
    action: string;
    submitLabel: string;
    fields: (errors: Errors) => ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
            </CardHeader>
            <CardContent>
                <AddItemForm {...form} />
            </CardContent>
        </Card>
    );
}

/** A card listing items, with a count badge and an empty state. `children` are the rows. */
export function ItemListCard({
    title,
    description,
    count,
    one,
    many,
    empty,
    children,
}: {
    title: string;
    description: string;
    count: number;
    one: string;
    many: string;
    empty: { icon: LucideIcon; title: string; description: string };
    children: ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{description}</CardDescription>
                <CardAction>
                    <Badge variant="outline">
                        {countLabel(count, one, many)}
                    </Badge>
                </CardAction>
            </CardHeader>
            <CardContent>
                {count > 0 ? (
                    <ul className="flex flex-col">{children}</ul>
                ) : (
                    <EmptyState {...empty} />
                )}
            </CardContent>
        </Card>
    );
}
