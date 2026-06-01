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
        expect(wrapper.text()).toContain('Build polished client homepages from one elegant Laravel workspace.');
        expect(wrapper.text()).toContain('AI-assisted homepage operations');
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
        expect(wrapper.text()).toContain('Design-aware color systems');
        expect(wrapper.text()).toContain('Homepage-ready output');
        expect(wrapper.text()).toContain('Capture the client');
        expect(wrapper.text()).toContain('Analyze and shape');
        expect(wrapper.text()).toContain('Publish confidently');
    });
});
