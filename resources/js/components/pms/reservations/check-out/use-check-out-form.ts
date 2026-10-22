import { useForm } from '@inertiajs/react';
import type { InertiaFormProps } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { useUnitPreview } from '@/components/pms/reservations/use-unit-preview';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import { useCanCorrectMoney } from '@/hooks/use-is-impersonating';
import { folio } from '@/routes/reservations';
import { move as moveCharges } from '@/routes/reservations/folio';
import {
    checkOut,
    checkOutPreview,
    earlyCheckOut,
} from '@/routes/reservations/units';
import { checkOutFigures } from './check-out-figures';

type Preview = App.Data.Reservations.UnitCheckOutPreviewData;
type Options = App.Data.Payments.PaymentFormOptionsData;

export type CheckOutData = {
    deposit: string;
    refund_amount: string;
    payment_method_id: string;
    reference: string;
    do_not_charge: boolean;
    reason: string;
    expected_fee: string;
    stayed_last_night: boolean;
    keep_damage: boolean;
    damage_amount: string;
    damage_note: string;
};

export type CheckOutForm = InertiaFormProps<CheckOutData>;
export type OverdueChoice = '' | 'left' | 'stayed';

type Params = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unitId: number;
    paymentOptions?: Options;
    canWaiveFees: boolean;
};

/**
 * The state of the check-out Dialog: the form, the choices the cards make, the server's preview and the figures worked
 * out from them (`checkOutFigures`). The Dialog and its sections only read this and draw it.
 */
export function useCheckOutForm({
    open,
    onOpenChange,
    reservationId,
    unitId,
    paymentOptions,
    canWaiveFees,
}: Params) {
    const key = { reservation: reservationId, unit: unitId };
    const [leftYesterday, setLeftYesterday] = useState(false);
    const [overdueChoice, setOverdueChoice] = useState<OverdueChoice>('');
    const form = useForm<CheckOutData>({
        deposit: '',
        refund_amount: '',
        payment_method_id: '',
        reference: '',
        do_not_charge: false,
        reason: '',
        expected_fee: '',
        stayed_last_night: false,
        keep_damage: false,
        damage_amount: '',
        damage_note: '',
    });
    const { data, errors } = form;
    const damageTyped =
        data.keep_damage && /^\d+(\.\d{1,2})?$/.test(data.damage_amount)
            ? data.damage_amount
            : '';
    const { preview, reload } = useUnitPreview<Preview>(
        checkOutPreview.url(key),
        open,
        {
            ...(leftYesterday && { left_yesterday: '1' }),
            ...(overdueChoice === 'stayed' && { stayed_last_night: '1' }),
            ...(damageTyped !== '' && { damage_amount: damageTyped }),
        },
        data.keep_damage ? 300 : 0,
    );
    useFormOptions(
        open && preview?.canCollect === true,
        paymentOptions !== undefined,
        'paymentOptions',
    );
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const methods = (paymentOptions?.methods ?? []).filter(
        (one) => !one.isOldSystem,
    );
    const early = preview?.early ?? null;
    const overdue = preview?.overdue ?? null;
    const pendingDay = preview?.auditPending?.businessDate ?? '';
    // Display rules only: the server decides who is overdue and sends the dates as text.
    const mustChoose = overdue !== null && overdueChoice === '';
    const needsReason = leftYesterday && data.reason.trim() === '';
    const methodId = data.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);
    const canCorrectMoney = useCanCorrectMoney();

    useEffect(() => {
        if (errors.expected_fee) {
            reload();
        }
        // Ask again only when the server says the fee changed.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [errors.expected_fee]);

    const figures = checkOutFigures({
        preview,
        canWaiveFees,
        canCorrectMoney,
        doNotCharge: data.do_not_charge,
        keepDamage: data.keep_damage,
        damageTyped,
        depositChoice: data.deposit,
        refundInput: data.refund_amount,
    });
    const verbs = [
        figures.collecting && 'collect',
        figures.refunding && figures.refund > 0 && 'refund',
    ].filter(Boolean);
    const action = early !== null ? 'check out early' : 'check out';
    const label =
        verbs.length === 0 ? action : `${verbs.join(', ')} and ${action}`;
    const folioLink =
        preview?.folioKey != null
            ? folio.url(
                  { reservation: reservationId },
                  { query: { folio: preview.folioKey } },
              )
            : null;
    const others = preview?.rooms.filter((room) => !room.own) ?? [];
    // Another room's scheduled nights block check-out: the room's scheduled choice is on the page, nothing is ticked.
    const moveLink =
        preview?.canMoveCharges === true &&
        (preview.receivesScheduled || preview.otherLineIds.length > 0)
            ? moveCharges.url(
                  { reservation: reservationId },
                  preview.receivesScheduled
                      ? undefined
                      : { query: { lines: preview.otherLineIds } },
              )
            : null;

    const submit = () => {
        form.transform((values) => ({
            refund_amount: figures.refunding ? figures.refundAmount : '',
            collect_amount: figures.collecting
                ? figures.collectAmount.toFixed(2)
                : '',
            payment_method_id: figures.collecting ? methodId : '',
            reference:
                figures.collecting && method?.requiresReference
                    ? values.reference
                    : '',
            idempotency_key: idempotencyKey,
            damage_amount: figures.damaging ? values.damage_amount : '',
            damage_note: figures.damaging ? values.damage_note : '',
            ...(early !== null && {
                do_not_charge: figures.waived,
                reason: values.reason,
                expected_fee: early.fee,
                left_yesterday: leftYesterday,
            }),
            ...(overdueChoice === 'stayed' && { stayed_last_night: true }),
        }));
        form.post((early !== null ? earlyCheckOut : checkOut).url(key), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return {
        form,
        preview,
        figures,
        early,
        overdue,
        pendingDay,
        leftYesterday,
        setLeftYesterday,
        overdueChoice,
        setOverdueChoice,
        mustChoose,
        needsReason,
        methods,
        methodId,
        method,
        canCorrectMoney,
        label,
        folioLink,
        others,
        moveLink,
        submit,
    };
}

export type CheckOutState = ReturnType<typeof useCheckOutForm>;
