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
    return marketDataMarkers(record).filter((marker) => marker !== 'free').join(' · ');
}

export function marketDataDisplayText(value) {
    if (typeof value !== 'string') {
        return value;
    }

    return value
        .replace(/\bFree for non-commercial use\b/g, 'Available for non-commercial use')
        .replace(/\bFree\s+([a-z])/g, (_, letter) => letter.toUpperCase())
        .replace(/\bfree\b\s*/gi, '')
        .trim();
}
