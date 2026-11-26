import { afterEach, expect, test, vi } from 'vite-plus/test';
import { flashToastsHeld } from './toast-progress';
import {
    UPLOAD_TOAST_MIN_BYTES,
    uploadToastCallbacks,
    uploadWithToast,
} from './upload-with-toast';

type Callbacks = {
    onProgress?: (progress: { percentage?: number }) => void;
    onFlash?: (flash: Record<string, unknown>) => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const { post, add, update, close } = vi.hoisted(() => ({
    post: vi.fn(),
    add: vi.fn(() => 'toast-1'),
    update: vi.fn(),
    close: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({ router: { post } }));
vi.mock('@/components/ui/toast', () => ({ toast: { add, update, close } }));

/** Each post answers with the next reply: a success flash, a refusal flash, or validation errors. */
function reply(...replies: Array<'ok' | 'full' | 'invalid'>) {
    post.mockImplementation(
        (_url: string, _data: unknown, callbacks: Callbacks) => {
            const next = replies.shift();

            callbacks.onProgress?.({ percentage: 50 });

            if (next === 'invalid') {
                callbacks.onError?.({ 'photos.0': 'Photos must be images.' });
            } else {
                callbacks.onFlash?.({
                    toast:
                        next === 'ok'
                            ? { type: 'success', message: 'Photos uploaded.' }
                            : { type: 'error', message: 'Album is full.' },
                });
                callbacks.onSuccess?.();
            }

            callbacks.onFinish?.();
        },
    );
}

const files = (n: number) =>
    Array.from({ length: n }, (_, i) => new File(['x'], `p${i}.jpg`));

afterEach(() => {
    vi.clearAllMocks();
});

test('one file per request, counting up, then the server message', async () => {
    reply('ok', 'ok', 'ok');

    const result = await uploadWithToast({
        url: '/photos',
        files: files(3),
        field: 'photos',
        asArray: true,
        oneByOne: true,
        noun: ['photo', 'photos'],
    });

    expect(result).toEqual({ saved: 3, total: 3 });
    expect(post).toHaveBeenCalledTimes(3);
    expect(post.mock.calls[0][1].photos).toHaveLength(1);
    expect(update).toHaveBeenCalledWith(
        'toast-1',
        expect.objectContaining({ description: '2 of 3 · 50%' }),
    );
    expect(close).toHaveBeenCalledWith('toast-1');
    expect(add).toHaveBeenLastCalledWith(
        expect.objectContaining({ type: 'success', title: 'Photos uploaded.' }),
    );
    expect(flashToastsHeld()).toBe(false);
});

test('stops at the first refusal and says how many were saved', async () => {
    reply('ok', 'full', 'ok');

    const result = await uploadWithToast({
        url: '/photos',
        files: files(3),
        field: 'photos',
        asArray: true,
        oneByOne: true,
        noun: ['photo', 'photos'],
    });

    expect(result).toEqual({ saved: 1, total: 3, error: 'Album is full.' });
    expect(post).toHaveBeenCalledTimes(2);
    expect(add).toHaveBeenLastCalledWith(
        expect.objectContaining({
            type: 'error',
            title: 'Album is full.',
            description: '1 of 3 photos were uploaded.',
        }),
    );
    expect(flashToastsHeld()).toBe(false);
});

test('a single file shows the validation message', async () => {
    reply('invalid');

    const result = await uploadWithToast({
        url: '/image',
        files: files(1),
        field: 'image',
        noun: ['image', 'images'],
    });

    expect(result.error).toBe('Photos must be images.');
    expect(post.mock.calls[0][1].image).toBeInstanceOf(File);
    expect(add).toHaveBeenLastCalledWith(
        expect.objectContaining({ type: 'error', description: undefined }),
    );
});

test('form callbacks open a toast only for big uploads and close it at the end', () => {
    const callbacks = uploadToastCallbacks('Uploading ID');
    const big = UPLOAD_TOAST_MIN_BYTES;

    callbacks.onProgress({ percentage: 40, total: big - 1 });
    expect(add).not.toHaveBeenCalled();

    callbacks.onProgress({ percentage: 40, total: big });
    callbacks.onProgress({ percentage: 100, total: big });

    expect(add).toHaveBeenCalledWith(
        expect.objectContaining({ title: 'Uploading ID', type: 'loading' }),
    );
    expect(update).toHaveBeenLastCalledWith(
        'toast-1',
        expect.objectContaining({ description: 'Processing…' }),
    );

    callbacks.onFinish();
    expect(close).toHaveBeenCalledWith('toast-1');

    // The same callbacks serve the next submit.
    callbacks.onProgress({ percentage: 10, total: big });
    expect(add).toHaveBeenCalledTimes(2);
    callbacks.onCancel();
    expect(close).toHaveBeenCalledTimes(2);
});
