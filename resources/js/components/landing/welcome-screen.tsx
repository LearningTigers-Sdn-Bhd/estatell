import { useEffect, useRef, useState } from 'react';
import { playsWelcome } from './use-reveal';

// Four dark panels frame the wordmark; the top and bottom bars leave room for the curtain split.
const panels = {
    top: 'inset-x-0 top-0 h-1/6',
    bottom: 'inset-x-0 bottom-0 h-1/6',
    left: 'top-1/6 bottom-1/6 left-0 w-1/2',
    right: 'top-1/6 right-0 bottom-1/6 w-1/2',
};

type Step = {
    target: Element | null;
    keyframes: Keyframe[];
    delay: number;
    duration: number;
    easing: string;
};

function play(screen: HTMLElement, onDone: () => void) {
    const letters = Array.from(
        screen.querySelectorAll('[data-welcome-letter]'),
    );
    const panel = (name: string) =>
        screen.querySelector(`[data-welcome-panel="${name}"]`);
    const move = (from: string, to: string) => [
        { translate: from },
        { translate: to },
    ];
    const steps: Step[] = [
        ...letters.map((target, index) => ({
            target,
            keyframes: move('0 110%', '0 0'),
            delay: 100 + index * 50,
            duration: 800,
            easing: 'cubic-bezier(0.16, 1, 0.3, 1)',
        })),
        {
            target: screen.querySelector('[data-welcome-word]'),
            keyframes: [
                { letterSpacing: '0.02em' },
                { letterSpacing: '-0.04em' },
            ],
            delay: 100,
            duration: 1200,
            easing: 'cubic-bezier(0.22, 1, 0.36, 1)',
        },
        ...letters.map((target, index) => ({
            target,
            keyframes: move('0 0', '0 -115%'),
            delay: 1400 + index * 20,
            duration: 350,
            easing: 'cubic-bezier(0.64, 0, 0.78, 0)',
        })),
        {
            target: panel('left'),
            keyframes: move('0 0', '-100% 0'),
            delay: 1800,
            duration: 800,
            easing: 'cubic-bezier(0.65, 0, 0.35, 1)',
        },
        {
            target: panel('right'),
            keyframes: move('0 0', '100% 0'),
            delay: 1800,
            duration: 800,
            easing: 'cubic-bezier(0.65, 0, 0.35, 1)',
        },
        {
            target: panel('top'),
            keyframes: move('0 0', '0 -100%'),
            delay: 1950,
            duration: 650,
            easing: 'cubic-bezier(0.65, 0, 0.35, 1)',
        },
        {
            target: panel('bottom'),
            keyframes: move('0 0', '0 100%'),
            delay: 1950,
            duration: 650,
            easing: 'cubic-bezier(0.65, 0, 0.35, 1)',
        },
    ];
    const animations = steps.flatMap(
        ({ target, keyframes, delay, duration, easing }) =>
            target
                ? [
                      target.animate(keyframes, {
                          delay,
                          duration,
                          easing,
                          fill: 'forwards',
                      }),
                  ]
                : [],
    );

    Promise.all(animations.map((animation) => animation.finished)).then(
        onDone,
        () => undefined,
    );

    return () => animations.forEach((animation) => animation.cancel());
}

export function WelcomeScreen() {
    const [active, setActive] = useState(playsWelcome);
    const screenRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!active || !screenRef.current) {
            return;
        }

        const root = document.documentElement;
        const previous = root.style.overflow;
        const stop = play(screenRef.current, () => setActive(false));
        const dismiss = () => setActive(false);

        root.style.overflow = 'hidden';
        document.addEventListener('focusin', dismiss, { once: true });

        return () => {
            stop();
            root.style.overflow = previous;
            document.removeEventListener('focusin', dismiss);
        };
    }, [active]);

    if (!active) {
        return null;
    }

    return (
        <div
            ref={screenRef}
            aria-hidden="true"
            className="dark fixed inset-0 z-50 grid place-items-center overflow-hidden text-foreground"
        >
            {Object.entries(panels).map(([name, position]) => (
                <div
                    key={name}
                    data-welcome-panel={name}
                    className={`absolute bg-background ${position}`}
                />
            ))}
            <div className="relative p-6">
                <div
                    data-welcome-word
                    className="flex font-poppins text-[clamp(3rem,16vw,13rem)] leading-none font-extrabold tracking-tighter"
                >
                    {Array.from('Estatell', (letter, index) => (
                        <span
                            key={index}
                            className="inline-block overflow-hidden py-1"
                        >
                            <span
                                data-welcome-letter
                                className="inline-block translate-y-full"
                            >
                                {letter}
                            </span>
                        </span>
                    ))}
                </div>
            </div>
        </div>
    );
}
