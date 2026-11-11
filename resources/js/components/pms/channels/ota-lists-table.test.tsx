import { renderToStaticMarkup } from 'react-dom/server';
import { expect, test } from 'vite-plus/test';
import { OtaListsTable, blankOtaListRow } from './ota-lists-table';

const channels = [
    { id: 1, name: 'Agoda', isOther: false },
    { id: 9, name: 'Other', isOther: true },
];

const render = (
    savedRows: App.Data.Onboarding.OnboardingOtaCredentialRowData[],
    rows: ReturnType<typeof blankOtaListRow>[],
) =>
    renderToStaticMarkup(
        <OtaListsTable
            savedRows={savedRows}
            rows={rows}
            channels={channels}
            maxRows={20}
            errors={{}}
            onRowsChange={() => {}}
            onRemoveSavedRow={() => {}}
            onRemoveRow={() => {}}
        />,
    );

test('a saved row shows only its channel and no login fields', () => {
    const markup = render([{ id: 5, channelLabel: 'Agoda' }], []);

    expect(markup).toContain('Agoda');
    expect(markup).toContain('Only the Estatell team can see this');
    expect(markup).toContain('aria-label="Remove Agoda"');
    expect(markup).not.toContain('aria-label="Username of row 1"');
    expect(markup).not.toContain('aria-label="Password of row 1"');
});

test('a new row has the channel select and the login fields', () => {
    const markup = render([], [blankOtaListRow()]);

    expect(markup).toContain('aria-label="Channel of row 1"');
    expect(markup).toContain('aria-label="Username of row 1"');
    expect(markup).toContain('aria-label="Password of row 1"');
});

test('the empty state shows only when there are no rows at all', () => {
    expect(render([], [])).toContain('No channels yet');
    expect(render([{ id: 5, channelLabel: 'Agoda' }], [])).not.toContain(
        'No channels yet',
    );
});
