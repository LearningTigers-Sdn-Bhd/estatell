import type { ReactNode } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { beforeEach, expect, test, vi } from 'vite-plus/test';
import { SettingsSection } from './settings-section';

const mocks = vi.hoisted(() => ({
    isDirty: false,
    processing: false,
    submit: vi.fn(),
    proceed: vi.fn(),
    guard: vi.fn(),
    dialog: vi.fn(),
    onSuccess: () => {},
    onFinish: () => {},
}));

vi.mock('@inertiajs/react', () => ({
    Form: ({
        children,
        onSuccess,
        onFinish,
    }: {
        children: (state: object) => ReactNode;
        onSuccess: () => void;
        onFinish: () => void;
    }) => {
        mocks.onSuccess = onSuccess;
        mocks.onFinish = onFinish;

        return children({
            errors: {},
            isDirty: mocks.isDirty,
            processing: mocks.processing,
            submit: mocks.submit,
        });
    },
}));

vi.mock('@/hooks/use-unsaved-changes', () => ({
    useUnsavedChanges: (dirty: boolean) => {
        mocks.guard(dirty);

        return { target: null, proceed: mocks.proceed, stay: vi.fn() };
    },
}));

vi.mock('@/components/pms/unsaved-changes-dialog', () => ({
    UnsavedChangesDialog: (props: { onSave: () => void }) => {
        mocks.dialog(props);

        return null;
    },
}));

beforeEach(() => {
    vi.clearAllMocks();
    mocks.isDirty = false;
    mocks.processing = false;
});

function render(guardUnsavedChanges = true) {
    return renderToStaticMarkup(
        <SettingsSection
            form={{ action: '/settings/profile', method: 'post' }}
            title="Profile"
            description="Property identity."
            guardUnsavedChanges={guardUnsavedChanges}
        >
            {() => <input name="name" defaultValue="Sandbay" />}
        </SettingsSection>,
    );
}

test('disables Save for a clean form and enables it after an edit', () => {
    expect(render()).toContain('aria-disabled="true"');
    expect(mocks.guard).toHaveBeenLastCalledWith(false);

    mocks.isDirty = true;

    expect(render()).toContain('aria-disabled="false"');
    expect(mocks.guard).toHaveBeenLastCalledWith(true);
});

test('keeps Save disabled during submission', () => {
    mocks.isDirty = true;
    mocks.processing = true;

    expect(render()).toContain('aria-disabled="true"');
});

test('Save in the leave dialog submits and leaves only after success', () => {
    mocks.isDirty = true;
    render();

    mocks.dialog.mock.lastCall?.[0].onSave();

    expect(mocks.submit).toHaveBeenCalledOnce();
    expect(mocks.proceed).not.toHaveBeenCalled();

    mocks.onSuccess();

    expect(mocks.proceed).toHaveBeenCalledOnce();
});

test('a failed save clears the pending destination callback', () => {
    mocks.isDirty = true;
    render();
    mocks.dialog.mock.lastCall?.[0].onSave();
    mocks.onFinish();

    expect(mocks.proceed).not.toHaveBeenCalled();

    mocks.onSuccess();

    expect(mocks.proceed).not.toHaveBeenCalled();
});

test('other settings sections retain their existing save behavior', () => {
    expect(render(false)).toContain('aria-disabled="false"');
    expect(mocks.guard).toHaveBeenLastCalledWith(false);
    expect(mocks.dialog).not.toHaveBeenCalled();
});
