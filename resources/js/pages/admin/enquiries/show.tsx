import { Head, router } from '@inertiajs/react';
import {
    Building2,
    Mail,
    MapPin,
    MessageSquare,
    Phone,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import { DeleteEnquiryDialog } from '@/components/pms/delete-enquiry-dialog';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes/admin';
import { index, show } from '@/routes/admin/enquiries';
import { update } from '@/routes/admin/enquiries/status';

type Props = {
    enquiry: App.Data.Platform.AdminContactEnquiryRowData;
    statuses: EnumOption[];
};

export default function EnquiryShow({ enquiry, statuses }: Props) {
    const [deleting, setDeleting] = useState(false);

    return (
        <>
            <Head title={enquiry.hotelName} />
            <div className="flex w-full flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={enquiry.hotelName}
                    description={`Enquiry #${enquiry.id} · Received ${enquiry.receivedAt} UTC`}
                    back={{ href: index(), label: 'Enquiries' }}
                    badge={<StatusBadge status={enquiry.status} />}
                    actions={
                        <>
                            {statuses
                                .filter(
                                    (status) =>
                                        status.value !== enquiry.status.value,
                                )
                                .map((status) => (
                                    <Button
                                        key={status.value}
                                        variant="outline"
                                        onClick={() =>
                                            router.put(
                                                update.url(enquiry.id),
                                                { status: status.value },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Mark {status.label.toLowerCase()}
                                    </Button>
                                ))}
                            <Button
                                variant="outline"
                                onClick={() => setDeleting(true)}
                            >
                                <Trash2 data-icon="inline-start" />
                                Delete
                            </Button>
                        </>
                    }
                />
                <div className="grid items-start gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader className="border-b">
                            <CardTitle>
                                <h2 className="flex items-center gap-2">
                                    <MessageSquare
                                        className="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Enquiry message
                                </h2>
                            </CardTitle>
                            <CardDescription>
                                From {enquiry.name}
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <p className="min-h-40 text-base leading-relaxed break-words whitespace-pre-wrap">
                                {enquiry.message}
                            </p>
                        </CardContent>
                    </Card>
                    <div className="grid min-w-0 gap-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h2 className="flex items-center gap-2">
                                        <Building2
                                            className="size-4 text-muted-foreground"
                                            aria-hidden="true"
                                        />
                                        Property
                                    </h2>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p className="font-medium break-words">
                                    {enquiry.hotelName}
                                </p>
                                <p className="mt-2 flex items-start gap-2 text-muted-foreground">
                                    <MapPin
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 break-words">
                                        {enquiry.location}
                                    </span>
                                </p>
                                <dl className="mt-4 flex items-center justify-between gap-3 border-t pt-4">
                                    <dt className="text-muted-foreground">
                                        Number of rooms
                                    </dt>
                                    <dd className="font-medium tabular-nums">
                                        {enquiry.roomCount}
                                    </dd>
                                </dl>
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>
                                    <h2>Contact person</h2>
                                </CardTitle>
                                <CardDescription>
                                    Reply using the details provided.
                                </CardDescription>
                            </CardHeader>
                            <CardContent className="flex flex-col gap-3">
                                <p className="font-medium break-words">
                                    {enquiry.name}
                                </p>
                                <a
                                    href={`mailto:${enquiry.email}`}
                                    className="flex min-h-6 items-start gap-2 text-primary underline-offset-4 hover:underline"
                                >
                                    <Mail
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 break-words">
                                        {enquiry.email}
                                    </span>
                                </a>
                                <a
                                    href={`tel:${enquiry.phone}`}
                                    className="flex min-h-6 items-start gap-2 text-primary underline-offset-4 hover:underline"
                                >
                                    <Phone
                                        className="mt-0.5 size-4 shrink-0"
                                        aria-hidden="true"
                                    />
                                    <span className="min-w-0 break-words">
                                        {enquiry.phone}
                                    </span>
                                </a>
                            </CardContent>
                        </Card>
                    </div>
                </div>
            </div>
            <DeleteEnquiryDialog
                enquiry={deleting ? enquiry : null}
                onOpenChange={setDeleting}
            />
        </>
    );
}

EnquiryShow.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Admin', href: dashboard() },
        { title: 'Enquiries', href: index() },
        { title: props.enquiry.hotelName, href: show(props.enquiry.id) },
    ],
});
