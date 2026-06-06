import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import Homepage from '../../../resources/js/Homepage.vue';

function mountHomepage() {
    return mount(Homepage, { attachTo: document.body });
}

describe('Homepage', () => {
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

    it('does not render the removed live value line', () => {
        const wrapper = mountHomepage();

        expect(wrapper.text()).not.toContain('$1,284,930');
        expect(wrapper.find('.value-row').exists()).toBe(false);
        expect(wrapper.find('.value').exists()).toBe(false);
        expect(wrapper.find('.value-dollar').exists()).toBe(false);
        expect(wrapper.find('.delta-pill').exists()).toBe(false);
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

    it('hides the ticker when no index data is available', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ indexes: [] }) })));
        const wrapper = mountHomepage();
        await flushPromises();

        expect(wrapper.find('.ticker-wrap').exists()).toBe(false);
    });

    it('shows a scrolling ticker with live index data', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                indexes: [
                    { symbol: 'ATX', country: 'Austria', currency: 'EUR', latest_price: '6116.5298', latest_price_change_pct: '0.33' },
                    { symbol: 'DAX', country: 'Germany', currency: 'EUR', latest_price: '24944.9492', latest_price_change_pct: '-0.12' },
                    { symbol: 'DJI', country: 'USA', currency: 'USD', latest_price: '51561.9297', latest_price_change_pct: '1.13' },
                ],
            }),
        })));
        const wrapper = mountHomepage();
        await flushPromises();

        expect(wrapper.find('.ticker-wrap').exists()).toBe(true);
        expect(global.fetch).toHaveBeenCalledWith('/indices');

        const text = wrapper.text();
        expect(text).toContain('ATX');
        expect(text).toContain('Austria');
        expect(text).toContain('DAX');
        expect(text).toContain('Germany');
        expect(text).toContain('DJI');
        // Positive change renders green arrow
        expect(text).toContain('▲ +0.33%');
        // Negative change renders red arrow
        expect(text).toContain('▼ -0.12%');
        // Non-EUR currency appended to price
        expect(text).toContain('USD');
        // Sparse index data is repeated before duplication so the scroll remains visible.
        expect(wrapper.findAll('.ticker-item')).toHaveLength(18);
        expect(wrapper.get('.ticker-track').attributes('style')).toContain('--ticker-duration: 180s;');
    });

    it('repeats a single index enough to visibly scroll across the viewport', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                indexes: [
                    { symbol: 'ATX', country: 'Austria', currency: 'EUR', latest_price: '6116.5298', latest_price_change_pct: '0.33' },
                ],
            }),
        })));
        const wrapper = mountHomepage();
        await flushPromises();

        expect(wrapper.findAll('.ticker-item')).toHaveLength(16);
        expect(wrapper.get('.ticker-track').attributes('style')).toContain('--ticker-duration: 180s;');
    });

    it('shifts blobs greener when indices are mostly positive', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                indexes: [
                    { symbol: 'DAX', country: 'Germany', currency: 'EUR', latest_price: '100', latest_price_change_pct: '2.40' },
                    { symbol: 'ATX', country: 'Austria', currency: 'EUR', latest_price: '100', latest_price_change_pct: '1.80' },
                ],
            }),
        })));
        const wrapper = mountHomepage();
        await flushPromises();

        const greenStyle = wrapper.get('.blob-green').attributes('style') ?? '';
        const redStyle   = wrapper.get('.blob-red').attributes('style')   ?? '';
        const greenOpacity = parseFloat(greenStyle.match(/rgba\(46, 204, 113, ([\d.]+)\)/)?.[1] ?? '0');
        const redOpacity   = parseFloat(redStyle.match(/rgba\(231, 76, 60, ([\d.]+)\)/)?.[1]    ?? '1');

        expect(greenOpacity).toBeGreaterThan(0.38);
        expect(redOpacity).toBeLessThan(0.32);
    });

    it('shifts blobs redder when indices are mostly negative', async () => {
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({
                indexes: [
                    { symbol: 'DAX', country: 'Germany', currency: 'EUR', latest_price: '100', latest_price_change_pct: '-2.10' },
                    { symbol: 'ATX', country: 'Austria', currency: 'EUR', latest_price: '100', latest_price_change_pct: '-1.50' },
                ],
            }),
        })));
        const wrapper = mountHomepage();
        await flushPromises();

        const greenStyle = wrapper.get('.blob-green').attributes('style') ?? '';
        const redStyle   = wrapper.get('.blob-red').attributes('style')   ?? '';
        const greenOpacity = parseFloat(greenStyle.match(/rgba\(46, 204, 113, ([\d.]+)\)/)?.[1] ?? '1');
        const redOpacity   = parseFloat(redStyle.match(/rgba\(231, 76, 60, ([\d.]+)\)/)?.[1]    ?? '0');

        expect(greenOpacity).toBeLessThan(0.38);
        expect(redOpacity).toBeGreaterThan(0.32);
    });

    it('marks changed prices with is-updated class on refresh', async () => {
        vi.useFakeTimers();
        let indicesCall = 0;
        vi.stubGlobal('fetch', vi.fn((url) => {
            if (url === '/indices') indicesCall++;
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve(
                    url === '/indices'
                        ? {
                            indexes: indicesCall === 1
                                ? [{ symbol: 'ATX', country: 'Austria', currency: 'EUR', latest_price: '6116.00', latest_price_change_pct: '0.33' }]
                                : [{ symbol: 'ATX', country: 'Austria', currency: 'EUR', latest_price: '6200.00', latest_price_change_pct: '1.50' }],
                        }
                        : {},
                ),
            });
        }));
        const wrapper = mountHomepage();
        await flushPromises();

        vi.advanceTimersByTime(60_000);
        await flushPromises();

        expect(wrapper.findAll('.ticker-item.is-updated').length).toBeGreaterThan(0);
        vi.useRealTimers();
    });

    it('briefly corrupts the eyebrow text on a scheduled timer', async () => {
        vi.useFakeTimers();
        vi.spyOn(Math, 'random').mockReturnValue(0);
        vi.stubGlobal('fetch', vi.fn(() => Promise.resolve({ ok: false })));

        const wrapper = mountHomepage();

        expect(wrapper.get('.eyebrow').text()).toContain('Total holdings · Live');

        vi.advanceTimersByTime(6001);
        await wrapper.vm.$nextTick();

        expect(wrapper.get('.eyebrow').text()).toContain('T̸');

        vi.useRealTimers();
    });

    it('colors the headline dot red when depot sum is negative', async () => {
        vi.stubGlobal('fetch', vi.fn((url) => {
            if (url === '/depot-sum-sign') {
                return Promise.resolve({ ok: true, json: () => Promise.resolve({ sign: -1 }) });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ indexes: [] }) });
        }));

        const wrapper = mountHomepage();
        await flushPromises();

        expect(wrapper.get('.headline-dot').attributes('style')).toContain('var(--red)');
    });

    it('colors the headline dot green when depot sum is positive', async () => {
        vi.stubGlobal('fetch', vi.fn((url) => {
            if (url === '/depot-sum-sign') {
                return Promise.resolve({ ok: true, json: () => Promise.resolve({ sign: 1 }) });
            }
            return Promise.resolve({ ok: true, json: () => Promise.resolve({ indexes: [] }) });
        }));

        const wrapper = mountHomepage();
        await flushPromises();

        expect(wrapper.get('.headline-dot').attributes('style')).toContain('var(--green)');
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
    });
});
