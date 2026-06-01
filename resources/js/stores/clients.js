import { defineStore } from 'pinia';
import { request } from './auth';

export const useClientStore = defineStore('clients', {
    state: () => ({
        clients: [],
        loading: false,
        error: '',
    }),
    actions: {
        async loadClients(companyId = null) {
            this.loading = true;
            this.error = '';

            try {
                const query = companyId ? `?company_id=${companyId}` : '';
                const data = await request(`/admin/clients${query}`);
                this.clients = data.clients;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async searchCompanies(search) {
            this.error = '';

            try {
                return await request(`/admin/companies/search?search=${encodeURIComponent(search)}`);
            } catch (error) {
                this.error = error.message;
                throw error;
            }
        },
        async createClient(payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/clients', {
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
        async updateClient(id, payload) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/clients/${id}`, {
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
        async activateClient(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/clients/${id}/activate`, {
                    method: 'PATCH',
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async deleteClient(id, confirmation) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/clients/${id}`, {
                    method: 'DELETE',
                    body: JSON.stringify({ confirmation }),
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
