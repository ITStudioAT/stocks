import { defineStore } from 'pinia';

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

export async function request(path, options = {}) {
    const response = await fetch(path, {
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
            ...(options.headers ?? {}),
        },
        ...options,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const message = data.message ?? Object.values(data.errors ?? {})?.[0]?.[0] ?? 'The request failed.';
        throw new Error(message);
    }

    return data;
}

export const useAuthStore = defineStore('auth', {
    state: () => ({
        user: null,
        email: 'kron@naturwelt.at',
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
                window.location.assign('/admin');
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
        selectCompany(company) {
            if (!this.user) {
                return;
            }

            this.user = {
                ...this.user,
                selected_company_id: company.id,
                selected_company_name: company.company_name_1,
            };
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
        async updatePassword(password) {
            this.loading = true;
            this.error = '';
            this.notice = '';

            try {
                const data = await request('/admin/profile/password', {
                    method: 'PATCH',
                    body: JSON.stringify({
                        password,
                    }),
                });
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
