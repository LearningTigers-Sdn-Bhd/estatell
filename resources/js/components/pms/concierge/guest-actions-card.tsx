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
    locationCheckEnabled,
    hasLocation,
}: {
    houseRules: string | null;
    walkInEnabled: boolean;
    refundRequestsEnabled: boolean;
    locationCheckEnabled: boolean;
    hasLocation: boolean;
}) {
    const [walkIn, setWalkIn] = useState(walkInEnabled);
    const [refunds, setRefunds] = useState(refundRequestsEnabled);
    const [locationCheck, setLocationCheck] = useState(locationCheckEnabled);

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
                        location_check_enabled: locationCheck,
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
                            <SwitchRow
                                id="location_check_enabled"
                                title="Check in at the property only"
                                body={
                                    hasLocation
                                        ? 'Ask the guest to allow location access, and check in only within 50 m of the property.'
                                        : 'Add the property location to the property profile first. Without it, this has no effect.'
                                }
                                checked={locationCheck}
                                onChange={setLocationCheck}
                            />
                        </>
                    )}
                </SettingsForm>
            </CardContent>
        </Card>
    );
}
