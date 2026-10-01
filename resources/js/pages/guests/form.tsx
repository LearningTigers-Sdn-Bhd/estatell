import { Head } from '@inertiajs/react';
import { GuestForm } from '@/components/pms/guests/guest-form';
import type { EnumOption } from '@/components/pms/enum-select';
import { PageHeader } from '@/components/pms/page-header';
import { create, edit, index, show } from '@/routes/guests';

type Guest = App.Data.Guests.GuestData;

type Props = {
    guest: Guest | null;
    countries: EnumOption[];
    genders: EnumOption[];
    locales: EnumOption[];
    tags: App.Data.Guests.TagData[];
    defaultCountryCode: string | null;
};

export default function GuestFormPage({ guest, ...options }: Props) {
    const title = guest ? `Edit ${guest.fullName}` : 'Add guest';

    return (
        <>
            <Head title={title} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title={title}
                    back={{
                        href: guest
                            ? show.url({ guest: guest.id })
                            : index.url(),
                        label: guest ? guest.fullName : 'Guests',
                    }}
                />
                <GuestForm guest={guest} {...options} />
            </div>
        </>
    );
}

GuestFormPage.layout = (props: { guest: Guest | null }) => ({
    breadcrumbs: [
        { title: 'Guests', href: index() },
        ...(props.guest
            ? [
                  {
                      title: props.guest.fullName,
                      href: show({ guest: props.guest.id }),
                  },
                  { title: 'Edit', href: edit({ guest: props.guest.id }) },
              ]
            : [{ title: 'Add guest', href: create() }]),
    ],
});
