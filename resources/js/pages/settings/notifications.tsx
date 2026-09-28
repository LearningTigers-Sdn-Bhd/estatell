import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    NotificationPreferenceMatrix,
    preferenceKey,
    updatePreferenceMatrix,
} from '@/components/pms/notification-preference-matrix';
import { PageHeader } from '@/components/pms/page-header';
import { index, update } from '@/routes/settings/notifications';

type Preference = App.Data.Notifications.NotificationPreferenceData;
type Cell = App.Data.Notifications.NotificationChannelPreferenceData;

export default function PropertyNotifications({
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
            <Head title="Notification settings" />
            <div className="flex flex-1 flex-col gap-6 p-4">
                <PageHeader
                    title="Notifications"
                    description="Choose which channels the property uses for each notification."
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

PropertyNotifications.layout = {
    breadcrumbs: [{ title: 'Notifications', href: index() }],
};
