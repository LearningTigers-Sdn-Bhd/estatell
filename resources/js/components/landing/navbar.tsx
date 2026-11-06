import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronDown, Menu, X, ArrowRight } from 'lucide-react';
import { services } from './services';
import { buttonVariants } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { home, login } from '@/routes';

const links = [
    { href: '/#platform', label: 'Platform' },
    { href: '/#save-ota', label: 'Direct Booking' },
    { href: '/#plans', label: 'Pricing' },
];

export function Navbar() {
    const [open, setOpen] = useState(false);
    const [scrolled, setScrolled] = useState(false);
    const [servicesOpen, setServicesOpen] = useState(false);
    const menuButtonRef = useRef<HTMLButtonElement>(null);
    const servicesRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!servicesOpen) {
            return;
        }

        const close = (event: Event) => {
            if (
                event instanceof KeyboardEvent
                    ? event.key === 'Escape'
                    : !servicesRef.current?.contains(event.target as Node)
            ) {
                setServicesOpen(false);
            }
        };
        document.addEventListener('mousedown', close);
        document.addEventListener('keydown', close);
        return () => {
            document.removeEventListener('mousedown', close);
            document.removeEventListener('keydown', close);
        };
    }, [servicesOpen]);

    useEffect(() => {
        const onScroll = () => setScrolled(window.scrollY > 40);
        onScroll();
        window.addEventListener('scroll', onScroll, { passive: true });
        return () => window.removeEventListener('scroll', onScroll);
    }, []);

    return (
        <header
            className={`fixed inset-x-0 top-0 z-40 pt-[env(safe-area-inset-top)] transition-[transform,padding] duration-300 ease-out motion-reduce:transition-none ${scrolled ? 'translate-y-3 pr-[max(0.75rem,env(safe-area-inset-right))] pl-[max(0.75rem,env(safe-area-inset-left))] sm:translate-y-4 sm:pr-[max(1.5rem,env(safe-area-inset-right))] sm:pl-[max(1.5rem,env(safe-area-inset-left))]' : 'translate-y-0 px-0'}`}
            onKeyDown={(event) => {
                if (event.key === 'Escape' && open) {
                    setOpen(false);
                    menuButtonRef.current?.focus();
                }
            }}
        >
            <div
                className={`relative mx-auto w-full border bg-background/95 text-foreground backdrop-blur-xl transition-[max-width,border-radius,border-color,box-shadow] duration-300 ease-out motion-reduce:transition-none ${scrolled ? 'max-w-7xl rounded-full border-border shadow-lg shadow-foreground/10' : 'max-w-[100vw] rounded-none border-transparent border-b-border'}`}
            >
                <div
                    className={`mx-auto flex min-h-18 max-w-7xl items-center justify-between gap-3 py-3 transition-[padding] duration-300 ease-out motion-reduce:transition-none ${scrolled ? 'px-4 sm:px-7' : 'pr-[max(1.25rem,env(safe-area-inset-right))] pl-[max(1.25rem,env(safe-area-inset-left))] sm:pr-[max(2rem,env(safe-area-inset-right))] sm:pl-[max(2rem,env(safe-area-inset-left))]'}`}
                >
                    <Link
                        href={home()}
                        onClick={(event) => {
                            setOpen(false);

                            // Already on the landing page: scroll up instead of visiting it again, which replays the welcome screen.
                            if (window.location.pathname === '/') {
                                event.preventDefault();
                                window.history.replaceState(
                                    window.history.state,
                                    '',
                                    '/',
                                );
                                window.scrollTo({ top: 0, behavior: 'smooth' });
                            }
                        }}
                        className="flex min-h-11 shrink-0 items-center rounded-lg focus-visible:outline-ring"
                        aria-label="Estatell home"
                    >
                        <img
                            src="/images/landing/estatell-logo.webp"
                            alt=""
                            width={809}
                            height={159}
                            loading="eager"
                            className="h-auto w-24 sm:w-28"
                        />
                    </Link>

                    <nav
                        className="hidden items-center gap-0.5 lg:flex"
                        aria-label="Primary"
                    >
                        <div ref={servicesRef} className="relative">
                            <button
                                type="button"
                                onClick={() =>
                                    setServicesOpen((value) => !value)
                                }
                                aria-expanded={servicesOpen}
                                aria-controls="services-menu"
                                className="inline-flex min-h-11 items-center gap-1 rounded-full px-3 text-sm font-medium whitespace-nowrap text-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-ring motion-reduce:transition-none"
                            >
                                Services
                                <ChevronDown
                                    className="size-4"
                                    aria-hidden="true"
                                />
                            </button>
                            <ul
                                id="services-menu"
                                hidden={!servicesOpen}
                                className="absolute top-full left-0 mt-2 w-64 rounded-2xl border border-border bg-background p-2 shadow-lg shadow-foreground/10"
                            >
                                {services.map((service) => (
                                    <li key={service.slug}>
                                        <a
                                            href={`/services/${service.slug}`}
                                            className="flex min-h-11 items-center rounded-xl px-3 text-sm font-medium text-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-ring"
                                        >
                                            {service.name}
                                        </a>
                                    </li>
                                ))}
                            </ul>
                        </div>
                        {links.map((link) => (
                            <a
                                key={link.href}
                                href={link.href}
                                className="inline-flex min-h-11 items-center rounded-full px-3 text-sm font-medium whitespace-nowrap text-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-ring motion-reduce:transition-none"
                            >
                                {link.label}
                            </a>
                        ))}
                    </nav>

                    <div className="flex shrink-0 items-center gap-2">
                        <Link
                            href={login()}
                            className={cn(
                                buttonVariants({ size: 'lg' }),
                                'h-11 rounded-full px-6',
                            )}
                        >
                            Sign in
                        </Link>
                        <button
                            ref={menuButtonRef}
                            type="button"
                            onClick={() => setOpen((value) => !value)}
                            aria-expanded={open}
                            aria-controls="mobile-navigation"
                            aria-label={open ? 'Close menu' : 'Open menu'}
                            className="inline-flex size-11 items-center justify-center rounded-full border border-border text-foreground transition-colors hover:bg-muted focus-visible:outline-ring motion-reduce:transition-none lg:hidden"
                        >
                            {open ? (
                                <X className="size-5" aria-hidden="true" />
                            ) : (
                                <Menu className="size-5" aria-hidden="true" />
                            )}
                        </button>
                    </div>
                </div>

                <nav
                    id="mobile-navigation"
                    aria-label="Mobile"
                    hidden={!open}
                    className="absolute top-full right-[max(0.75rem,env(safe-area-inset-right))] left-[max(0.75rem,env(safe-area-inset-left))] mx-auto mt-3 max-h-[calc(100dvh-7rem-env(safe-area-inset-top)-env(safe-area-inset-bottom))] max-w-7xl overflow-y-auto overscroll-contain rounded-2xl border border-border bg-background p-3 shadow-lg shadow-foreground/10 lg:hidden"
                >
                    {services.map((service) => (
                        <a
                            key={service.slug}
                            href={`/services/${service.slug}`}
                            onClick={() => setOpen(false)}
                            className="flex min-h-12 items-center justify-between rounded-xl px-4 text-base font-medium text-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-ring motion-reduce:transition-none"
                        >
                            {service.name}
                            <ArrowRight
                                className="size-4 text-foreground"
                                aria-hidden="true"
                            />
                        </a>
                    ))}
                    <hr className="my-2 border-border" />
                    {links.map((link) => (
                        <a
                            key={link.href}
                            href={link.href}
                            onClick={() => setOpen(false)}
                            className="flex min-h-12 items-center justify-between rounded-xl px-4 text-base font-medium text-foreground/70 transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-ring motion-reduce:transition-none"
                        >
                            {link.label}
                            <ArrowRight
                                className="size-4 text-foreground"
                                aria-hidden="true"
                            />
                        </a>
                    ))}
                </nav>
            </div>
        </header>
    );
}
