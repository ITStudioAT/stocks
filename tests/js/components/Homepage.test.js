import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Homepage from '../../../resources/js/Homepage.vue';

function mountHomepage() {
    return mount(Homepage, { attachTo: document.body });
}

describe('Homepage', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('renders the wordmark, eyebrow, and balanced headline', () => {
        const wrapper = mountHomepage();

        expect(wrapper.get('.wordmark').text()).toBe('Stocks.');
        expect(wrapper.get('.eyebrow').text()).toContain('Total holdings · Live');
        expect(wrapper.get('.headline').text()).toBe('A quiet place to watch your money grow.');
        expect(wrapper.get('.headline-your').text()).toBe('your');
    });

    it('wires the Admin pill to the admin login route', () => {
        const wrapper = mountHomepage();

        const pill = wrapper.get('.admin-pill');
        expect(pill.text()).toBe('Admin');
        expect(pill.attributes('href')).toBe('/admin/login');
    });

    it('renders the starting value and delta with a leading dollar sign', () => {
        const wrapper = mountHomepage();

        expect(wrapper.get('.value').text()).toBe('$1,284,930');
        expect(wrapper.get('.value-dollar').text()).toBe('$');
        expect(wrapper.get('.delta-pill').text()).toBe('▲ 2.41% today');
    });

    it('renders both trend lines, area fills, grid lines, and end dots', () => {
        const wrapper = mountHomepage();

        expect(wrapper.find('.line-green').exists()).toBe(true);
        expect(wrapper.find('.line-red').exists()).toBe(true);
        expect(wrapper.find('.area-green').exists()).toBe(true);
        expect(wrapper.find('.area-red').exists()).toBe(true);
        expect(wrapper.findAll('.grid-line')).toHaveLength(5);
        expect(wrapper.find('.dot-green').exists()).toBe(true);
        expect(wrapper.find('.dot-red').exists()).toBe(true);

        // Lines are normalized for the dash draw-in animation.
        expect(wrapper.get('.line-green').element.getAttribute('pathLength')).toBe('1');
        expect(wrapper.get('.line-green').attributes('d')).toMatch(/^M .* C /);
    });

    it('jitters the value within bounds on the cosmetic interval', async () => {
        const randomSpy = vi.spyOn(Math, 'random').mockReturnValue(0.9);
        const wrapper = mountHomepage();

        vi.advanceTimersByTime(2200);
        await wrapper.vm.$nextTick();

        const rendered = Number(wrapper.get('.value').text().replace(/[$,]/g, ''));
        expect(rendered).toBeGreaterThanOrEqual(1200000);

        const delta = Number(wrapper.get('.delta-pill').text().match(/[\d.]+/)[0]);
        expect(delta).toBeGreaterThanOrEqual(1.6);
        expect(delta).toBeLessThanOrEqual(3.2);

        randomSpy.mockRestore();
    });

    it('marks the stage still and skips loops under reduced motion', () => {
        window.matchMedia.mockImplementationOnce((query) => ({
            matches: true,
            media: query,
            onchange: null,
            addListener: vi.fn(),
            removeListener: vi.fn(),
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
            dispatchEvent: vi.fn(),
        }));

        const wrapper = mountHomepage();

        expect(wrapper.get('.stage').classes()).toContain('is-still');

        // No interval should be queued when motion is reduced.
        vi.advanceTimersByTime(2200);
        expect(wrapper.get('.value').text()).toBe('$1,284,930');
    });
});
