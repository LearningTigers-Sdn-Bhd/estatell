import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { AddFaqSection, FaqList } from '@/components/pms/concierge/faqs-tab';

export default function ConciergeFaqs({
    faqs,
}: {
    faqs: App.Data.Concierge.ConciergeFaqData[];
}) {
    return (
        <>
            <Head title="Concierge FAQs" />
            <ConciergeShell
                activeTab="faqs"
                asideLabel="Add a question"
                aside={<AddFaqSection />}
            >
                <FaqList faqs={faqs} />
            </ConciergeShell>
        </>
    );
}

ConciergeFaqs.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('faqs'),
});
