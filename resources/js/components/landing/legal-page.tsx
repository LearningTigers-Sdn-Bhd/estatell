import { Link } from '@inertiajs/react';
import { home, privacy, terms } from '@/routes';
import { ArrowLeft, ArrowUpRight } from 'lucide-react';
import { Navbar } from './navbar';
import { Container } from './ui';

export function LegalPage({
    title,
    introduction,
    sections,
    current,
}: {
    title: string;
    introduction: string;
    sections: { id: string; title: string; paragraphs: string[] }[];
    current: 'terms' | 'privacy';
}) {
    const contents = (
        <ol className="border-t border-border">
            {sections.map((section, index) => (
                <li key={section.id} className="border-b border-border">
                    <a
                        href={`#${section.id}`}
                        className="flex min-h-11 items-baseline gap-3 py-3 text-sm leading-relaxed text-foreground transition-colors hover:text-primary motion-reduce:transition-none"
                    >
                        <span
                            className="shrink-0 text-xs text-foreground/70 tabular-nums"
                            aria-hidden="true"
                        >
                            {String(index + 1).padStart(2, '0')}
                        </span>
                        {section.title}
                    </a>
                </li>
            ))}
        </ol>
    );

    return (
        <>
            <Navbar />
            <main id="top" tabIndex={-1}>
                <header className="dark bg-background pt-[calc(7rem+env(safe-area-inset-top))] pb-10 text-foreground sm:pt-40 sm:pb-20">
                    <Container>
                        <Link
                            href={home()}
                            className="inline-flex min-h-11 items-center gap-2 text-sm text-foreground/75 transition-colors hover:text-chart-1"
                        >
                            <ArrowLeft className="size-4" aria-hidden="true" />
                            Back to Estatell
                        </Link>
                        <p className="mt-6 text-xs font-medium tracking-widest text-chart-1 uppercase sm:mt-10">
                            The details that matter
                        </p>
                        <h1 className="mt-4 max-w-4xl text-[2.5rem] leading-none font-medium tracking-tighter text-balance sm:mt-5 sm:text-7xl">
                            {title}
                        </h1>
                        <p className="mt-6 max-w-2xl text-base leading-relaxed text-foreground/75 sm:text-lg">
                            {introduction}
                        </p>
                        <p className="mt-6 text-sm text-foreground/75 sm:mt-8">
                            Last updated{' '}
                            <time dateTime="2026-10-09">9 October 2026</time>
                        </p>
                    </Container>
                </header>

                <Container className="grid gap-8 py-8 sm:gap-12 sm:py-20 lg:grid-cols-[240px_minmax(0,1fr)] lg:gap-20">
                    <aside className="min-w-0">
                        <details className="rounded-xl border border-border px-4 lg:hidden">
                            <summary className="min-h-12 cursor-pointer py-3 text-sm font-medium text-foreground">
                                On this page
                            </summary>
                            <nav aria-label="On this page" className="pb-3">
                                {contents}
                            </nav>
                        </details>
                        <nav
                            aria-label="On this page"
                            className="hidden lg:sticky lg:top-[calc(7rem+env(safe-area-inset-top))] lg:block lg:max-h-[calc(100dvh-8rem-env(safe-area-inset-top))] lg:overflow-y-auto lg:overscroll-contain lg:px-1"
                        >
                            <p className="mb-4 text-xs font-medium tracking-widest text-foreground/70 uppercase">
                                On this page
                            </p>
                            {contents}
                        </nav>
                    </aside>

                    <div className="max-w-prose min-w-0">
                        {sections.map((section, index) => (
                            <section
                                key={section.id}
                                id={section.id}
                                aria-labelledby={`${section.id}-heading`}
                                className="border-b border-border py-8 first:pt-0 sm:py-10"
                            >
                                <p
                                    className="text-xs font-medium text-primary tabular-nums"
                                    aria-hidden="true"
                                >
                                    {String(index + 1).padStart(2, '0')}
                                </p>
                                <h2
                                    id={`${section.id}-heading`}
                                    className="mt-3 text-2xl leading-tight font-medium tracking-tight sm:text-3xl"
                                >
                                    {section.title}
                                </h2>
                                <div className="mt-5 grid gap-4 text-base leading-7 [overflow-wrap:anywhere] text-foreground/70">
                                    {section.paragraphs.map((paragraph) => (
                                        <p key={paragraph}>{paragraph}</p>
                                    ))}
                                </div>
                            </section>
                        ))}
                        <div className="mt-8 rounded-2xl border border-border bg-muted p-5 sm:mt-10 sm:p-8">
                            <h2 className="text-2xl font-medium tracking-tight">
                                Let’s make things clear.
                            </h2>
                            <p className="mt-3 text-base leading-7 text-foreground/70">
                                Questions about{' '}
                                {current === 'terms'
                                    ? 'these terms'
                                    : 'your personal data'}
                                ? Contact Jesselton Pixel Sdn. Bhd., the company
                                behind Estatell.
                            </p>
                            <a
                                href="mailto:hello@estatell.com"
                                className="mt-4 inline-flex min-h-11 max-w-full items-center gap-3 text-base font-medium text-primary underline underline-offset-4 hover:text-foreground"
                            >
                                <span className="min-w-0 [overflow-wrap:anywhere]">
                                    hello@estatell.com
                                </span>{' '}
                                <ArrowUpRight
                                    className="size-4 shrink-0"
                                    aria-hidden="true"
                                />
                            </a>
                            <p className="mt-2 text-sm leading-relaxed text-foreground/70">
                                For a booking, cancellation, refund, or
                                guest-data request, contact your hotel first.
                            </p>
                        </div>
                    </div>
                </Container>
            </main>

            <footer className="dark bg-background pt-10 pb-[calc(2.5rem+env(safe-area-inset-bottom))] text-foreground sm:py-14">
                <Container>
                    <div className="flex flex-col gap-8 border-b border-border pb-8 sm:flex-row sm:items-center sm:justify-between">
                        <Link
                            href={home()}
                            className="inline-flex min-h-11 w-fit items-center font-archivo-black text-3xl font-normal tracking-tighter"
                        >
                            Estatell
                        </Link>
                        <nav
                            aria-label="Legal"
                            className="flex flex-wrap gap-x-6 gap-y-2 text-sm"
                        >
                            <Link
                                href={terms()}
                                aria-current={
                                    current === 'terms' ? 'page' : undefined
                                }
                                className="inline-flex min-h-11 items-center text-foreground/75 hover:text-chart-1 aria-[current=page]:text-chart-1"
                            >
                                Terms and Conditions
                            </Link>
                            <Link
                                href={privacy()}
                                aria-current={
                                    current === 'privacy' ? 'page' : undefined
                                }
                                className="inline-flex min-h-11 items-center text-foreground/75 hover:text-chart-1 aria-[current=page]:text-chart-1"
                            >
                                Privacy Policy
                            </Link>
                        </nav>
                    </div>
                </Container>
            </footer>
        </>
    );
}
