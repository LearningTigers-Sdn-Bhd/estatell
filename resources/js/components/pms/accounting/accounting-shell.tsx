import { Link } from '@inertiajs/react';
import { Check, History, ListChecks, Plug } from 'lucide-react';
import type { ReactNode } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { TabLinks } from '@/components/pms/tab-links';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { accounts, edit, history } from '@/routes/settings/accounting';

export type AccountingTab = 'connection' | 'accounts' | 'history';

type Step = {
    title: string;
    detail: string;
    done: boolean;
    href: ReturnType<typeof edit>;
};

/**
 * What every Accounting tab shows: the title with the setup status, the three setup steps with their progress, and the
 * tabs. The steps are links, so a hotel can start with any of them: account codes can be typed before the connection is
 * saved.
 */
export function AccountingShell({
    setup,
    tab,
    children,
}: {
    setup: App.Data.Accounting.AccountingSetupData;
    tab: AccountingTab;
    children: ReactNode;
}) {
    const steps: Step[] = [
        {
            title: 'Connect',
            detail: setup.connectionComplete
                ? (setup.providerLabel ?? 'Connected')
                : 'Choose your software and paste its key',
            done: setup.connectionComplete,
            href: edit(),
        },
        {
            title: 'Map accounts',
            detail:
                setup.accountsTotal === 0
                    ? 'No transaction codes yet'
                    : `${setup.accountsMapped} of ${setup.accountsTotal} codes mapped`,
            done: setup.accountsMapped > 0,
            href: accounts(),
        },
        {
            title: 'Go live',
            detail: setup.enabled
                ? 'Closed days are pushed automatically'
                : 'Turn on when you are ready',
            done: setup.enabled,
            href: edit(),
        },
    ];

    return (
        <div className="flex flex-1 flex-col gap-6 p-4">
            <PageHeader
                title="Accounting"
                description="Push each closed business date to your accounting software as one journal."
                badge={<StatusBadge status={setup.status} />}
            />

            <ol className="grid gap-3 sm:grid-cols-3">
                {steps.map((step, index) => (
                    <li key={step.title} className="flex">
                        <Link
                            href={step.href}
                            preserveScroll
                            className="flex w-full rounded-xl outline-none focus-visible:ring-3 focus-visible:ring-ring/50"
                        >
                            <Card
                                size="sm"
                                className="w-full transition-colors hover:bg-muted/40"
                            >
                                <CardContent className="flex items-center gap-3">
                                    <span
                                        className={cn(
                                            'flex size-8 shrink-0 items-center justify-center rounded-full text-sm font-medium',
                                            step.done
                                                ? 'bg-success/10 text-success'
                                                : 'bg-muted text-muted-foreground',
                                        )}
                                    >
                                        {step.done ? (
                                            <Check
                                                className="size-4"
                                                aria-hidden
                                            />
                                        ) : (
                                            index + 1
                                        )}
                                        <span className="sr-only">
                                            {step.done ? 'Done' : 'To do'}
                                        </span>
                                    </span>
                                    <span className="flex min-w-0 flex-col">
                                        <span className="font-medium">
                                            {step.title}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {step.detail}
                                        </span>
                                    </span>
                                </CardContent>
                            </Card>
                        </Link>
                    </li>
                ))}
            </ol>

            <TabLinks
                activeTab={tab}
                className="flex flex-col gap-4"
                panelWidth="full"
                tabs={[
                    {
                        value: 'connection',
                        label: 'Connection',
                        icon: Plug,
                        href: edit(),
                    },
                    {
                        value: 'accounts',
                        label: 'Accounts',
                        icon: ListChecks,
                        href: accounts(),
                    },
                    {
                        value: 'history',
                        label: 'History',
                        icon: History,
                        href: history(),
                    },
                ]}
            >
                {children}
            </TabLinks>
        </div>
    );
}
