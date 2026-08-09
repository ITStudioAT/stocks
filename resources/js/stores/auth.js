import { defineStore } from 'pinia';

export const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

export async function request(path, options = {}) {
    const { headers = {}, ...requestOptions } = options;
    const response = await fetch(path, {
        credentials: 'same-origin',
        ...requestOptions,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...headers,
        },
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = data.message ?? Object.values(data.errors ?? {})?.[0]?.[0] ?? 'The request failed.';
        const error = new Error(message);
        error.status = response.status;
        error.data = data;

        throw error;
    }

    return data;
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        email: '',
        loading: false,
        notice: '',
        error: '',
    }),
    getters: {
        isAuthenticated: (state) => Boolean(state.user),
    },
    actions: {
        async sendCode(email) {
            this.loading = true;
            this.error = '';
            this.notice = '';
            this.email = email;

            try {
                const data = await request('/admin/login-code', {
                    method: 'POST',
                    body: JSON.stringify({ email }),
                });
                this.notice = data.message;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async verifyCode(code) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request('/admin/verify-code', {
                    method: 'POST',
                    body: JSON.stringify({ email: this.email, code }),
                });
                this.user = data.user;
                window.location.assign(data.user?.can_initialize_password ? '/admin/profile' : '/admin');
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async passwordLogin(email, password) {
            this.loading = true;
            this.error = '';
            this.notice = '';
            this.email = email;

            try {
                const data = await request('/admin/password-login', {
                    method: 'POST',
                    body: JSON.stringify({ email, password }),
                });
                this.user = data.user;
                window.location.assign('/admin');
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async loadUser() {
            if (!window.location.pathname.startsWith('/admin') || window.location.pathname === '/admin/login') {
                return;
            }

            const data = await request('/admin/me');
            this.user = data.user;
        },
        async updateName(lastName, firstName) {
            this.loading = true;
            this.error = '';
            this.notice = '';

            try {
                const data = await request('/admin/profile/name', {
                    method: 'PATCH',
                    body: JSON.stringify({
                        last_name: lastName,
                        first_name: firstName,
                    }),
                });
                this.user = data.user;
                this.notice = data.message;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async updatePassword(currentPassword, password, passwordConfirmation) {
            this.loading = true;
            this.error = '';
            this.notice = '';

            try {
                const data = await request('/admin/profile/password', {
                    method: 'PATCH',
                    body: JSON.stringify({
                        ...(this.user?.can_initialize_password ? {} : { current_password: currentPassword }),
                        password,
                        password_confirmation: passwordConfirmation,
                    }),
                });
                this.user = data.user ?? this.user;
                this.notice = data.message;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
    },
});
