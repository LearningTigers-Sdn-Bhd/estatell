import { expect, test } from 'vite-plus/test';
import {
    MYKAD_PROBLEMS_URL,
    MYKAD_SETUP_URL,
    REQUIRED_BRIDGE_VERSION,
    bridgeDownload,
    isOlderVersion,
    readErrorMessage,
    readerChip,
} from './use-mykad-reader';

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

test('downloads come from GitHub Releases and other systems have none', () => {
    expect(windows.href).toBe(
        'https://github.com/LearningTigers-Sdn-Bhd/estatell-mykad-reader/releases/latest/download/estatell-mykad-setup.exe',
    );
    expect(mac.href).toBe(
        'https://github.com/LearningTigers-Sdn-Bhd/estatell-mykad-reader/releases/latest/download/estatell-mykad.pkg',
    );
    expect(other).toEqual({ href: null, filename: null, platform: 'other' });
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
