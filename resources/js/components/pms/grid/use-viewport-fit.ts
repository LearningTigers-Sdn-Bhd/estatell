import { useEffect, useLayoutEffect, useState } from 'react';
import type { RefObject } from 'react';

/**
 * The most height an element can take before the page itself has to scroll: what is left of the window below the
 * element's top edge, minus a bottom gap. A grid with this max height scrolls inside itself and never adds a
 * second vertical scrollbar to the page. It measures again on every render and when the window resizes.
 */
export function useViewportFit(
    ref: RefObject<HTMLElement | null>,
    {
        bottomGap = 16,
        minHeight = 240,
    }: { bottomGap?: number; minHeight?: number } = {},
): number | undefined {
    const [height, setHeight] = useState<number>();

    const measure = () => {
        const element = ref.current;

        if (!element) {
            return;
        }

        const top = element.getBoundingClientRect().top + window.scrollY;
        const next = Math.max(
            minHeight,
            Math.floor(window.innerHeight - top - bottomGap),
        );

        setHeight((current) => (current === next ? current : next));
    };

    useLayoutEffect(measure);

    useEffect(() => {
        window.addEventListener('resize', measure);

        return () => window.removeEventListener('resize', measure);
    });

    return height;
}
