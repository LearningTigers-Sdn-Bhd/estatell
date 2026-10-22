import { useLayoutEffect, useRef } from 'react';

const WELCOME_MS = 1850;

export function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** The welcome curtain plays once per page load unless the visitor asks for reduced motion. */
export function playsWelcome(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.location.pathname === '/' &&
        !prefersReducedMotion()
    );
}

function useReveal<T extends HTMLElement>(
    pick: (root: T) => HTMLElement[],
    stepMs: number,
    delayMs: number,
    hidden: string,
    replay = false,
) {
    const ref = useRef<T>(null);

    useLayoutEffect(() => {
        const root = ref.current;

        if (!root || prefersReducedMotion()) {
            return;
        }

        const targets = pick(root);
        const hide = hidden.split(' ');
        const motion = [
            'transition-[opacity,translate]',
            'duration-500',
            'ease-out',
        ];

        if (targets.length === 0) {
            return;
        }

        targets.forEach((target) => target.classList.add(...hide, ...motion));

        const observer = new IntersectionObserver(
            ([entry]) => {
                if (entry.isIntersecting) {
                    targets.forEach((target, index) => {
                        target.style.transitionDelay = `${delayMs + index * stepMs}ms`;
                        target.classList.remove(...hide);
                    });

                    if (!replay) {
                        observer.disconnect();
                    }

                    return;
                }

                // Out of view: snap back to hidden with no transition so the next scroll-in plays again.
                targets.forEach((target) => {
                    target.style.transitionDuration = '0ms';
                    target.style.transitionDelay = '0ms';
                    target.classList.add(...hide);
                    void target.offsetWidth;
                    target.style.transitionDuration = '';
                });
            },
            { rootMargin: '0px 0px -12% 0px' },
        );

        observer.observe(root);

        return () => {
            observer.disconnect();
            targets.forEach((target) => {
                target.classList.remove(...hide, ...motion);
                target.style.transitionDelay = '';
            });
        };
        // The refs and options are constants per call site.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    return ref;
}

/** Fade and lift one block as it scrolls into view. `delay` is in milliseconds. */
export function useScrollReveal(delay = 0) {
    return useReveal<HTMLDivElement>(
        (root) => [root],
        0,
        delay,
        'opacity-0 translate-y-4',
    );
}

/** Fade and lift every `selector` child in turn. `stagger` is in seconds. */
export function useStaggerReveal(
    stagger = 0.06,
    selector = '[data-stagger]',
    hidden = 'opacity-0 translate-y-3',
    replay = false,
) {
    return useReveal<HTMLDivElement>(
        (root) => Array.from(root.querySelectorAll<HTMLElement>(selector)),
        stagger * 1000,
        0,
        hidden,
        replay,
    );
}

/** Staged entrance of the hero copy, stats and illustration. Waits for the welcome curtain when it plays. */
export function useHeroTimeline() {
    const ref = useRef<HTMLDivElement>(null);

    useLayoutEffect(() => {
        const root = ref.current;

        if (!root || prefersReducedMotion()) {
            return;
        }

        const base = playsWelcome() ? WELCOME_MS : 0;
        const steps: [string, number, string][] = [
            ['[data-hero-eyebrow]', 150, 'opacity-0 translate-y-2'],
            ['[data-hero-headline]', 300, 'opacity-0 translate-y-6'],
            ['[data-hero-lede]', 700, 'opacity-0 translate-y-3'],
            ['[data-hero-cta] > a', 950, 'opacity-0 translate-y-3'],
            ['[data-hero-stats] > div', 1150, 'opacity-0 translate-y-4'],
            ['.hotel-foundation', 200, 'opacity-0 translate-y-6'],
            ['.hotel-floor', 400, 'opacity-0 translate-y-7'],
            ['.hotel-note', 1300, 'opacity-0 translate-y-2'],
        ];
        const motion = [
            'transition-[opacity,translate]',
            'duration-700',
            'ease-out',
        ];
        const applied: HTMLElement[] = [];

        steps.forEach(([selector, at, hidden]) => {
            const hide = hidden.split(' ');

            root.parentElement
                ?.querySelectorAll<HTMLElement>(selector)
                .forEach((target, index) => {
                    target.classList.add(...hide, ...motion);
                    target.style.transitionDelay = `${base + at + index * 150}ms`;
                    applied.push(target);
                    requestAnimationFrame(() =>
                        requestAnimationFrame(() =>
                            target.classList.remove(...hide),
                        ),
                    );
                });
        });

        return () =>
            applied.forEach((target) => {
                target.classList.remove('opacity-0', ...motion);
                target.style.transitionDelay = '';
            });
    }, []);

    return ref;
}
