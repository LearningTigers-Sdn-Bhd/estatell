import { afterEach, expect, test, vi } from 'vite-plus/test';
import {
    MYKAD_PROBLEMS_URL,
    MYKAD_SETUP_URL,
    REQUIRED_BRIDGE_VERSION,
    bridgeDownload,
    createMykadReader,
    isOlderVersion,
    readErrorMessage,
    readerChip,
} from './use-mykad-reader';

afterEach(() => {
    vi.unstubAllGlobals();
});

const windows = bridgeDownload('Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
const mac = bridgeDownload('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)');
const other = bridgeDownload('Mozilla/5.0 (X11; Linux x86_64)');

test('requires MyKad Reader 2.0.0', () => {
    expect(REQUIRED_BRIDGE_VERSION).toBe('2.0.0');
    expect(isOlderVersion('1.1.0', REQUIRED_BRIDGE_VERSION)).toBe(true);
    expect(isOlderVersion(null, REQUIRED_BRIDGE_VERSION)).toBe(true);
    expect(isOlderVersion('2.0.0', REQUIRED_BRIDGE_VERSION)).toBe(false);
    expect(isOlderVersion('2.1.3', REQUIRED_BRIDGE_VERSION)).toBe(false);
});

test('downloads use the link for this system and other systems have none', () => {
    expect(windows.href).toBe('/downloads/mykad-reader/windows');
    expect(mac.href).toBe('/downloads/mykad-reader/mac');
    expect(other).toEqual({ href: null, platform: 'other' });
});

test('maps each read error code to Estatell wording', () => {
    expect(readErrorMessage('no_card', 'No card in the reader.')).toBe(
        'Insert the MyKad, chip first and face up.',
    );
    expect(readErrorMessage('busy', 'x')).toBe(
        'Still reading the last card. Try again in a moment.',
    );
    expect(readErrorMessage('read_failed', 'x')).toBe(
        "The card couldn't be read. Take it out and insert it again.",
    );
    expect(readErrorMessage('forbidden', 'x')).toBe(
        'MyKad Reader refused this page.',
    );
});

test('falls back to the error text for a 1.x reader with no code', () => {
    expect(readErrorMessage(undefined, 'Card read failed')).toBe(
        'Card read failed',
    );
    expect(readErrorMessage('new_code', 'Something else')).toBe(
        'Something else',
    );
    expect(readErrorMessage(undefined, undefined)).toBe(
        'Failed to read smart card',
    );
});

test('chip shows nothing while checking', () => {
    expect(readerChip('checking', false, windows)).toBeNull();
});

test('chip links to set-up when the reader does not answer', () => {
    expect(readerChip('offline', false, windows)).toEqual({
        tone: 'idle',
        label: "MyKad Reader isn't running",
        href: MYKAD_SETUP_URL,
    });
});

test('chip says not supported on other systems', () => {
    expect(readerChip('offline', false, other)).toEqual({
        tone: 'idle',
        label: "MyKad Reader isn't supported on this computer",
        href: null,
    });
});

test('chip links to the download when an update is required', () => {
    expect(readerChip('ready', true, mac)).toEqual({
        tone: 'warning',
        label: 'Update required',
        href: mac.href,
    });
    expect(readerChip('no_reader', true, windows)?.label).toBe(
        'Update required',
    );
});

test('chip links to the problems page when no reader is plugged in', () => {
    expect(readerChip('no_reader', false, windows)).toEqual({
        tone: 'idle',
        label: 'Plug in card reader',
        href: MYKAD_PROBLEMS_URL,
    });
});

test('chip is ready or reading otherwise', () => {
    expect(readerChip('ready', false, windows)?.label).toBe('Ready for MyKad');
    expect(readerChip('card_present', false, windows)?.label).toBe(
        'Ready for MyKad',
    );
    expect(readerChip('error', false, windows)?.label).toBe('Ready for MyKad');
    expect(readerChip('reading', false, windows)?.label).toBe(
        'Reading the card…',
    );
});

test('a read card asks staff to take it out', () => {
    expect(readerChip('card_read', false, windows)?.label).toBe(
        'Take the card out',
    );
});

function stubBridge(status: { card_present: boolean }) {
    const reads = vi.fn(() =>
        Promise.resolve({
            ok: true,
            json: () =>
                Promise.resolve({
                    success: true,
                    data: { name: 'NUR AISYAH', gender: 'female' },
                }),
        }),
    );

    vi.stubGlobal(
        'fetch',
        vi.fn((url: string) =>
            url.endsWith('/read')
                ? reads()
                : Promise.resolve({
                      ok: true,
                      json: () =>
                          Promise.resolve({
                              reader_connected: true,
                              version: '2.0.0',
                              ...status,
                          }),
                  }),
        ),
    );

    return reads;
}

async function settle() {
    await new Promise((resolve) => setTimeout(resolve, 0));
}

test('one card goes to one claim and is read once', async () => {
    const reads = stubBridge({ card_present: true });
    const reader = createMykadReader();
    const first = vi.fn();
    const second = vi.fn();

    reader.claim(first, 'row');
    reader.claim(second, 'row');
    await reader.poll();
    await settle();
    await reader.poll();
    await settle();

    expect(reads).toHaveBeenCalledTimes(1);
    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledTimes(1);
    expect(reader.getSnapshot().status).toBe('card_read');
});

test('a screen goes before the rows behind it, whichever came first', async () => {
    stubBridge({ card_present: true });
    const reader = createMykadReader();
    const screen = vi.fn();
    const row = vi.fn();

    reader.claim(screen, 'screen');
    reader.claim(row, 'row');
    await reader.poll();
    await settle();

    expect(screen).toHaveBeenCalledTimes(1);
    expect(row).not.toHaveBeenCalled();
});

test('a card with no claim is read when a claim comes', async () => {
    const reads = stubBridge({ card_present: true });
    const reader = createMykadReader();
    const row = vi.fn();

    await reader.poll();
    expect(reads).not.toHaveBeenCalled();
    expect(reader.getSnapshot().status).toBe('card_present');

    reader.claim(row, 'row');
    await settle();

    expect(row).toHaveBeenCalledTimes(1);
});

test('a new claim does not read the same card again until it comes out', async () => {
    const reads = stubBridge({ card_present: true });
    const reader = createMykadReader();
    const first = vi.fn();
    const next = vi.fn();

    const release = reader.claim(first, 'row');
    await reader.poll();
    await settle();
    release();
    reader.claim(next, 'row');
    await reader.poll();
    await settle();

    expect(next).not.toHaveBeenCalled();

    stubBridge({ card_present: false });
    await reader.poll();
    expect(reader.getSnapshot().status).toBe('ready');

    const nextReads = stubBridge({ card_present: true });
    await reader.poll();
    await settle();

    expect(reads).toHaveBeenCalledTimes(1);
    expect(nextReads).toHaveBeenCalledTimes(1);
    expect(next).toHaveBeenCalledTimes(1);
});

test('releasing the only claim stops the next card going anywhere', async () => {
    const reads = stubBridge({ card_present: true });
    const reader = createMykadReader();
    const row = vi.fn();

    reader.claim(row, 'row')();
    await reader.poll();
    await settle();

    expect(reads).not.toHaveBeenCalled();
    expect(row).not.toHaveBeenCalled();
});
