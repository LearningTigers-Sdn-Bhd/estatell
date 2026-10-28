import { useForm, useHttp } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import {
    KEEP_OPTIONS,
    useFormOptions,
} from '@/components/pms/use-form-options';
import {
    planPayload,
    visibleErrors,
} from '@/components/pms/reservations/day-use-check-out-plan';
import { checkOutAsDayUse } from '@/routes/reservations/units';
import { preview as previewRoute } from '@/routes/reservations/units/check-out-as-day-use';

type Preview = App.Data.Reservations.CheckOutAsDayUseData;
type Options = App.Data.Payments.PaymentFormOptionsData;

/** The time without seconds: a day use is priced from whole minutes. */
const minute = (time: string | null | undefined) => time?.slice(0, 5) ?? '';

/** The server quotes every change. Old responses never enable a save of newer fields. */
export function useDayUseCheckOut({
    open,
    onOpenChange,
    reservationId,
    unitId,
    paymentOptions,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    reservationId: number;
    unitId: number;
    paymentOptions?: Options;
}) {
    const routeKey = { reservation: reservationId, unit: unitId };
    const [idempotencyKey] = useState(() => crypto.randomUUID());
    const [overriding, setOverriding] = useState(false);
    // '' until staff touch the Plan select, then a plan id or 'none'.
    const [planChoice, setPlanChoice] = useState('');
    const [saveAttempted, setSaveAttempted] = useState(false);
    const [answer, setAnswer] = useState<{
        key: string;
        preview: Preview;
    } | null>(null);
    const [requestError, setRequestError] = useState<string | null>(null);
    const form = useForm({
        start: '',
        end: '',
        amount: '',
        reason: '',
        refund_amount: '',
        payment_method_id: '',
        reference: '',
    });
    const { data, setData } = form;
    const payload = {
        ...(data.start !== '' && { start: data.start }),
        ...(data.end !== '' && { end: data.end }),
        ...(data.amount !== '' && { amount: data.amount }),
        ...(data.reason !== '' && { reason: data.reason }),
        ...planPayload(planChoice),
    };
    const key = JSON.stringify(payload);
    // The key of the last answer. The first quote is sent at once; later changes wait for typing to stop.
    const answered = useRef<string | null>(null);
    const http = useHttp<Record<string, string>, Preview>(
        'post',
        previewRoute.url(routeKey),
        {},
    );
    const { submit, transform } = http;

    useEffect(() => {
        if (!open) {
            return;
        }

        if (answered.current === key) {
            return;
        }

        let current = true;
        const timer = window.setTimeout(
            () => {
                transform(() => JSON.parse(key));
                submit()
                    .then((preview) => {
                        if (!current) {
                            return;
                        }

                        setRequestError(null);
                        const typed = JSON.parse(key) as Record<string, string>;
                        // Freeze the real check-in and now on first load; later quotes keep these times.
                        const times = {
                            start: typed.start ?? minute(preview.start),
                            end: typed.end ?? minute(preview.end),
                        };
                        const answerKey = JSON.stringify(
                            Object.fromEntries(
                                Object.entries({ ...times, ...typed }).filter(
                                    ([, value]) => value !== '',
                                ),
                            ),
                        );
                        answered.current = answerKey;
                        setData((values) => ({ ...values, ...times }));
                        setAnswer({ key: answerKey, preview });
                    })
                    .catch(() => {
                        if (current) {
                            setRequestError(
                                'Could not load the preview. Change a field to try again.',
                            );
                        }
                    });
            },
            answered.current === null ? 0 : 300,
        );

        return () => {
            current = false;
            window.clearTimeout(timer);
        };
    }, [open, key, submit, transform, setData]);

    const preview = answer?.preview ?? null;
    const current = answer?.key === key && requestError === null;
    const settlement = preview?.settlement ?? null;
    const methods = (paymentOptions?.methods ?? []).filter(
        (method) => !method.isOldSystem,
    );
    const methodId = data.payment_method_id || String(methods[0]?.id ?? '');
    const method = methods.find((one) => String(one.id) === methodId);
    const refund = data.refund_amount || settlement?.refundDefault || '0.00';
    const owing = settlement?.owing ?? '0.00';
    const collecting = Number(owing) > 0 && settlement?.canCollect === true;
    const refunding =
        Number(settlement?.balance ?? 0) < 0 &&
        Number(refund) > 0 &&
        settlement?.canRefund === true;
    const settled =
        settlement !== null &&
        settlement.error === null &&
        (Number(settlement.balance) === 0 ||
            collecting ||
            (refunding &&
                Math.abs(Number(settlement.balance) + Number(refund)) < 0.005));
    useFormOptions(
        open && collecting,
        paymentOptions !== undefined,
        'paymentOptions',
    );

    const amountMissing = current && preview?.refusal === 'amount_required';

    const pickPlan = (value: string) => {
        setPlanChoice(value);
        setOverriding(false);
        setSaveAttempted(false);
        form.setData((values) => ({ ...values, amount: '', reason: '' }));
        form.clearErrors('amount', 'reason');
    };

    const save = () => {
        if (amountMissing) {
            setSaveAttempted(true);

            return;
        }

        if (!current || preview?.refusal !== null || !settled) {
            return;
        }

        form.transform((values) => ({
            ...values,
            amount: values.amount || null,
            refund_amount: refunding ? refund : '',
            collect_amount: collecting ? owing : '',
            payment_method_id: collecting ? methodId : '',
            reference:
                collecting && method?.requiresReference ? values.reference : '',
            idempotency_key: idempotencyKey,
        }));
        form.post(checkOutAsDayUse.url(routeKey), {
            preserveScroll: true,
            except: KEEP_OPTIONS,
            onSuccess: () => onOpenChange(false),
        });
    };

    return {
        form,
        preview,
        current,
        overriding,
        setOverriding,
        settlement,
        methods,
        methodId,
        method,
        refund,
        owing,
        collecting,
        refunding,
        planChoice,
        pickPlan,
        errors: visibleErrors(
            {
                ...(current ? preview?.errors : {}),
                ...http.errors,
                ...form.errors,
                ...(requestError !== null &&
                    !http.hasErrors && { form: requestError }),
            },
            current ? preview?.refusal : null,
            saveAttempted,
        ),
        canSubmit:
            amountMissing ||
            (current &&
                preview?.refusal === null &&
                settled &&
                (!collecting || methodId !== '')),
        canSave:
            current &&
            preview?.refusal === null &&
            settled &&
            (!collecting || methodId !== ''),
        save,
    };
}
