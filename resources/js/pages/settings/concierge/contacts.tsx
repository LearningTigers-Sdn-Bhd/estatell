import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { ContactsForm } from '@/components/pms/concierge/contacts-form';
import { LinkedSection } from '@/components/pms/concierge/guide-layout';
import { profile } from '@/routes/settings';

export default function ConciergeContacts({
    hasContact,
    contacts,
}: {
    hasContact: boolean;
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
                        body="The main phone number and email on the Contact page come from the property profile."
                        ready={hasContact}
                        href={profile.url()}
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
