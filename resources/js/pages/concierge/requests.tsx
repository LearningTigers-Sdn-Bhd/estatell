import { Form } from '@inertiajs/react';
import { GuestFrame } from '@/components/concierge/stay-menu';
import { EnumSelect } from '@/components/pms/enum-select';
import type { EnumOption } from '@/components/pms/enum-select';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import {
    Field,
    FieldError,
    FieldGroup,
    FieldLabel,
} from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { store } from '@/routes/concierge/requests';

/** A checked-in guest asks the front desk for something and sees how their requests are going. */
export default function ConciergeRequests({
    token,
    back,
    requests,
    types,
    selectedType,
}: {
    token: string;
    back: { href: string; label: string };
    requests: App.Data.Concierge.ConciergeRequestData[];
    types: EnumOption[];
    selectedType: string;
}) {
    return (
        <GuestFrame
            back={back}
            title="Ask for something"
            description="Tell the front desk what you need. The team sees it at once."
        >
            <Form action={store.url({ token })} method="post" resetOnSuccess>
                {({ processing, errors }) => (
                    <div className="flex flex-col gap-6">
                        <FieldGroup>
                            <Field data-invalid={!!errors.type}>
                                <FieldLabel htmlFor="type">
                                    What is it about?
                                </FieldLabel>
                                <EnumSelect
                                    id="type"
                                    name="type"
                                    options={types}
                                    defaultValue={selectedType}
                                />
                                <FieldError>{errors.type}</FieldError>
                            </Field>
                            <Field data-invalid={!!errors.details}>
                                <FieldLabel htmlFor="details">
                                    Details
                                </FieldLabel>
                                <Textarea
                                    id="details"
                                    name="details"
                                    placeholder="e.g. Two extra towels and a pillow"
                                    maxLength={1000}
                                    required
                                    aria-invalid={!!errors.details}
                                />
                                <FieldError>{errors.details}</FieldError>
                            </Field>
                        </FieldGroup>
                        <Button
                            type="submit"
                            size="lg"
                            className="h-12 w-full rounded-full"
                            disabled={processing}
                            focusableWhenDisabled
                        >
                            {processing && <Spinner data-icon="inline-start" />}
                            Send to the front desk
                        </Button>
                    </div>
                )}
            </Form>

            <section className="flex flex-col gap-3">
                <h2 className="text-lg font-semibold">Your requests</h2>
                {requests.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        You have not asked for anything yet.
                    </p>
                )}
                <ul className="flex flex-col gap-3">
                    {requests.map((request) => (
                        <li
                            key={request.id}
                            className="flex flex-col gap-2 rounded-lg border p-4"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <p className="font-medium">{request.type}</p>
                                <StatusBadge status={request.status} />
                            </div>
                            <p className="text-sm text-pretty">
                                {request.details}
                            </p>
                            {request.note && (
                                <p className="text-sm text-pretty text-muted-foreground">
                                    {request.note}
                                </p>
                            )}
                            <p className="text-xs text-muted-foreground">
                                {new Date(request.openedAt).toLocaleString()}
                            </p>
                        </li>
                    ))}
                </ul>
            </section>
        </GuestFrame>
    );
}
