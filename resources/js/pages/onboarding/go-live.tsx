import { Form, Head } from '@inertiajs/react';
import { CircleCheck, Circle } from 'lucide-react';
import { useState } from 'react';
import GoLiveController from '@/actions/App/Http/Controllers/Onboarding/GoLiveController';
import Heading from '@/components/heading';
import { DatePicker } from '@/components/pms/date-picker';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Field, FieldError, FieldLabel } from '@/components/ui/field';
import { Spinner } from '@/components/ui/spinner';
import { show as goLiveShow } from '@/routes/onboarding/go-live';

export default function GoLive({
    checks,
    ready,
    awaitingApproval,
    requiresApproval,
    today,
}: {
    checks: App.Data.Onboarding.GoLiveCheckData[];
    ready: boolean;
    awaitingApproval: boolean;
    requiresApproval: boolean;
    today: string;
}) {
    const [openingDate, setOpeningDate] = useState(today);

    return (
        <>
            <Head title="Go live" />
            <div className="flex flex-col gap-6 p-4">
                <Heading
                    title="Go live"
                    description="Start taking real bookings. This opens your first business date and cannot be undone."
                />
                {awaitingApproval ? (
                    <Alert>
                        <AlertTitle>Waiting for approval</AlertTitle>
                        <AlertDescription>
                            Your request was sent. You can use the pages that
                            run the property once the team approves it.
                        </AlertDescription>
                    </Alert>
                ) : (
                    <Form {...GoLiveController.store.form()}>
                        {({ processing, errors }) => (
                            <Card>
                                <CardHeader>
                                    <CardTitle>Before you start</CardTitle>
                                    <CardDescription>
                                        {requiresApproval
                                            ? 'The team reviews your property before it goes live.'
                                            : 'Your property goes live as soon as you confirm.'}
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="flex flex-col gap-5">
                                    {checks.length > 0 && (
                                        <ul className="flex flex-col gap-2">
                                            {checks.map((check) => (
                                                <li
                                                    key={check.key}
                                                    className="flex items-center gap-2 text-sm"
                                                >
                                                    {check.done ? (
                                                        <CircleCheck
                                                            aria-hidden
                                                            className="size-4 text-muted-foreground"
                                                        />
                                                    ) : (
                                                        <Circle
                                                            aria-hidden
                                                            className="size-4 text-muted-foreground"
                                                        />
                                                    )}
                                                    {check.label}
                                                    <span className="sr-only">
                                                        {check.done
                                                            ? 'Done'
                                                            : 'Not done'}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                    <Field className="max-w-xs">
                                        <FieldLabel htmlFor="opening_date">
                                            Opening date
                                        </FieldLabel>
                                        <DatePicker
                                            id="opening_date"
                                            value={openingDate}
                                            onChange={setOpeningDate}
                                            max={today}
                                            openOn={today}
                                            aria-invalid={!!errors.opening_date}
                                        />
                                        <input
                                            type="hidden"
                                            name="opening_date"
                                            value={openingDate}
                                        />
                                        <FieldError>
                                            {errors.opening_date}
                                        </FieldError>
                                    </Field>
                                </CardContent>
                                <CardFooter className="justify-end">
                                    <Button
                                        type="submit"
                                        disabled={
                                            processing ||
                                            !ready ||
                                            openingDate === ''
                                        }
                                        focusableWhenDisabled
                                    >
                                        {processing && <Spinner />}
                                        {requiresApproval
                                            ? 'Submit for approval'
                                            : 'Go live'}
                                    </Button>
                                </CardFooter>
                            </Card>
                        )}
                    </Form>
                )}
            </div>
        </>
    );
}

GoLive.layout = {
    breadcrumbs: [{ title: 'Go live', href: goLiveShow() }],
};
