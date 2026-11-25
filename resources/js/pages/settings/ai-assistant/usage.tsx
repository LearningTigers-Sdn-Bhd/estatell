import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarCheck,
    ChartColumn,
    Languages,
    ListChecks,
    MapPin,
    Wrench,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { DateText } from '@/components/pms/date-text';
import { EmptyState } from '@/components/pms/empty-state';
import { MoneyText } from '@/components/pms/money-text';
import { PanelHeading } from '@/components/pms/panel-heading';
import {
    AiAssistantShell,
    aiAssistantBreadcrumbs,
} from '@/components/pms/settings/ai-assistant-tabs';
import { StatusBadge } from '@/components/pms/status-badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { show as reservation } from '@/routes/reservations';
import { usage } from '@/routes/settings/ai-assistant';

const languageNames = new Intl.DisplayNames(['en'], { type: 'language' });

/** "ms" as "Malay". Display only; the server keeps the code. */
function languageName(code: string | null): string {
    if (code === null) {
        return 'Not known';
    }

    return languageNames.of(code) ?? code.toUpperCase();
}

/** One figure at the top of the tab. */
function Figure({ label, children }: { label: string; children: ReactNode }) {
    return (
        <Card size="sm">
            <CardHeader>
                <CardTitle className="text-sm font-normal text-muted-foreground">
                    {label}
                </CardTitle>
            </CardHeader>
            <CardContent className="text-2xl font-semibold tabular-nums">
                {children}
            </CardContent>
        </Card>
    );
}

/** A heading and a bordered table, with an empty row when the month has nothing. */
function UsageTable({
    icon,
    title,
    description,
    headings,
    empty,
    children,
    footer,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    headings: { label: string; numeric?: boolean }[];
    empty: boolean;
    children: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <PanelHeading icon={icon} title={title} description={description} />
            <div className="overflow-x-auto rounded-xl border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            {headings.map((heading) => (
                                <TableHead
                                    key={heading.label}
                                    className={
                                        heading.numeric
                                            ? 'text-right'
                                            : undefined
                                    }
                                >
                                    {heading.label}
                                </TableHead>
                            ))}
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {empty ? (
                            <TableRow className="hover:bg-transparent">
                                <TableCell colSpan={headings.length}>
                                    <EmptyState
                                        icon={icon}
                                        title="Nothing this month."
                                    />
                                </TableCell>
                            </TableRow>
                        ) : (
                            children
                        )}
                    </TableBody>
                    {!empty && footer}
                </Table>
            </div>
        </section>
    );
}

/**
 * Settings › AI assistant › Usage: what the assistant did and cost in one month. Counts and amounts only, never what
 * anyone wrote. Spend is an estimate in US dollars, as OpenAI bills; room totals are in the property currency.
 */
