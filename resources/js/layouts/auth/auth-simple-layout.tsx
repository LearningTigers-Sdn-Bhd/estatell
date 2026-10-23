import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="grid min-h-dvh bg-background lg:grid-cols-[minmax(0,1fr)_minmax(0,1.1fr)]">
            <main className="flex flex-col px-6 py-8 sm:px-12 lg:px-16">
                <div className="flex items-center justify-between gap-3">
                    <Link
                        href={home()}
                        className="rounded-sm outline-hidden focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-4 focus-visible:ring-offset-background"
                    >
                        <img
                            src="/images/landing/estatell-logo.webp"
                            alt="Estatell"
                            width={809}
                            height={159}
                            className="h-auto w-28 sm:w-32"
                        />
                    </Link>
                    <Link
                        href={home()}
                        className="inline-flex items-center gap-1.5 text-sm text-muted-foreground transition-colors hover:text-foreground motion-reduce:transition-none"
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        Back to site
                    </Link>
                </div>

                <div className="m-auto flex w-full max-w-sm shrink-0 flex-col gap-8 py-12">
                    <div className="flex flex-col gap-3 text-center">
                        <h1 className="font-heading text-4xl leading-none font-medium tracking-tighter text-balance">
                            {title}
                        </h1>
                        <p className="text-base leading-relaxed text-pretty text-muted-foreground">
                            {description}
                        </p>
                    </div>
                    <div className="flex flex-col gap-6 [&_[data-slot=input]]:h-11 [&_[data-slot=input]]:rounded-xl [&_[data-slot=input]]:px-3.5 [&_[data-slot=label]]:font-medium">
                        {children}
                    </div>
                </div>
            </main>

            <aside
                aria-hidden="true"
                className="hidden p-4 lg:sticky lg:top-0 lg:block lg:h-dvh"
            >
                <img
                    src="/images/landing/hotel-host.webp"
                    alt=""
                    className="size-full rounded-3xl object-cover object-[50%_35%]"
                />
            </aside>
        </div>
    );
}
