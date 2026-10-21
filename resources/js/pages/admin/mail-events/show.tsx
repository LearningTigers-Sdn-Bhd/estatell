import { Head } from '@inertiajs/react';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Card, CardContent } from '@/components/ui/card';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/mail-events';

type Props = {
    event: App.Data.Platform.AdminMailEventRowData;
};

export default function MailEventShow({ event }: Props) {
    const rows: [string, string | null][] = [
        ['Sent', event.sentAt],
        [
            'Recipient',
            event.recipientCount > 1
                ? `${event.recipient} (+${event.recipientCount - 1} more)`
                : event.recipient,
        ],
        ['Subject', event.subject],
        ['Type', event.notificationType],
        ['Property', event.propertyName],
        ['Provider message ID', event.providerMessageId],
        ['Error', event.error],
    ];

    return (
        <>
            <Head title="Mail log entry" />

            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={event.subject}
                    back={{ href: index(), label: 'Mail log' }}
                    badge={<StatusBadge status={event.status} />}
                />

                <Card>
                    <CardContent>
                        <dl className="grid gap-4 sm:grid-cols-[12rem_1fr]">
                            {rows.map(([label, value]) => (
                                <div key={label} className="contents">
                                    <dt className="text-sm text-muted-foreground">
                                        {label}
                                    </dt>
                                    <dd className="text-sm break-words">
                                        {value ?? '—'}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

MailEventShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Mail log', href: index() },
        { title: 'Entry', href: show(props.event.id) },
    ],
});
