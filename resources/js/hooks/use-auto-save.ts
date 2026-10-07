import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { toast } from '@/components/ui/toast';

type ChangeOptions = {
    /** Milliseconds to wait for more changes before saving. 0 saves now, for example on blur. */
    delay?: number;
};

/**
 * A form with no Save button: each change saves by itself 300 ms after the last one, so a burst of clicks is one
 * request. While saves are pending the form shows the local copy; once every change is saved the server copy is the
 * truth again, so a refused change snaps back. A validation error shows an error toast.
 *
 * `draft` updates the local copy without saving, for a text field that saves on blur.
 */
export function useAutoSave<T>(
    server: T,
    save: (value: T) => { url: string; data: Record<string, unknown> },
) {
    const [value, setValue] = useState(server);
    const [saving, setSaving] = useState(false);
    // Bumped when a save finishes, so the effect below re-reads the server copy.
    const [settled, setSettled] = useState(0);
    // Counts changes. A save is current only if no change came after it was sent.
    const changes = useRef(0);
    const saved = useRef(0);
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => {
        if (changes.current !== saved.current) {
            return;
        }

        setValue(server);
    }, [server, settled]);

    useEffect(() => () => clearTimeout(timer.current), []);

    const change = (next: T, { delay = 300 }: ChangeOptions = {}) => {
        // Nothing to save: no save is pending and the value is the server copy, for example a blur with no edit.
        if (
            changes.current === saved.current &&
            JSON.stringify(next) === JSON.stringify(server)
        ) {
            setValue(next);

            return;
        }

        const current = ++changes.current;

        setValue(next);
        clearTimeout(timer.current);
        timer.current = setTimeout(() => {
            const { url, data } = save(next);

            setSaving(true);
            router.put(url, data as never, {
                preserveScroll: true,
                preserveState: true,
                onError: () => {
                    toast.add({
                        title: 'The change could not be saved. Check the fields and try again.',
                        type: 'error',
                    });
                },
                onFinish: () => {
                    saved.current = Math.max(saved.current, current);
                    setSaving(changes.current !== saved.current);
                    setSettled((count) => count + 1);
                },
            });
        }, delay);
    };

    return { value, change, draft: setValue, saving };
}
