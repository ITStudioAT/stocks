import { defineStore } from 'pinia';
import { request } from './auth';

export const useUserStore = defineStore('users', {
    state: () => ({
        users: [],
        roles: [],
        pagination: {
            current_page: 1,
            last_page: 1,
            per_page: 10,
            total: 0,
            from: null,
            to: null,
        },
        loading: false,
        error: '',
    }),
    actions: {
        async loadUsers(page = 1) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request(`/admin/users?page=${page}`);
                this.users = data.users;
                this.roles = data.roles;
                this.pagination = data.meta;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async createUser(payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/users', {
                    method: 'POST',
                    body: JSON.stringify(payload),
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async updateUser(id, payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/users/${id}`, {
                    method: 'PATCH',
                    body: JSON.stringify(payload),
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async deleteUser(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/users/${id}`, {
                    method: 'DELETE',
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
    },
});
