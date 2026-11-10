import { useState } from 'react';
import {
    fieldsFromCard,
    mykadToggleState,
    pickCardFields,
    targetRowKey,
} from '@/components/pms/reservations/guest-mykad';
import type {
    CardFields,
    MykadTarget,
    MykadTargetRow,
} from '@/components/pms/reservations/guest-mykad';
import { useMykadReader } from '@/hooks/use-mykad-reader';

export type MykadTargetControls = {
    activeKey: string | null;
    turnOn: (key: string) => void;
    turnOff: () => void;
    /** After a fill: the next empty row becomes the target, or none. */
    moveOn: () => void;
};

/** Which guest row the next card fills, for one table of guest rows. */
export function useMykadTarget(rows: MykadTargetRow[]): MykadTargetControls {
    const [target, setTarget] = useState<MykadTarget>({ mode: 'auto' });

    return {
        activeKey: targetRowKey(target, rows),
        turnOn: (key) => setTarget({ mode: 'row', key }),
        turnOff: () => setTarget({ mode: 'off' }),
        moveOn: () => setTarget({ mode: 'auto' }),
    };
}

/**
 * The MyKad toggle of one guest row. The row listens for a card only while it is the target, and keeps the values from
 * before the scan so a clear can put them back.
 */
export function useGuestMykadRow({
    rowKey,
    values,
    target,
    apply,
    onFilled,
    withIdentity = false,
}: {
    rowKey: string;
    values: CardFields | null;
    target: MykadTargetControls;
    apply: (fields: CardFields) => void;
    onFilled: () => void;
    withIdentity?: boolean;
}) {
    const [before, setBefore] = useState<CardFields | null>(null);
    const active = target.activeKey === rowKey;
    const state = mykadToggleState(active, before !== null);

    useMykadReader({
        listening: active,
        priority: 'row',
        onScan: (card) => {
            if (values === null) {
                return;
            }

            setBefore((current) => current ?? pickCardFields(values));
            apply(fieldsFromCard(card, withIdentity));
            onFilled();
            target.moveOn();
        },
    });

    return {
        state,
        press: () => {
            if (state === 'on') {
                target.turnOff();

                return;
            }

            if (before !== null) {
                apply(before);
                setBefore(null);
            }

            target.turnOn(rowKey);
        },
        /** The row was saved or now holds another guest, so there is no scan left to clear. */
        forget: () => setBefore(null),
    };
}
