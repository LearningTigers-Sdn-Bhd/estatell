import type { RouteFormDefinition, RouteQueryOptions } from '@/wayfinder';
import { show as eventPage } from '@/routes/events';
import {
    cityLedger as eventCityLedger,
    close as eventClose,
    invoice as eventInvoice,
    reopen as eventReopen,
    voidInvoiceAndReopen as eventVoidInvoiceAndReopen,
    voucher as eventVoucher,
} from '@/routes/events/folios';
import { store as eventCharge } from '@/routes/events/folios/charges';
import {
    destroy as eventDiscountDestroy,
    quote as eventDiscountQuote,
    store as eventDiscountStore,
} from '@/routes/events/folios/discounts';
import { reverse as eventReverse } from '@/routes/events/folios/lines';
import {
    receipt as eventReceipt,
    store as eventPayment,
    voidMethod as eventVoidPayment,
} from '@/routes/events/folios/payments';
import { store as eventRefund } from '@/routes/events/folios/payments/refunds';
import { store as eventRefundCredit } from '@/routes/events/folios/refund-credit';
import { quote as eventPaymentQuote } from '@/routes/events/payments';
import { folio as reservationPage } from '@/routes/reservations';
import {
    cityLedger as reservationCityLedger,
    close as reservationClose,
    invoice as reservationInvoice,
    reopen as reservationReopen,
    voidInvoiceAndReopen as reservationVoidInvoiceAndReopen,
    voucher as reservationVoucher,
} from '@/routes/reservations/folios';
import { store as reservationCharge } from '@/routes/reservations/folios/charges';
import {
    destroy as reservationDiscountDestroy,
    quote as reservationDiscountQuote,
    store as reservationDiscountStore,
} from '@/routes/reservations/folios/discounts';
import { reverse as reservationReverse } from '@/routes/reservations/folios/lines';
import {
    receipt as reservationReceipt,
    store as reservationPayment,
    voidMethod as reservationVoidPayment,
} from '@/routes/reservations/folios/payments';
import { store as reservationRefund } from '@/routes/reservations/folios/payments/refunds';
import { store as reservationRefundCredit } from '@/routes/reservations/folios/refund-credit';
import { quote as reservationPaymentQuote } from '@/routes/reservations/payments';

/**
 * What a folio belongs to: a reservation, or an event (people who are not staying). The folio panel and its Dialogs
 * take an owner and post to that owner's folio routes. An event folio has no units, routing, moves, manual entry or
 * e-invoice, so those routes exist for reservations only.
 */
export type FolioOwner =
    | { kind: 'reservation'; id: number }
    | { kind: 'event'; id: number };

export function reservationOwner(id: number): FolioOwner {
    return { kind: 'reservation', id };
}

type Endpoint<M extends 'get' | 'post' | 'delete' | 'put'> = {
    url: string;
    form: RouteFormDefinition<M>;
};

type WayfinderRoute<A, M extends 'get' | 'post' | 'delete' | 'put'> = {
    url: (args: A, options?: RouteQueryOptions) => string;
    form: (args: A, options?: RouteQueryOptions) => RouteFormDefinition<M>;
};

function endpoint<A, M extends 'get' | 'post' | 'delete' | 'put'>(
    route: WayfinderRoute<A, M>,
    args: A,
    options?: RouteQueryOptions,
): Endpoint<M> {
    return { url: route.url(args, options), form: route.form(args, options) };
}

/**
 * The folio routes of an owner. Each takes the folio id (and the line or payment it acts on).
 */
export function folioRoutes(owner: FolioOwner) {
    if (owner.kind === 'event') {
        const at = (folio: number) => ({ event: owner.id, folio });

        return {
            page: (key?: string) =>
                eventPage.url(
                    { event: owner.id },
                    key === undefined ? undefined : { query: { folio: key } },
                ),
            charge: (folio: number) => endpoint(eventCharge, at(folio)),
            reverse: (folio: number, line: number) =>
                endpoint(eventReverse, { ...at(folio), line }),
            discountQuote: (folio: number) =>
                endpoint(eventDiscountQuote, at(folio)),
            discountStore: (folio: number) =>
                endpoint(eventDiscountStore, at(folio)),
            discountDestroy: (folio: number, line: number) =>
                endpoint(eventDiscountDestroy, { ...at(folio), line }),
            close: (folio: number) => endpoint(eventClose, at(folio)),
            reopen: (folio: number) => endpoint(eventReopen, at(folio)),
            voidInvoiceAndReopen: (folio: number) =>
                endpoint(eventVoidInvoiceAndReopen, at(folio)),
            cityLedger: (folio: number) => endpoint(eventCityLedger, at(folio)),
            payment: (folio: number) => endpoint(eventPayment, at(folio)),
            paymentQuote: () =>
                endpoint(eventPaymentQuote, { event: owner.id }),
            refund: (folio: number, payment: number) =>
                endpoint(eventRefund, { ...at(folio), payment }),
            voidPayment: (folio: number, payment: number) =>
                endpoint(eventVoidPayment, { ...at(folio), payment }),
            refundCredit: (folio: number) =>
                endpoint(eventRefundCredit, at(folio)),
            receipt: (folio: number, payment: number) =>
                endpoint(eventReceipt, { ...at(folio), payment }),
            voucher: (folio: number, options?: RouteQueryOptions) =>
                endpoint(eventVoucher, at(folio), options),
            invoice: (folio: number) => endpoint(eventInvoice, at(folio)),
        };
    }

    const at = (folio: number) => ({ reservation: owner.id, folio });

    return {
        page: (key?: string) =>
            reservationPage.url(
                { reservation: owner.id },
                key === undefined ? undefined : { query: { folio: key } },
            ),
        charge: (folio: number) => endpoint(reservationCharge, at(folio)),
        reverse: (folio: number, line: number) =>
            endpoint(reservationReverse, { ...at(folio), line }),
        discountQuote: (folio: number) =>
            endpoint(reservationDiscountQuote, at(folio)),
        discountStore: (folio: number) =>
            endpoint(reservationDiscountStore, at(folio)),
        discountDestroy: (folio: number, line: number) =>
            endpoint(reservationDiscountDestroy, { ...at(folio), line }),
        close: (folio: number) => endpoint(reservationClose, at(folio)),
        reopen: (folio: number) => endpoint(reservationReopen, at(folio)),
        voidInvoiceAndReopen: (folio: number) =>
            endpoint(reservationVoidInvoiceAndReopen, at(folio)),
        cityLedger: (folio: number) =>
            endpoint(reservationCityLedger, at(folio)),
        payment: (folio: number) => endpoint(reservationPayment, at(folio)),
        paymentQuote: () =>
            endpoint(reservationPaymentQuote, { reservation: owner.id }),
        refund: (folio: number, payment: number) =>
            endpoint(reservationRefund, { ...at(folio), payment }),
        voidPayment: (folio: number, payment: number) =>
            endpoint(reservationVoidPayment, { ...at(folio), payment }),
        refundCredit: (folio: number) =>
            endpoint(reservationRefundCredit, at(folio)),
        receipt: (folio: number, payment: number) =>
            endpoint(reservationReceipt, { ...at(folio), payment }),
        voucher: (folio: number, options?: RouteQueryOptions) =>
            endpoint(reservationVoucher, at(folio), options),
        invoice: (folio: number) => endpoint(reservationInvoice, at(folio)),
    };
}
