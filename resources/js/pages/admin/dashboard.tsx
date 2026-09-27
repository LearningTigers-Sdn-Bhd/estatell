import { Head } from '@inertiajs/react';
import { dashboard } from '@/routes/admin';

export default function AdminDashboard() {
    return (
        <>
            <Head title="Admin" />
            <div className="flex flex-1 flex-col gap-2 p-4">
                <h1 className="text-xl font-semibold">Admin</h1>
                <p className="text-sm text-muted-foreground">
                    Properties, onboarding and platform settings will appear
                    here.
                </p>
            </div>
        </>
    );
}

AdminDashboard.layout = {
    breadcrumbs: [
        {
            title: 'Admin',
            href: dashboard(),
        },
    ],
};
