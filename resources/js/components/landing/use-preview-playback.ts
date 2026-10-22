import { useEffect, useRef, useState } from 'react';
import { bookingPlaybackDelay } from './booking-playback';

export function usePreviewPlayback(count: number) {
    const sectionRef = useRef<HTMLElement>(null);
    const [selected, setSelected] = useState(0);
    const [paused, setPaused] = useState(false);
    const [focused, setFocused] = useState(false);
    const [visible, setVisible] = useState(false);
    const [pageVisible, setPageVisible] = useState(true);
    const [reducedMotion, setReducedMotion] = useState(true);
    const [resumeAt, setResumeAt] = useState(0);
    const [manual, setManual] = useState(false);

    useEffect(() => {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches)
            return;
        sectionRef.current
            ?.querySelectorAll('[data-preview-content]')
            .forEach((element) => {
                element.animate(
                    [
                        { opacity: 0, transform: 'translateY(12px)' },
                        { opacity: 1, transform: 'none' },
                    ],
                    { duration: 400, easing: 'ease-out' },
                );
            });
    }, [selected]);

    useEffect(() => {
        const media = window.matchMedia('(prefers-reduced-motion: reduce)');
        const syncMotion = () => setReducedMotion(media.matches);
        const syncVisibility = () => setPageVisible(!document.hidden);
        syncMotion();
        syncVisibility();
        media.addEventListener('change', syncMotion);
        document.addEventListener('visibilitychange', syncVisibility);
        const observer = new IntersectionObserver(([entry]) =>
            setVisible(entry.isIntersecting),
        );
        observer.observe(sectionRef.current!);
        return () => {
            observer.disconnect();
            media.removeEventListener('change', syncMotion);
            document.removeEventListener('visibilitychange', syncVisibility);
        };
    }, []);

    useEffect(() => {
        const delay = bookingPlaybackDelay(
            Date.now(),
            resumeAt,
            visible && pageVisible && !paused && !focused && !reducedMotion,
        );
        if (delay === null) return;
        const timer = window.setTimeout(() => {
            setManual(false);
            setSelected((current) => (current + 1) % count);
        }, delay);
        return () => window.clearTimeout(timer);
    }, [
        selected,
        count,
        resumeAt,
        visible,
        pageVisible,
        paused,
        focused,
        reducedMotion,
    ]);

    function select(index: number) {
        setManual(true);
        setSelected(index);
        setResumeAt(Date.now() + 60_000);
    }

    function togglePaused() {
        setPaused(!paused);
        if (paused) setResumeAt(0);
    }

    return {
        sectionRef,
        selected,
        paused,
        reducedMotion,
        manual,
        setFocused,
        select,
        togglePaused,
    };
}
