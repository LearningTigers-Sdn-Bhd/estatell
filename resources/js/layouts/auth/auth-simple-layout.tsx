import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative h-dvh w-full overflow-hidden bg-background p-10 sm:p-20">
            <div
                aria-hidden="true"
                className="pointer-events-none absolute inset-0"
            >
                <div className="auth-dot-grid absolute inset-0" />
                <div className="absolute inset-x-0 top-6 border-t border-dashed border-border sm:top-12" />
                <div className="absolute inset-x-0 bottom-6 border-t border-dashed border-border sm:bottom-12" />
                <div className="absolute inset-y-0 left-6 border-l border-dashed border-border sm:left-12" />
                <div className="absolute inset-y-0 right-6 border-l border-dashed border-border sm:right-12" />
            </div>
            <main className="relative flex h-full w-full flex-col overflow-y-auto px-1">
                <div className="m-auto flex w-full max-w-sm shrink-0 flex-col gap-6 py-4">
                    <div className="flex flex-col items-center gap-8">
                        <Link
                            href={home()}
                            className="rounded-sm outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:ring-offset-background"
                        >
                            <AppLogoIcon className="text-4xl" />
                        </Link>

                        <div className="flex flex-col gap-2 text-center">
                            <h1 className="font-heading text-xl font-semibold text-balance">
                                {title}
                            </h1>
                            <p className="text-sm leading-relaxed text-pretty text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </main>
        </div>
    );
}
