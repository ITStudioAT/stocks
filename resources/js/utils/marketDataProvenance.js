const validMarkers = ['free', 'EODHD'];

export function mergeMarketDataMarkers(...groups) {
    return validMarkers.filter((marker) => groups.some((group) => Array.isArray(group) && group.includes(marker)));
}

export function marketDataMarkers(record, field = null) {
    if (!record) {
        return [];
    }
    if (Array.isArray(record)) {
        return mergeMarketDataMarkers(...record.map((item) => marketDataMarkers(item)));
    }

    if (field) {
        return mergeMarketDataMarkers(record[`${field}_source_markers`]);
    }

    return mergeMarketDataMarkers(record.source_markers, record.data_source_markers);
}

export function marketDataSourceText(record) {
    return marketDataMarkers(record).join(' · ');
}
