import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import {
    NotificationPreferenceMatrix,
    preferenceKey,
    updatePreferenceMatrix,
} from '@/components/pms/notification-preference-matrix';
import { edit, update } from '@/routes/account/notifications';

type Preference = App.Data.Notifications.NotificationPreferenceData;
type Cell = App.Data.Notifications.NotificationChannelPreferenceData;

export default function AccountNotifications({
    preferences,
}: {
    preferences: Preference[];
}) {
    const [pending, setPending] = useState<Set<string>>(new Set());

    const toggle = (
        type: Preference['type'],
        channel: Cell['channel'],
        enabled: boolean,
    ) => {
        const key = preferenceKey(type, channel);
        setPending((current) => new Set(current).add(key));

        router
            .optimistic<{ preferences: Preference[] }>((props) => ({
                preferences: updatePreferenceMatrix(
                    props.preferences,
                    type,
                    channel,
                    enabled,
                ),
            }))
            .put(
                update.url({ type, channel }),
                { enabled },
                {
                    preserveScroll: true,
                    only: ['preferences'],
                    onFinish: () =>
                        setPending((current) => {
                            const next = new Set(current);
                            next.delete(key);
                            return next;
                        }),
                },
            );
    };

    return (
        <>
            <Head title="Notification preferences" />
            <div className="flex flex-col gap-6">
                <Heading
                    variant="small"
                    title="Notifications"
                    description="Choose how you receive notifications for your property."
                />
                <NotificationPreferenceMatrix
                    preferences={preferences}
                    pending={pending}
                    onChange={toggle}
                />
            </div>
        </>
    );
}

AccountNotifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: edit() }],
};
