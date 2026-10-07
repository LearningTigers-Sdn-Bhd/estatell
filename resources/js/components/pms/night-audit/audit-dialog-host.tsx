import { router, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { CheckInAllDialog } from '@/components/pms/reservations/check-in-all-dialog';
import { CheckInDialog } from '@/components/pms/reservations/check-in-dialog';
import { CheckOutDialog } from '@/components/pms/reservations/check-out-dialog';
import { show } from '@/routes/reservations';

type Action = App.Data.NightAudit.ReadinessActionData;
type Data = App.Data.NightAudit.NightAuditReservationData;

/**
 * Opens the check-in, check-in-all or check-out Dialog of the reservation page over the night audit sheet. The Dialog
 * loads its reservation, units and payment options from the sheet's own route when it opens, and the sheet reloads when
 * it closes. This is the one place a Dialog may open over another overlay. See docs/design-system/05-form-containers.md.
 */
export function AuditDialogHost({
    action,
    onLoaded,
    onClose,
    onNavigate,
}: {
    action: Action | null;
    /** Called when the Dialog's data has arrived or failed, so the button stops spinning. */
    onLoaded: () => void;
    onClose: () => void;
    onNavigate: () => void;
}) {
    const http = useHttp<Record<string, never>, Data>({});
    const [loaded, setLoaded] = useState<{ url: string; data: Data } | null>(
        null,
    );
    const [open, setOpen] = useState(false);
    const url = action?.url ?? null;

    useEffect(() => {
        if (url === null) {
            return;
        }

        let current = true;

        http.get(url, {
            onSuccess: (data) => {
                if (current) {
                    setLoaded({ url, data });
                    setOpen(true);
                }
            },
        })
            .catch(() => undefined)
            .finally(() => current && onLoaded());

        return () => {
            current = false;
        };
        // The Dialog loads once for each time it is asked for.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [action]);

    if (action === null || loaded === null || loaded.url !== action.url) {
        return null;
    }

    const { data } = loaded;
    const unit = data.units.find((item) => item.id === action.unitId);
    const change = (next: boolean) => {
        setOpen(next);

        if (!next) {
            onClose();
        }
    };

    return (
        // A submit inside a Dialog bubbles through React to the sheet's own form, so it stops here.
        <div onSubmit={(event) => event.stopPropagation()}>
            {action.dialog === 'check-in-all' && (
                <CheckInAllDialog
                    open={open}
                    onOpenChange={change}
                    reservation={data.reservation}
                />
            )}
            {action.dialog === 'check-in' && unit && (
                <CheckInDialog
                    open={open}
                    onOpenChange={change}
                    reservationId={data.reservation.id}
                    unit={unit}
                    paymentOptions={data.paymentOptions ?? undefined}
                    canAssignRoom={data.canAssignRoom}
                    onAssignRoom={() => {
                        onNavigate();
                        router.visit(
                            show.url({ reservation: data.reservation.id }),
                        );
                    }}
                    currency={data.currency}
                />
            )}
            {action.dialog === 'check-out' && unit && (
                <CheckOutDialog
                    open={open}
                    onOpenChange={change}
                    reservationId={data.reservation.id}
                    unit={unit}
                    canWaiveFees={data.canWaiveFees}
                    currency={data.currency}
                />
            )}
        </div>
    );
}
