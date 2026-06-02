import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import Homepage from '../../../resources/js/Homepage.vue';
import { createStocksVuetify } from '../../../resources/js/plugins/vuetify';

function mountHomepage() {
    return mount(Homepage, {
        global: {
            plugins: [createStocksVuetify()],
        },
    });
}

describe('Homepage', () => {
    it('renders the primary marketing content and admin actions', () => {
        const wrapper = mountHomepage();

        expect(wrapper.text()).toContain('Stocks');
        expect(wrapper.text()).toContain('Manage your future stock workspace from one compact Laravel admin.');
        expect(wrapper.text()).toContain('Personal stock workspace');
        expect(wrapper.text()).toContain('Open admin');
        expect(wrapper.text()).toContain('Continue to admin');
    });

    it('renders platform navigation and section anchors', () => {
        const wrapper = mountHomepage();

        expect(wrapper.get('a[aria-label="Stocks home"]').attributes('href')).toBe('/');
        expect(wrapper.get('a[href="#platform"]').text()).toBe('Platform');
        expect(wrapper.get('a[href="#workflow"]').text()).toBe('Workflow');
        expect(wrapper.get('a[href="#quality"]').text()).toBe('Quality');
        expect(wrapper.findAll('a[href="/admin/login"]')).toHaveLength(3);
    });

    it('renders the expected feature and workflow cards', () => {
        const wrapper = mountHomepage();

        expect(wrapper.text()).toContain('One calm workspace');
        expect(wrapper.text()).toContain('Controlled access');
        expect(wrapper.text()).toContain('Ready for stocks');
        expect(wrapper.text()).toContain('Sign in');
        expect(wrapper.text()).toContain('Keep access tidy');
        expect(wrapper.text()).toContain('Build forward');
    });
});
