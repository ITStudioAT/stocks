import { defineStore } from 'pinia';
import { request } from './auth';

export const useRoleStore = defineStore('roles', {
    state: () => ({
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
        async loadRoles(page = 1) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request(`/admin/roles?page=${page}`);
                this.roles = data.roles;
                this.pagination = data.meta;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async createRole(payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/roles', {
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
        async updateRole(id, payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/roles/${id}`, {
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
        async deleteRole(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/roles/${id}`, {
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
