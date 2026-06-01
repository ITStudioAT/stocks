import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { request, useAuthStore } from '../../../resources/js/stores/auth';

function jsonResponse(data, options = {}) {
    return {
        ok: options.ok ?? true,
        json: () => Promise.resolve(data),
    };
}

describe('request', () => {
    beforeEach(() => {
        document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    });

    it('sends JSON headers, credentials, and the csrf token', async () => {
        const fetchMock = vi.fn().mockResolvedValue(jsonResponse({ saved: true }));
        vi.stubGlobal('fetch', fetchMock);

        const data = await request('/admin/example', {
            method: 'POST',
            body: JSON.stringify({ name: 'Stocks' }),
        });

        expect(data).toEqual({ saved: true });
        expect(fetchMock).toHaveBeenCalledWith('/admin/example', {
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': 'test-token',
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
        }, { ok: false })));

        await expect(request('/admin/login-code')).rejects.toThrow('The email field is required.');
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
