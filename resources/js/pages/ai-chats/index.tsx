import { Head, router } from '@inertiajs/react';
import { useCallback } from 'react';
import { aiChatTabs } from '@/components/pms/ai-chats/ai-chat-tabs';
import { AiThreadSheet } from '@/components/pms/ai-chats/ai-thread-sheet';
import { AiThreadsTable } from '@/components/pms/ai-chats/ai-threads-table';
import type { AiThreadRow } from '@/components/pms/ai-chats/ai-threads-table';
import { DateRangePicker } from '@/components/pms/date-range-picker';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { TabLinks, withTabCrumb } from '@/components/pms/tab-links';
import { all, index } from '@/routes/ai-chats';

type Tab = 'handed-over' | 'all';
type Filters = { place?: string; from?: string; to?: string };

type Props = {
    tab: Tab;
    threads: Illuminate.LengthAwarePaginator<number, AiThreadRow>;
    filters: Filters;
    places: EnumOption[];
    handedOverCount: number;
    selectedThread: App.Data.AiAssistant.AiThreadData | null;
};

export default function AiChatsIndex({
    tab,
    threads,
    filters,
    places,
    handedOverCount,
    selectedThread,
}: Props) {
    const visit = useCallback(
        (changes: Filters & { thread?: string | null }, only?: string[]) => {
            const { thread, ...filterChanges } = changes;
            const next: Filters = { ...filters, ...filterChanges };
            const filter = Object.fromEntries(
                Object.entries(next).filter(([, value]) => value),
            );
            const route = tab === 'all' ? all : index;

            router.get(
                route.url(undefined, {
                    query: {
                        ...(Object.keys(filter).length ? { filter } : {}),
                        ...(thread ? { thread } : {}),
                    },
                }),
                {},
                {
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    ...(only ? { only } : {}),
                },
            );
        },
        [filters, tab],
    );

    const hasFilters = Boolean(filters.place || filters.from || filters.to);

    return (
        <>
            <Head title="AI chats" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="AI chats"
                    description="Chats with the AI assistant. Contact the guest, then resolve the handover."
                />

                <TabLinks
                    activeTab={tab}
                    panelWidth="full"
                    className="gap-4"
                    tabs={aiChatTabs(handedOverCount)}
                >
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-wrap gap-2">
                            <EnumSelect
                                options={places}
                                value={filters.place ?? ''}
                                onValueChange={(place) => visit({ place })}
                                allLabel="All places"
                                aria-label="Place"
                                className="w-48"
                            />
                            <DateRangePicker
                                from={filters.from ?? ''}
                                to={filters.to ?? ''}
                                onChange={(from, to) => visit({ from, to })}
                                onClear={() => visit({ from: '', to: '' })}
                                clearLabel="Any date"
                                placeholder="Any date"
                                aria-label="Last message"
                                className="w-64"
                            />
                        </div>

                        <AiThreadsTable
                            threads={threads}
                            handedOver={tab === 'handed-over'}
                            hasFilters={hasFilters}
                            onClearFilters={() =>
                                visit({ place: '', from: '', to: '' })
                            }
                            onOpen={(row) =>
                                visit({ thread: row.id }, ['selectedThread'])
                            }
                        />
                    </div>
                </TabLinks>
            </div>

            <AiThreadSheet
                thread={selectedThread}
                onClose={() => visit({ thread: null }, ['selectedThread'])}
            />
        </>
    );
}

AiChatsIndex.layout = (props: Props) => ({
    breadcrumbs: withTabCrumb(
        [{ title: 'AI chats', href: index() }],
        aiChatTabs(props.handedOverCount),
        props.tab,
    ),
});
