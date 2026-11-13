import { Info } from 'lucide-react';
import {
    RemovableItem,
    TextField,
} from '@/components/pms/concierge/content-fields';
import { AddItemForm } from '@/components/pms/concierge/item-forms';
import { EmptyState } from '@/components/pms/empty-state';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { destroy, store } from '@/routes/settings/concierge/topics';

/** Extra topics the property tells guests about, each under its own title (laundry, parking rules, nearby clinic). */
export function InfoTopics({
    topics,
}: {
    topics: App.Data.Concierge.ConciergeTopicData[];
}) {
    return (
        <Card className="print:hidden">
            <CardHeader>
                <CardTitle>Additional information</CardTitle>
                <CardDescription>
                    Add topics such as laundry, parking rules, or a nearby
                    clinic. They appear under The property in the guide.
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-5">
                {topics.length > 0 ? (
                    <ul className="flex flex-col">
                        {topics.map((topic) => (
                            <RemovableItem
                                key={topic.id}
                                url={destroy.url({ infoTopic: topic.id })}
                                label={`Remove topic: ${topic.title}`}
                            >
                                <span className="font-medium">
                                    {topic.title}
                                </span>
                                <span className="text-pretty whitespace-pre-wrap text-muted-foreground">
                                    {topic.body}
                                </span>
                            </RemovableItem>
                        ))}
                    </ul>
                ) : (
                    <EmptyState
                        icon={Info}
                        title="No additional topics"
                        description="Use the fields below for information that does not fit the main property details."
                    />
                )}
                <AddItemForm
                    action={store.url()}
                    submitLabel="Add topic"
                    variant="outline"
                    className="border-t pt-5"
                    fields={(errors) => (
                        <>
                            <TextField
                                name="title"
                                label="Title"
                                error={errors.title}
                                maxLength={100}
                                placeholder="e.g. Laundry"
                            />
                            <TextField
                                name="body"
                                placeholder="e.g. Self-service laundry on level 2, open 8 am to 10 pm. Detergent is sold at the front desk."
                                label="Details"
                                error={errors.body}
                                rows={3}
                                maxLength={2000}
                            />
                        </>
                    )}
                />
            </CardContent>
        </Card>
    );
}
