import { Banknote, ReceiptText } from 'lucide-react';
import type { Choice } from '@/components/pms/choice-cards';

/**
 * How a company pays. The value is sent as `direct_bill_enabled` ("1" or "0").
 */
export const billingChoices: Choice[] = [
    {
        value: '0',
        label: 'Pay at check-out',
        description:
            'The guest pays the bill at the front desk before leaving. Nothing is owed afterwards.',
        icon: Banknote,
    },
    {
        value: '1',
        label: 'Direct billing',
        description:
            "Stays are charged to the company's account. We send an invoice and the company pays later, within its payment terms.",
        icon: ReceiptText,
    },
];
