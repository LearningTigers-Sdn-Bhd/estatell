import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { checkIn } from '@/routes/concierge';

/** A heading and a sentence, for the steps that only tell the guest something. */
export function MessageStep({ title, body }: { title: string; body: string }) {
    return (
        <div className="flex flex-col gap-2 rounded-lg border p-4">
            <h2 className="text-lg font-semibold">{title}</h2>
            <p className="text-sm text-pretty text-muted-foreground">{body}</p>
        </div>
    );
}

/** The last step before the key: one button that checks the guest in. */
export function CheckInStep({
    stay,
}: {
    stay: App.Data.Concierge.ConciergeStayData;
}) {
    const [busy, setBusy] = useState(false);

    const send = (position?: GeolocationPosition) =>
        router.post(
            checkIn.url({ token: stay.token }),
            position
                ? {
                      latitude: position.coords.latitude,
                      longitude: position.coords.longitude,
                  }
                : {},
            {
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );

    // A property that checks the guest's position gets it from the browser. A refused prompt sends none, and the server
    // tells the guest to allow location access.
    const checkInNow = () =>
        stay.locationRequired && navigator.geolocation
            ? navigator.geolocation.getCurrentPosition(send, () => send(), {
                  enableHighAccuracy: true,
                  timeout: 15000,
              })
            : send();

    return (
        <div className="flex flex-col gap-6">
            <div className="flex flex-col gap-1">
                <h2 className="text-lg font-semibold">Ready to check in</h2>
                <p className="text-sm text-pretty text-muted-foreground">
                    Everything is done. Check in to get your room and door code.
                </p>
            </div>
            <Button
                size="lg"
                className="h-12 w-full rounded-full"
                disabled={busy}
                focusableWhenDisabled
                onClick={checkInNow}
            >
                {busy && <Spinner data-icon="inline-start" />}
                Check in now
            </Button>
        </div>
    );
}
