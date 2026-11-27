import { useEffect, useState } from 'react';
import type { RefObject } from 'react';

/** The element's width in pixels, kept up to date as it resizes; 0 before it is measured. */
export function useElementWidth(ref: RefObject<HTMLElement | null>): number {
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        const observer = new ResizeObserver(([entry]) =>
            setWidth(Math.floor(entry.contentRect.width)),
        );

        observer.observe(element);

        return () => observer.disconnect();
    }, [ref]);

    return width;
}
