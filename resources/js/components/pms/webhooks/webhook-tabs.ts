import { ListChecks, Webhook } from 'lucide-react';
import type { TabLink } from '@/components/pms/tab-links';
import { deliveries, index } from '@/routes/admin/webhooks';

export type WebhookEndpoint = App.Data.Webhooks.WebhookEndpointData;

/** The Endpoints and Deliveries tabs of the admin Webhooks page. */
export function webhookTabs(): TabLink[] {
    return [
        {
            value: 'endpoints',
            label: 'Endpoints',
            icon: Webhook,
            href: index(),
        },
        {
            value: 'deliveries',
            label: 'Deliveries',
            icon: ListChecks,
            href: deliveries(),
        },
    ];
}

export type EndpointState = 'active' | 'off' | 'disabled' | 'failing';

/** Active, Off (an admin turned it off), Disabled (the system turned it off), or Failing (active with failures). */
export function endpointState(endpoint: WebhookEndpoint): EndpointState {
    if (!endpoint.isActive) {
        return endpoint.disabledAt ? 'disabled' : 'off';
    }

    return endpoint.failingSince ? 'failing' : 'active';
}

/** The warning line under a Failing or Disabled endpoint, or null. */
export function endpointWarning(endpoint: WebhookEndpoint): string | null {
    const state = endpointState(endpoint);

    if (state === 'disabled') {
        return `Disabled by the system on ${endpoint.disabledAt}${endpoint.disabledReason ? ` · ${endpoint.disabledReason}` : ''}`;
    }

    if (state === 'failing') {
        return `Failing since ${endpoint.failingSince}`;
    }

    return null;
}
