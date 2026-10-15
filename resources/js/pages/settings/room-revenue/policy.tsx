import { usePage } from '@inertiajs/react';
import { PolicyEditor } from '@/components/pms/room-revenue/policy-editor';
import type { PolicyEditorProps } from '@/components/pms/room-revenue/policy-editor';
import { index } from '@/routes/settings/room-revenue';

export default function RoomRevenuePolicy(props: PolicyEditorProps) {
    const { url } = usePage();

    return <PolicyEditor key={url.split('?')[0]} {...props} />;
}

RoomRevenuePolicy.layout = {
    variant: 'fixed',
    breadcrumbs: [{ title: 'Room revenue', href: index() }],
};
