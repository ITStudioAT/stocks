import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { request, useAuthStore } from '../../../resources/js/stores/auth';

function jsonResponse(data, options = {}) {
    return {
        ok: options.ok ?? true,
        status: options.status ?? 200,
        json: () => Promise.resolve(data),
    };
}

describe('request', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    });

    it('shares identical pending reads but fetches fresh data after completion', async () => {
        let resolveResponse;
        const fetchMock = vi.fn().mockImplementationOnce(() => new Promise(resolve => { resolveResponse = resolve; }))
            .mockResolvedValue(jsonResponse({ version: 2 }));
        vi.stubGlobal('fetch', fetchMock);
        const first = request('/admin/prices');
        const second = request('/admin/prices');
        expect(fetchMock).toHaveBeenCalledTimes(1);
        resolveResponse(jsonResponse({ version: 1 }));
        expect(await Promise.all([first, second])).toEqual([{ version: 1 }, { version: 1 }]);
        expect(await request('/admin/prices')).toEqual({ version: 2 });
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it('deduplicates opted-in chart reads without merging writes or different chart parameters', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({}));
        vi.stubGlobal('fetch', fetchMock);
        await Promise.all([
            request('/admin/charts?id=1', { method: 'POST', deduplicate: true }),
            request('/admin/charts?id=1', { method: 'POST', deduplicate: true }),
            request('/admin/charts?id=2', { method: 'POST', deduplicate: true }),
            request('/admin/save', { method: 'POST', body: '{}' }),
            request('/admin/save', { method: 'POST', body: '{}' }),
        ]);
        expect(fetchMock).toHaveBeenCalledTimes(4);
        expect(fetchMock.mock.calls[0][1]).not.toHaveProperty('deduplicate');
    });

    it('releases failed pending reads so a retry can succeed', async () => {
        const fetchMock = vi.fn().mockRejectedValueOnce(new Error('Offline')).mockResolvedValue(jsonResponse({ recovered: true }));
        vi.stubGlobal('fetch', fetchMock);
        const responses = await Promise.allSettled([request('/admin/retry'), request('/admin/retry')]);
        expect(responses.every(response => response.status === 'rejected')).toBe(true);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(await request('/admin/retry')).toEqual({ recovered: true });
    });

    it('sends JSON headers, credentials, and the csrf token', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ saved: true }));
        vi.stubGlobal('fetch', fetchMock);

        const data = await request('/admin/example', {
            method: 'POST',
            body: JSON.stringify({ name: 'Stocks' }),
            headers: {
                'X-Request-Context': 'test',
            },
        });

        expect(data).toEqual({ saved: true });
        expect(fetchMock).toHaveBeenCalledWith('/admin/example', {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'test-token',
                'X-Request-Context': 'test',
            },
            method: 'POST',
            body: JSON.stringify({ name: 'Stocks' }),
        });
    });

    it('throws the first validation message from failed JSON responses', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
            errors: {
                email: ['The email field is required.'],
            },
        }, {
            ok: false,
            status: 422,
        })));

        await expect(request('/admin/login-code')).rejects.toMatchObject({
            message: 'The email field is required.',
            status: 422,
        });
    });
});

describe('useAuthStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
    });

    it('stores the selected email and notice after requesting a login code', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
            message: 'Code sent.',
        })));

        const auth = useAuthStore();
        expect(auth.email).toBe('');

        await auth.sendCode('admin@example.com');

        expect(auth.email).toBe('admin@example.com');
        expect(auth.notice).toBe('Code sent.');
        expect(auth.error).toBe('');
        expect(auth.loading).toBe(false);
    });

    it('records request errors and resets loading state', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(jsonResponse({
            message: 'Invalid credentials.',
        }, { ok: false })));

        const auth = useAuthStore();

        await expect(auth.sendCode('admin@example.com')).rejects.toThrow('Invalid credentials.');
        expect(auth.error).toBe('Invalid credentials.');
        expect(auth.loading).toBe(false);
    });

    it('submits the current password and confirmed replacement password', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            message: 'Password updated.',
        }));
        vi.stubGlobal('fetch', fetchMock);

        const auth = useAuthStore();

        await auth.updatePassword(
            'Current-Password-123!',
            'New-Password-456!',
            'New-Password-456!',
        );

        expect(fetchMock).toHaveBeenCalledWith('/admin/profile/password', expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({
                current_password: 'Current-Password-123!',
                password: 'New-Password-456!',
                password_confirmation: 'New-Password-456!',
            }),
        }));
    });

    it('omits the prohibited current password during one-time initialization and refreshes user state', async () => {
        const initializedUser = {
            email: 'admin@example.com',
            roles: ['admin'],
            can_initialize_password: false,
        };
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({
            message: 'Password updated.',
            user: initializedUser,
        }));
        vi.stubGlobal('fetch', fetchMock);

        const auth = useAuthStore();
        auth.user = {
            email: 'admin@example.com',
            roles: ['admin'],
            can_initialize_password: true,
        };

        await auth.updatePassword(
            'must-not-be-sent',
            'Initialized-Password-456!',
            'Initialized-Password-456!',
        );

        const [, requestOptions] = fetchMock.mock.calls[0];
        const payload = JSON.parse(requestOptions.body);

        expect(payload).toEqual({
            password: 'Initialized-Password-456!',
            password_confirmation: 'Initialized-Password-456!',
        });
        expect(payload).not.toHaveProperty('current_password');
        expect(auth.user).toEqual(initializedUser);
    });

    it('does not load the current user outside authenticated admin pages', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);
        window.history.pushState({}, '', '/admin/login');

        const auth = useAuthStore();

        await auth.loadUser();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(auth.user).toBeNull();
    });
});
