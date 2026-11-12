import { Link } from '@inertiajs/react';
import { Circle, CircleCheck } from 'lucide-react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { buttonVariants } from '@/components/ui/button';
import {
    Card,
    CardAction,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/** A main column with an optional narrower column beside it on wide screens. */
export function ContentGrid({
    children,
    aside,
}: {
    children: ReactNode;
    aside?: ReactNode;
}) {
    return (
        <div className="grid items-start gap-6 lg:grid-cols-3">
            <div className="flex min-w-0 flex-col gap-6 lg:col-span-2">
                {children}
            </div>
            {aside && (
                <aside className="flex min-w-0 flex-col gap-6">{aside}</aside>
            )}
        </div>
    );
}

export function Status({ ready }: { ready: boolean }) {
    const Icon = ready ? CircleCheck : Circle;

    return (
        <Badge variant={ready ? 'secondary' : 'outline'}>
            <Icon data-icon="inline-start" aria-hidden />
            {ready ? 'Ready' : 'Add details'}
        </Badge>
    );
}

/** A section edited on another settings page: what guests see now, and a link to change it. */
export function LinkedSection({
    title,
    body,
    ready,
    href,
    action,
}: {
    title: string;
    body: string;
    ready: boolean;
    href: string | null;
    action: string;
}) {
    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                <CardDescription>{body}</CardDescription>
                <CardAction>
                    <Status ready={ready} />
                </CardAction>
            </CardHeader>
            {href && (
                <CardFooter>
                    <Link
                        href={href}
                        className={buttonVariants({
                            variant: 'outline',
                            className: 'min-h-11',
                        })}
                    >
                        {action}
                    </Link>
                </CardFooter>
            )}
        </Card>
    );
}
