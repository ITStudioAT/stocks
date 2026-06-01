import { defineStore } from 'pinia';
import { request } from './auth';

const noActiveClientMessage = 'No active client is selected for this company.';

export const useAnalysisStore = defineStore('analyses', {
    state: () => ({
        analyses: [],
        currentAnalysis: null,
        currentReport: null,
        loading: false,
        error: '',
    }),
    actions: {
        async loadAnalyses({ silent = false } = {}) {
            if (!silent) {
                this.loading = true;
            }
            this.error = '';

            try {
                const data = await request('/admin/website-analyses');
                this.analyses = data.analyses;
            } catch (error) {
                if (error.message === noActiveClientMessage) {
                    this.analyses = [];
                    this.currentAnalysis = null;
                    this.currentReport = null;
                    this.error = '';

                    return;
                }

                this.error = error.message;
                throw error;
            } finally {
                if (!silent) {
                    this.loading = false;
                }
            }
        },
        async loadAnalysis(id, { silent = false } = {}) {
            if (!silent) {
                this.loading = true;
            }
            this.error = '';

            try {
                const data = await request(`/admin/website-analyses/${id}`);
                this.currentAnalysis = data.analysis;
                this.currentReport = data.report;

                return data;
            } catch (error) {
                this.currentAnalysis = null;
                this.currentReport = null;
                this.error = error.message;
                throw error;
            } finally {
                if (!silent) {
                    this.loading = false;
                }
            }
        },
        clearCurrentAnalysis() {
            this.currentAnalysis = null;
            this.currentReport = null;
        },
        async createAnalysis(url) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/website-analyses', {
                    method: 'POST',
                    body: JSON.stringify({ url }),
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async deleteAnalysis(id) {
            this.loading = true;
            this.error = '';

            try {
                return await request(`/admin/website-analyses/${id}`, {
                    method: 'DELETE',
                });
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async rerunAnalysis(id) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request(`/admin/website-analyses/${id}/rerun`, {
                    method: 'POST',
                });
                this.currentAnalysis = data.analysis;
                this.currentReport = data.report;

                return data;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async cancelAnalysis(id) {
            this.loading = true;
            this.error = '';

            try {
                const data = await request(`/admin/website-analyses/${id}/cancel`, {
                    method: 'POST',
                });

                if (this.currentAnalysis?.id === data.analysis.id) {
                    this.currentAnalysis = data.analysis;
                }

                return data;
            } catch (error) {
                this.error = error.message;
                throw error;
            } finally {
                this.loading = false;
            }
        },
        async checkUrl(url) {
            this.loading = true;
            this.error = '';

            try {
                return await request('/admin/website-analyses/check-url', {
                    method: 'POST',
                    body: JSON.stringify({ url }),
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
