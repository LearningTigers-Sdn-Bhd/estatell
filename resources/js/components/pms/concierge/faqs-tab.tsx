import { CircleHelp } from 'lucide-react';
import {
    RemovableItem,
    TextField,
} from '@/components/pms/concierge/content-fields';
import { ContentGrid } from '@/components/pms/concierge/guide-layout';
import {
    AddItemCard,
    ItemListCard,
} from '@/components/pms/concierge/item-forms';
import { destroy, store } from '@/routes/settings/concierge/faqs';

/** The questions guests ask, with a form to add one. */
export function FaqsTab({
    faqs,
}: {
    faqs: App.Data.Concierge.ConciergeFaqData[];
}) {
    return (
        <ContentGrid
            aside={
                <AddItemCard
                    title="Add a question"
                    description="Answer a practical question guests ask before or during their stay."
                    action={store.url()}
                    submitLabel="Add question"
                    fields={(errors) => (
                        <>
                            <TextField
                                name="question"
                                label="Question"
                                error={errors.question}
                                required
                                maxLength={200}
                                placeholder="e.g. Is breakfast included?"
                            />
                            <TextField
                                name="answer"
                                placeholder="e.g. Yes. Breakfast is served from 7 to 10 am at the lobby café."
                                label="Answer"
                                error={errors.answer}
                                required
                                rows={5}
                                maxLength={1000}
                            />
                        </>
                    )}
                />
            }
        >
            <ItemListCard
                title="Questions guests ask"
                description="These answers appear in the property guide before and during a stay."
                count={faqs.length}
                one="question"
                many="questions"
                empty={{
                    icon: CircleHelp,
                    title: 'No questions yet',
                    description:
                        'Add the questions your front desk answers most often. They will appear in the guest guide.',
                }}
            >
                {faqs.map((faq) => (
                    <RemovableItem
                        key={faq.id}
                        url={destroy.url({ faq: faq.id })}
                        label={`Remove question: ${faq.question}`}
                    >
                        <span className="font-medium">{faq.question}</span>
                        <span className="text-pretty whitespace-pre-wrap text-muted-foreground">
                            {faq.answer}
                        </span>
                    </RemovableItem>
                ))}
            </ItemListCard>
        </ContentGrid>
    );
}
