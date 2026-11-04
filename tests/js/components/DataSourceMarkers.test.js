import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import DataSourceMarkers from '../../../resources/js/DataSourceMarkers.vue';
import { marketDataMarkers } from '../../../resources/js/utils/marketDataProvenance';

describe('market data provenance', () => {
    it('shows both origins for mixed records while keeping each point specific', () => {
        const oldPoint = { price: 10, source_markers: ['EODHD'] };
        const newPoint = { price: 11, source_markers: ['free'] };
        const wrapper = mount(DataSourceMarkers, { props: { markers: marketDataMarkers([oldPoint, newPoint]) } });
        expect(wrapper.text()).toBe('freeEODHD');
        expect(marketDataMarkers(oldPoint)).toEqual(['EODHD']);
        expect(marketDataMarkers(newPoint)).toEqual(['free']);
        expect(wrapper.findAll('[title]').map((badge) => badge.attributes('title'))).toEqual(['Data origin: free', 'Data origin: EODHD']);
    });

    it('does not invent a third marker or assign an origin to saved data', () => {
        const wrapper = mount(DataSourceMarkers, { props: { markers: ['mixed', 'database', 'cache'] } });
        expect(wrapper.find('[aria-label="Data provenance"]').exists()).toBe(false);
        expect(marketDataMarkers({ source_key: 'stored_instrument' })).toEqual([]);
        expect(marketDataMarkers({ latest_price_source_markers: ['free'], data_source_markers: ['free', 'EODHD'] }, 'latest_price')).toEqual(['free']);
    });
});
