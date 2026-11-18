import { router } from '@inertiajs/react';
import { CreditCard } from 'lucide-react';
import { useState } from 'react';
import BookingWebsiteController from '@/actions/App/Http/Controllers/BookingEngine/BookingWebsiteController';
import { SectionHeader } from '@/components/pms/booking-website/section-header';
import {
    Field,
    FieldContent,
    FieldDescription,
    FieldLabel,
} from '@/components/ui/field';
import { Switch } from '@/components/ui/switch';

/**
 * The Payments section of Settings › Booking website › General: one switch between Test and Live payments. It saves
 * at once. Staff without the payment mode permission see it locked.
 */
export function PaymentModeSection({
    paymentMode,
    canChange,
}: {
    paymentMode: string;
    canChange: boolean;
}) {
    const [saving, setSaving] = useState(false);
    const isTest = paymentMode === 'test';

    function save(test: boolean) {
        router.put(
            BookingWebsiteController.updatePaymentMode.url(),
            { payment_mode: test ? 'test' : 'live' },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <section className="flex flex-col gap-4">
            <SectionHeader
                title="Payments"
                description="How guests pay on your booking website."
                icon={CreditCard}
            />
            <Field orientation="horizontal" className="ps-11">
                <Switch
                    id="test-payments"
                    checked={isTest}
                    disabled={!canChange || saving}
                    onCheckedChange={save}
                />
                <FieldContent>
                    <FieldLabel htmlFor="test-payments">
                        Test payments
                    </FieldLabel>
                    <FieldDescription>
                        {isTest
                            ? 'Guests pay with Curlec test cards and no real money moves. Bookings are marked Test. Cancel them after checking.'
                            : 'Guests pay real money.'}
                        {!canChange &&
                            ' Ask someone with payment mode access to change this.'}
                    </FieldDescription>
                </FieldContent>
            </Field>
        </section>
    );
}
