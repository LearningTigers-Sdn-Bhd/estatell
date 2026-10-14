import { TriangleAlert } from 'lucide-react';
import {
    Alert,
    AlertAction,
    AlertDescription,
    AlertTitle,
} from '@/components/ui/alert';
import { Button } from '@/components/ui/button';

/**
 * The night audit alert: shown only when something is wrong. The server writes the sentence. Open audit needs
 * `night-audit.run`, so without it the alert only informs.
 */
export function DashboardAlert({
    alert,
    onOpenAudit,
}: {
    alert: App.Data.Dashboard.DashboardAlertData;
    onOpenAudit?: () => void;
}) {
    return (
        <Alert
            variant="destructive"
            className={onOpenAudit ? 'pr-36' : undefined}
        >
            <TriangleAlert />
            <AlertTitle>Night audit</AlertTitle>
            <AlertDescription>{alert.message}</AlertDescription>
            {onOpenAudit && (
                <AlertAction className="top-1/2 -translate-y-1/2">
                    <Button variant="outline" size="sm" onClick={onOpenAudit}>
                        Open audit
                    </Button>
                </AlertAction>
            )}
        </Alert>
    );
}
