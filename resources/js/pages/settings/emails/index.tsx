import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { Fragment } from 'react';
import { PageHeader } from '@/components/pms/page-header';
import { Badge } from '@/components/ui/badge';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { edit, index } from '@/routes/settings/emails';

type GuestEmail = {
    value: string;
    label: string;
    module: string;
    sent_when: string;
    customised: boolean;
};

/** The emails in the order the server sends them, one group per module. */
function byModule(emails: GuestEmail[]): [string, GuestEmail[]][] {
    const groups = new Map<string, GuestEmail[]>();
    for (const email of emails) {
        groups.set(email.module, [...(groups.get(email.module) ?? []), email]);
    }

    return [...groups.entries()];
}

/**
 * Settings › Emails: every email the property sends to guests, grouped by the module that sends it. Only emails of
 * features the property uses are listed. A row opens the email's editor.
 */
export default function GuestEmailsIndex({ emails }: { emails: GuestEmail[] }) {
    return (
        <>
            <Head title="Emails" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Emails"
                    description="The wording of the emails your property sends to guests. Changes apply to the next email sent."
                />

                <div className="overflow-auto rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Email</TableHead>
                                <TableHead>Sent when</TableHead>
                                <TableHead>Wording</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {byModule(emails).map(([module, rows]) => (
                                <Fragment key={module}>
                                    <TableRow className="hover:bg-transparent">
                                        <TableCell
                                            colSpan={3}
                                            className="bg-muted/40 text-xs font-medium text-muted-foreground"
                                        >
                                            {module}
                                        </TableCell>
                                    </TableRow>
                                    {rows.map((email) => (
                                        <TableRow
                                            key={email.value}
                                            className="relative"
                                        >
                                            <TableCell>
                                                <Link
                                                    href={edit({
                                                        email: email.value,
                                                    })}
                                                    className="rounded-sm font-medium outline-none after:absolute after:inset-0 focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    {email.label}
                                                </Link>
                                            </TableCell>
                                            <TableCell className="whitespace-normal text-muted-foreground">
                                                {email.sent_when}
                                            </TableCell>
                                            <TableCell>
                                                <span className="flex items-center justify-between gap-2">
                                                    {email.customised ? (
                                                        <Badge variant="secondary">
                                                            Edited
                                                        </Badge>
                                                    ) : (
                                                        <Badge variant="outline">
                                                            Default
                                                        </Badge>
                                                    )}
                                                    <ChevronRight
                                                        aria-hidden
                                                        className="size-4 shrink-0 text-muted-foreground"
                                                    />
                                                </span>
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </Fragment>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

GuestEmailsIndex.layout = {
    breadcrumbs: [{ title: 'Emails', href: index() }],
};
