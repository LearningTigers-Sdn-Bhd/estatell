import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { ContactsForm } from '@/components/pms/concierge/contacts-form';
import { LinkedSection } from '@/components/pms/concierge/guide-layout';
import { profile as profileRoute } from '@/routes/settings';

export default function ConciergeContacts({
    profile,
    contacts,
}: {
    profile: App.Data.Concierge.ConciergeProfileData;
    contacts: App.Data.Concierge.ConciergeContactSettingsData;
}) {
    return (
        <>
            <Head title="Concierge contact" />
            <ConciergeShell
                activeTab="contacts"
                asideLabel="Phone and email"
                aside={
                    <LinkedSection
                        title="Phone and email"
                        body="The main phone number and email on the Contact page. Change them on the property profile."
                        ready={Boolean(profile.phone || profile.email)}
                        fields={[
                            { label: 'Phone', value: profile.phone },
                            { label: 'Email', value: profile.email },
                        ]}
                        href={profileRoute.url()}
                        action="Edit property profile"
                    />
                }
            >
                <ContactsForm contacts={contacts} />
            </ConciergeShell>
        </>
    );
}

ConciergeContacts.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('contacts'),
});
