import { Head, Link } from '@inertiajs/react';
import { ChevronLeft } from 'lucide-react';
import type { ReactNode } from 'react';

/** The frame of a guest page: a title, a link back to where the guest came from, and the content. */
export function GuestFrame({
    back,
    title,
    description,
    children,
}: {
    back: { href: string; label: string };
    title: string;
    description?: string;
    children: ReactNode;
}) {
    return (
        <>
            <Head title={title} />
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-8">
                <div className="mx-auto flex w-full max-w-md flex-col gap-6">
                    <div className="flex flex-col gap-2">
                        <Link
                            href={back.href}
                            className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground underline-offset-4 hover:underline"
                        >
                            <ChevronLeft className="size-4" aria-hidden />
                            {back.label}
                        </Link>
                        <h1 className="font-heading text-2xl font-semibold">
                            {title}
                        </h1>
                        {description && (
                            <p className="text-sm text-pretty text-muted-foreground">
                                {description}
                            </p>
                        )}
                    </div>
                    {children}
                </div>
            </div>
        </>
    );
}
