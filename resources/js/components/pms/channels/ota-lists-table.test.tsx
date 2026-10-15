import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test } from 'vite-plus/test';
import {
    OtaListsTable,
    blankOtaListRow,
    otaListRowFromSaved,
} from './ota-lists-table';

const channels = [
    { id: 1, name: 'Agoda', isOther: false },
    { id: 9, name: 'Other', isOther: true },
];

const savedRow = (overrides: Record<string, unknown> = {}) =>
    otaListRowFromSaved({
        id: 5,
        otaChannelId: 1,
        channelLabel: 'Agoda',
        channelName: null,
        propertyCode: '123456',
        username: 'frontdesk@seaview.my',
        hasPassword: true,
        marketManagerName: null,
        marketManagerPhone: null,
        marketManagerEmail: null,
        status: { value: 'pending', label: 'Pending', tone: 'warning' },
        ...overrides,
    } as App.Data.Onboarding.OnboardingOtaCredentialRowData);

const render = (rows: ReturnType<typeof savedRow>[]) =>
    renderToStaticMarkup(
        <OtaListsTable
            rows={rows}
            channels={channels}
            maxRows={20}
            errors={{}}
            showStatus
            lockSavedChannels
            onRowsChange={() => {}}
            onRemoveRow={() => {}}
        />,
    );

test('a saved row shows its channel as text, not a select', () => {
    const markup = render([savedRow()]);

    expect(markup).not.toContain('aria-label="Channel of row 1"');
    expect(markup).toContain('Agoda');
});

test('a saved Other row also locks its channel name', () => {
    const markup = render([
        savedRow({ otaChannelId: 9, channelName: 'Traveloka' }),
    ]);

    expect(markup).not.toContain('aria-label="Channel of row 1"');
    expect(markup).toMatch(/aria-label="Channel name of row 1"[^>]*readOnly/);
});

test('a new row keeps the channel select', () => {
    const markup = render([blankOtaListRow()]);

    expect(markup).toContain('aria-label="Channel of row 1"');
});
