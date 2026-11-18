import { Head } from '@inertiajs/react';
import {
    ConciergeShell,
    conciergeBreadcrumbs,
} from '@/components/pms/concierge/concierge-shell';
import { FaqTable } from '@/components/pms/concierge/faqs-tab';

/** Concierge › FAQs: the questions in a table grouped by topic. No rail, so the table takes the full width. */
export default function ConciergeFaqs({
    faqs,
    faqLimit,
}: {
    faqs: App.Data.Concierge.ConciergeFaqData[];
    faqLimit: number;
}) {
    return (
        <>
            <Head title="Concierge FAQs" />
            <ConciergeShell activeTab="faqs">
                <FaqTable faqs={faqs} limit={faqLimit} />
            </ConciergeShell>
        </>
    );
}

ConciergeFaqs.layout = () => ({
    variant: 'fixed',
    breadcrumbs: conciergeBreadcrumbs('faqs'),
});
