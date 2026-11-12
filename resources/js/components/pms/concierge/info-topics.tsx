import { Form, router } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { TextField } from '@/components/pms/concierge/content-fields';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { FieldGroup } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { destroy, store } from '@/routes/settings/concierge/topics';

const keepPage = { preserveScroll: true, preserveState: true };

/** Extra topics the property tells guests about, each under its own title (laundry, parking rules, nearby clinic). */
export function InfoTopics({
    topics,
}: {
    topics: App.Data.Concierge.ConciergeTopicData[];
}) {
    return (
        <Card className="max-w-md print:hidden">
            <CardContent className="flex flex-col gap-4">
                <div className="flex flex-col gap-1">
                    <h2 className="text-lg font-semibold">
                        Additional information
                    </h2>
                    <p className="text-sm text-pretty text-muted-foreground">
                        Shown under The property in the guide.
                    </p>
                </div>
                <ul className="flex flex-col gap-2">
                    {topics.map((topic) => (
                        <li
                            key={topic.id}
                            className="flex items-start justify-between gap-3 rounded-lg border p-3 text-sm"
                        >
                            <span className="flex flex-col gap-1">
                                <span className="font-medium">
                                    {topic.title}
                                </span>
                                <span className="text-muted-foreground">
                                    {topic.body}
                                </span>
                            </span>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon-sm"
                                aria-label={`Remove topic: ${topic.title}`}
                                onClick={() =>
                                    router.delete(
                                        destroy.url({ infoTopic: topic.id }),
                                        keepPage,
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
                <Form
                    action={store.url()}
                    method="post"
                    resetOnSuccess
                    options={keepPage}
                >
                    {({ processing, errors }) => (
                        <div className="flex flex-col gap-4">
                            <FieldGroup>
                                <TextField
                                    name="title"
                                    label="Title"
                                    defaultValue=""
                                    error={errors.title}
                                    maxLength={100}
                                    placeholder="e.g. Laundry"
                                />
                                <TextField
                                    name="body"
                                    label="Details"
                                    defaultValue=""
                                    error={errors.body}
                                    rows={3}
                                    maxLength={2000}
                                />
                            </FieldGroup>
                            <Button
                                type="submit"
                                variant="outline"
                                className="self-end"
                                disabled={processing}
                                focusableWhenDisabled
                            >
                                {processing ? (
                                    <Spinner data-icon="inline-start" />
                                ) : (
                                    <Plus data-icon="inline-start" />
                                )}
                                Add topic
                            </Button>
                        </div>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}
