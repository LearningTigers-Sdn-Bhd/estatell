import { Head } from '@inertiajs/react';
import { DocumentForm } from '@/components/pms/guests/document-form';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { index, show } from '@/routes/guests';
import { create, edit, index as documentsTab } from '@/routes/guests/documents';

type Guest = App.Data.Guests.GuestData;
type Document = App.Data.Guests.GuestIdentityDocumentData;

type Props = {
    guest: Guest;
    document: Document | null;
    types: EnumOption[];
    countries: EnumOption[];
};

export default function DocumentFormPage({ document, ...props }: Props) {
    const title = document ? `Edit ${document.typeLabel}` : 'Add ID document';

    return (
        <>
            <Head title={`${title} · ${props.guest.fullName}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={title}
                    description={props.guest.fullName}
                    back={{
                        href: documentsTab.url({ guest: props.guest.id }),
                        label: 'documents',
                    }}
                />
                <DocumentForm document={document} {...props} />
            </div>
        </>
    );
}

DocumentFormPage.layout = (props: {
    guest: Guest;
    document: Document | null;
}) => ({
    breadcrumbs: [
        { title: 'Guests', href: index() },
        { title: props.guest.fullName, href: show({ guest: props.guest.id }) },
        {
            title: 'Documents',
            href: documentsTab({ guest: props.guest.id }),
        },
        props.document
            ? {
                  title: 'Edit',
                  href: edit({
                      guest: props.guest.id,
                      document: props.document.id,
                  }),
              }
            : { title: 'Add', href: create({ guest: props.guest.id }) },
    ],
});
