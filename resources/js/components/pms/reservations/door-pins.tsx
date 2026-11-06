import { Check, Copy, KeyRound } from 'lucide-react';
import { useState } from 'react';
import { SetDoorPinDialog } from '@/components/pms/reservations/set-door-pin-dialog';
import { StatusBadge } from '@/components/pms/status-badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useClipboard } from '@/hooks/use-clipboard';
import { usePollWhile } from '@/hooks/use-poll-while';

type Pins = App.Data.DoorLocks.UnitDoorPinsData;
type Passcode = App.Data.DoorLocks.DoorPasscodeData;

const TIME: Intl.DateTimeFormatOptions = {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
};

const when = (iso: string) => new Date(iso).toLocaleString('en-GB', TIME);

/**
 * The PINs staff should look at now: every Active or Pending PIN, and the newest Failed one of a lock that has no working PIN.
 * Revoked and Expired PINs are history and go under "Earlier PINs".
 */
function split(passcodes: Passcode[]): {
    now: Passcode[];
    earlier: Passcode[];
} {
    const working = new Set(
        passcodes
            .filter((p) => ['active', 'pending'].includes(p.status.value))
            .map((p) => p.lockName),
    );
    const seenFailed = new Set<string>();
    const now: Passcode[] = [];
    const earlier: Passcode[] = [];

    for (const passcode of passcodes) {
        const status = passcode.status.value;

        if (status === 'active' || status === 'pending') {
            now.push(passcode);
        } else if (
            status === 'failed' &&
            !working.has(passcode.lockName) &&
            !seenFailed.has(passcode.lockName)
        ) {
            seenFailed.add(passcode.lockName);
            now.push(passcode);
        } else {
            earlier.push(passcode);
        }
    }

    return { now, earlier };
}

function PinRow({
    passcode,
    removing,
    updating,
    onSet,
}: {
    passcode: Passcode;
    removing: boolean;
    updating: boolean;
    onSet: () => void;
}) {
    const [copiedText, copy] = useClipboard();
    const failed = passcode.status.value === 'failed';

    return (
        <li className="flex flex-col gap-1 rounded-lg border bg-card px-3 py-1.5">
            <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                <span className="font-mono text-xl font-semibold tracking-[0.2em] tabular-nums">
                    {passcode.code ?? '—'}
                </span>
                {passcode.code !== null && (
                    <Button
                        variant="outline"
                        size="icon-sm"
                        aria-label="Copy PIN"
                        onClick={() => copy(passcode.code!)}
                    >
                        {copiedText === passcode.code ? <Check /> : <Copy />}
                    </Button>
                )}
                {removing ? (
                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Spinner /> Removing from the lock…
                    </span>
                ) : (
                    <StatusBadge status={passcode.status} />
                )}
                <span className="text-sm text-muted-foreground">
                    {passcode.lockName} · until {when(passcode.endsAt)}
                </span>
                {updating && !removing && (
                    <span className="flex items-center gap-1.5 text-sm text-muted-foreground">
                        <Spinner /> Updating the end time…
                    </span>
                )}
                {passcode.canReplace && !removing && !updating && (
                    <Button
                        variant="outline"
                        size="sm"
                        className="ml-auto"
                        onClick={onSet}
                    >
                        Set PIN
                    </Button>
                )}
            </div>
            {failed && passcode.error && (
                <p className="text-sm text-destructive">{passcode.error}</p>
            )}
        </li>
    );
}

/**
 * The door PINs of the stay, so staff can read one out at check-in. Shown only when a lock is linked to the unit's room.
 * It follows a PIN being made after check-in, or removed after check-out, by reloading itself until it settles.
 */
export function DoorPins({
    pins,
    reservationId,
    unitId,
}: {
    pins: Pins;
    reservationId: number;
    unitId: number;
}) {
    const [setting, setSetting] = useState<Passcode | null>(null);
    const { now, earlier } = split(pins.passcodes);
    const { polling, gaveUp } = usePollWhile(pins.syncing, {
        only: ['doorPins'],
    });

    return (
        <section className="flex items-start gap-x-2">
            <h4 className="flex h-11 shrink-0 items-center gap-1.5 text-muted-foreground">
                <KeyRound aria-hidden className="size-4" />
                Door PIN
            </h4>
            <div className="flex min-w-0 flex-1 flex-col gap-2">
                {gaveUp && (
                    <p className="text-sm text-warning">
                        The door PINs did not finish updating. The lock may be
                        unreachable. Refresh the page later, or use Set PIN.
                    </p>
                )}
                {pins.sendNewPin && (
                    <p className="text-sm text-warning">
                        The stay was extended. Give the guest the newest PIN.
                    </p>
                )}
                <ul className="flex flex-col gap-2">
                    {now.map((passcode) => (
                        <PinRow
                            key={passcode.id}
                            passcode={passcode}
                            removing={
                                polling && pins.leavingIds.includes(passcode.id)
                            }
                            updating={polling && pins.updating}
                            onSet={() => setSetting(passcode)}
                        />
                    ))}
                    {pins.locksWithoutPin.map((lock) => (
                        <li
                            key={lock.name}
                            className="flex items-center gap-2 rounded-lg border border-dashed px-3 py-2.5 text-sm text-muted-foreground"
                        >
                            {polling && pins.making && lock.reason === null ? (
                                <>
                                    <Spinner /> Making the PIN for {lock.name}…
                                </>
                            ) : (
                                <>
                                    No PIN yet for {lock.name}
                                    {lock.reason && ` · ${lock.reason}`}
                                </>
                            )}
                        </li>
                    ))}
                </ul>
                {earlier.length > 0 && (
                    <details className="text-sm text-muted-foreground">
                        <summary className="cursor-pointer select-none">
                            Earlier PINs ({earlier.length})
                        </summary>
                        <ul className="mt-2 flex flex-col gap-1.5">
                            {earlier.map((passcode) => (
                                <li
                                    key={passcode.id}
                                    className="flex flex-wrap items-center gap-x-3 gap-y-1"
                                >
                                    <span className="font-mono tabular-nums">
                                        {passcode.code ?? '—'}
                                    </span>
                                    <StatusBadge status={passcode.status} />
                                    <span>
                                        {passcode.lockName} ·{' '}
                                        {when(passcode.startsAt)} →{' '}
                                        {when(passcode.endsAt)}
                                    </span>
                                    {passcode.revokeNote && (
                                        <span className="text-warning">
                                            {passcode.revokeNote}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </details>
                )}
                {setting !== null && (
                    <SetDoorPinDialog
                        open
                        onOpenChange={(open) => !open && setSetting(null)}
                        reservationId={reservationId}
                        unitId={unitId}
                        passcode={setting}
                    />
                )}
            </div>
        </section>
    );
}
