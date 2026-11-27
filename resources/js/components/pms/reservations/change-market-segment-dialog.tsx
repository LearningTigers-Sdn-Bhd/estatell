import { useForm } from '@inertiajs/react';
import { FormOverlay } from '@/components/pms/form-overlay';
import { RecordSelectField } from '@/components/pms/reservations/record-select-field';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useFreshOnOpen } from '@/components/pms/use-fresh-on-open';
import { update } from '@/routes/reservations/market-segment';

type Reservation = App.Data.Reservations.ReservationData;
type Segment = App.Data.Reservations.MarketSegmentData;

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservation: Reservation;
    marketSegments?: Segment[];
};

function ChangeMarketSegmentForm({
    open,
    onOpenChange,
    reservation,
    marketSegments,
}: Props) {
    useFormOptions(open, marketSegments !== undefined, 'marketSegments');

    const form = useForm({
        market_segment_id: reservation.marketSegmentId
            ? String(reservation.marketSegmentId)
            : '',
    });
    const { data, errors } = form;

    const submit = () => {
        form.put(update.url({ reservation: reservation.id }), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <FormOverlay
            variant="dialog"
            open={open}
            onOpenChange={onOpenChange}
            title="Change market segment"
            description={reservation.reference}
            errors={errors}
            onSubmit={submit}
            submitLabel="Save market segment"
            processing={form.processing}
            loading={marketSegments === undefined}
        >
            <RecordSelectField
                id="change-market_segment_id"
                label="Market segment"
                value={data.market_segment_id}
                onValueChange={(value) =>
                    form.setData('market_segment_id', value)
                }
                records={marketSegments ?? []}
                current={
                    reservation.marketSegmentId !== null
                        ? {
                              id: reservation.marketSegmentId,
                              name: reservation.marketSegmentName ?? '',
                          }
                        : null
                }
                error={errors.market_segment_id}
            />
        </FormOverlay>
    );
}

/**
 * Sets or clears the market segment of the booking. It works on closed reservations too. The segment list loads when
 * the Dialog opens.
 */
export function ChangeMarketSegmentDialog(props: Props) {
    return (
        <ChangeMarketSegmentForm key={useFreshOnOpen(props.open)} {...props} />
    );
}
