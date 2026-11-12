import { useState } from 'react';
import { SwitchRow } from '@/components/pms/concierge/content-fields';
import { SettingsForm } from '@/components/pms/concierge/settings-form';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

/** What guests can do from the lobby and stay pages. House rules are sent along unchanged. */
export function GuestActionsCard({
    houseRules,
    walkInEnabled,
    refundRequestsEnabled,
}: {
    houseRules: string | null;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
}) {
    const [walkIn, setWalkIn] = useState(walkInEnabled);
    const [refunds, setRefunds] = useState(refundRequestsEnabled);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Guest actions</CardTitle>
                <CardDescription>
                    Choose what guests can do from the lobby and stay pages.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <SettingsForm
                    kept={{
                        house_rules: houseRules ?? '',
                        walk_in_enabled: walkIn,
                        refund_requests_enabled: refunds,
                    }}
                >
                    {() => (
                        <>
                            <SwitchRow
                                id="walk_in_enabled"
                                title="Guests with no booking"
                                body="Let a guest book a room for tonight on the lobby page. The room is held for 10 minutes and released if it is not paid for."
                                checked={walkIn}
                                onChange={setWalkIn}
                            />
                            <SwitchRow
                                id="refund_requests_enabled"
                                title="Refund requests"
                                body="Let a guest ask for a refund after check-out. It reaches the Requests list for staff to answer. Nothing is refunded automatically."
                                checked={refunds}
                                onChange={setRefunds}
                            />
                        </>
                    )}
                </SettingsForm>
            </CardContent>
        </Card>
    );
}
