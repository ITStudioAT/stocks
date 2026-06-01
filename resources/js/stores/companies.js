import { defineStore } from 'pinia';
import { request } from './auth';

export const useCompanyStore = defineStore('companies', {
    state: () => ({
        companies: [],
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
        async loadCompanies(page = 1) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request(`/admin/companies?page=${page}`);
                this.companies = data.companies;
                this.pagination = data.meta;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async createCompany(payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/companies', {
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
        async updateCompany(id, payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/companies/${id}`, {
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
        async activateCompany(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/companies/${id}/activate`, {
                    method: 'PATCH',
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async deleteCompany(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/companies/${id}`, {
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
