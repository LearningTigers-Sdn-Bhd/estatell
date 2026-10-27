import { afterEach, expect, test, vi } from 'vite-plus/test';
import { announceFormError } from './form-error';

const add = vi.hoisted(() => vi.fn());

vi.mock('@/components/ui/toast', () => ({ toast: { add } }));

afterEach(() => {
    add.mockClear();
    vi.unstubAllGlobals();
});

test('a form-wide refusal shows a toast and scrolls to the banner', () => {
    const scrollIntoView = vi.fn();

    vi.stubGlobal('document', {
        querySelector: vi.fn(() => ({ scrollIntoView })),
    });

    expect(announceFormError({ form: 'Room 202 is not free.' })).toBe(true);
    expect(add).toHaveBeenCalledWith({
        title: 'Room 202 is not free.',
        type: 'error',
    });
    expect(scrollIntoView).toHaveBeenCalledOnce();
});

test('a field error is left to the field', () => {
    expect(announceFormError({ arrival_date: 'Pick a date.' })).toBe(false);
    expect(add).not.toHaveBeenCalled();
});
