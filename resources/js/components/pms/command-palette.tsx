import { usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { lazy, Suspense, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';

const CommandPaletteDialog = lazy(
    () => import('@/components/pms/command-palette-dialog'),
);

/** One instance in each signed-in header; the search engine loads only on first open. */
export function CommandPalette() {
    const { props, url } = usePage();
    const [open, setOpen] = useState(false);
    const returnFocus = useRef<HTMLElement | null>(null);
    const context = JSON.stringify([
        props.auth.user.id,
        props.property?.code,
        props.property?.status,
        props.impersonation?.supportUserName,
        props.navigation,
        props.adminNavigation,
        props.partnerNavigation,
        props.accountNavigation,
        props.commandShortcuts,
        url,
    ]);

    function openPalette() {
        if (document.querySelector('[role="dialog"], [role="alertdialog"]'))
            return;
        returnFocus.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        setOpen(true);
    }

    useEffect(() => {
        function shortcut(event: KeyboardEvent) {
            if (
                event.key.toLowerCase() !== 'k' ||
                !(event.metaKey || event.ctrlKey) ||
                event.altKey ||
                event.isComposing ||
                event.repeat
            )
                return;
            event.preventDefault();
            openPalette();
        }
        document.addEventListener('keydown', shortcut);
        return () => document.removeEventListener('keydown', shortcut);
    }, []);

    return (
        <>
            <Button
                variant="outline"
                size="sm"
                aria-label="Search pages and records"
                aria-keyshortcuts="Meta+K Control+K"
                onClick={openPalette}
                className="w-full min-w-0 flex-1 justify-start gap-2 font-normal text-muted-foreground"
            >
                <Search data-icon="inline-start" className="shrink-0" />
                <span className="hidden truncate sm:inline">Search…</span>
                <Kbd className="ml-auto hidden sm:inline-flex">⌘K / Ctrl K</Kbd>
            </Button>
            {open && (
                <Suspense fallback={null}>
                    <CommandPaletteDialog
                        key={context}
                        onClose={() => setOpen(false)}
                        returnFocus={returnFocus}
                    />
                </Suspense>
            )}
        </>
    );
}