export default function AiAssistantUsage({
    settings,
    status,
}: {
    settings: App.Data.AiAssistant.AiUsageData;
    status: App.Data.AiAssistant.AiAssistantStatusData;
}) {
    return (
        <>
            <Head title="AI assistant" />
            <AiAssistantShell activeTab="usage" status={status}>
                <div className="flex flex-col gap-6">
                    <PanelHeading
                        icon={ChartColumn}
                        title="Usage"
                        description="Estimate from tokens. Your OpenAI bill shows the exact amount."
                        actions={
                            <Input
                                type="month"
                                aria-label="Month"
                                className="w-auto"
                                value={settings.month}
                                max={settings.thisMonth}
                                onChange={(event) => {
                                    if (event.target.value !== '') {
                                        router.get(
                                            usage.url(
                                                {},
                                                {
                                                    query: {
                                                        month: event.target
                                                            .value,
                                                    },
                                                },
                                            ),
                                            {},
                                            {
                                                preserveScroll: true,
                                                replace: true,
                                            },
                                        );
                                    }
                                }}
                            />
                        }
                    />

                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <Figure label="Spent (USD)">{settings.spentUsd}</Figure>
                        <Figure label="Cap used">
                            {settings.capPercent === null
                                ? 'No cap'
                                : `${settings.capPercent}%`}
                        </Figure>
                        <Figure label="Replies">{settings.turns}</Figure>
                        <Figure label="Chats">{settings.threads}</Figure>
                    </div>

                    <div className="grid gap-6 xl:grid-cols-2">
                        <UsageTable
                            icon={MapPin}
                            title="By place"
                            description="Where people chat with the assistant."
                            headings={[
                                { label: 'Place' },
                                { label: 'Replies', numeric: true },
                                { label: 'Spent (USD)', numeric: true },
                            ]}
                            empty={settings.places.length === 0}
                        >
                            {settings.places.map((row) => (
                                <TableRow key={row.label}>
                                    <TableCell>{row.label}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.turns}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.spentUsd}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </UsageTable>

                        <UsageTable
                            icon={Languages}
                            title="By language"
                            description="The language of each chat's browser."
                            headings={[
                                { label: 'Language' },
                                { label: 'Chats', numeric: true },
                            ]}
                            empty={settings.languages.length === 0}
                        >
                            {settings.languages.map((row) => (
                                <TableRow key={row.language ?? 'unknown'}>
                                    <TableCell>
                                        {languageName(row.language)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.threads}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </UsageTable>

                        <UsageTable
                            icon={ListChecks}
                            title="Outcomes"
                            description="How each reply ended."
                            headings={[
                                { label: 'Outcome' },
                                { label: 'Replies', numeric: true },
                            ]}
                            empty={settings.turns === 0}
                        >
                            {settings.outcomes.map((row) => (
                                <TableRow key={row.outcome.value}>
                                    <TableCell>
                                        <StatusBadge status={row.outcome} />
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.turns}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </UsageTable>

                        <UsageTable
                            icon={Wrench}
                            title="Tools"
                            description="What the assistant looked up or did, and how often it failed."
                            headings={[
                                { label: 'Tool' },
                                { label: 'Runs', numeric: true },
                                { label: 'Failed', numeric: true },
                            ]}
                            empty={settings.tools.length === 0}
                        >
                            {settings.tools.map((row) => (
                                <TableRow key={row.label}>
                                    <TableCell>{row.label}</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.runs}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {row.failures}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </UsageTable>
                    </div>

                    <UsageTable
                        icon={CalendarCheck}
                        title="Bookings"
                        description="Bookings made from a quote link the assistant sent."
                        headings={[
                            { label: 'Booking' },
                            { label: 'Status' },
                            { label: 'Booked on' },
                            {
                                label: `Room total${settings.currency ? ` (${settings.currency.code})` : ''}`,
                                numeric: true,
                            },
                        ]}
                        empty={settings.bookings.length === 0}
                        footer={
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={3}>Total</TableCell>
                                    <TableCell className="text-right">
                                        <MoneyText
                                            amount={settings.bookedRoomTotal}
                                            currency={settings.currency}
                                            plain
                                        />
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        }
                    >
                        {settings.bookings.map((row) => (
                            <TableRow key={row.id}>
                                <TableCell>
                                    <Link
                                        href={reservation({
                                            reservation: row.id,
                                        })}
                                        className="font-medium"
                                    >
                                        {row.reference}
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <StatusBadge status={row.status} />
                                </TableCell>
                                <TableCell>
                                    <DateText date={row.bookedAt} />
                                </TableCell>
                                <TableCell className="text-right">
                                    <MoneyText
                                        amount={row.roomTotal}
                                        currency={settings.currency}
                                        plain
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </UsageTable>
                </div>
            </AiAssistantShell>
        </>
    );
}

AiAssistantUsage.layout = () => ({
    variant: 'fixed',
    breadcrumbs: aiAssistantBreadcrumbs('usage'),
});
