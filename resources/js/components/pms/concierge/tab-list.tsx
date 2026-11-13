import { useEffect, useRef } from 'react';
import { CONCIERGE_TABS } from '@/components/pms/concierge/guide-sections';
import { TabsList, TabsTrigger } from '@/components/ui/tabs';

/** The row of tabs. It scrolls sideways on a phone and keeps the active tab centred. */
export function ConciergeTabList({ active }: { active: string }) {
    const row = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const current =
            row.current?.querySelector<HTMLElement>('[data-active]');

        if (row.current && current) {
            row.current.scrollLeft =
                current.offsetLeft -
                (row.current.clientWidth - current.offsetWidth) / 2;
        }
    }, [active]);

    return (
        <div
            ref={row}
            className="no-scrollbar max-w-full overflow-x-auto border-b"
        >
            <TabsList variant="line" className="h-11">
                {CONCIERGE_TABS.map((tab) => (
                    <TabsTrigger
                        key={tab.key}
                        value={tab.key}
                        className="min-h-11 flex-none px-3 md:min-w-24"
                    >
                        <tab.icon aria-hidden />
                        {tab.label}
                    </TabsTrigger>
                ))}
            </TabsList>
        </div>
    );
}
