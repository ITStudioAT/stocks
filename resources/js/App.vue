<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useDisplay } from 'vuetify';
import { storeToRefs } from 'pinia';
import { useAuthStore } from './stores/auth';
import { useDepotStore } from './stores/depots';
import { useRoleStore } from './stores/roles';
import { useUserStore } from './stores/users';
import { formatAdaptiveNumber, formatPriceValue } from './utils/numberFormatters';

const indexRecentPriceLimit = 30;
const logoMarkUrl = '/images/gkstocks-logo-mark.png';
const displayTimeZone = 'Europe/Vienna';

const { lgAndDown, mdAndDown, smAndDown } = useDisplay();

const auth = useAuthStore();
const depotsStore = useDepotStore();
const usersStore = useUserStore();
const rolesStore = useRoleStore();

const { user, loading, notice, error } = storeToRefs(auth);
const {
    activeDepot,
    depots,
    holdings,
    indexWatchItems,
    depotHoldings,
    transactions,
    exchangeTradingTimes,
    appVersion,
    eodhdApiUsage,
    priceRefresh,
    priceRefreshSettings,
    indexPriceRefreshSettings,
    intradayBackfillSettings,
    intradayBackfillRefresh,
    queueStatus,
    testOptions,
    testTickerExchangeCode,
    testTickers,
    testExchanges,
    testExchangeDetails,
    testExchangeDetailErrors,
    dataExchanges,
    dataExchangeRefresh,
    dataIntradayStocks,
    dataIntradaySelectedStockId,
    dataIntradayDays,
    dataIntradayRefresh,
    analyzeIntradayCandles,
    holdingIntradayCandles,
    holdingIntradayCandlesLoading,
    holdingIntradayCandlesErrors,
    uiPreferences,
    stockSearchResults,
    pagination: depotPagination,
    holdingsPagination,
    loading: depotsLoading,
    holdingsLoading,
    transactionsLoading,
    exchangeTradingTimesLoading,
    queueStatusLoading,
    testOptionsLoading,
    testTickersLoading,
    testExchangesLoading,
    dataExchangesLoading,
    dataExchangeReloadLoading,
    dataIntradayLoading,
    dataIntradayReloadLoading,
    stockSearchLoading,
    analyzeIntradayCandlesLoading,
    error: depotsError,
    holdingsError,
    transactionsError,
    exchangeTradingTimesError,
    queueStatusError,
    testOptionsError,
    testTickersError,
    testExchangesError,
    dataExchangesError,
    dataIntradayError,
    stockSearchError,
    analyzeIntradayCandlesError,
} = storeToRefs(depotsStore);
const {
    users,
    roles: availableUserRoles,
    pagination: userPagination,
    loading: usersLoading,
    error: usersError,
} = storeToRefs(usersStore);
const {
    roles,
    pagination: rolePagination,
    loading: rolesLoading,
    error: rolesError,
} = storeToRefs(rolesStore);

const email = ref(auth.email);
const code = ref('');
const loginPassword = ref('');
const loginMode = ref('password');
const localError = ref('');
const activeSection = ref('dashboard');
const activeAnalyzeSubsection = ref('overview');
const activeDataSubsection = ref('exchanges');
const selectedAnalyzeHistoryRange = ref('1y');
const selectedAnalyzeHistoryWindowOffset = ref(0);
const selectedAnalyzeHoldingId = ref(null);
const selectedTestIndexId = ref(null);
const selectedTestStockId = ref(null);
const selectedTestTab = ref('tickers');
const selectedDataIntradayStockId = ref(null);
const expandedDataIntradayDays = ref({});
const expandedAnalyzeIntradayDays = ref({});
const profileLastName = ref('');
const profileFirstName = ref('');
const newPassword = ref('');
const profileMessage = ref('');
const profileError = ref('');
const isNameDialogOpen = ref(false);
const isPasswordDialogOpen = ref(false);
const userDialogMode = ref('create');
const isUserDialogOpen = ref(false);
const isDeleteUserDialogOpen = ref(false);
const selectedUser = ref(null);
const userForm = ref(emptyUserForm());
const userMessage = ref('');
const userError = ref('');
const roleDialogMode = ref('create');
const isRoleDialogOpen = ref(false);
const isDeleteRoleDialogOpen = ref(false);
const selectedRole = ref(null);
const roleForm = ref(emptyRoleForm());
const roleMessage = ref('');
const roleError = ref('');
const analyzeIntradayRequestedHoldingId = ref(null);
const depotDialogMode = ref('create');
const isDepotDialogOpen = ref(false);
const selectedDepot = ref(null);
const depotForm = ref(emptyDepotForm());
const depotMessage = ref('');
const depotError = ref('');
const isHoldingDialogOpen = ref(false);
const isIndexDialogOpen = ref(false);
const isIndexPriceDialogOpen = ref(false);
const isIndexPriceDialogLoading = ref(false);
const isDeleteHoldingDialogOpen = ref(false);
const isCashTransactionDialogOpen = ref(false);
const isStockTransactionDialogOpen = ref(false);
const selectedHolding = ref(null);
const selectedIndexWatchItem = ref(null);
const transactionHolding = ref(null);
const holdingSearchQuery = ref('');
const indexSearchQuery = ref('');
const holdingSearchInput = ref(null);
const indexSearchInput = ref(null);
const holdingMessage = ref('');
const holdingError = ref('');
const indexMessage = ref('');
const indexError = ref('');
const indexPriceDialogError = ref('');
const expandedHoldingIds = ref([]);
const priceRefreshScheduleForm = ref(emptyPriceRefreshScheduleForm());
const indexPriceRefreshScheduleForm = ref(emptyPriceRefreshScheduleForm());
const intradayBackfillScheduleForm = ref(emptyIntradayBackfillScheduleForm());
const priceRefreshScheduleMessage = ref('');
const priceRefreshScheduleError = ref('');
const isPriceRefreshScheduleEditing = ref(false);
const isIndexPriceRefreshScheduleEditing = ref(false);
const isIntradayBackfillScheduleEditing = ref(false);
const isIntradayBackfillRunningNow = ref(false);
const priceRefreshTimer = ref(null);
const intradayBackfillTimer = ref(null);
const priceRefreshSettingsTimer = ref(null);
const isPriceRefreshSettingsPolling = ref(false);
const historicalPriceFetchTimer = ref(null);
const isHistoricalPriceFetchPolling = ref(false);
const dataExchangeReloadTimer = ref(null);
const dataIntradayReloadTimer = ref(null);
const isDashboardMenuCompact = ref(smAndDown.value);
const viewportWidth = ref(window.visualViewport?.width ?? window.innerWidth);
const viewportHeight = ref(window.visualViewport?.height ?? window.innerHeight);
const cashTransactionForm = ref(emptyCashTransactionForm());
const stockTransactionForm = ref(emptyStockTransactionForm());
const editingFlatexHoldingId = ref(null);
const flatexPriceEditValue = ref('');
const flatexPriceEditInput = ref(null);
const depotPriceSource = ref('latest');

const isLoginPage = computed(() => window.location.pathname === '/admin/login');
const canManageUsers = computed(() => user.value?.roles?.includes('super_admin') ?? false);
const canManageDashboardAdmin = computed(() => user.value?.roles?.some((role) => ['admin', 'super_admin'].includes(role)) ?? false);
const profileDisplayName = computed(() => user.value?.name || 'Loading...');
const appVersionLabel = computed(() => appVersion.value ?? '');
const dashboardMenuToggleLabel = computed(() => (isDashboardMenuCompact.value
    ? 'Enhance dashboard menu'
    : 'Minify dashboard menu'));
const isCompactWatchListTable = computed(() => viewportWidth.value < 1024);
const isHandsetLandscape = computed(() => viewportWidth.value <= 960
    && viewportHeight.value <= 600
    && viewportWidth.value > viewportHeight.value);
const isCompactDepotStocksTable = computed(() => isHandsetLandscape.value);
const isCompactCashLedgerTable = computed(() => isHandsetLandscape.value);
const watchListTableColumnCount = computed(() => {
    if (isHandsetLandscape.value) {
        return 6;
    }

    return isCompactWatchListTable.value ? 7 : 9;
});
const roleList = computed(() => user.value?.roles?.join(', ') ?? '');
const isPriceRefreshRunning = computed(() => {
    if (!priceRefresh.value || isFinishedPriceRefresh(priceRefresh.value)) {
        return false;
    }

    return ['queued', 'running'].includes(priceRefresh.value.status);
});
const isAutomaticPriceRefreshUpdating = computed(() => isPriceRefreshRunning.value
    || priceRefreshSettings.value?.status === 'updating');
const isAutomaticIndexPriceRefreshUpdating = computed(() => indexPriceRefreshSettings.value?.status === 'updating');
const isHistoricalPriceFetchRunning = computed(() => holdings.value.some((holding) => holding.historical_prices_fetching));
const isHeaderStatusUpdating = computed(() => isAutomaticPriceRefreshUpdating.value || isHistoricalPriceFetchRunning.value);
const priceRefreshHeaderStatusLabel = computed(() => {
    if (isAutomaticPriceRefreshUpdating.value) {
        return 'Updating prices';
    }

    if (isHistoricalPriceFetchRunning.value) {
        return 'fetching historical data';
    }

    return 'waiting';
});
const indexPriceRefreshHeaderStatusLabel = computed(() => {
    if (isAutomaticIndexPriceRefreshUpdating.value) {
        return 'Updating prices';
    }

    return indexPriceRefreshSettings.value?.status_label ?? 'waiting';
});
const visibleHoldingMessage = computed(() => {
    if (priceRefresh.value && isFinishedPriceRefresh(priceRefresh.value)) {
        return '';
    }

    return holdingMessage.value;
});
const priceRefreshProgressValue = computed(() => {
    if (!priceRefresh.value || priceRefresh.value.total === 0) {
        return 0;
    }

    return Math.round((priceRefresh.value.processed / priceRefresh.value.total) * 100);
});
const isIntradayBackfillRunning = computed(() => {
    if (!intradayBackfillRefresh.value || isFinishedPriceRefresh(intradayBackfillRefresh.value)) {
        return false;
    }

    return ['queued', 'running'].includes(intradayBackfillRefresh.value.status);
});
const intradayBackfillProgressValue = computed(() => {
    if (!intradayBackfillRefresh.value || intradayBackfillRefresh.value.total === 0) {
        return 0;
    }

    return Math.round((intradayBackfillRefresh.value.processed / intradayBackfillRefresh.value.total) * 100);
});
const isDataExchangeReloadRunning = computed(() => ['queued', 'running'].includes(dataExchangeRefresh.value?.status));
const selectedDataIntradayStock = computed(() => dataIntradayStocks.value.find((stock) => stock.id === selectedDataIntradayStockId.value) ?? null);
const dataIntradayLastUpdatedAt = computed(() => dataIntradayRefresh.value?.finished_at ?? null);
const isDataIntradayReloadRunning = computed(() => ['queued', 'running'].includes(dataIntradayRefresh.value?.status));
const EXCHANGE_REFRESH_DISMISSED_KEY = 'exchange_refresh_dismissed_id';
const dismissedDataExchangeRefreshId = ref(sessionStorage.getItem(EXCHANGE_REFRESH_DISMISSED_KEY));
const dataExchangeRefreshVisible = computed(() => {
    if (!dataExchangeRefresh.value) return false;
    return String(dataExchangeRefresh.value.refresh_id) !== dismissedDataExchangeRefreshId.value;
});
function dismissDataExchangeRefresh() {
    if (dataExchangeRefresh.value?.refresh_id) {
        dismissedDataExchangeRefreshId.value = String(dataExchangeRefresh.value.refresh_id);
        sessionStorage.setItem(EXCHANGE_REFRESH_DISMISSED_KEY, dismissedDataExchangeRefreshId.value);
    }
}
const dataExchangeReloadProgressValue = computed(() => {
    if (!dataExchangeRefresh.value || dataExchangeRefresh.value.total === 0) {
        return 0;
    }

    return Math.round((dataExchangeRefresh.value.processed / dataExchangeRefresh.value.total) * 100);
});
const dataExchangesLastUpdatedAt = computed(() => {
    const exchangeSyncedTimes = dataExchanges.value
        .map((exchange) => exchange.synced_at)
        .filter(Boolean)
        .map((value) => new Date(value))
        .filter((date) => !Number.isNaN(date.getTime()));

    if (exchangeSyncedTimes.length > 0) {
        return new Date(Math.max(...exchangeSyncedTimes.map((date) => date.getTime()))).toISOString();
    }

    return dataExchangeRefresh.value?.finished_at ?? null;
});
const DATA_INTRADAY_REFRESH_DISMISSED_KEY = 'data_intraday_refresh_info_dismissed';
const isDataIntradayRefreshDismissed = ref(localStorage.getItem(DATA_INTRADAY_REFRESH_DISMISSED_KEY) === '1');
const dataIntradayRefreshVisible = computed(() => {
    if (!dataIntradayRefresh.value || dataIntradayError.value) {
        return false;
    }

    if (isDataIntradayReloadRunning.value || dataIntradayRefresh.value.status === 'failed') {
        return true;
    }

    return !isDataIntradayRefreshDismissed.value;
});
function dismissDataIntradayRefresh() {
    isDataIntradayRefreshDismissed.value = true;
    localStorage.setItem(DATA_INTRADAY_REFRESH_DISMISSED_KEY, '1');
}
const dataIntradayReloadProgressValue = computed(() => {
    if (!dataIntradayRefresh.value || dataIntradayRefresh.value.total === 0) {
        return 0;
    }

    return Math.round((dataIntradayRefresh.value.processed / dataIntradayRefresh.value.total) * 100);
});
const sessionHeaderDates = computed(() => {
    const datedHolding = holdings.value.find((holding) => holding.start_price_date || holding.end_price_date);

    return {
        start: formatSessionHeaderDateValue(datedHolding?.start_price_date) ?? formatSessionHeaderDate(0),
        end: formatSessionHeaderDateValue(datedHolding?.end_price_date) ?? formatSessionHeaderDate(0),
        end24: formatSessionHeaderDateValue(datedHolding?.end_price_24_date) ?? formatSessionHeaderDate(1),
        end48: formatSessionHeaderDateValue(datedHolding?.end_price_48_date) ?? formatSessionHeaderDate(2),
    };
});
const selectedIndexRecentPrices = computed(() => selectedIndexWatchItem.value?.recent_prices ?? []);
const selectedIndexChart = computed(() => buildIndexPriceChart(selectedIndexRecentPrices.value));
const selectedAnalyzeHolding = computed(() => holdings.value.find((holding) => holding.id === selectedAnalyzeHoldingId.value) ?? null);
const selectedAnalyzeScopeLabel = computed(() => {
    if (selectedAnalyzeHoldingId.value === null) {
        return 'ALL';
    }

    return selectedAnalyzeHolding.value?.name
        || selectedAnalyzeHolding.value?.symbol
        || `Stock ${selectedAnalyzeHoldingId.value}`;
});
const selectedAnalyzeHistoryWindow = computed(() => analyzeChartWindow(
    selectedAnalyzeHolding.value?.intraday_candles ?? [],
    selectedAnalyzeHistoryRange.value,
    selectedAnalyzeHistoryWindowOffset.value,
));
const selectedAnalyzeIntradayCandlePrices = computed(() => selectedAnalyzeHistoryWindow.value.prices);
const canMoveAnalyzeChartBackward = computed(() => selectedAnalyzeHistoryWindow.value.canMoveBackward);
const canMoveAnalyzeChartForward = computed(() => selectedAnalyzeHistoryWindow.value.canMoveForward);
const analyzeChartMaxPoints = 256;
const analyzeCalendarNormalizedRangeKeys = ['1y', '6m', '3m', '1m', '1w'];
const selectedAnalyzeRawChartPrices = computed(() => {
    return selectedAnalyzeIntradayCandlePrices.value;
});
const selectedAnalyzeChartPrices = computed(() => normalizeAnalyzeChartPrices(
    selectedAnalyzeRawChartPrices.value,
    selectedAnalyzeHistoryRange.value,
));
const selectedAnalyzePreviousTradingClose = computed(() => previousAnalyzeTradingClose(
    selectedAnalyzeHolding.value?.intraday_candles ?? [],
    selectedAnalyzeChartPrices.value,
    selectedAnalyzeHistoryRange.value,
));
const selectedAnalyzeChartPricesWithPreviousClose = computed(() => withAnalyzePreviousTradingClosePoint(
    selectedAnalyzeChartPrices.value,
    selectedAnalyzePreviousTradingClose.value,
    selectedAnalyzeHistoryRange.value,
));
const selectedAnalyzeSparkline = computed(() => buildAnalyzeSparkline(
    selectedAnalyzeChartPricesWithPreviousClose.value,
    selectedAnalyzeHistoryRange.value,
));
const selectedAnalyzeRangeCaptureLabel = computed(() => (
    analyzeHistoryRangeItems.find((item) => item.key === selectedAnalyzeHistoryRange.value)?.captureLabel ?? ''
));
const showAnalyzeSparklineDots = computed(() => isAnalyzeTodayRange(selectedAnalyzeHistoryRange.value));
const analyzeIntradayDetail = computed(() => analyzeIntradayCandles.value?.intraday ?? null);
const analyzeIntradayDetailDays = computed(() => {
    const days = analyzeIntradayCandles.value?.intraday_days;

    if (Array.isArray(days)) {
        return days;
    }

    return analyzeIntradayDetail.value ? [analyzeIntradayDetail.value] : [];
});
const analyzeIntradayDetailRows = computed(() => analyzeIntradayDetailDays.value.flatMap((day) => day.rows ?? []));
const selectedAnalyzeRealtimePriceRows = computed(() => {
    const recentPrices = selectedAnalyzeHolding.value?.recent_prices;

    if (!Array.isArray(recentPrices)) {
        return [];
    }

    return sortPriceRowsByTime(recentPrices.filter(isRealtimePriceRow));
});
const selectedAnalyzeRealtimePriceRowsWithChanges = computed(() => priceRowsWithPercentChanges(
    selectedAnalyzeRealtimePriceRows.value,
));
const selectedAnalyzeIntradayPriceRows = computed(() => {
    const intradayPrices = selectedAnalyzeHolding.value?.intraday_candles;

    if (!Array.isArray(intradayPrices)) {
        return [];
    }

    const sortedIntradayPrices = sortPriceRowsByTime(
        intradayPrices.filter((intradayPrice) => intradayPrice.price !== null && intradayPrice.price !== undefined),
    );

    const selectedTradingDate = selectedAnalyzeIntradayTradingDate(sortedIntradayPrices);

    if (selectedTradingDate === null) {
        return sortedIntradayPrices;
    }

    const previousDayLastPrice = previousTradingDayLastIntradayPrice(sortedIntradayPrices, selectedTradingDate);
    const selectedDayPrices = sortedIntradayPrices
        .filter((intradayPrice) => intradayPriceTradingDate(intradayPrice) === selectedTradingDate);

    return previousDayLastPrice === null
        ? selectedDayPrices
        : [previousDayLastPrice, ...selectedDayPrices];
});
const selectedAnalyzeIntradayPriceRowsWithChanges = computed(() => priceRowsWithPercentChanges(
    selectedAnalyzeIntradayPriceRows.value,
));

function priceRowsWithPercentChanges(priceRows) {
    const firstPrice = numericPrice(priceRows[0]);

    return priceRows.map((priceRow, priceRowIndex) => {
        const price = numericPrice(priceRow);
        const previousPrice = priceRowIndex === 0 ? null : numericPrice(priceRows[priceRowIndex - 1]);

        return {
            ...priceRow,
            firstChangePercent: priceChangePercent(price, firstPrice),
            previousChangePercent: priceChangePercent(price, previousPrice),
        };
    });
}

function numericPrice(priceRow) {
    const price = Number(priceRow?.price);

    return Number.isFinite(price) ? price : null;
}

function priceChangePercent(price, referencePrice) {
    if (price === null || referencePrice === null || referencePrice === 0) {
        return null;
    }

    return ((price - referencePrice) / referencePrice) * 100;
}

function isRealtimePriceRow(priceRow) {
    const sourceName = typeof priceRow.source_name === 'string' ? priceRow.source_name.toLowerCase() : '';

    return sourceName.includes('real-time') || sourceName.includes('realtime');
}

function sortPriceRowsByTime(priceRows) {
    return [...priceRows].sort((firstPrice, secondPrice) => {
        const firstTime = new Date(firstPrice.as_of ?? '').getTime();
        const secondTime = new Date(secondPrice.as_of ?? '').getTime();

        if (Number.isNaN(firstTime) && Number.isNaN(secondTime)) {
            return 0;
        }

        if (Number.isNaN(firstTime)) {
            return 1;
        }

        if (Number.isNaN(secondTime)) {
            return -1;
        }

        return firstTime - secondTime;
    });
}

function previousTradingDayLastIntradayPrice(intradayPrices, selectedTradingDate) {
    const previousTradingDate = intradayPrices
        .map((intradayPrice) => intradayPriceTradingDate(intradayPrice))
        .filter((tradingDate) => tradingDate !== null && tradingDate < selectedTradingDate)
        .sort()
        .at(-1);

    if (!previousTradingDate) {
        return null;
    }

    return intradayPrices
        .filter((intradayPrice) => intradayPriceTradingDate(intradayPrice) === previousTradingDate)
        .at(-1) ?? null;
}

function selectedAnalyzeIntradayTradingDate(intradayPrices) {
    const tradingDates = intradayPrices
        .map((intradayPrice) => intradayPriceTradingDate(intradayPrice))
        .filter(Boolean);

    if (tradingDates.length === 0) {
        return null;
    }

    const today = localDateKey(new Date());

    if (tradingDates.includes(today)) {
        return today;
    }

    return [...tradingDates].sort().at(-1) ?? null;
}

function intradayPriceTradingDate(intradayPrice) {
    if (typeof intradayPrice.trading_date === 'string' && intradayPrice.trading_date.trim() !== '') {
        return intradayPrice.trading_date.slice(0, 10);
    }

    if (!intradayPrice.as_of) {
        return null;
    }

    const date = new Date(intradayPrice.as_of);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return localDateKey(date);
}

function localDateKey(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}
const eodhdUsageItems = computed(() => {
    if (!eodhdApiUsage.value) {
        return [];
    }

    return [
        {
            key: 'hour',
            label: 'Hour',
            usage: eodhdApiUsage.value.hour,
        },
        {
            key: 'day',
            label: 'Day',
            usage: eodhdApiUsage.value.day,
        },
    ].filter(item => item.usage);
});
const queueStatusLabel = computed(() => {
    if (queueStatusLoading.value) {
        return 'Queue checking';
    }

    if (queueStatusError.value) {
        return 'Queue unavailable';
    }

    if (!queueStatus.value) {
        return 'Queue unknown';
    }

    const pending = Number(queueStatus.value.pending ?? 0);
    const reserved = Number(queueStatus.value.reserved ?? 0);
    const failed = Number(queueStatus.value.failed ?? 0);
    const statusLabel = {
        ok: 'Queue OK',
        waiting: 'Queue waiting',
        check: 'Queue check',
    }[queueStatus.value.status] ?? 'Queue check';

    return [
        statusLabel,
        `${queueStatus.value.connection}:${queueStatus.value.name}`,
        `P ${formatInteger(pending)}`,
        `R ${formatInteger(reserved)}`,
        `F ${formatInteger(failed)}`,
        `${formatInteger(queueStatus.value.retry_after)}s/${formatInteger(queueStatus.value.max_job_timeout)}s`,
    ].join(' · ');
});
const queueStatusTitle = computed(() => {
    if (queueStatusError.value) {
        return queueStatusError.value;
    }

    if (!queueStatus.value) {
        return 'Queue status has not been loaded yet.';
    }

    if (queueStatus.value.status === 'waiting') {
        return 'Jobs are pending, but no worker currently has a job reserved. This is normal briefly with a cron worker; it should clear on the next run.';
    }

    if (!queueStatus.value.issues?.length) {
        return 'Queue configuration looks appropriate.';
    }

    return queueStatus.value.issues.join(' ');
});
const queueStatusClass = computed(() => {
    if (queueStatusError.value || queueStatus.value?.status === 'check') {
        return 'text-error';
    }

    if (queueStatus.value?.status === 'waiting') {
        return 'text-warning';
    }

    return 'text-medium-emphasis';
});
const canClearQueue = computed(() => queueStatus.value
    && queueStatus.value.status !== 'ok'
    && !queueStatusLoading.value);
const queueClearButtonLabel = computed(() => (canClearQueue.value
    ? `Clear queue: ${queueStatusTitle.value}`
    : 'Queue is clean'));
const analyzeSubmenuItems = [
    {
        key: 'overview',
        label: 'Charts',
        icon: 'mdi-view-grid-outline',
    },
    {
        key: 'intraday',
        label: 'Intraday',
        icon: 'mdi-chart-timeline-variant',
    },
    {
        key: 'detail',
        label: 'Detail',
        icon: 'mdi-chart-box-outline',
    },
    {
        key: 'tests',
        label: 'Tests',
        icon: 'mdi-test-tube',
    },
];
const dataSubmenuItems = [
    {
        key: 'exchanges',
        label: 'Exchanges',
        icon: 'mdi-swap-horizontal',
    },
    {
        key: 'intraday',
        label: 'Intraday',
        icon: 'mdi-chart-timeline-variant',
    },
];
const analyzeHistoryRangeItems = [
    {
        key: '1y',
        label: '1 year',
        captureLabel: '365-day capture',
    },
    {
        key: '6m',
        label: '6 months',
        captureLabel: '183-day capture',
    },
    {
        key: '3m',
        label: '3 months',
        captureLabel: '92-day capture',
    },
    {
        key: '1m',
        label: '1 month',
        captureLabel: '31-day capture',
    },
    {
        key: '1w',
        label: '1 week',
        captureLabel: '7-day capture',
    },
    {
        key: 'today-1',
        label: 'today-1',
        captureLabel: 'with previous close',
    },
    {
        key: 'today',
        label: 'today',
        captureLabel: 'all prices captured',
    },
];
const analyzeHistoryRangeDays = {
    '1y': 365,
    '6m': 183,
    '3m': 92,
    '1m': 31,
    '1w': 7,
    today: 0,
    'today-1': 0,
};

const menuItems = computed(() => [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: 'mdi-view-dashboard-outline',
    },
    ...(canManageDashboardAdmin.value ? [
        {
            key: 'data',
            label: 'Data',
            icon: 'mdi-database-outline',
        },
    ] : []),
    {
        key: 'analyze',
        label: 'Analyze',
        icon: 'mdi-chart-line',
    },
    {
        key: 'depot',
        label: 'Depot',
        subtitle: activeDepot.value?.name ?? '–',
        icon: 'mdi-briefcase-outline',
    },
    ...(canManageDashboardAdmin.value ? [
        {
            key: 'admin',
            label: 'Admin',
            icon: 'mdi-shield-crown-outline',
            children: [
                {
                    key: 'depots',
                    label: 'Depots',
                    icon: 'mdi-briefcase-outline',
                },
                ...(canManageUsers.value ? [
                    {
                        key: 'users',
                        label: 'Users',
                        icon: 'mdi-account-group-outline',
                    },
                    {
                        key: 'roles',
                        label: 'Roles',
                        icon: 'mdi-shield-account-outline',
                    },
                ] : []),
                {
                    key: 'updates',
                    label: 'Updates',
                    icon: 'mdi-update',
                },
            ],
        },
    ] : []),
    {
        key: 'profile',
        label: 'Profile',
        icon: 'mdi-account-circle-outline',
    },
]);

watch(
    user,
    (currentUser) => {
        profileLastName.value = currentUser?.last_name ?? '';
        profileFirstName.value = currentUser?.first_name ?? '';
    },
    { immediate: true },
);

watch(
    priceRefreshSettings,
    (settings) => {
        if (isPriceRefreshScheduleEditing.value) {
            return;
        }

        priceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(settings);
    },
    { immediate: true },
);

watch(
    indexPriceRefreshSettings,
    (settings) => {
        if (isIndexPriceRefreshScheduleEditing.value) {
            return;
        }

        indexPriceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(settings);
    },
    { immediate: true },
);

watch(
    intradayBackfillSettings,
    (settings) => {
        if (isIntradayBackfillScheduleEditing.value) {
            return;
        }

        intradayBackfillScheduleForm.value = intradayBackfillScheduleFormFromSettings(settings);
    },
    { immediate: true },
);

watch(
    uiPreferences,
    (preferences) => {
        if (['latest', 'flatex'].includes(preferences?.depot_price_source)) {
            depotPriceSource.value = preferences.depot_price_source;
        }
    },
    { immediate: true },
);

watch(
    holdings,
    (currentHoldings) => {
        if (selectedAnalyzeHoldingId.value === null) {
            return;
        }

        if (currentHoldings.some((holding) => holding.id === selectedAnalyzeHoldingId.value)) {
            return;
        }

        selectedAnalyzeHoldingId.value = null;

        if (activeSection.value === 'analyze') {
            updateUrlPath({ replace: true });
        }
    },
);

watch(
    [selectedAnalyzeHistoryRange, selectedAnalyzeHoldingId],
    () => {
        selectedAnalyzeHistoryWindowOffset.value = 0;
    },
);

watch(
    [activeSection, activeAnalyzeSubsection, activeDataSubsection],
    ([section, subsection, dataSubsection]) => {
        if (section === 'analyze' && subsection === 'tests') {
            depotsStore.loadTestOptions();
        }

        if (section === 'data' && dataSubsection === 'exchanges') {
            depotsStore.loadDataExchanges()
                .then((data) => {
                    if (['queued', 'running'].includes(data.refresh?.status)) {
                        startDataExchangeReloadPolling(data.refresh.refresh_id);
                    }
                })
                .catch(() => {});
        }

        if (section === 'data' && dataSubsection === 'intraday') {
            depotsStore.loadDataIntraday(selectedDataIntradayStockId.value)
                .then((data) => {
                    selectedDataIntradayStockId.value = data.selected_stock_id ?? selectedDataIntradayStockId.value;

                    if (['queued', 'running'].includes(data.refresh?.status)) {
                        startDataIntradayReloadPolling(data.refresh.refresh_id);
                    }
                })
                .catch(() => {});
        }
    },
);

watch(
    [activeSection, activeAnalyzeSubsection, selectedAnalyzeHoldingId],
    () => {
        ensureAnalyzeDetailIntradayCandles();
    },
);

watch(
    isHistoricalPriceFetchRunning,
    (isRunning) => {
        if (isRunning) {
            startHistoricalPriceFetchPolling();

            return;
        }

        stopHistoricalPriceFetchPolling();
    },
);

watch(
    isIntradayBackfillRunning,
    (isRunning) => {
        if (isRunning && intradayBackfillRefresh.value?.refresh_id) {
            startIntradayBackfillPolling(intradayBackfillRefresh.value.refresh_id);

            return;
        }

        stopIntradayBackfillPolling();
    },
);

onMounted(async () => {
    updateViewportMetrics();
    window.addEventListener('resize', updateViewportMetrics);

    if (isLoginPage.value) {
        return;
    }

    await auth.loadUser();
    applyRouteFromPath();

    await depotsStore.loadActiveDepot();

    if (activeSection.value === 'depot') {
        depotsStore.loadTransactions();
    }

    await Promise.all([
        depotsStore.loadWatchlistHoldings(),
        depotsStore.loadIndexWatchItems(),
        depotsStore.loadWatchlistExchangeTradingTimes(),
    ]);

    if (activeSection.value === 'dashboard') {
        depotsStore.loadQueueStatus();
    }

    ensureAnalyzeDetailIntradayCandles();
    startPriceRefreshSettingsPolling();

    await depotsStore.loadDepots();

    if (canManageUsers.value) {
        await Promise.all([
            usersStore.loadUsers(),
            rolesStore.loadRoles(),
        ]);
    }

    window.addEventListener('popstate', applyRouteFromPath);
});

onBeforeUnmount(() => {
    stopPriceRefreshPolling();
    stopIntradayBackfillPolling();
    stopPriceRefreshSettingsPolling();
    stopHistoricalPriceFetchPolling();
    stopDataExchangeReloadPolling();
    stopDataIntradayReloadPolling();
    stopHoldingDialogKeyboardShortcuts();
    window.removeEventListener('resize', updateViewportMetrics);
    window.removeEventListener('popstate', applyRouteFromPath);
});

function updateViewportMetrics() {
    viewportWidth.value = window.visualViewport?.width ?? window.innerWidth;
    viewportHeight.value = window.visualViewport?.height ?? window.innerHeight;
}

function navigateSection(section) {
    activeSection.value = section;

    if (section === 'analyze' && !isAnalyzeSubsection(activeAnalyzeSubsection.value)) {
        activeAnalyzeSubsection.value = 'overview';
    }

    if (section === 'data' && !isDataSubsection(activeDataSubsection.value)) {
        activeDataSubsection.value = 'exchanges';
    }

    clearSectionMessages();
    updateUrlPath();

    if (section === 'users') {
        usersStore.loadUsers(userPagination.value.current_page);
    }

    if (section === 'roles') {
        rolesStore.loadRoles(rolePagination.value.current_page);
    }

    if (section === 'depot') {
        depotsStore.loadActiveDepot();
        depotsStore.loadTransactions();
    }

    if (section === 'depots') {
        depotsStore.loadDepots(depotPagination.value.current_page);
    }

    if (section === 'dashboard') {
        depotsStore.loadQueueStatus();
    }

    if (section === 'updates') {
        loadPriceRefreshSettings();
    }
}

function navigateAnalyzeSubsection(subsection) {
    if (!isAnalyzeSubsection(subsection) || activeAnalyzeSubsection.value === subsection) {
        return;
    }

    activeAnalyzeSubsection.value = subsection;
    activeSection.value = 'analyze';
    clearSectionMessages();
    updateUrlPath();
}

function navigateDataSubsection(subsection) {
    if (!isDataSubsection(subsection) || activeDataSubsection.value === subsection) {
        return;
    }

    activeDataSubsection.value = subsection;
    activeSection.value = 'data';
    clearSectionMessages();
    updateUrlPath();
}

function selectAnalyzeHolding(holdingId) {
    selectedAnalyzeHoldingId.value = holdingId;
    updateUrlPath();
}

function moveAnalyzeChartWindowBackward() {
    if (!canMoveAnalyzeChartBackward.value) {
        return;
    }

    selectedAnalyzeHistoryWindowOffset.value += 1;
}

function moveAnalyzeChartWindowForward() {
    if (!canMoveAnalyzeChartForward.value) {
        return;
    }

    selectedAnalyzeHistoryWindowOffset.value = Math.max(0, selectedAnalyzeHistoryWindowOffset.value - 1);
}

function toggleDashboardMenuCompact() {
    isDashboardMenuCompact.value = !isDashboardMenuCompact.value;
}

function applyRouteFromPath() {
    const path = window.location.pathname.replace(/\/+$/, '') || '/admin';

    if (path === '/admin/profile') {
        activeSection.value = 'profile';

        return;
    }

    if (path.startsWith('/admin/menu/')) {
        const [sectionSegment, subsectionSegment] = path
            .replace('/admin/menu/', '')
            .split('/')
            .map((segment) => decodeURIComponent(segment));
        const section = sectionSegment ?? '';
        const normalizedSection = section === 'dashboard-admin' ? 'updates' : section;

        if (normalizedSection === 'analyze') {
            activeSection.value = 'analyze';
            activeAnalyzeSubsection.value = isAnalyzeSubsection(subsectionSegment)
                ? subsectionSegment
                : 'overview';
            applyAnalyzeSelectionFromQuery(new URLSearchParams(window.location.search));
            updateUrlPath({ replace: true });

            return;
        }

        if (normalizedSection === 'data') {
            activeSection.value = 'data';
            activeDataSubsection.value = isDataSubsection(subsectionSegment)
                ? subsectionSegment
                : 'exchanges';
            updateUrlPath({ replace: true });

            return;
        }

        const isTopLevel = menuItems.value.some((item) => item.key === normalizedSection);
        const isChild = menuItems.value.flatMap((item) => item.children ?? []).some((child) => child.key === normalizedSection);
        activeSection.value = (isTopLevel || isChild) ? normalizedSection : 'dashboard';

        return;
    }

    activeSection.value = 'dashboard';
}

function updateUrlPath(options = {}) {
    const path = activeSection.value === 'dashboard'
        ? '/admin/dashboard'
        : activeSection.value === 'profile'
            ? '/admin/profile'
            : activeSection.value === 'analyze'
                ? `/admin/menu/analyze/${activeAnalyzeSubsection.value}`
                : activeSection.value === 'data'
                    ? `/admin/menu/data/${activeDataSubsection.value}`
                    : `/admin/menu/${activeSection.value}`;
    const target = activeSection.value === 'analyze'
        ? `${path}?stock=${selectedAnalyzeHoldingId.value === null ? 'all' : encodeURIComponent(String(selectedAnalyzeHoldingId.value))}`
        : path;

    if (`${window.location.pathname}${window.location.search}` === target) {
        return;
    }

    if (options.replace) {
        window.history.replaceState({}, '', target);

        return;
    }

    window.history.pushState({}, '', target);
}

function isAnalyzeSubsection(subsection) {
    return analyzeSubmenuItems.some((item) => item.key === subsection);
}

function isDataSubsection(subsection) {
    return dataSubmenuItems.some((item) => item.key === subsection);
}

function applyAnalyzeSelectionFromQuery(searchParams) {
    const stock = searchParams.get('stock');

    if (stock === null || stock === '' || stock === 'all') {
        selectedAnalyzeHoldingId.value = null;

        return;
    }

    const holdingId = Number(stock);
    selectedAnalyzeHoldingId.value = Number.isInteger(holdingId) && holdingId > 0
        ? holdingId
        : null;
}

async function requestLoginCode() {
    localError.value = '';

    try {
        await auth.sendCode(email.value);
    } catch (err) {
        localError.value = err.message;
    }
}

async function verifyLoginCode() {
    localError.value = '';

    try {
        await auth.verifyCode(code.value);
    } catch (err) {
        localError.value = err.message;
    }
}

async function submitPasswordLogin() {
    localError.value = '';

    try {
        await auth.passwordLogin(email.value, loginPassword.value);
    } catch (err) {
        localError.value = err.message;
    }
}

function openNameDialog() {
    profileLastName.value = user.value?.last_name ?? '';
    profileFirstName.value = user.value?.first_name ?? '';
    profileError.value = '';
    profileMessage.value = '';
    isNameDialogOpen.value = true;
}

function abortNameEdit() {
    isNameDialogOpen.value = false;
}

async function saveProfileName() {
    profileError.value = '';
    profileMessage.value = '';

    try {
        await auth.updateName(profileLastName.value, profileFirstName.value);
        profileMessage.value = auth.notice;
        isNameDialogOpen.value = false;
    } catch (err) {
        profileError.value = err.message;
    }
}

function openPasswordDialog() {
    newPassword.value = '';
    profileError.value = '';
    profileMessage.value = '';
    isPasswordDialogOpen.value = true;
}

function abortPasswordEdit() {
    isPasswordDialogOpen.value = false;
    newPassword.value = '';
}

async function savePassword() {
    profileError.value = '';
    profileMessage.value = '';

    try {
        await auth.updatePassword(newPassword.value);
        profileMessage.value = auth.notice;
        abortPasswordEdit();
    } catch (err) {
        profileError.value = err.message;
    }
}

function openCreateUserDialog() {
    userDialogMode.value = 'create';
    selectedUser.value = null;
    userForm.value = emptyUserForm();
    userError.value = '';
    userMessage.value = '';
    isUserDialogOpen.value = true;
}

function openEditUserDialog(userRecord) {
    userDialogMode.value = 'edit';
    selectedUser.value = userRecord;
    userForm.value = {
        last_name: userRecord.last_name,
        first_name: userRecord.first_name,
        email: userRecord.email,
        roles: [...userRecord.roles],
    };
    userError.value = '';
    userMessage.value = '';
    isUserDialogOpen.value = true;
}

function abortUserDialog() {
    isUserDialogOpen.value = false;
    selectedUser.value = null;
}

async function saveUser() {
    userError.value = '';
    userMessage.value = '';

    try {
        const payload = { ...userForm.value };
        const data = userDialogMode.value === 'create'
            ? await usersStore.createUser(payload)
            : await usersStore.updateUser(selectedUser.value.id, payload);

        userMessage.value = data.message;
        isUserDialogOpen.value = false;
        await usersStore.loadUsers(userPagination.value.current_page);
    } catch (err) {
        userError.value = err.message;
    }
}

function openDeleteUserDialog(userRecord) {
    selectedUser.value = userRecord;
    userError.value = '';
    userMessage.value = '';
    isDeleteUserDialogOpen.value = true;
}

function abortDeleteUserDialog() {
    isDeleteUserDialogOpen.value = false;
    selectedUser.value = null;
}

async function deleteUser() {
    userError.value = '';
    userMessage.value = '';

    try {
        const data = await usersStore.deleteUser(selectedUser.value.id);
        userMessage.value = data.message;
        abortDeleteUserDialog();
        await usersStore.loadUsers(userPagination.value.current_page);
    } catch (err) {
        userError.value = err.message;
    }
}

function openCreateRoleDialog() {
    roleDialogMode.value = 'create';
    selectedRole.value = null;
    roleForm.value = emptyRoleForm();
    roleError.value = '';
    roleMessage.value = '';
    isRoleDialogOpen.value = true;
}

function openEditRoleDialog(role) {
    roleDialogMode.value = 'edit';
    selectedRole.value = role;
    roleForm.value = {
        name: role.name,
    };
    roleError.value = '';
    roleMessage.value = '';
    isRoleDialogOpen.value = true;
}

function abortRoleDialog() {
    isRoleDialogOpen.value = false;
    selectedRole.value = null;
}

async function saveRole() {
    roleError.value = '';
    roleMessage.value = '';

    try {
        const data = roleDialogMode.value === 'create'
            ? await rolesStore.createRole(roleForm.value)
            : await rolesStore.updateRole(selectedRole.value.id, roleForm.value);

        roleMessage.value = data.message;
        isRoleDialogOpen.value = false;
        await rolesStore.loadRoles(rolePagination.value.current_page);
        await usersStore.loadUsers(userPagination.value.current_page);
    } catch (err) {
        roleError.value = err.message;
    }
}

function openDeleteRoleDialog(role) {
    selectedRole.value = role;
    roleError.value = '';
    roleMessage.value = '';
    isDeleteRoleDialogOpen.value = true;
}

function abortDeleteRoleDialog() {
    isDeleteRoleDialogOpen.value = false;
    selectedRole.value = null;
}

async function deleteRole() {
    roleError.value = '';
    roleMessage.value = '';

    try {
        const data = await rolesStore.deleteRole(selectedRole.value.id);
        roleMessage.value = data.message;
        abortDeleteRoleDialog();
        await rolesStore.loadRoles(rolePagination.value.current_page);
        await usersStore.loadUsers(userPagination.value.current_page);
    } catch (err) {
        roleError.value = err.message;
    }
}

function openCreateDepotDialog() {
    depotDialogMode.value = 'create';
    selectedDepot.value = null;
    depotForm.value = emptyDepotForm();
    depotError.value = '';
    depotMessage.value = '';
    isDepotDialogOpen.value = true;
}

function openEditDepotDialog(depot) {
    depotDialogMode.value = 'edit';
    selectedDepot.value = depot;
    depotForm.value = {
        name: depot.name,
        account_balance: depot.account_balance,
    };
    depotError.value = '';
    depotMessage.value = '';
    isDepotDialogOpen.value = true;
}

function abortDepotDialog() {
    isDepotDialogOpen.value = false;
    selectedDepot.value = null;
}

async function saveDepot() {
    depotError.value = '';
    depotMessage.value = '';

    try {
        const data = depotDialogMode.value === 'create'
            ? await depotsStore.createDepot(depotForm.value)
            : await depotsStore.updateDepot(selectedDepot.value.id, depotForm.value);

        depotMessage.value = data.message;
        isDepotDialogOpen.value = false;
        await depotsStore.loadActiveDepot();
        await depotsStore.loadDepots(depotPagination.value.current_page);
    } catch (err) {
        depotError.value = err.message;
    }
}

async function activateDepot(depot) {
    depotError.value = '';
    depotMessage.value = '';

    try {
        const data = await depotsStore.activateDepot(depot.id);
        depotMessage.value = data.message;
        await depotsStore.loadActiveDepot();
        await depotsStore.loadWatchlistHoldings();
        await depotsStore.loadDepots(depotPagination.value.current_page);
    } catch (err) {
        depotError.value = err.message;
    }
}

function openHoldingDialog() {
    holdingSearchQuery.value = '';
    depotsStore.stockSearchResults = [];
    depotsStore.stockSearchError = '';
    holdingError.value = '';
    holdingMessage.value = '';
    isHoldingDialogOpen.value = true;
    startHoldingDialogKeyboardShortcuts();
    focusHoldingSearchInput();
}

function abortHoldingDialog() {
    isHoldingDialogOpen.value = false;
    stopHoldingDialogKeyboardShortcuts();
}

function openIndexDialog() {
    indexSearchQuery.value = '';
    depotsStore.stockSearchResults = [];
    depotsStore.stockSearchError = '';
    indexError.value = '';
    indexMessage.value = '';
    isIndexDialogOpen.value = true;
    focusIndexSearchInput();
}

function abortIndexDialog() {
    isIndexDialogOpen.value = false;
}

async function openIndexPriceDialog(indexItem) {
    selectedIndexWatchItem.value = indexItem;
    indexPriceDialogError.value = '';
    isIndexPriceDialogOpen.value = true;

    if (hasEnoughIndexRecentPrices(indexItem)) {
        return;
    }

    isIndexPriceDialogLoading.value = true;

    try {
        const data = await depotsStore.ensureIndexWatchItemPrices(indexItem.id);
        selectedIndexWatchItem.value = data.index ?? indexItem;
    } catch (err) {
        indexPriceDialogError.value = err.message;
    } finally {
        isIndexPriceDialogLoading.value = false;
    }
}

function closeIndexPriceDialog() {
    isIndexPriceDialogOpen.value = false;
    isIndexPriceDialogLoading.value = false;
    indexPriceDialogError.value = '';
    selectedIndexWatchItem.value = null;
}

async function focusHoldingSearchInput() {
    await nextTick();

    holdingSearchInput.value?.focus?.();
}

async function focusIndexSearchInput() {
    await nextTick();

    indexSearchInput.value?.focus?.();
}

function handleHoldingSearchKeydown(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        searchStocks();

        return;
    }

    if (event.key === 'Escape') {
        event.preventDefault();
        event.stopPropagation();
        abortHoldingDialog();
    }
}

function handleHoldingDialogDocumentKeydown(event) {
    if (! isHoldingDialogOpen.value || event.key !== 'Escape') {
        return;
    }

    event.preventDefault();
    abortHoldingDialog();
}

function startHoldingDialogKeyboardShortcuts() {
    stopHoldingDialogKeyboardShortcuts();
    document.addEventListener('keydown', handleHoldingDialogDocumentKeydown, true);
}

function stopHoldingDialogKeyboardShortcuts() {
    document.removeEventListener('keydown', handleHoldingDialogDocumentKeydown, true);
}

async function searchStocks() {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        await depotsStore.searchStocks(holdingSearchQuery.value);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function searchIndexes() {
    indexError.value = '';
    indexMessage.value = '';

    try {
        await depotsStore.searchStocks(indexSearchQuery.value);
    } catch (err) {
        indexError.value = err.message;
    }
}

async function saveHolding(result) {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.createWatchlistHolding(result);
        holdingMessage.value = data.message;
        isHoldingDialogOpen.value = false;
        stopHoldingDialogKeyboardShortcuts();
        await Promise.all([
            depotsStore.loadWatchlistHoldings(holdingsPagination.value.current_page),
            depotsStore.loadWatchlistExchangeTradingTimes(),
        ]);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function saveIndexWatchItem(result) {
    indexError.value = '';
    indexMessage.value = '';

    try {
        const data = await depotsStore.createIndexWatchItem(result);
        indexMessage.value = data.message;
        holdingMessage.value = data.message;
        isIndexDialogOpen.value = false;
        await depotsStore.loadWatchlistExchangeTradingTimes();
    } catch (err) {
        indexError.value = err.message;
    }
}

async function refreshHoldingPrices() {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.refreshWatchlistPrices();
        holdingMessage.value = data.message;
        await depotsStore.loadQueueStatus();

        if (!data.refresh) {
            await depotsStore.loadWatchlistHoldings();
            await loadPriceRefreshSettings();
            holdingMessage.value = '';

            return;
        }

        if (isFinishedPriceRefresh(data.refresh)) {
            await finishPriceRefresh(data.refresh);

            return;
        }

        startPriceRefreshPolling(data.refresh.refresh_id);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function clearQueue() {
    if (!canClearQueue.value) {
        return;
    }

    try {
        await depotsStore.clearQueue();
    } catch (err) {
        queueStatusError.value = err.message;
    }
}

function exportHoldingsPdf() {
    window.open('/admin/watchlist/holdings/pdf', '_blank', 'noopener');
}

function openCashTransactionDialog(type) {
    cashTransactionForm.value = {
        type,
        total_amount: '',
        booked_at: localDateInputValue(),
        note: '',
    };
    holdingError.value = '';
    holdingMessage.value = '';
    isCashTransactionDialogOpen.value = true;
}

function abortCashTransactionDialog() {
    isCashTransactionDialogOpen.value = false;
    cashTransactionForm.value = emptyCashTransactionForm();
}

async function bookCashTransaction() {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.bookCashTransaction({
            type: cashTransactionForm.value.type,
            total_amount: Number(cashTransactionForm.value.total_amount),
            booked_at: cashTransactionForm.value.booked_at || null,
            note: cashTransactionForm.value.note || null,
        });

        holdingMessage.value = data.message;
        abortCashTransactionDialog();
        await depotsStore.loadActiveDepot();
    } catch (err) {
        holdingError.value = err.message;
    }
}

function openStockTransactionDialog(holding, type) {
    transactionHolding.value = holding;
    stockTransactionForm.value = {
        type,
        stock_holding_id: holding.id,
        pieces: '',
        total_amount: '',
        booked_at: localDateInputValue(),
        note: '',
    };
    holdingError.value = '';
    holdingMessage.value = '';
    isStockTransactionDialogOpen.value = true;
}

function abortStockTransactionDialog() {
    isStockTransactionDialogOpen.value = false;
    transactionHolding.value = null;
    stockTransactionForm.value = emptyStockTransactionForm();
}

async function bookStockTransaction() {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.bookStockTransaction({
            type: stockTransactionForm.value.type,
            stock_holding_id: stockTransactionForm.value.stock_holding_id,
            pieces: Number(stockTransactionForm.value.pieces),
            total_amount: Number(stockTransactionForm.value.total_amount),
            booked_at: stockTransactionForm.value.booked_at || null,
            note: stockTransactionForm.value.note || null,
        });

        holdingMessage.value = data.message;
        abortStockTransactionDialog();
        await depotsStore.loadWatchlistHoldings(holdingsPagination.value.current_page);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function savePriceRefreshSchedule() {
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';

    try {
        const data = await depotsStore.updatePriceRefreshSettings({
            trading_interval_minutes: Number(priceRefreshScheduleForm.value.trading_interval_minutes),
            trading_starts_before_minutes: Number(priceRefreshScheduleForm.value.trading_starts_before_minutes),
            trading_ends_after_minutes: Number(priceRefreshScheduleForm.value.trading_ends_after_minutes),
            closed_refresh_enabled: Boolean(priceRefreshScheduleForm.value.closed_refresh_enabled),
            closed_interval_minutes: Number(priceRefreshScheduleForm.value.closed_interval_minutes),
        });

        priceRefreshScheduleMessage.value = data.message;
        isPriceRefreshScheduleEditing.value = false;
        priceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(
            data.price_refresh_settings ?? priceRefreshSettings.value,
        );

        if (data.refresh) {
            holdingMessage.value = data.refresh.message;
            startPriceRefreshPolling(data.refresh.refresh_id);
        }
    } catch (err) {
        priceRefreshScheduleError.value = err.message;
    }
}

async function saveIndexPriceRefreshSchedule() {
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';

    try {
        const data = await depotsStore.updateIndexPriceRefreshSettings({
            trading_interval_minutes: Number(indexPriceRefreshScheduleForm.value.trading_interval_minutes),
            trading_starts_before_minutes: Number(indexPriceRefreshScheduleForm.value.trading_starts_before_minutes),
            trading_ends_after_minutes: Number(indexPriceRefreshScheduleForm.value.trading_ends_after_minutes),
            closed_refresh_enabled: Boolean(indexPriceRefreshScheduleForm.value.closed_refresh_enabled),
            closed_interval_minutes: Number(indexPriceRefreshScheduleForm.value.closed_interval_minutes),
        });

        priceRefreshScheduleMessage.value = data.message;
        isIndexPriceRefreshScheduleEditing.value = false;
        indexPriceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(
            data.index_price_refresh_settings ?? indexPriceRefreshSettings.value,
        );
    } catch (err) {
        priceRefreshScheduleError.value = err.message;
    }
}

async function saveIntradayBackfillSchedule() {
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';

    try {
        const data = await depotsStore.updateIntradayBackfillSettings({
            daily_time: intradayBackfillScheduleForm.value.daily_time,
        });

        priceRefreshScheduleMessage.value = data.message;
        isIntradayBackfillScheduleEditing.value = false;
        intradayBackfillScheduleForm.value = intradayBackfillScheduleFormFromSettings(
            data.intraday_backfill_settings ?? intradayBackfillSettings.value,
        );
    } catch (err) {
        priceRefreshScheduleError.value = err.message;
    }
}

async function runIntradayBackfillNow() {
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';
    isIntradayBackfillRunningNow.value = true;

    try {
        const data = await depotsStore.runIntradayBackfillNow();
        priceRefreshScheduleMessage.value = data.message;

        if (data.intraday_backfill_refresh?.refresh_id) {
            startIntradayBackfillPolling(data.intraday_backfill_refresh.refresh_id);
        }
    } catch (err) {
        priceRefreshScheduleError.value = err.message;
    } finally {
        isIntradayBackfillRunningNow.value = false;
    }
}

function editPriceRefreshSchedule() {
    priceRefreshScheduleMessage.value = '';
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(priceRefreshSettings.value);
    isPriceRefreshScheduleEditing.value = true;
}

function editIndexPriceRefreshSchedule() {
    priceRefreshScheduleMessage.value = '';
    priceRefreshScheduleError.value = '';
    indexPriceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(indexPriceRefreshSettings.value);
    isIndexPriceRefreshScheduleEditing.value = true;
}

function editIntradayBackfillSchedule() {
    priceRefreshScheduleMessage.value = '';
    priceRefreshScheduleError.value = '';
    intradayBackfillScheduleForm.value = intradayBackfillScheduleFormFromSettings(intradayBackfillSettings.value);
    isIntradayBackfillScheduleEditing.value = true;
}

async function loadPriceRefreshSettings() {
    priceRefreshScheduleError.value = '';

    try {
        await depotsStore.loadPriceRefreshSettings();
    } catch (err) {
        priceRefreshScheduleError.value = err.message;
    }
}

function startPriceRefreshSettingsPolling() {
    stopPriceRefreshSettingsPolling();
    priceRefreshSettingsTimer.value = window.setInterval(pollPriceRefreshSettings, 5000);
}

function stopPriceRefreshSettingsPolling() {
    if (!priceRefreshSettingsTimer.value) {
        return;
    }

    window.clearInterval(priceRefreshSettingsTimer.value);
    priceRefreshSettingsTimer.value = null;
}

async function pollPriceRefreshSettings() {
    if (isPriceRefreshSettingsPolling.value) {
        return;
    }

    isPriceRefreshSettingsPolling.value = true;

    try {
        const data = await depotsStore.loadPriceRefreshSettings();
        const refresh = data.refresh;
        const intradayRefresh = data.intraday_backfill_refresh;

        if (intradayRefresh && !isFinishedPriceRefresh(intradayRefresh) && intradayRefresh.refresh_id) {
            const isPollingCurrentIntradayRefresh = intradayBackfillTimer.value
                && intradayBackfillRefresh.value?.refresh_id === intradayRefresh.refresh_id;

            if (!isPollingCurrentIntradayRefresh) {
                startIntradayBackfillPolling(intradayRefresh.refresh_id);
            }
        }

        if (!refresh || isFinishedPriceRefresh(refresh)) {
            if (priceRefreshTimer.value) {
                stopPriceRefreshPolling();
            }

            return;
        }

        const isPollingCurrentRefresh = priceRefreshTimer.value
            && priceRefresh.value?.refresh_id === refresh.refresh_id;

        if (!isPollingCurrentRefresh) {
            startPriceRefreshPolling(refresh.refresh_id);
        }
    } catch (err) {
        if (activeSection.value === 'updates') {
            priceRefreshScheduleError.value = err.message;
        }
    } finally {
        isPriceRefreshSettingsPolling.value = false;
    }
}

function startPriceRefreshPolling(refreshId) {
    stopPriceRefreshPolling();
    pollPriceRefreshStatus(refreshId);
    priceRefreshTimer.value = window.setInterval(() => pollPriceRefreshStatus(refreshId), 2000);
}

function startIntradayBackfillPolling(refreshId) {
    stopIntradayBackfillPolling();
    pollIntradayBackfillStatus(refreshId);
    intradayBackfillTimer.value = window.setInterval(() => pollIntradayBackfillStatus(refreshId), 3000);
}

function stopIntradayBackfillPolling() {
    if (!intradayBackfillTimer.value) {
        return;
    }

    window.clearInterval(intradayBackfillTimer.value);
    intradayBackfillTimer.value = null;
}

async function pollIntradayBackfillStatus(refreshId) {
    try {
        const data = await depotsStore.loadIntradayBackfillRefresh(refreshId);
        const refresh = data.intraday_backfill_refresh;

        if (!refresh || isFinishedPriceRefresh(refresh)) {
            stopIntradayBackfillPolling();
        }
    } catch (err) {
        stopIntradayBackfillPolling();

        if (activeSection.value === 'updates') {
            priceRefreshScheduleError.value = err.message;
        }
    }
}

function stopPriceRefreshPolling() {
    if (!priceRefreshTimer.value) {
        return;
    }

    window.clearInterval(priceRefreshTimer.value);
    priceRefreshTimer.value = null;
}

function startHistoricalPriceFetchPolling() {
    if (historicalPriceFetchTimer.value) {
        return;
    }

    historicalPriceFetchTimer.value = window.setInterval(pollHistoricalPriceFetches, 5000);
}

function stopHistoricalPriceFetchPolling() {
    if (!historicalPriceFetchTimer.value) {
        return;
    }

    window.clearInterval(historicalPriceFetchTimer.value);
    historicalPriceFetchTimer.value = null;
}

async function pollHistoricalPriceFetches() {
    if (isHistoricalPriceFetchPolling.value) {
        return;
    }

    isHistoricalPriceFetchPolling.value = true;

    try {
        await depotsStore.loadWatchlistHoldings(holdingsPagination.value.current_page, { silent: true });
    } catch {
    } finally {
        isHistoricalPriceFetchPolling.value = false;
    }
}

async function ensureAnalyzeDetailIntradayCandles() {
    if (activeSection.value !== 'analyze' || activeAnalyzeSubsection.value !== 'detail') {
        return;
    }

    if (selectedAnalyzeHoldingId.value === null) {
        analyzeIntradayRequestedHoldingId.value = null;
        depotsStore.clearHoldingIntradayCandles();

        return;
    }

    if (analyzeIntradayCandles.value?.holding?.id === selectedAnalyzeHoldingId.value) {
        return;
    }

    if (analyzeIntradayRequestedHoldingId.value === selectedAnalyzeHoldingId.value && analyzeIntradayCandlesLoading.value) {
        return;
    }

    analyzeIntradayRequestedHoldingId.value = selectedAnalyzeHoldingId.value;
    depotsStore.clearHoldingIntradayCandles();

    try {
        await depotsStore.loadHoldingIntradayCandles(selectedAnalyzeHoldingId.value);
    } catch {
    }
}

async function reloadDataExchanges() {
    try {
        const data = await depotsStore.reloadDataExchanges();
        await depotsStore.loadQueueStatus();

        if (data.refresh && !isFinishedPriceRefresh(data.refresh)) {
            startDataExchangeReloadPolling(data.refresh.refresh_id);

            return;
        }

        stopDataExchangeReloadPolling();
    } catch {
    }
}

async function selectDataIntradayStock(stockId) {
    selectedDataIntradayStockId.value = stockId;

    try {
        await depotsStore.loadDataIntraday(stockId);
    } catch {
    }
}

async function reloadDataIntraday() {
    if (!selectedDataIntradayStockId.value) {
        return;
    }

    try {
        const data = await depotsStore.reloadDataIntraday(selectedDataIntradayStockId.value);
        selectedDataIntradayStockId.value = data.selected_stock_id ?? selectedDataIntradayStockId.value;

        if (data.refresh && !isFinishedPriceRefresh(data.refresh)) {
            startDataIntradayReloadPolling(data.refresh.refresh_id);

            return;
        }

        stopDataIntradayReloadPolling();
    } catch {
    }
}

function startDataExchangeReloadPolling(refreshId) {
    stopDataExchangeReloadPolling();
    pollDataExchangeReload(refreshId);
    dataExchangeReloadTimer.value = window.setInterval(() => pollDataExchangeReload(refreshId), 3000);
}

function stopDataExchangeReloadPolling() {
    if (!dataExchangeReloadTimer.value) {
        return;
    }

    window.clearInterval(dataExchangeReloadTimer.value);
    dataExchangeReloadTimer.value = null;
}

async function pollDataExchangeReload(refreshId) {
    try {
        const data = await depotsStore.loadDataExchangeRefresh(refreshId);
        await depotsStore.loadQueueStatus();

        if (isFinishedPriceRefresh(data.refresh)) {
            stopDataExchangeReloadPolling();
        }
    } catch {
        stopDataExchangeReloadPolling();
    }
}

function startDataIntradayReloadPolling(refreshId) {
    stopDataIntradayReloadPolling();
    pollDataIntradayReload(refreshId);
    dataIntradayReloadTimer.value = window.setInterval(() => pollDataIntradayReload(refreshId), 3000);
}

function stopDataIntradayReloadPolling() {
    if (!dataIntradayReloadTimer.value) {
        return;
    }

    window.clearInterval(dataIntradayReloadTimer.value);
    dataIntradayReloadTimer.value = null;
}

async function pollDataIntradayReload(refreshId) {
    try {
        const data = await depotsStore.loadDataIntradayRefresh(refreshId, selectedDataIntradayStockId.value);
        await depotsStore.loadQueueStatus();

        if (data.selected_stock_id) {
            selectedDataIntradayStockId.value = data.selected_stock_id;
        }

        if (isFinishedPriceRefresh(data.refresh)) {
            stopDataIntradayReloadPolling();
        }
    } catch {
        stopDataIntradayReloadPolling();
    }
}

async function pollPriceRefreshStatus(refreshId) {
    try {
        const data = await depotsStore.loadWatchlistPriceRefresh(refreshId);
        holdingMessage.value = data.message;

        if (isFinishedPriceRefresh(data.refresh)) {
            await finishPriceRefresh(data.refresh);
        }
    } catch (err) {
        stopPriceRefreshPolling();
        holdingError.value = err.message;
    }
}

async function finishPriceRefresh(refresh) {
    stopPriceRefreshPolling();
    depotsStore.clearPriceRefresh();
    await Promise.all([
        depotsStore.loadWatchlistHoldings(holdingsPagination.value.current_page),
        depotsStore.loadQueueStatus(),
    ]);

    if (refresh.status === 'failed') {
        holdingError.value = refresh.error || refresh.message;

        return;
    }

    holdingMessage.value = '';
}

function isFinishedPriceRefresh(refresh) {
    if (['finished', 'failed'].includes(refresh?.status)) {
        return true;
    }

    return Number(refresh?.total ?? 0) > 0
        && Number(refresh?.processed ?? 0) >= Number(refresh?.total ?? 0);
}

function openDeleteHoldingDialog(holding) {
    selectedHolding.value = holding;
    holdingError.value = '';
    holdingMessage.value = '';
    isDeleteHoldingDialogOpen.value = true;
}

function abortDeleteHoldingDialog() {
    isDeleteHoldingDialogOpen.value = false;
    selectedHolding.value = null;
}

async function deleteHolding() {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.deleteWatchlistHolding(selectedHolding.value.id);
        holdingMessage.value = data.message;
        abortDeleteHoldingDialog();
        await Promise.all([
            depotsStore.loadWatchlistHoldings(holdingsPagination.value.current_page),
            depotsStore.loadWatchlistExchangeTradingTimes(),
        ]);
    } catch (err) {
        holdingError.value = err.message;
    }
}

function formatTransactionDate(isoString) {
    if (!isoString) return '–';

    return new Intl.DateTimeFormat('en-GB', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(isoString));
}

function transactionTypeColor(type) {
    return { deposit: 'success', withdrawal: 'error', buy: 'warning', sell: 'teal' }[type] ?? 'default';
}

function transactionTypeLabel(type) {
    return { deposit: 'Add cash', withdrawal: 'Withdraw', buy: 'Buy', sell: 'Sell' }[type] ?? type;
}

function formatCashDelta(value) {
    const num = Number(value);
    const prefix = num > 0 ? '+' : '';

    return `${prefix}${formatAccountBalance(num)}`;
}

function formatAccountBalance(value) {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value ?? 0));
}

function formatInteger(value) {
    return new Intl.NumberFormat('en-US').format(Number(value ?? 0));
}

function formatDataExchangeTitle(exchange) {
    return [
        exchange.name,
        exchange.code,
    ].filter(Boolean).join(' · ');
}

function formatDataExchangeTradingHours(exchange) {
    const tradingHours = exchange.trading_hours;

    if (!tradingHours || Array.isArray(tradingHours) || typeof tradingHours !== 'object') {
        return [];
    }

    const preferredKeys = ['Open', 'Close', 'PreMarketOpen', 'PreMarketClose', 'LunchBreakStart', 'LunchBreakEnd', 'PostMarketOpen', 'PostMarketClose'];
    const preferredRows = preferredKeys
        .filter((key) => Object.hasOwn(tradingHours, key))
        .map((key) => ({
            key,
            label: formatExchangeDetailKey(key),
            value: tradingHours[key],
        }));
    const remainingRows = Object.entries(tradingHours)
        .filter(([key]) => !preferredKeys.includes(key) && key !== 'WorkingDays')
        .map(([key, value]) => ({
            key,
            label: formatExchangeDetailKey(key),
            value,
        }));
    const workingDaysRow = Object.hasOwn(tradingHours, 'WorkingDays')
        ? [{ key: 'WorkingDays', label: formatExchangeDetailKey('WorkingDays'), value: tradingHours.WorkingDays }]
        : [];

    return [...preferredRows, ...remainingRows, ...workingDaysRow]
        .filter((row) => row.value !== null && row.value !== undefined && row.value !== '');
}

function formatDataExchangeHolidays(exchange) {
    const holidays = exchange.holidays;

    if (!holidays) {
        return [];
    }

    function holidayLabel(obj) {
        if (!obj || typeof obj !== 'object') return String(obj ?? '');
        const name = obj.Name ?? obj.name ?? obj.Holiday ?? obj.holiday ?? null;
        const earlyClose = obj.EarlyClose ?? obj.early_close ?? obj.CloseTime ?? obj.close_time ?? null;
        if (earlyClose) return `Early Close: ${earlyClose}`;
        return name ?? '';
    }

    if (Array.isArray(holidays)) {
        return holidays.map((holiday, index) => {
            if (holiday && typeof holiday === 'object') {
                const date = holiday.Date ?? holiday.date ?? holiday.TradingDate ?? holiday.trading_date ?? null;
                const label = holidayLabel(holiday);
                return {
                    key: `${date ?? index}-${label}`,
                    date: date ?? `Holiday ${index + 1}`,
                    label,
                };
            }
            return {
                key: `${index}-${holiday}`,
                date: `Holiday ${index + 1}`,
                label: String(holiday),
            };
        });
    }

    if (typeof holidays === 'object') {
        return Object.entries(holidays).map(([date, value]) => ({
            key: date,
            date,
            label: holidayLabel(value),
        }));
    }

    return [{ key: 'holidays', date: 'Holidays', label: String(holidays) }];
}

function formatDataExchangeHolidayValue(value) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    if (Array.isArray(value)) {
        return value.map(formatDataExchangeHolidayValue).join(', ');
    }

    if (typeof value === 'object') {
        return Object.entries(value)
            .map(([key, itemValue]) => `${formatExchangeDetailKey(key)}: ${formatDataExchangeHolidayValue(itemValue)}`)
            .join(' · ');
    }

    return String(value);
}

function formatDataIntradayDayTitle(day) {
    return day.title ?? `Intraday ${formatIndexHistoryDate(day.trading_date)}`;
}

function isDataIntradayDayExpanded(day) {
    return expandedDataIntradayDays.value[day.trading_date] === true;
}

function toggleDataIntradayDay(day) {
    expandedDataIntradayDays.value = {
        ...expandedDataIntradayDays.value,
        [day.trading_date]: !isDataIntradayDayExpanded(day),
    };
}

function isAnalyzeIntradayDayExpanded(day) {
    return expandedAnalyzeIntradayDays.value[analyzeIntradayDayExpansionKey(day)] === true;
}

function toggleAnalyzeIntradayDay(day) {
    const expansionKey = analyzeIntradayDayExpansionKey(day);

    expandedAnalyzeIntradayDays.value = {
        ...expandedAnalyzeIntradayDays.value,
        [expansionKey]: !isAnalyzeIntradayDayExpanded(day),
    };
}

function analyzeIntradayDayExpansionKey(day) {
    return `${analyzeIntradayCandles.value?.holding?.id ?? selectedAnalyzeHoldingId.value ?? 'all'}:${day.trading_date}`;
}

function formatDataIntradayReloadMessage() {
    if (!dataIntradayRefresh.value) {
        return '';
    }

    if (dataIntradayRefresh.value.error) {
        return dataIntradayRefresh.value.error;
    }

    return dataIntradayRefresh.value.message ?? `${formatInteger(dataIntradayRefresh.value.stored_count)} intraday candles loaded/updated.`;
}

function formatExchangeDetailKey(key) {
    return String(key)
        .replace(/([a-z])([A-Z])/g, '$1 $2')
        .replace(/_/g, ' ');
}

function formatEodhdUsageReset(value) {
    return formatScheduleDateTime(value);
}

function formatDepotCashBalance() {
    return `${formatAccountBalance(activeDepot.value?.account_balance)} EUR`;
}

function isDepotPriceSource(source) {
    return depotPriceSource.value === source;
}

async function selectDepotPriceSource(source) {
    if (!['latest', 'flatex'].includes(source)) {
        return;
    }

    const previousSource = depotPriceSource.value;
    depotPriceSource.value = source;

    try {
        await depotsStore.updateUiPreferences({
            depot_price_source: source,
        });
    } catch (error) {
        depotPriceSource.value = previousSource;
        transactionsError.value = error.message;
    }
}

function depotPriceSourceButtonClass(source) {
    return {
        'depot-price-source-button--active': isDepotPriceSource(source),
    };
}

function selectedDepotHoldingPrice(holding) {
    return depotPriceSource.value === 'flatex'
        ? holding.flatex_price
        : holding.latest_price;
}

function depotStockBalance() {
    return depotHoldings.value.reduce((sum, holding) => {
        const selectedPrice = Number(selectedDepotHoldingPrice(holding));
        const pieces = Number(holding.position_pieces ?? 0);

        if (Number.isNaN(selectedPrice) || Number.isNaN(pieces)) {
            return sum;
        }

        return sum + (selectedPrice * pieces);
    }, 0);
}

function depotYearStartStockBalance() {
    return depotHoldings.value.reduce((sum, holding) => {
        const yearStartPrice = Number(holding.year_start_price);
        const pieces = Number(holding.position_pieces ?? 0);

        if (Number.isNaN(yearStartPrice) || Number.isNaN(pieces)) {
            return sum;
        }

        return sum + (yearStartPrice * pieces);
    }, 0);
}

function formatDepotStockBalance() {
    return `${formatAccountBalance(depotStockBalance())} EUR`;
}

function depotCashBalance() {
    return Number(activeDepot.value?.account_balance ?? 0);
}

function formatDepotAccountBalance() {
    return `${formatAccountBalance(depotCashBalance() + depotStockBalance())} EUR`;
}

function depotYearStartBalance() {
    return depotCashBalance() + depotYearStartStockBalance();
}

function depotCurrentBalance() {
    return depotCashBalance() + depotStockBalance();
}

function formatDepotYearStartBalance() {
    return `${formatAccountBalance(depotYearStartBalance())} EUR`;
}

function formatDepotCurrentBalance() {
    return `${formatAccountBalance(depotCurrentBalance())} EUR`;
}

function depotBalanceChangeAmount() {
    return depotCurrentBalance() - depotYearStartBalance();
}

function formatDepotBalanceChangeAmount() {
    const amount = depotBalanceChangeAmount();
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotBalanceChangePercent() {
    const yearStartBalance = depotYearStartBalance();

    if (yearStartBalance === 0) {
        return '-';
    }

    const amount = (depotBalanceChangeAmount() / yearStartBalance) * 100;
    const sign = amount > 0 ? '+' : '';

    return `${sign}${amount.toFixed(2)}%`;
}

function depotBalanceChangeClass() {
    const amount = depotBalanceChangeAmount();

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function formatCurrentDayMonth() {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: displayTimeZone,
        day: '2-digit',
        month: '2-digit',
    }).format(new Date());
}

function formatPositionPieces(holding) {
    const pieces = Number(holding.position_pieces ?? 0);

    if (Number.isNaN(pieces) || pieces === 0) {
        return '0';
    }

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 8,
    }).format(pieces);
}

function hasPositionPieces(holding) {
    const pieces = Number(holding.position_pieces ?? 0);

    return !Number.isNaN(pieces) && pieces > 0;
}

function isEditingFlatexPrice(holding) {
    return editingFlatexHoldingId.value === holding.id;
}

function activeFlatexPriceEditInput() {
    if (Array.isArray(flatexPriceEditInput.value)) {
        return flatexPriceEditInput.value[0] ?? null;
    }

    return flatexPriceEditInput.value;
}

async function startFlatexPriceEdit(holding) {
    editingFlatexHoldingId.value = holding.id;
    flatexPriceEditValue.value = holding.flatex_price ?? '';
    transactionsError.value = '';

    await nextTick();

    const input = activeFlatexPriceEditInput();

    input?.focus();
    input?.select();
}

function abortFlatexPriceEdit() {
    editingFlatexHoldingId.value = null;
    flatexPriceEditValue.value = '';
    flatexPriceEditInput.value = null;
}

async function saveFlatexPriceEdit(holding) {
    if (!isEditingFlatexPrice(holding)) {
        return;
    }

    const rawValue = String(flatexPriceEditValue.value).trim();
    const flatexPrice = rawValue === '' ? null : Number(rawValue);

    if (flatexPrice !== null && (Number.isNaN(flatexPrice) || flatexPrice < 0)) {
        transactionsError.value = 'Please enter a valid Flatex price.';

        return;
    }

    try {
        await depotsStore.updateHoldingFlatexPrice(holding.id, flatexPrice);
        abortFlatexPriceEdit();
    } catch (error) {
        transactionsError.value = error.message;
    }
}

function formatLatestPrice(holding) {
    if (holding.latest_price === null || holding.latest_price === undefined || holding.latest_price === '') {
        return '-';
    }

    return formatPriceValue(holding.latest_price, holding.currency);
}

function formatHoldingCardPrice(holding) {
    const price = holding.latest_price === null || holding.latest_price === undefined || holding.latest_price === ''
        ? holding.end_price
        : holding.latest_price;

    if (price === null || price === undefined || price === '') {
        return '-';
    }

    return formatPriceValue(price, holding.currency, { showCurrency: true });
}

function mobileHoldingPriceSource(holding) {
    if (holding.latest_price !== null && holding.latest_price !== undefined && holding.latest_price !== '') {
        return 'latest';
    }

    if (holding.end_price !== null && holding.end_price !== undefined && holding.end_price !== '') {
        return 'end';
    }

    return null;
}

function formatMobileHoldingPrice(holding) {
    return mobileHoldingPriceSource(holding) === 'latest'
        ? formatLatestPrice(holding)
        : formatSessionPrice(holding.end_price, holding);
}

function mobileHoldingPriceClass(holding) {
    return mobileHoldingPriceSource(holding) === 'latest'
        ? latestPriceClass(holding)
        : endPriceValueClass(holding);
}

function mobileHoldingPriceChangePercent(holding) {
    if (mobileHoldingPriceSource(holding) === 'latest' && formatLatestPriceChangePercent(holding)) {
        return formatLatestPriceChangePercent(holding);
    }

    return formatEndPriceChangePercent(holding);
}

function mobileHoldingPriceReference(holding) {
    if (holding.end_price_24 === null || holding.end_price_24 === undefined || holding.end_price_24 === '') {
        return '';
    }

    return formatSessionPrice(holding.end_price_24, holding);
}

function mobileHoldingPriceChangeText(holding) {
    const changePercent = mobileHoldingPriceChangePercent(holding);
    const referencePrice = mobileHoldingPriceReference(holding);

    if (!changePercent || !referencePrice || referencePrice === '-') {
        return '';
    }

    return `${changePercent} · ${referencePrice}`;
}

function stockHoldingValue(holding) {
    const selectedPrice = Number(selectedDepotHoldingPrice(holding));
    const pieces = Number(holding.position_pieces ?? 0);

    if (Number.isNaN(selectedPrice) || Number.isNaN(pieces)) {
        return null;
    }

    return selectedPrice * pieces;
}

function formatStockHoldingValue(holding) {
    const value = stockHoldingValue(holding);

    if (value === null) {
        return '-';
    }

    return formatPriceValue(value, holding.currency);
}

function formatLatestPriceChangePercent(holding) {
    if (
        holding.latest_price_change_pct === null
        || holding.latest_price_change_pct === undefined
        || holding.latest_price_change_pct === ''
    ) {
        return '';
    }

    const amount = Number(holding.latest_price_change_pct);

    if (Number.isNaN(amount)) {
        return '';
    }

    const sign = amount > 0 ? '+' : '';

    return `${sign}${amount.toFixed(2)}%`;
}

function formatIndexPrice(indexItem) {
    const price = indexItem.latest_price ?? indexItem.last_price;

    if (price === null || price === undefined || price === '') {
        return '-';
    }

    return formatPriceValue(price, indexItem.currency);
}

function formatIndexDialogActualPrice(indexItem) {
    if (!indexItem) {
        return '-';
    }

    return formatIndexPrice(indexItem);
}

function hasEnoughIndexRecentPrices(indexItem) {
    return Array.isArray(indexItem?.recent_prices) && indexItem.recent_prices.length >= indexRecentPriceLimit;
}

function formatIndexHistoryPrice(value, indexItem) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return formatPriceValue(value, indexItem?.currency);
}

function formatIndexHistoryDate(value) {
    if (!value || typeof value !== 'string') {
        return '-';
    }

    const parts = value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}.${parts[1]}.${parts[0]}`;
}

function indexHistoryActualPrice(price) {
    return price.actual_price ?? price.last_price ?? price.start_price;
}

function indexHistoryChartPrice(price) {
    const value = indexHistoryActualPrice(price);

    if (value === null || value === undefined || value === '' || (typeof value === 'string' && value.trim() === '')) {
        return Number.NaN;
    }

    return Number(value);
}

function formatIndexChartDate(value) {
    if (!value || typeof value !== 'string') {
        return '';
    }

    const parts = value.split('-');

    if (parts.length !== 3) {
        return value;
    }

    return `${parts[2]}.${parts[1]}`;
}

function formatIndexChartValue(value) {
    return formatAdaptiveNumber(value);
}

function formatIndexChartEndpointPrice(point) {
    if (!point || Number.isNaN(point.chart_price)) {
        return '-';
    }

    return formatIndexHistoryPrice(point.chart_price, selectedIndexWatchItem.value);
}

function indexChartTickPoints(points) {
    const maximumTickCount = 7;

    if (points.length <= maximumTickCount) {
        return points;
    }

    const lastPointIndex = points.length - 1;
    const pointIndexes = Array.from({ length: maximumTickCount }, (_, index) => (
        Math.round((index / (maximumTickCount - 1)) * lastPointIndex)
    ));

    return [...new Set(pointIndexes)].map((index) => points[index]);
}

function buildIndexPriceChart(prices) {
    const chartWidth = 720;
    const chartHeight = 260;
    const chartPadding = {
        top: 18,
        right: 92,
        bottom: 44,
        left: 88,
    };
    const plot = {
        left: chartPadding.left,
        top: chartPadding.top,
        right: chartWidth - chartPadding.right,
        bottom: chartHeight - chartPadding.bottom,
    };
    const plotWidth = plot.right - plot.left;
    const plotHeight = plot.bottom - plot.top;
    const chartPrices = [...prices]
        .reverse()
        .map((price) => ({
            ...price,
            chart_price: indexHistoryChartPrice(price),
        }))
        .filter((price) => !Number.isNaN(price.chart_price));

    if (chartPrices.length === 0) {
        return {
            width: chartWidth,
            height: chartHeight,
            plot,
            points: [],
            linePoints: '',
            horizontalGridLines: [],
            verticalGridLines: [],
            first: null,
            latest: null,
            firstLabel: null,
            latestLabel: null,
            trendLine: null,
        };
    }

    const values = chartPrices.map((price) => price.chart_price);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min;
    const rangePadding = range === 0 ? Math.max(Math.abs(max) * 0.05, 1) : range * 0.05;
    const chartMin = min - rangePadding;
    const chartMax = max + rangePadding;
    const chartRange = chartMax - chartMin;
    const horizontalGridLines = Array.from({ length: 5 }, (_, index) => {
        const ratio = index / 4;
        const value = chartMax - chartRange * ratio;
        const y = plot.top + plotHeight * ratio;

        return {
            value,
            y,
            label: formatIndexChartValue(value),
        };
    });

    const points = chartPrices.map((price, index) => {
        const x = chartPrices.length === 1
            ? chartWidth / 2
            : plot.left + (index / (chartPrices.length - 1)) * plotWidth;
        const normalized = (price.chart_price - chartMin) / chartRange;
        const y = plot.bottom - normalized * plotHeight;

        return {
            ...price,
            x,
            y,
            label: formatIndexChartDate(price.trading_date),
        };
    });

    return {
        width: chartWidth,
        height: chartHeight,
        plot,
        points,
        linePoints: points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' '),
        horizontalGridLines,
        verticalGridLines: indexChartTickPoints(points).map((point) => ({
            x: point.x,
            label: point.label,
        })),
        first: points[0],
        latest: points[points.length - 1],
        firstLabel: chartEndpointLabel(points[0], plot, 'start'),
        latestLabel: chartEndpointLabel(points[points.length - 1], plot, 'end', points[0]),
        trendLine: chartRegressionLine(points, chartMin, chartRange, plot),
    };
}

function analyzeChartWindow(prices, rangeKey, windowOffset = 0) {
    const chartPrices = [...prices]
        .map((price) => ({
            ...price,
            trading_date: price.as_of,
            chart_price: analyzeDailyPriceValue(price),
        }))
        .filter((price) => price.as_of && !Number.isNaN(price.chart_price))
        .sort((first, second) => first.as_of.localeCompare(second.as_of));

    if (chartPrices.length === 0) {
        return {
            prices: [],
            canMoveBackward: false,
            canMoveForward: false,
        };
    }

    const normalizedWindowOffset = Math.max(0, Math.trunc(Number(windowOffset)) || 0);

    if (isAnalyzeTodayRange(rangeKey)) {
        return analyzeTodayChartWindow(chartPrices, normalizedWindowOffset);
    }

    return analyzePeriodChartWindow(chartPrices, rangeKey, normalizedWindowOffset);
}

function analyzeTodayChartWindow(chartPrices, windowOffset) {
    const tradingDates = [...new Set(chartPrices.map((price) => price.as_of.slice(0, 10)))].sort();
    const maximumWindowOffset = Math.max(tradingDates.length - 1, 0);
    const currentWindowOffset = Math.min(windowOffset, maximumWindowOffset);
    const selectedTradingDate = tradingDates[tradingDates.length - 1 - currentWindowOffset];

    return {
        prices: chartPrices.filter((price) => price.as_of.slice(0, 10) === selectedTradingDate),
        canMoveBackward: currentWindowOffset < maximumWindowOffset,
        canMoveForward: currentWindowOffset > 0,
    };
}

function analyzePeriodChartWindow(chartPrices, rangeKey, windowOffset) {
    const days = analyzeHistoryRangeDays[rangeKey] ?? analyzeHistoryRangeDays['1y'];
    const latestDate = chartPrices[chartPrices.length - 1].as_of.slice(0, 10);
    let currentWindowOffset = windowOffset;
    let bounds = analyzePeriodChartWindowBounds(latestDate, days, currentWindowOffset);
    let prices = chartPrices.filter((price) => isAnalyzePriceInWindow(price, bounds));

    while (prices.length === 0 && currentWindowOffset > 0) {
        currentWindowOffset -= 1;
        bounds = analyzePeriodChartWindowBounds(latestDate, days, currentWindowOffset);
        prices = chartPrices.filter((price) => isAnalyzePriceInWindow(price, bounds));
    }

    return {
        prices,
        canMoveBackward: chartPrices.some((price) => price.as_of.slice(0, 10) < bounds.startDate),
        canMoveForward: currentWindowOffset > 0,
    };
}

function analyzePeriodChartWindowBounds(latestDate, days, windowOffset) {
    const stepDays = Math.max(days + 1, 1);
    const endDate = dateStringDaysBefore(latestDate, stepDays * windowOffset);

    return {
        startDate: dateStringDaysBefore(endDate, days),
        endDate,
    };
}

function isAnalyzePriceInWindow(price, bounds) {
    const priceDate = price.as_of.slice(0, 10);

    return priceDate >= bounds.startDate && priceDate <= bounds.endDate;
}

function limitAnalyzeChartPrices(prices) {
    if (prices.length <= analyzeChartMaxPoints) {
        return prices;
    }

    const lastIndex = prices.length - 1;

    return Array.from({ length: analyzeChartMaxPoints }, (_, index) => {
        const sourceIndex = Math.round((index / (analyzeChartMaxPoints - 1)) * lastIndex);

        return prices[sourceIndex];
    }).filter((price) => price);
}

function normalizeAnalyzeChartPrices(prices, rangeKey) {
    if (!analyzeCalendarNormalizedRangeKeys.includes(rangeKey)) {
        return limitAnalyzeChartPrices(prices);
    }

    return normalizeAnalyzeCalendarChartPrices(prices);
}

function previousAnalyzeTradingClose(prices, currentPrices, rangeKey) {
    if (rangeKey !== 'today-1' || currentPrices.length === 0) {
        return null;
    }

    const currentDate = currentPrices[0].as_of?.slice(0, 10);

    if (!currentDate) {
        return null;
    }

    return [...prices]
        .map((price) => ({
            ...price,
            trading_date: price.as_of,
            chart_price: analyzeDailyPriceValue(price),
        }))
        .filter((price) => price.as_of && price.as_of.slice(0, 10) < currentDate && !Number.isNaN(price.chart_price))
        .sort((first, second) => first.as_of.localeCompare(second.as_of))
        .at(-1) ?? null;
}

function withAnalyzePreviousTradingClosePoint(prices, previousTradingClose, rangeKey) {
    if (rangeKey !== 'today-1' || !previousTradingClose || prices.length === 0) {
        return prices;
    }

    const firstPriceTime = analyzeSparklinePointTime(prices[0]);

    if (firstPriceTime === null) {
        return prices;
    }

    const secondPriceTime = prices[1] ? analyzeSparklinePointTime(prices[1]) : null;
    const chartInterval = secondPriceTime !== null && secondPriceTime > firstPriceTime
        ? secondPriceTime - firstPriceTime
        : 5 * 60 * 1000;
    const previousChartAsOf = new Date(firstPriceTime - chartInterval).toISOString();

    return [
        {
            ...previousTradingClose,
            id: `previous-close-${previousTradingClose.id ?? previousTradingClose.as_of}`,
            chart_as_of: previousChartAsOf,
            chart_label: 'Prev',
            endpoint_label: 'Prev',
            is_previous_trading_close: true,
        },
        {
            ...prices[0],
            endpoint_label: 'Start',
        },
        ...prices.slice(1),
    ];
}

function isAnalyzeTodayRange(rangeKey) {
    return ['today', 'today-1'].includes(rangeKey);
}

function normalizeAnalyzeCalendarChartPrices(prices) {
    if (prices.length === 0) {
        return [];
    }

    const sortedPrices = [...prices].sort((first, second) => first.as_of.localeCompare(second.as_of));
    const pricesByDate = sortedPrices.reduce((groupedPrices, price) => {
        const dateString = price.as_of.slice(0, 10);

        groupedPrices.set(dateString, [
            ...(groupedPrices.get(dateString) ?? []),
            price,
        ]);

        return groupedPrices;
    }, new Map());
    const dateStrings = [...pricesByDate.keys()].sort();
    const pointCounts = distributeAnalyzeChartPointCounts(dateStrings.length, analyzeChartMaxPoints);

    return dateStrings.flatMap((dateString, index) => normalizeAnalyzeChartDatePrices(
        pricesByDate.get(dateString),
        pointCounts[index] ?? 0,
        dateString,
    )).filter((price) => price);
}

function normalizeAnalyzeChartDatePrices(dayPrices, pointCount, dateString) {
    if (pointCount <= 0) {
        return [];
    }

    if (dayPrices.length >= pointCount) {
        return sampleAnalyzeChartDatePrices(dayPrices, pointCount, dateString);
    }

    return interpolateAnalyzeChartDatePrices(dayPrices, pointCount, dateString);
}

function sampleAnalyzeChartDatePrices(dayPrices, pointCount, dateString) {
    if (pointCount === 1) {
        return [
            withAnalyzeChartTime(
                dayPrices[Math.floor((dayPrices.length - 1) / 2)],
                analyzeDateSampleTime(dateString, 0, pointCount),
            ),
        ];
    }

    const lastIndex = dayPrices.length - 1;

    return Array.from({ length: pointCount }, (_, index) => {
        const sourceIndex = Math.round((index / (pointCount - 1)) * lastIndex);

        return withAnalyzeChartTime(dayPrices[sourceIndex], analyzeDateSampleTime(dateString, index, pointCount));
    });
}

function interpolateAnalyzeChartDatePrices(dayPrices, pointCount, dateString) {
    if (dayPrices.length === 1) {
        return Array.from({ length: pointCount }, (_, index) => withAnalyzeChartTime(
            dayPrices[0],
            analyzeDateSampleTime(dateString, index, pointCount),
        ));
    }

    return Array.from({ length: pointCount }, (_, index) => {
        const ratio = pointCount === 1 ? 0.5 : index / (pointCount - 1);
        const scaledIndex = ratio * (dayPrices.length - 1);
        const previousIndex = Math.floor(scaledIndex);
        const nextIndex = Math.ceil(scaledIndex);
        const segmentRatio = scaledIndex - previousIndex;

        return interpolateAnalyzeChartPrice(
            dayPrices[previousIndex],
            dayPrices[nextIndex],
            segmentRatio,
            analyzeDateSampleTime(dateString, index, pointCount),
            dateString,
        );
    });
}

function interpolateAnalyzeChartPrice(previousPrice, nextPrice, ratio, chartAsOf, dateString) {
    if (!previousPrice || !nextPrice) {
        return null;
    }

    if (previousPrice === nextPrice || ratio === 0) {
        return withAnalyzeChartTime(previousPrice, chartAsOf);
    }

    if (ratio === 1) {
        return withAnalyzeChartTime(nextPrice, chartAsOf);
    }

    const chartPrice = previousPrice.chart_price + ((nextPrice.chart_price - previousPrice.chart_price) * ratio);

    return {
        ...previousPrice,
        id: undefined,
        as_of: chartAsOf,
        trading_date: dateString,
        chart_as_of: chartAsOf,
        chart_price: chartPrice,
        price: chartPrice.toFixed(8),
        is_interpolated: true,
    };
}

function withAnalyzeChartTime(price, chartAsOf) {
    if (!price) {
        return null;
    }

    return {
        ...price,
        chart_as_of: chartAsOf,
    };
}

function distributeAnalyzeChartPointCounts(bucketCount, totalCount) {
    if (bucketCount <= 0) {
        return [];
    }

    if (bucketCount > totalCount && totalCount >= 2) {
        const middleCounts = distributeAnalyzeChartPointCountsEvenly(bucketCount - 2, totalCount - 2);

        return [
            1,
            ...middleCounts,
            1,
        ];
    }

    return distributeAnalyzeChartPointCountsEvenly(bucketCount, totalCount);
}

function distributeAnalyzeChartPointCountsEvenly(bucketCount, totalCount) {
    if (bucketCount <= 0) {
        return [];
    }

    const baseCount = Math.floor(totalCount / bucketCount);
    const remainder = totalCount % bucketCount;

    return Array.from({ length: bucketCount }, (_, index) => {
        const previousRemainderShare = Math.floor((index * remainder) / bucketCount);
        const currentRemainderShare = Math.floor(((index + 1) * remainder) / bucketCount);

        return baseCount + (currentRemainderShare - previousRemainderShare);
    });
}

function dateStringToUtcTime(dateString) {
    const [year, month, day] = dateString.split('-').map((part) => Number(part));

    if ([year, month, day].some((part) => Number.isNaN(part))) {
        return null;
    }

    return Date.UTC(year, month - 1, day);
}

function analyzeDateSampleTime(dateString, index, pointCount) {
    const startTime = dateStringToUtcTime(dateString);

    if (startTime === null) {
        return dateString;
    }

    const ratio = pointCount <= 1 ? 0.5 : index / (pointCount - 1);
    const dayDuration = (24 * 60 * 60 * 1000) - 1;

    return new Date(startTime + (ratio * dayDuration)).toISOString();
}

function analyzeDailyPriceValue(price) {
    const value = price.price;

    if (value === null || value === undefined || value === '' || (typeof value === 'string' && value.trim() === '')) {
        return Number.NaN;
    }

    return Number(value);
}

function dateStringDaysBefore(dateString, days) {
    const [year, month, day] = dateString.split('-').map((part) => Number(part));

    if ([year, month, day].some((part) => Number.isNaN(part))) {
        return dateString;
    }

    const date = new Date(Date.UTC(year, month - 1, day));
    date.setUTCDate(date.getUTCDate() - days);

    return date.toISOString().slice(0, 10);
}

function buildAnalyzeSparkline(prices, rangeKey = selectedAnalyzeHistoryRange.value) {
    const width = 1440;
    const height = 600;
    const plot = {
        left: 104,
        top: 38,
        right: width - 160,
        bottom: height - 72,
    };
    const chartPrices = prices.filter((price) => !Number.isNaN(price.chart_price));

    if (chartPrices.length === 0) {
        return {
            width,
            height,
            plot,
            points: [],
            linePath: '',
            areaPath: '',
            horizontalGridLines: [],
            verticalGridLines: [],
            monthStartMarkers: [],
            dateRangeLabels: [],
            first: null,
            latest: null,
            firstLabel: null,
            latestLabel: null,
            todayStartLabel: null,
            trendLine: null,
            highMarker: null,
            lowMarker: null,
            min: null,
            max: null,
            trend: 'flat',
        };
    }

    const chartPriceValues = chartPrices.map((price) => price.chart_price);
    const min = Math.min(...chartPriceValues);
    const max = Math.max(...chartPriceValues);
    const scaleMin = min;
    const scaleMax = max;
    const range = scaleMax - scaleMin;
    const rangePadding = range === 0 ? Math.max(Math.abs(scaleMax) * 0.05, 1) : range * 0.05;
    const chartMin = scaleMin - rangePadding;
    const chartMax = scaleMax + rangePadding;
    const chartRange = chartMax - chartMin;
    const plotWidth = plot.right - plot.left;
    const plotHeight = plot.bottom - plot.top;
    const firstChartTime = isAnalyzeTodayRange(rangeKey) ? analyzeSparklinePointTime(chartPrices[0]) : null;
    const latestChartTime = isAnalyzeTodayRange(rangeKey) ? analyzeSparklinePointTime(chartPrices[chartPrices.length - 1]) : null;
    const useTimeScale = firstChartTime !== null && latestChartTime !== null && firstChartTime < latestChartTime;
    const points = chartPrices.map((price, index) => {
        const priceTime = useTimeScale ? analyzeSparklinePointTime(price) : null;
        const x = chartPrices.length === 1
            ? plot.left + plotWidth / 2
            : (useTimeScale && priceTime !== null
                ? plot.left + ((priceTime - firstChartTime) / (latestChartTime - firstChartTime)) * plotWidth
                : plot.left + (index / (chartPrices.length - 1)) * plotWidth);
        const normalized = (price.chart_price - chartMin) / chartRange;
        const y = plot.bottom - normalized * plotHeight;

        return {
            ...price,
            x,
            y,
        };
    });
    const highPoint = points.find((point) => point.chart_price === max);
    const lowPoint = points.find((point) => point.chart_price === min);
    const todayStartPoint = rangeKey === 'today-1'
        ? points.find((point) => !point.is_previous_trading_close)
        : null;

    return {
        width,
        height,
        plot,
        points,
        linePath: analyzeSparklinePath(points),
        areaPath: analyzeSparklineAreaPath(points, plot),
        horizontalGridLines: analyzeSparklineHorizontalGridLines(chartMin, chartMax, plot),
        verticalGridLines: shouldUseAnalyzeDateMarkers(rangeKey) ? [] : analyzeSparklineTickPoints(points).map((point) => ({
            x: point.x,
            label: formatAnalyzeSparklineDate(point),
        })),
        monthStartMarkers: shouldUseAnalyzeDateMarkers(rangeKey)
            ? analyzeSparklineDateMarkers(points, plot, rangeKey)
            : [],
        dateRangeLabels: shouldUseAnalyzeDateMarkers(rangeKey)
            ? analyzeSparklineDateRangeLabels(points, rangeKey)
            : [],
        first: points[0],
        latest: points[points.length - 1],
        firstLabel: chartEndpointLabel(points[0], plot, 'start'),
        latestLabel: chartEndpointLabel(points[points.length - 1], plot, 'end', points[0]),
        todayStartLabel: chartEndpointLabel(todayStartPoint, plot, 'start', null, {
            changeClass: chartValueDirectionClass(todayStartPoint, points[0]),
            changePercent: formatChartEndpointChangePercent(todayStartPoint, points[0]),
            labelPrefix: 'Start',
            labelX: points[0].x,
            labelY: plot.bottom + 38,
        }),
        trendLine: chartRegressionLine(points, chartMin, chartRange, plot),
        highMarker: analyzeSparklineExtremumMarker(highPoint, 'high', plot),
        lowMarker: analyzeSparklineExtremumMarker(lowPoint, 'low', plot),
        min,
        max,
        trend: points[points.length - 1].chart_price > points[0].chart_price
            ? 'up'
            : (points[points.length - 1].chart_price < points[0].chart_price ? 'down' : 'flat'),
    };
}

function shouldUseAnalyzeMonthMarkers(rangeKey) {
    return analyzeCalendarNormalizedRangeKeys.includes(rangeKey);
}

function shouldUseAnalyzeDateMarkers(rangeKey) {
    return shouldUseAnalyzeMonthMarkers(rangeKey) || isAnalyzeTodayRange(rangeKey);
}

function analyzeMarkerDaysForRange(rangeKey) {
    if (rangeKey === '3m') {
        return [1, 10, 20];
    }

    return rangeKey === '6m' ? [1, 15] : [1];
}

function analyzeMarkerIntervalDaysForRange(rangeKey) {
    if (rangeKey === '1w') {
        return 1;
    }

    return rangeKey === '1m' ? 3 : null;
}

function analyzeSparklineDateRangeLabels(points, rangeKey) {
    const first = points[0];
    const latest = points[points.length - 1];

    if (!first || !latest) {
        return [];
    }

    return [
        {
            x: first.x,
            label: formatAnalyzeRangeBoundaryLabel(first, rangeKey),
            anchor: 'start',
        },
        {
            x: latest.x,
            label: formatAnalyzeRangeBoundaryLabel(latest, rangeKey),
            anchor: 'end',
        },
    ].filter((label) => label.label);
}

function formatAnalyzeRangeBoundaryLabel(point, rangeKey) {
    if (point?.is_previous_trading_close) {
        return '';
    }

    return isAnalyzeTodayRange(rangeKey) ? formatAnalyzeTimeLabel(point) : formatAnalyzeDateLabel(point);
}

function analyzeSparklineDateMarkers(points, plot, rangeKey) {
    const intervalDays = analyzeMarkerIntervalDaysForRange(rangeKey);

    if (isAnalyzeTodayRange(rangeKey)) {
        return analyzeSparklineHourlyMarkers(points, plot);
    }

    if (rangeKey === '1w') {
        return analyzeSparklineTradingDateCalendarMarkers(points, plot);
    }

    if (rangeKey === '1m') {
        return analyzeSparklineCalendarIntervalMarkers(points, plot, intervalDays);
    }

    if (intervalDays) {
        return analyzeSparklineIntervalMarkers(points, plot, intervalDays);
    }

    if (rangeKey === '3m') {
        return analyzeSparklineTargetDateMonthMarkers(points, plot, analyzeMarkerDaysForRange(rangeKey));
    }

    return analyzeSparklineMonthMarkers(points, plot, analyzeMarkerDaysForRange(rangeKey));
}

function analyzeSparklineHourlyMarkers(points, plot) {
    if (points.length < 2) {
        return [];
    }

    const firstTime = analyzeSparklinePointTime(points[0]);
    const latestTime = analyzeSparklinePointTime(points[points.length - 1]);

    if (firstTime === null || latestTime === null || firstTime >= latestTime) {
        return [];
    }

    const markers = [];
    const markerDate = new Date(firstTime);
    markerDate.setMinutes(0, 0, 0);

    if (markerDate.getTime() <= firstTime) {
        markerDate.setHours(markerDate.getHours() + 1);
    }

    while (markerDate.getTime() < latestTime) {
        const markerTime = markerDate.getTime();
        const markerPosition = analyzeSparklineMarkerPosition(points, markerTime, plot);

        if (markerPosition) {
            markers.push({
                ...markerPosition,
                label: formatAnalyzeTimeLabel(new Date(markerTime)),
            });
        }

        markerDate.setHours(markerDate.getHours() + 1);
    }

    return markers;
}

function analyzeSparklineIntervalMarkers(points, plot, intervalDays) {
    if (points.length < 2) {
        return [];
    }

    const tradingDates = analyzeSparklineTradingDates(points);

    if (tradingDates.length < 3) {
        return [];
    }

    const markers = [];
    for (let index = intervalDays; index < tradingDates.length - 1; index += intervalDays) {
        const markerDateString = tradingDates[index];
        const markerTime = dateStringToUtcTime(markerDateString);

        if (markerTime === null) {
            continue;
        }

        const markerPosition = analyzeSparklineMarkerPosition(points, markerTime, plot);

        if (markerPosition) {
            markers.push({
                ...markerPosition,
                label: formatAnalyzeMonthStartLabel(new Date(markerTime)),
            });
        }
    }

    return markers;
}

function analyzeSparklineTradingDateCalendarMarkers(points, plot) {
    if (points.length < 2) {
        return [];
    }

    const tradingDates = analyzeSparklineTradingDates(points);

    if (tradingDates.length < 3) {
        return [];
    }

    return tradingDates.slice(1, -1).map((markerDateString, index) => {
        const markerTime = dateStringToUtcTime(markerDateString);

        if (markerTime === null) {
            return null;
        }

        const markerPosition = analyzeSparklineMarkerPositionAtRatio(
            points,
            (index + 1) / (tradingDates.length - 1),
            plot,
        );

        if (!markerPosition) {
            return null;
        }

        return {
            ...markerPosition,
            label: formatAnalyzeMonthStartLabel(new Date(markerTime)),
        };
    }).filter((marker) => marker);
}

function analyzeSparklineCalendarIntervalMarkers(points, plot, intervalDays) {
    if (points.length < 2 || !intervalDays) {
        return [];
    }

    const firstTime = analyzeSparklinePointTime(points[0]);
    const latestTime = analyzeSparklinePointTime(points[points.length - 1]);

    if (firstTime === null || latestTime === null || firstTime >= latestTime) {
        return [];
    }

    const markers = [];
    const tradingDates = analyzeSparklineTradingDates(points);
    const usedMarkerDateStrings = new Set();
    const firstDateString = analyzeSparklineTradingDateString(points[0]);
    const latestDateString = analyzeSparklineTradingDateString(points[points.length - 1]);
    const firstDateTime = firstDateString ? dateStringToUtcTime(firstDateString) : null;
    const latestDateTime = latestDateString ? dateStringToUtcTime(latestDateString) : null;

    if (firstDateTime === null || latestDateTime === null || firstDateTime >= latestDateTime) {
        return [];
    }

    const markerDate = new Date(firstTime);
    markerDate.setUTCHours(0, 0, 0, 0);
    markerDate.setUTCDate(markerDate.getUTCDate() + intervalDays);

    while (markerDate.getTime() < latestTime) {
        const nearestMarkerDateString = nearestAnalyzeSparklineTradingDate(
            tradingDates,
            markerDate.toISOString().slice(0, 10),
            firstTime,
            latestTime,
        );

        if (nearestMarkerDateString && !usedMarkerDateStrings.has(nearestMarkerDateString)) {
            usedMarkerDateStrings.add(nearestMarkerDateString);
            markers.push({
                markerDateString: nearestMarkerDateString,
                targetDateString: markerDate.toISOString().slice(0, 10),
            });
        }

        markerDate.setUTCDate(markerDate.getUTCDate() + intervalDays);
    }

    return markers.map(({ markerDateString, targetDateString }) => {
        const targetDateTime = dateStringToUtcTime(targetDateString);
        const markerTime = dateStringToUtcTime(markerDateString);

        if (targetDateTime === null || markerTime === null) {
            return null;
        }

        const markerPosition = analyzeSparklineMarkerPositionAtRatio(
            points,
            (targetDateTime - firstDateTime) / (latestDateTime - firstDateTime),
            plot,
        );

        if (!markerPosition) {
            return null;
        }

        return {
            ...markerPosition,
            label: formatAnalyzeMonthStartLabel(new Date(markerTime)),
        };
    }).filter((marker) => marker);
}

function analyzeSparklineTradingDates(points) {
    return [...new Set(points.map((point) => analyzeSparklineTradingDateString(point)).filter((dateString) => dateString))].sort();
}

function analyzeSparklineTradingDateString(point) {
    const value = point?.chart_as_of ?? point?.as_of ?? point?.trading_date;

    return typeof value === 'string' && value.length >= 10 ? value.slice(0, 10) : null;
}

function analyzeSparklineMonthMarkers(points, plot, markerDays) {
    return analyzeSparklineMonthMarkerDates(points, markerDays).map(({ markerDateString }) => {
        const markerTime = dateStringToUtcTime(markerDateString);

        if (markerTime === null) {
            return null;
        }

        const markerPosition = analyzeSparklineMarkerPosition(points, markerTime, plot);

        if (!markerPosition) {
            return null;
        }

        return {
            ...markerPosition,
            label: formatAnalyzeMonthStartLabel(new Date(markerTime)),
        };
    }).filter((marker) => marker);
}

function analyzeSparklineTargetDateMonthMarkers(points, plot, markerDays) {
    const markerDates = analyzeSparklineMonthMarkerDates(points, markerDays);
    const firstDateString = analyzeSparklineTradingDateString(points[0]);
    const latestDateString = analyzeSparklineTradingDateString(points[points.length - 1]);
    const firstDateTime = firstDateString ? dateStringToUtcTime(firstDateString) : null;
    const latestDateTime = latestDateString ? dateStringToUtcTime(latestDateString) : null;

    if (firstDateTime === null || latestDateTime === null || firstDateTime >= latestDateTime) {
        return [];
    }

    return markerDates.map(({ markerDateString, targetDateString }) => {
        const markerTime = dateStringToUtcTime(markerDateString);
        const targetDateTime = dateStringToUtcTime(targetDateString);

        if (markerTime === null || targetDateTime === null) {
            return null;
        }

        const markerPosition = analyzeSparklineMarkerPositionAtRatio(
            points,
            (targetDateTime - firstDateTime) / (latestDateTime - firstDateTime),
            plot,
        );

        if (!markerPosition) {
            return null;
        }

        return {
            ...markerPosition,
            label: formatAnalyzeMonthStartLabel(new Date(markerTime)),
        };
    }).filter((marker) => marker);
}

function analyzeSparklineMonthMarkerDates(points, markerDays) {
    if (points.length < 2) {
        return [];
    }

    const firstTime = analyzeSparklinePointTime(points[0]);
    const latestTime = analyzeSparklinePointTime(points[points.length - 1]);

    if (firstTime === null || latestTime === null || firstTime >= latestTime) {
        return [];
    }

    const markers = [];
    const tradingDates = analyzeSparklineTradingDates(points);
    const markerDateStrings = new Set();
    const firstDate = new Date(firstTime);
    const markerMonth = new Date(Date.UTC(firstDate.getUTCFullYear(), firstDate.getUTCMonth(), 1));

    while (markerMonth.getTime() < latestTime) {
        markerDays.forEach((day) => {
            const markerDate = new Date(Date.UTC(markerMonth.getUTCFullYear(), markerMonth.getUTCMonth(), day));
            const markerTime = markerDate.getTime();
            const nearestMarkerDateString = nearestAnalyzeSparklineTradingDate(
                tradingDates,
                markerDate.toISOString().slice(0, 10),
                firstTime,
                latestTime,
            );

            if (
                markerTime > firstTime
                && markerTime < latestTime
                && nearestMarkerDateString
                && !markerDateStrings.has(nearestMarkerDateString)
            ) {
                markerDateStrings.add(nearestMarkerDateString);
                markers.push({
                    markerDateString: nearestMarkerDateString,
                    targetDateString: markerDate.toISOString().slice(0, 10),
                });
            }
        });

        markerMonth.setUTCMonth(markerMonth.getUTCMonth() + 1);
    }

    return markers;
}

function analyzeSparklineMarkerPositionAtRatio(points, ratio, plot) {
    if (points.length === 0) {
        return null;
    }

    if (points.length === 1) {
        return {
            x: points[0].x,
            y: points[0].y,
            priceLabelY: Math.max(plot.top + 12, points[0].y - 10),
            chart_price: points[0].chart_price,
        };
    }

    const scaledIndex = ratio * (points.length - 1);
    const previousIndex = Math.floor(scaledIndex);
    const currentIndex = Math.ceil(scaledIndex);
    const segmentRatio = scaledIndex - previousIndex;
    const previousPoint = points[previousIndex];
    const currentPoint = points[currentIndex];
    const y = previousPoint.y + (segmentRatio * (currentPoint.y - previousPoint.y));

    return {
        x: previousPoint.x + (segmentRatio * (currentPoint.x - previousPoint.x)),
        y,
        priceLabelY: Math.max(plot.top + 12, y - 10),
        chart_price: previousPoint.chart_price + (segmentRatio * (currentPoint.chart_price - previousPoint.chart_price)),
    };
}

function nearestAnalyzeSparklineTradingDate(tradingDates, targetDateString, firstTime, latestTime) {
    const targetTime = dateStringToUtcTime(targetDateString);

    if (targetTime === null) {
        return null;
    }

    const nearestDate = tradingDates.reduce((nearest, tradingDate) => {
        const tradingTime = dateStringToUtcTime(tradingDate);

        if (tradingTime === null || tradingTime <= firstTime || tradingTime >= latestTime) {
            return nearest;
        }

        if (!nearest) {
            return {
                dateString: tradingDate,
                time: tradingTime,
            };
        }

        const currentDistance = Math.abs(tradingTime - targetTime);
        const nearestDistance = Math.abs(nearest.time - targetTime);

        if (currentDistance < nearestDistance) {
            return {
                dateString: tradingDate,
                time: tradingTime,
            };
        }

        if (currentDistance === nearestDistance && tradingTime < nearest.time) {
            return {
                dateString: tradingDate,
                time: tradingTime,
            };
        }

        return nearest;
    }, null);

    return nearestDate?.dateString ?? null;
}

function analyzeSparklineMarkerPosition(points, markerTime, plot) {
    for (let index = 1; index < points.length; index++) {
        const previousPoint = points[index - 1];
        const currentPoint = points[index];
        const previousTime = analyzeSparklinePointTime(previousPoint);
        const currentTime = analyzeSparklinePointTime(currentPoint);

        if (previousTime === null || currentTime === null || previousTime === currentTime) {
            continue;
        }

        if (markerTime >= previousTime && markerTime <= currentTime) {
            const ratio = (markerTime - previousTime) / (currentTime - previousTime);
            const y = previousPoint.y + ratio * (currentPoint.y - previousPoint.y);

            return {
                x: previousPoint.x + ratio * (currentPoint.x - previousPoint.x),
                y,
                priceLabelY: Math.max(plot.top + 12, y - 10),
                chart_price: previousPoint.chart_price + ratio * (currentPoint.chart_price - previousPoint.chart_price),
            };
        }
    }

    return null;
}

function analyzeSparklinePointTime(point) {
    const value = point?.chart_as_of ?? point?.as_of ?? point?.trading_date;

    if (!value) {
        return null;
    }

    const time = new Date(value).getTime();

    return Number.isNaN(time) ? null : time;
}

function dateFromUtcValue(value) {
    if (!value) {
        return null;
    }

    const date = value instanceof Date ? value : new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatViennaDateTime(date, options) {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: displayTimeZone,
        ...options,
    }).format(date);
}

function formatAnalyzeMonthStartLabel(date) {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
    }).format(date);
}

function formatAnalyzeDateLabel(point) {
    const time = analyzeSparklinePointTime(point);

    if (time === null) {
        return '-';
    }

    return formatAnalyzeMonthStartLabel(new Date(time));
}

function formatAnalyzeTimeLabel(point) {
    const time = point instanceof Date ? point.getTime() : analyzeSparklinePointTime(point);

    if (time === null || Number.isNaN(time)) {
        return '-';
    }

    return formatViennaDateTime(new Date(time), {
        hour: '2-digit',
        minute: '2-digit',
    });
}

function chartRegressionLine(points, chartMin, chartRange, plot) {
    if (points.length < 2) {
        return null;
    }

    const pointCount = points.length;
    const sums = points.reduce((totals, point, index) => ({
        x: totals.x + index,
        y: totals.y + point.chart_price,
        xy: totals.xy + index * point.chart_price,
        xx: totals.xx + index * index,
    }), {
        x: 0,
        y: 0,
        xy: 0,
        xx: 0,
    });
    const denominator = pointCount * sums.xx - sums.x * sums.x;

    if (denominator === 0) {
        return null;
    }

    const slope = (pointCount * sums.xy - sums.x * sums.y) / denominator;
    const intercept = (sums.y - slope * sums.x) / pointCount;
    const firstValue = intercept;
    const latestValue = slope * (pointCount - 1) + intercept;

    return {
        x1: points[0].x,
        y1: chartValueToY(firstValue, chartMin, chartRange, plot),
        x2: points[points.length - 1].x,
        y2: chartValueToY(latestValue, chartMin, chartRange, plot),
        slope,
    };
}

function chartValueToY(value, chartMin, chartRange, plot) {
    const normalized = (value - chartMin) / chartRange;

    return plot.bottom - normalized * (plot.bottom - plot.top);
}

function chartEndpointLabel(point, plot, side, referencePoint = null, options = {}) {
    if (!point) {
        return null;
    }

    const verticalOffset = 18;

    return {
        ...point,
        labelX: options.labelX ?? point.x,
        labelY: options.labelY ?? (side === 'start'
            ? plot.bottom + verticalOffset
            : plot.top - verticalOffset),
        labelAnchor: side === 'start' ? 'start' : 'end',
        labelPrefix: options.labelPrefix ?? point.endpoint_label ?? (side === 'start' ? 'Start' : 'End'),
        changeClass: options.changeClass ?? '',
        changePercent: options.changePercent ?? (side === 'end' ? formatChartEndpointChangePercent(point, referencePoint) : ''),
    };
}

function chartValueDirectionClass(point, referencePoint) {
    if (!point || !referencePoint) {
        return '';
    }

    const referenceValue = Number(referencePoint.chart_price);
    const value = Number(point.chart_price);

    if (Number.isNaN(referenceValue) || Number.isNaN(value) || value === referenceValue) {
        return '';
    }

    return value > referenceValue
        ? 'analyze-sparkline-endpoint-label-change--up'
        : 'analyze-sparkline-endpoint-label-change--down';
}

function formatChartEndpointChangePercent(point, referencePoint) {
    if (!point || !referencePoint) {
        return '';
    }

    const referenceValue = Number(referencePoint.chart_price);
    const value = Number(point.chart_price);

    if (Number.isNaN(referenceValue) || Number.isNaN(value) || referenceValue === 0) {
        return '';
    }

    const changePercent = ((value - referenceValue) / Math.abs(referenceValue)) * 100;
    const sign = changePercent > 0 ? '+' : '';

    return `${sign}${changePercent.toFixed(2)}%`;
}

function analyzeSparklineExtremumMarker(point, direction, plot) {
    if (!point) {
        return null;
    }

    const markerDistance = 16;
    const markerPadding = 6;
    const preferredMarkerY = direction === 'high'
        ? point.y - markerDistance
        : point.y + markerDistance;
    const fallbackMarkerY = direction === 'high'
        ? point.y + markerDistance
        : point.y - markerDistance;
    const markerY = preferredMarkerY >= plot.top + markerPadding && preferredMarkerY <= plot.bottom - markerPadding
        ? preferredMarkerY
        : fallbackMarkerY;
    const shouldPlaceLabelLeft = point.x > (plot.left + plot.right) / 2;
    const labelOffset = 14;

    return {
        ...point,
        markerX: point.x,
        markerY: Math.min(plot.bottom - markerPadding, Math.max(plot.top + markerPadding, markerY)),
        labelX: point.x + (shouldPlaceLabelLeft ? -labelOffset : labelOffset),
        labelY: Math.min(plot.bottom - markerPadding, Math.max(plot.top + markerPadding, markerY)) + 4,
        labelAnchor: shouldPlaceLabelLeft ? 'end' : 'start',
    };
}

function analyzeSparklinePath(points) {
    if (points.length === 1) {
        const segmentOffset = 34;

        return [
            `M ${(points[0].x - segmentOffset).toFixed(2)} ${points[0].y.toFixed(2)}`,
            `L ${(points[0].x + segmentOffset).toFixed(2)} ${points[0].y.toFixed(2)}`,
        ].join(' ');
    }

    return points.reduce((path, point, index) => {
        if (index === 0) {
            return `M ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
        }

        return `${path} L ${point.x.toFixed(2)} ${point.y.toFixed(2)}`;
    }, '');
}

function analyzeSparklineAreaPath(points, plot) {
    if (points.length === 0) {
        return '';
    }

    if (points.length === 1) {
        return '';
    }

    return `${analyzeSparklinePath(points)} L ${points[points.length - 1].x.toFixed(2)} ${plot.bottom.toFixed(2)} L ${points[0].x.toFixed(2)} ${plot.bottom.toFixed(2)} Z`;
}

function analyzeSparklineHorizontalGridLines(min, max, plot) {
    const gridLineCount = 5;
    const range = max - min;

    return Array.from({ length: gridLineCount }, (_, index) => {
        const ratio = index / (gridLineCount - 1);
        const value = max - range * ratio;
        const y = plot.top + (plot.bottom - plot.top) * ratio;

        return {
            y,
            value,
        };
    });
}

function analyzeSparklineTickPoints(points) {
    const maximumTickCount = 6;

    if (points.length <= maximumTickCount) {
        return points;
    }

    const lastPointIndex = points.length - 1;
    const pointIndexes = Array.from({ length: maximumTickCount }, (_, index) => (
        Math.round((index / (maximumTickCount - 1)) * lastPointIndex)
    ));

    return [...new Set(pointIndexes)].map((index) => points[index]);
}

function formatAnalyzeSparklinePrice(point) {
    if (!point) {
        return '-';
    }

    return formatPriceValue(point.chart_price, selectedAnalyzeHolding.value?.currency);
}

function analyzeIntradayCloseSummaryItems(day, dayIndex) {
    const summary = analyzeIntradayCloseSummary(day);
    const referenceSummary = analyzeIntradayCloseSummary(analyzeIntradayDetailDays.value[dayIndex + 1] ?? {});
    const lastReferenceValue = referenceSummary.last ?? summary.first;

    return [
        {
            key: 'first',
            label: 'First',
            value: formatAnalyzeIntradayCloseSummaryValue(summary.first),
            changePercent: formatAnalyzeIntradayCloseChangePercent(summary.first, referenceSummary.last),
            changeClass: analyzeIntradayCloseChangeClass(summary.first, referenceSummary.last),
        },
        { key: 'low', label: 'Lowest', value: formatAnalyzeIntradayCloseSummaryValue(summary.low) },
        { key: 'high', label: 'Highest', value: formatAnalyzeIntradayCloseSummaryValue(summary.high) },
        {
            key: 'ups',
            label: 'Ups',
            value: formatInteger(summary.ups),
            itemClass: 'is-compact',
            valueClass: 'is-up',
        },
        {
            key: 'downs',
            label: 'Downs',
            value: formatInteger(summary.downs),
            itemClass: 'is-compact',
            valueClass: 'is-down',
        },
        {
            key: 'first-to-noon',
            label: 'First -> 12:00+',
            value: formatAnalyzeIntradayCloseDevelopmentPercent(summary.firstAfterNoon, summary.first),
            valueClass: analyzeIntradayCloseChangeClass(summary.firstAfterNoon, summary.first),
        },
        {
            key: 'noon-to-last',
            label: '12:00+ -> End',
            value: formatAnalyzeIntradayCloseDevelopmentPercent(summary.last, summary.firstAfterNoon),
            valueClass: analyzeIntradayCloseChangeClass(summary.last, summary.firstAfterNoon),
        },
        {
            key: 'last',
            label: 'Last',
            value: formatAnalyzeIntradayCloseSummaryValue(summary.last),
            changePercent: formatAnalyzeIntradayCloseChangePercent(summary.last, lastReferenceValue),
            changeClass: analyzeIntradayCloseChangeClass(summary.last, lastReferenceValue),
        },
    ];
}

function analyzeIntradayCloseSummary(day) {
    const closePrices = (day.rows ?? [])
        .map((row) => Number(row.close))
        .filter((closePrice) => !Number.isNaN(closePrice));

    if (closePrices.length === 0) {
        return {
            first: null,
            low: null,
            high: null,
            last: null,
            ups: 0,
            downs: 0,
            firstAfterNoon: null,
        };
    }

    const firstAfterNoonClosePrice = (day.rows ?? [])
        .find((row) => isAnalyzeIntradayCandleAtOrAfterViennaNoon(row) && !Number.isNaN(Number(row.close)));

    const closeMoves = closePrices.slice(1).reduce((moves, closePrice, index) => {
        const previousClosePrice = closePrices[index];

        if (closePrice > previousClosePrice) {
            moves.ups += 1;
        }

        if (closePrice < previousClosePrice) {
            moves.downs += 1;
        }

        return moves;
    }, { ups: 0, downs: 0 });

    return {
        first: closePrices[0],
        low: Math.min(...closePrices),
        high: Math.max(...closePrices),
        last: closePrices[closePrices.length - 1],
        ups: closeMoves.ups,
        downs: closeMoves.downs,
        firstAfterNoon: firstAfterNoonClosePrice ? Number(firstAfterNoonClosePrice.close) : null,
    };
}

function formatAnalyzeIntradayCloseSummaryValue(value) {
    if (value === null || value === undefined) {
        return '-';
    }

    return formatPriceValue(value, selectedAnalyzeHolding.value?.currency);
}

function formatAnalyzeIntradayCloseChangePercent(value, referenceValue) {
    const amount = analyzeIntradayCloseChangePercent(value, referenceValue);

    if (amount === null) {
        return '';
    }

    const sign = amount > 0 ? '+' : '';

    return `${sign}${amount.toFixed(2)}%`;
}

function formatAnalyzeIntradayCloseDevelopmentPercent(value, referenceValue) {
    return formatAnalyzeIntradayCloseChangePercent(value, referenceValue) || '-';
}

function analyzeIntradayCloseChangeClass(value, referenceValue) {
    const amount = analyzeIntradayCloseChangePercent(value, referenceValue);

    if (amount === null || amount === 0) {
        return 'is-flat';
    }

    return amount > 0 ? 'is-up' : 'is-down';
}

function analyzeIntradayCloseChangePercent(value, referenceValue) {
    if (value === null || value === undefined || referenceValue === null || referenceValue === undefined) {
        return null;
    }

    if (referenceValue === 0) {
        return null;
    }

    return ((value - referenceValue) / referenceValue) * 100;
}

function isAnalyzeIntradayCandleAtOrAfterViennaNoon(row) {
    const viennaMinutes = analyzeIntradayCandleViennaMinutes(row);

    if (viennaMinutes === null) {
        return false;
    }

    return viennaMinutes >= 720;
}

function analyzeIntradayCandleViennaMinutes(row) {
    const date = analyzeIntradayCandleDate(row);

    if (!date) {
        return null;
    }

    const parts = new Intl.DateTimeFormat('en-GB', {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
        timeZone: displayTimeZone,
    }).formatToParts(date);

    const hour = Number(parts.find((part) => part.type === 'hour')?.value);
    const minute = Number(parts.find((part) => part.type === 'minute')?.value);

    if (Number.isNaN(hour) || Number.isNaN(minute)) {
        return null;
    }

    return (hour * 60) + minute;
}

function analyzeIntradayCandleDate(row) {
    if (Number(row?.timestamp) > 0) {
        return new Date(Number(row.timestamp) * 1000);
    }

    if (typeof row?.datetime !== 'string' || row.datetime.trim() === '') {
        return null;
    }

    const date = new Date(`${row.datetime.replace(' ', 'T')}Z`);

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatAnalyzeSparklineDate(point) {
    if (point?.is_previous_trading_close) {
        return 'Prev';
    }

    if (point?.as_of) {
        const date = dateFromUtcValue(point.as_of);

        if (!date) {
            return '-';
        }

        return formatViennaDateTime(date, {
            day: '2-digit',
            month: '2-digit',
            year: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
        });
    }

    if (!point?.trading_date) {
        return '-';
    }

    return formatIndexHistoryDate(point.trading_date);
}

function formatAnalyzeIntradayCandleDateTime(row) {
    const date = analyzeIntradayCandleDate(row);

    if (!date) {
        return '-';
    }

    return formatViennaDateTime(date, {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function formatAnalyzeIntradayCandleValue(value) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    return value;
}

function formatAnalyzeSparklineAxisPrice(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return '-';
    }

    return formatPriceValue(value, selectedAnalyzeHolding.value?.currency);
}

function formatIndexChangePercent(indexItem) {
    if (
        indexItem.latest_price_change_pct === null
        || indexItem.latest_price_change_pct === undefined
        || indexItem.latest_price_change_pct === ''
    ) {
        return '';
    }

    const amount = Number(indexItem.latest_price_change_pct);

    if (Number.isNaN(amount)) {
        return '';
    }

    const sign = amount > 0 ? '+' : '';

    return `${sign}${amount.toFixed(2)}%`;
}

function indexChangeClass(indexItem) {
    const amount = Number(indexItem.latest_price_change_pct);

    if (Number.isNaN(amount)) {
        return 'is-flat';
    }

    if (amount > 0) {
        return 'is-up';
    }

    if (amount < 0) {
        return 'is-down';
    }

    return 'is-flat';
}

function formatEndPriceChangePercent(holding) {
    if (
        holding.end_price === null
        || holding.end_price === undefined
        || holding.end_price === ''
        || holding.end_price_24 === null
        || holding.end_price_24 === undefined
        || holding.end_price_24 === ''
    ) {
        return '';
    }

    const referencePrice = Number(holding.end_price_24);
    const endPrice = Number(holding.end_price);

    if (Number.isNaN(referencePrice) || Number.isNaN(endPrice) || referencePrice === 0) {
        return '';
    }

    const amount = ((endPrice - referencePrice) / referencePrice) * 100;
    const sign = amount > 0 ? '+' : '';

    return `${sign}${amount.toFixed(2)}%`;
}

function yearStartChangePercent(holding) {
    if (
        holding.year_start_price === null
        || holding.year_start_price === undefined
        || holding.year_start_price === ''
        || selectedDepotHoldingPrice(holding) === null
        || selectedDepotHoldingPrice(holding) === undefined
        || selectedDepotHoldingPrice(holding) === ''
    ) {
        return null;
    }

    const yearStartPrice = Number(holding.year_start_price);
    const selectedPrice = Number(selectedDepotHoldingPrice(holding));

    if (Number.isNaN(yearStartPrice) || Number.isNaN(selectedPrice) || yearStartPrice === 0) {
        return null;
    }

    return ((selectedPrice - yearStartPrice) / yearStartPrice) * 100;
}

function yearStartChangeAmount(holding) {
    if (
        holding.year_start_price === null
        || holding.year_start_price === undefined
        || holding.year_start_price === ''
        || selectedDepotHoldingPrice(holding) === null
        || selectedDepotHoldingPrice(holding) === undefined
        || selectedDepotHoldingPrice(holding) === ''
    ) {
        return null;
    }

    const yearStartPrice = Number(holding.year_start_price);
    const selectedPrice = Number(selectedDepotHoldingPrice(holding));
    const pieces = Number(holding.position_pieces ?? 0);

    if (Number.isNaN(yearStartPrice) || Number.isNaN(selectedPrice) || Number.isNaN(pieces)) {
        return null;
    }

    return (selectedPrice - yearStartPrice) * pieces;
}

function formatYearStartChangeAmount(holding) {
    const amount = yearStartChangeAmount(holding);

    if (amount === null) {
        return '-';
    }

    const sign = amount > 0 ? '+' : '';
    const formattedAmount = new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(amount);

    return `${sign}${formattedAmount}`;
}

function depotHoldingsChangeAmountTotal() {
    return depotHoldings.value.reduce((sum, holding) => {
        const amount = yearStartChangeAmount(holding);

        if (amount === null) {
            return sum;
        }

        return sum + amount;
    }, 0);
}

function formatDepotHoldingsChangeAmountTotal() {
    const amount = depotHoldingsChangeAmountTotal();
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)}`;
}

function depotHoldingsChangeAmountTotalClass() {
    const amount = depotHoldingsChangeAmountTotal();

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function formatYearStartChangePercent(holding) {
    const amount = yearStartChangePercent(holding);

    if (amount === null) {
        return '-';
    }

    const sign = amount > 0 ? '+' : '';

    return `${sign}${amount.toFixed(2)}%`;
}

function yearStartChangeSymbol(holding) {
    const amount = yearStartChangePercent(holding);

    if (amount === null) {
        return '';
    }

    if (amount > 0) {
        return '\u2191';
    }

    if (amount < 0) {
        return '\u2193';
    }

    return '=';
}

function yearStartChangeClass(holding) {
    const amount = yearStartChangeAmount(holding);

    return {
        'text-success': amount !== null && amount > 0,
        'text-error': amount !== null && amount < 0,
        'text-medium-emphasis': amount === null || amount === 0,
    };
}

function depotHoldingRowClass(holding) {
    const amount = yearStartChangeAmount(holding);

    return {
        'depot-holding-row--positive': amount !== null && amount > 0,
        'depot-holding-row--negative': amount !== null && amount < 0,
    };
}

function endPriceValueClass(holding) {
    const formattedChange = formatEndPriceChangePercent(holding);

    if (formattedChange.startsWith('+')) {
        return 'bg-success text-white';
    }

    if (formattedChange.startsWith('-')) {
        return 'bg-error text-white';
    }

    return '';
}

async function toggleHoldingDetails(holding) {
    const holdingId = holding.id;
    const willExpand = !expandedHoldingIds.value.includes(holdingId);

    expandedHoldingIds.value = willExpand
        ? [...expandedHoldingIds.value, holdingId]
        : expandedHoldingIds.value.filter((expandedHoldingId) => expandedHoldingId !== holdingId);

    if (!willExpand || holdingIntradayCandles.value[holdingId] || holdingIntradayCandlesLoading.value[holdingId]) {
        return;
    }

    try {
        await depotsStore.loadExpandedHoldingIntradayCandles(holdingId);
    } catch {
    }
}

function expandedHoldingIntradayDay(holding) {
    const candlePayload = holdingIntradayCandles.value[holding.id] ?? null;
    const days = candlePayload?.intraday_days;

    if (Array.isArray(days) && days.length > 0) {
        return days[0];
    }

    return candlePayload?.intraday ?? null;
}

function expandedHoldingIntradayRows(holding) {
    return expandedHoldingIntradayDay(holding)?.rows ?? [];
}

function expandedHoldingIntradayTitle(holding) {
    return expandedHoldingIntradayDay(holding)?.title ?? 'Intraday - 5m';
}

function expandedHoldingIntradayLoading(holding) {
    return holdingIntradayCandlesLoading.value[holding.id] === true;
}

function expandedHoldingIntradayError(holding) {
    return holdingIntradayCandlesErrors.value[holding.id] ?? '';
}

function isHoldingExpanded(holding) {
    return expandedHoldingIds.value.includes(holding.id);
}

function recentPricesForExpandedHolding(holding) {
    return (holding.recent_prices ?? []).map((recentPrice, recentPriceIndex, recentPrices) => ({
        ...recentPrice,
        trend: recentStoredPriceTrend(recentPrice, recentPrices[recentPriceIndex - 1] ?? null),
    }));
}

function recentPricesFallbackDate(holding) {
    const firstRecentPrice = holding.recent_prices?.[0] ?? null;

    if (!holding.recent_prices_are_fallback || !firstRecentPrice?.as_of) {
        return null;
    }

    return formatRecentStoredPriceDate(firstRecentPrice);
}

function recentPriceTrendDots(holding) {
    return recentPricesForExpandedHolding(holding).slice(-10);
}

function recentStoredPriceTrend(recentPrice, previousRecentPrice) {
    if (!previousRecentPrice) {
        return 'flat';
    }

    const price = Number(recentPrice.price);
    const previousPrice = Number(previousRecentPrice.price);

    if (Number.isNaN(price) || Number.isNaN(previousPrice) || price === previousPrice) {
        return 'flat';
    }

    return price > previousPrice ? 'up' : 'down';
}

function recentStoredPriceTrendSymbol(recentPrice) {
    const symbols = {
        up: '\u2191',
        down: '\u2193',
        flat: '=',
    };

    return symbols[recentPrice.trend] ?? '=';
}

function recentStoredPriceTrendClass(recentPrice) {
    return {
        'text-success': recentPrice.trend === 'up',
        'text-error': recentPrice.trend === 'down',
        'text-medium-emphasis': recentPrice.trend === 'flat',
    };
}

function recentStoredPriceTrendDotClass(recentPrice) {
    return {
        'recent-price-trend-dot-up': recentPrice.trend === 'up',
        'recent-price-trend-dot-down': recentPrice.trend === 'down',
        'recent-price-trend-dot-flat': recentPrice.trend === 'flat',
    };
}

function recentStoredPriceTrendLabel(recentPrice) {
    const labels = {
        up: 'Price increased',
        down: 'Price decreased',
        flat: 'Price unchanged',
    };

    return labels[recentPrice.trend] ?? labels.flat;
}

function latestPriceClass(holding) {
    return {
        'bg-success text-white': holding.latest_price_trend === 'up',
        'bg-error text-white': holding.latest_price_trend === 'down',
    };
}

function latestPriceTickSymbol(holding) {
    const symbols = {
        up: '\u2191',
        down: '\u2193',
        flat: '=',
    };

    return symbols[holding.latest_price_tick_trend] ?? null;
}

function latestPriceTickLabel(holding) {
    const labels = {
        up: 'Price increased from previous quote',
        down: 'Price decreased from previous quote',
        flat: 'Price unchanged from previous quote',
    };

    return labels[holding.latest_price_tick_trend] ?? null;
}

function startPriceTrend(holding) {
    if (
        holding.start_price === null
        || holding.start_price === undefined
        || holding.start_price === ''
        || holding.end_price_24 === null
        || holding.end_price_24 === undefined
        || holding.end_price_24 === ''
    ) {
        return null;
    }

    const startPrice = Number(holding.start_price);
    const end24Price = Number(holding.end_price_24);

    if (Number.isNaN(startPrice) || Number.isNaN(end24Price) || startPrice === end24Price) {
        return null;
    }

    return startPrice > end24Price ? 'up' : 'down';
}

function startPriceTickSymbol(holding) {
    const symbols = {
        up: '\u2191',
        down: '\u2193',
    };

    return symbols[startPriceTrend(holding)] ?? null;
}

function startPriceTickLabel(holding) {
    const labels = {
        up: 'Start price higher than End 24 price',
        down: 'Start price lower than End 24 price',
    };

    return labels[startPriceTrend(holding)] ?? null;
}

function startPriceTickClass(holding) {
    return {
        'text-success': startPriceTrend(holding) === 'up',
        'text-error': startPriceTrend(holding) === 'down',
    };
}

function end24PriceTrend(holding) {
    if (
        holding.end_price_24 === null
        || holding.end_price_24 === undefined
        || holding.end_price_24 === ''
        || holding.end_price_48 === null
        || holding.end_price_48 === undefined
        || holding.end_price_48 === ''
    ) {
        return null;
    }

    const end24Price = Number(holding.end_price_24);
    const end48Price = Number(holding.end_price_48);

    if (Number.isNaN(end24Price) || Number.isNaN(end48Price) || end24Price === end48Price) {
        return null;
    }

    return end24Price > end48Price ? 'up' : 'down';
}

function end24PriceTickSymbol(holding) {
    const symbols = {
        up: '\u2191',
        down: '\u2193',
    };

    return symbols[end24PriceTrend(holding)] ?? null;
}

function end24PriceTickLabel(holding) {
    const labels = {
        up: 'End 24 price higher than End 48 price',
        down: 'End 24 price lower than End 48 price',
    };

    return labels[end24PriceTrend(holding)] ?? null;
}

function end24PriceTickClass(holding) {
    return {
        'text-success': end24PriceTrend(holding) === 'up',
        'text-error': end24PriceTrend(holding) === 'down',
    };
}

function formatSessionPrice(value, holding) {
    return formatPriceValue(value, holding.currency);
}

function formatRecentStoredPrice(recentPrice, holding) {
    return formatPriceValue(recentPrice.price, recentPrice.currency ?? holding.currency);
}

function formatPriceChangePercent(changePercent) {
    if (changePercent === null) {
        return '-';
    }

    const sign = changePercent > 0 ? '+' : '';

    return `${sign}${changePercent.toFixed(2)}%`;
}

function priceChangePercentClass(changePercent) {
    return {
        'text-success': changePercent !== null && changePercent > 0,
        'text-error': changePercent !== null && changePercent < 0,
        'text-medium-emphasis': changePercent === null || changePercent === 0,
    };
}

function formatRecentStoredPriceTime(recentPrice) {
    if (!recentPrice.as_of) {
        return '-';
    }

    const date = new Date(recentPrice.as_of);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'Europe/Vienna',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(date);
}

function formatRecentStoredPriceDate(recentPrice) {
    const date = new Date(recentPrice.as_of);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'Europe/Vienna',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    const localDateTime = parseLocalUsDateTime(value);

    if (localDateTime) {
        return localDateTime;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'Europe/Vienna',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).format(date);
}

function formatSourceDateTime(value) {
    if (!value || typeof value !== 'string') {
        return '-';
    }

    const sourceDateTime = parseSourceDateTime(value);

    if (sourceDateTime) {
        return sourceDateTime;
    }

    return formatDateTime(value);
}

function formatSessionHeaderDate(daysAgo) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Europe/Vienna',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(new Date());
    const dateParts = Object.fromEntries(parts.map((part) => [part.type, part.value]));
    const viennaDate = new Date(Date.UTC(
        Number(dateParts.year),
        Number(dateParts.month) - 1,
        Number(dateParts.day) - daysAgo,
    ));

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(viennaDate);
}

function formatSessionHeaderDateValue(value) {
    if (!value) {
        return null;
    }

    const date = new Date(`${value}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return null;
    }

    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(date);
}

function parseSourceDateTime(value) {
    const trimmedValue = value.trim();
    const isoLikeMatch = trimmedValue.match(
        /^(?<year>\d{4})-(?<month>\d{1,2})-(?<day>\d{1,2})(?:[ T](?<hour>\d{1,2}):(?<minute>\d{2})(?::\d{2})?)?(?:\s*(?<timezone>Z|UTC|CET|CEST|Europe\/[A-Za-z_]+|[+-]\d{2}:?\d{2}))?$/i,
    );

    if (isoLikeMatch?.groups) {
        return formatSourceDateTimeParts(isoLikeMatch.groups);
    }

    const europeanMatch = trimmedValue.match(
        /^(?<day>\d{1,2})[.\/-](?<month>\d{1,2})[.\/-](?<year>\d{2,4})(?:\s+(?<hour>\d{1,2}):(?<minute>\d{2})(?::\d{2})?)?(?:\s*(?<timezone>CET|CEST|UTC|Europe\/[A-Za-z_]+))?$/i,
    );

    if (!europeanMatch?.groups) {
        return null;
    }

    return formatSourceDateTimeParts(europeanMatch.groups);
}

function formatSourceDateTimeParts(parts) {
    const year = Number(parts.year.length === 2 ? `20${parts.year}` : parts.year);
    const month = Number(parts.month);
    const day = Number(parts.day);

    if (!isValidDateParts(year, month, day)) {
        return null;
    }

    const dateText = [
        String(day).padStart(2, '0'),
        String(month).padStart(2, '0'),
        String(year),
    ].join('.');

    if (!parts.hour || !parts.minute) {
        return dateText;
    }

    const hour = Number(parts.hour);
    const minute = Number(parts.minute);

    if (hour > 23 || minute > 59) {
        return null;
    }

    const timezone = parts.timezone?.toUpperCase();

    if (timezone === 'UTC' || timezone === 'Z' || /^[+-]\d{2}:?\d{2}$/.test(parts.timezone ?? '')) {
        const offset = timezone === 'UTC' || timezone === 'Z' ? 'Z' : parts.timezone;
        const date = new Date(`${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}T${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}:00${offset}`);

        if (!Number.isNaN(date.getTime())) {
            return new Intl.DateTimeFormat('de-AT', {
                timeZone: 'Europe/Vienna',
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            }).format(date);
        }
    }

    return `${dateText}, ${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function isValidDateParts(year, month, day) {
    return year >= 1900 && month >= 1 && month <= 12 && day >= 1 && day <= 31;
}

function parseLocalUsDateTime(value) {
    if (typeof value !== 'string') {
        return null;
    }

    const match = value
        .trim()
        .match(/^(?<month>\d{1,2})\/(?<day>\d{1,2})\/(?<year>\d{2,4}),?\s+(?<hour>\d{1,2}):(?<minute>\d{2})\s*(?<period>AM|PM)$/i);

    if (!match?.groups) {
        return null;
    }

    const month = Number(match.groups.month);
    const day = Number(match.groups.day);
    const year = Number(match.groups.year);
    const hour = Number(match.groups.hour);
    const minute = Number(match.groups.minute);

    if (month < 1 || month > 12 || day < 1 || day > 31 || hour < 1 || hour > 12 || minute > 59) {
        return null;
    }

    const fullYear = year < 100 ? 2000 + year : year;
    const normalizedHour = match.groups.period.toUpperCase() === 'PM'
        ? (hour === 12 ? 12 : hour + 12)
        : (hour === 12 ? 0 : hour);

    return [
        String(day).padStart(2, '0'),
        String(month).padStart(2, '0'),
        String(fullYear),
    ].join('.') + `, ${String(normalizedHour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
}

function formatLatestPriceSource(holding) {
    return holding.latest_price_source || '-';
}

function formatExchangeTradingTime(exchange) {
    const sessions = exchangeSessions(exchange);

    if (!sessions.length) {
        return '-';
    }

    return sessions
        .map((session) => `${session.open}-${session.close}`)
        .join(', ');
}

function formatExchangeNextTradingText(exchange) {
    if (exchange.error) {
        return '-';
    }

    const tradingState = exchangeTradingState(exchange);

    if (tradingState.isOpen) {
        return tradingState.session ? `Trading closes: ${tradingState.session.close}` : '-';
    }

    const nextTradingDateTime = nextExchangeTradingDateTime(exchange);

    return nextTradingDateTime === '-' ? '-' : `Next trading: ${nextTradingDateTime}`;
}

function formatExchangeStatusText(exchange) {
    return exchangeTradingState(exchange).isOpen ? 'Open' : 'Closed';
}

function exchangeTradingState(exchange) {
    const sessions = exchangeSessions(exchange);
    const exchangeToday = exchangeLocalDateParts(exchange.timezone);

    if (!sessions.length || !exchangeToday) {
        return {
            isOpen: Boolean(exchange.is_open),
            session: sessions.at(-1) ?? null,
        };
    }

    if (
        !exchangeWorkingDays(exchange).includes(exchangeToday.weekday)
        || exchangeHolidayDates(exchange).includes(exchangeDateKey(exchangeToday))
    ) {
        return {
            isOpen: false,
            session: null,
        };
    }

    const currentMinutes = (exchangeToday.hour * 60) + exchangeToday.minute;
    const session = sessions.find((tradingSession) => (
        currentMinutes >= tradingSession.openMinutes
        && currentMinutes < tradingSession.closeMinutes
    ));

    return {
        isOpen: Boolean(session),
        session: session ?? null,
    };
}

function nextExchangeTradingDateTime(exchange) {
    const sessions = exchangeSessions(exchange);

    if (!sessions.length || !exchange.timezone) {
        return '-';
    }

    const workingDays = exchangeWorkingDays(exchange);
    const holidays = exchangeHolidayDates(exchange);
    const exchangeToday = exchangeLocalDateParts(exchange.timezone);

    if (!exchangeToday) {
        return '-';
    }

    const currentMinutes = (exchangeToday.hour * 60) + exchangeToday.minute;

    for (let daysToAdd = 0; daysToAdd < 370; daysToAdd += 1) {
        const nextDate = exchangeDatePartsAfter(exchangeToday, daysToAdd);

        if (!workingDays.includes(nextDate.weekday) || holidays.includes(exchangeDateKey(nextDate))) {
            continue;
        }

        const nextSession = sessions.find((session) => daysToAdd > 0 || currentMinutes < session.openMinutes);

        if (nextSession) {
            return `${formatExchangeDate(nextDate)}, ${nextSession.open}`;
        }
    }

    return '-';
}

function exchangeSessions(exchange) {
    const sessions = Array.isArray(exchange.sessions) ? exchange.sessions : [];
    const normalizedSessions = sessions
        .map((session) => normalizedExchangeSession(session.open, session.close))
        .filter(Boolean);

    if (normalizedSessions.length) {
        return normalizedSessions;
    }

    return [normalizedExchangeSession(exchange.open, exchange.close)].filter(Boolean);
}

function normalizedExchangeSession(openValue, closeValue) {
    const open = formatClock(openValue);
    const close = formatClock(closeValue);
    const openMinutes = clockTotalMinutes(open);
    const closeMinutes = clockTotalMinutes(close);

    if (openMinutes === null || closeMinutes === null || openMinutes >= closeMinutes) {
        return null;
    }

    return {
        open,
        close,
        openMinutes,
        closeMinutes,
    };
}

function clockTotalMinutes(value) {
    if (!value || value === '-') {
        return null;
    }

    const [hours, minutes] = value.split(':').map((part) => Number(part));

    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
        return null;
    }

    return (hours * 60) + minutes;
}

function exchangeHolidayDates(exchange) {
    if (!Array.isArray(exchange.holidays)) {
        return [];
    }

    return exchange.holidays
        .map((holiday) => String(holiday).trim())
        .filter(Boolean);
}

function exchangeDatePartsAfter(exchangeToday, daysToAdd) {
    const date = new Date(Date.UTC(
        exchangeToday.year,
        exchangeToday.month - 1,
        exchangeToday.day + daysToAdd,
    ));

    return {
        year: date.getUTCFullYear(),
        month: date.getUTCMonth() + 1,
        day: date.getUTCDate(),
        weekday: weekdayAfter(exchangeToday.weekday, daysToAdd),
    };
}

function exchangeDateKey(exchangeDate) {
    return [
        String(exchangeDate.year),
        String(exchangeDate.month).padStart(2, '0'),
        String(exchangeDate.day).padStart(2, '0'),
    ].join('-');
}

function formatExchangeDate(exchangeDate) {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(Date.UTC(exchangeDate.year, exchangeDate.month - 1, exchangeDate.day)));
}

function exchangeLocalDateParts(timezone) {
    try {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: timezone,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit',
            weekday: 'short',
            hourCycle: 'h23',
        }).formatToParts(new Date());
        const dateParts = Object.fromEntries(parts.map((part) => [part.type, part.value]));

        return {
            year: Number(dateParts.year),
            month: Number(dateParts.month),
            day: Number(dateParts.day),
            hour: Number(dateParts.hour),
            minute: Number(dateParts.minute),
            weekday: dateParts.weekday,
        };
    } catch {
        return null;
    }
}

function exchangeWorkingDays(exchange) {
    if (!exchange.working_days) {
        return ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
    }

    return String(exchange.working_days)
        .split(',')
        .map((day) => day.trim())
        .filter(Boolean);
}

function weekdayAfter(weekday, daysToAdd) {
    const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const weekdayIndex = weekdays.indexOf(weekday);

    if (weekdayIndex === -1) {
        return weekday;
    }

    return weekdays[(weekdayIndex + daysToAdd) % weekdays.length];
}

function formatClock(value) {
    if (!value) {
        return '-';
    }

    const [hours, minutes] = String(value).split(':');

    if (!hours || !minutes) {
        return '-';
    }

    return `${hours.padStart(2, '0')}:${minutes.padStart(2, '0')}`;
}

function formatScheduleDateTime(value) {
    return formatDateTime(value);
}

function formatPriceStatus(holding) {
    const labels = {
        realtime: 'Realtime',
        fresh: 'Fresh',
        delayed: 'Delayed',
        closed_market: 'Closed Market',
        stale: 'Stale',
        unavailable: 'Unavailable',
        unavailable_now: 'Unavailable Now',
        suspicious: 'Suspicious',
        missing: 'Missing',
    };

    return labels[holding.latest_price_status] ?? labels[holding.price_status] ?? holding.latest_price_status ?? '-';
}

function formatPriceType(holding) {
    const labels = {
        indicative_mid: 'Mid',
        last: 'Last',
        close: 'Close',
        nav: 'NAV',
        unavailable: 'Unavailable',
    };

    return labels[holding.price_type] ?? holding.price_type ?? '-';
}

function formatSpread(holding) {
    if (holding.price_spread_pct === null || holding.price_spread_pct === undefined || holding.price_spread_pct === '') {
        return '-';
    }

    const amount = Number(holding.price_spread_pct);

    return Number.isNaN(amount) ? `${holding.price_spread_pct}%` : `${amount.toFixed(2)}%`;
}

function formatValidationErrors(holding) {
    if (!Array.isArray(holding.validation_errors) || holding.validation_errors.length === 0) {
        return '';
    }

    return holding.validation_errors.join('\n');
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text);
}

function clearSectionMessages() {
    profileMessage.value = '';
    profileError.value = '';
    depotMessage.value = '';
    depotError.value = '';
    holdingMessage.value = '';
    holdingError.value = '';
    priceRefreshScheduleMessage.value = '';
    priceRefreshScheduleError.value = '';
    isPriceRefreshScheduleEditing.value = false;
    isIndexPriceRefreshScheduleEditing.value = false;
    userMessage.value = '';
    userError.value = '';
    roleMessage.value = '';
    roleError.value = '';
}

function emptyUserForm() {
    return {
        last_name: '',
        first_name: '',
        email: '',
        roles: ['admin'],
    };
}

function emptyRoleForm() {
    return {
        name: '',
    };
}

function emptyDepotForm() {
    return {
        name: '',
        account_balance: '0.00',
    };
}

function emptyCashTransactionForm() {
    return {
        type: 'deposit',
        total_amount: '',
        booked_at: localDateInputValue(),
        note: '',
    };
}

function emptyStockTransactionForm() {
    return {
        type: 'buy',
        stock_holding_id: null,
        pieces: '',
        total_amount: '',
        booked_at: localDateInputValue(),
        note: '',
    };
}

function localDateInputValue(date = new Date()) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
}

function emptyPriceRefreshScheduleForm() {
    return {
        trading_interval_minutes: 20,
        trading_starts_before_minutes: 0,
        trading_ends_after_minutes: 0,
        closed_refresh_enabled: true,
        closed_interval_minutes: 60,
    };
}

function priceRefreshScheduleFormFromSettings(settings) {
    return {
        trading_interval_minutes: settings?.trading_interval_minutes ?? 20,
        trading_starts_before_minutes: settings?.trading_starts_before_minutes ?? 0,
        trading_ends_after_minutes: settings?.trading_ends_after_minutes ?? 0,
        closed_refresh_enabled: settings?.closed_refresh_enabled ?? true,
        closed_interval_minutes: settings?.closed_interval_minutes ?? 60,
    };
}

function emptyIntradayBackfillScheduleForm() {
    return {
        daily_time: '18:30',
    };
}

function intradayBackfillScheduleFormFromSettings(settings) {
    return {
        daily_time: settings?.daily_time ?? '18:30',
    };
}
</script>

<template>
    <v-app>
        <template v-if="isLoginPage">
            <v-main class="login-screen">
                <v-container class="login-container">
                    <v-card class="login-card" elevation="0">
                        <v-card-title>GKStocks admin</v-card-title>
                        <v-card-subtitle>Sign in to manage your workspace.</v-card-subtitle>

                        <v-card-text>
                            <v-alert v-if="notice" type="success" variant="tonal" density="compact" class="mb-4">
                                {{ notice }}
                            </v-alert>
                            <v-alert v-if="localError || error" type="error" variant="tonal" density="compact" class="mb-4">
                                {{ localError || error }}
                            </v-alert>

                            <v-tabs v-model="loginMode" class="mb-5">
                                <v-tab value="password">Password</v-tab>
                                <v-tab value="code">Code</v-tab>
                            </v-tabs>

                            <v-window v-model="loginMode">
                                <v-window-item value="password">
                                    <form @submit.prevent="submitPasswordLogin">
                                        <v-text-field
                                            v-model="email"
                                            autocomplete="email"
                                            label="Email"
                                            prepend-inner-icon="mdi-email-outline"
                                            required
                                        />
                                        <v-text-field
                                            v-model="loginPassword"
                                            autocomplete="current-password"
                                            label="Password"
                                            prepend-inner-icon="mdi-lock-outline"
                                            required
                                            type="password"
                                        />
                                        <v-btn block color="primary" type="submit" variant="flat" :loading="loading">
                                            Sign in
                                        </v-btn>
                                    </form>
                                </v-window-item>

                                <v-window-item value="code">
                                    <form class="mb-5" @submit.prevent="requestLoginCode">
                                        <v-text-field
                                            v-model="email"
                                            autocomplete="email"
                                            label="Email"
                                            prepend-inner-icon="mdi-email-outline"
                                            required
                                        />
                                        <v-btn block color="primary" type="submit" variant="tonal" :loading="loading">
                                            Send code
                                        </v-btn>
                                    </form>

                                    <form @submit.prevent="verifyLoginCode">
                                        <v-text-field
                                            v-model="code"
                                            inputmode="numeric"
                                            label="6-digit code"
                                            maxlength="6"
                                            prepend-inner-icon="mdi-numeric"
                                            required
                                        />
                                        <v-btn block color="primary" type="submit" variant="flat" :loading="loading">
                                            Verify code
                                        </v-btn>
                                    </form>
                                </v-window-item>
                            </v-window>
                        </v-card-text>
                    </v-card>
                </v-container>
            </v-main>
        </template>

        <template v-else>
            <v-navigation-drawer
                class="dashboard-navigation-drawer"
                :class="{ 'dashboard-navigation-drawer--compact': isDashboardMenuCompact }"
                permanent
                :width="isDashboardMenuCompact ? 64 : 272"
            >
                <div class="dashboard-navigation-header pa-6">
                    <div
                        class="d-flex align-center ga-3"
                        :class="{ 'justify-center': isDashboardMenuCompact }"
                    >
                        <img
                            class="dashboard-brand-mark"
                            :src="logoMarkUrl"
                            alt="GKStocks"
                        >
                        <div v-if="!isDashboardMenuCompact" class="dashboard-brand-copy">
                            <strong>GKStocks</strong>
                            <div class="dashboard-brand-version text-medium-emphasis">{{ appVersionLabel }}</div>
                        </div>
                    </div>
                </div>

                <div v-if="isDashboardMenuCompact" class="dashboard-compact-menu">
                    <button
                        v-for="item in menuItems"
                        :key="item.key"
                        type="button"
                        class="dashboard-compact-menu-item"
                        :class="{ 'dashboard-compact-menu-item--active': item.children ? item.children.some(c => activeSection === c.key) : activeSection === item.key }"
                        :aria-label="item.label"
                        :title="item.label"
                        @click="item.children ? navigateSection(item.children[0].key) : navigateSection(item.key)"
                    >
                        <v-icon :icon="item.icon" />
                    </button>
                </div>

                <v-list v-else nav>
                    <template v-for="item in menuItems" :key="item.key">
                        <v-list-item
                            :active="item.children ? item.children.some(c => activeSection === c.key) : activeSection === item.key"
                            :prepend-icon="item.icon"
                            :title="item.label"
                            :subtitle="item.subtitle ?? undefined"
                            @click="item.children ? navigateSection(item.children[0].key) : navigateSection(item.key)"
                        />
                    </template>
                </v-list>
            </v-navigation-drawer>

            <v-app-bar flat border>
                <div class="app-bar-row">
                    <v-btn
                        class="dashboard-menu-toggle"
                        density="comfortable"
                        icon
                        size="small"
                        type="button"
                        variant="text"
                        :aria-label="dashboardMenuToggleLabel"
                        :title="dashboardMenuToggleLabel"
                        @click="toggleDashboardMenuCompact"
                    >
                        <v-icon :icon="isDashboardMenuCompact ? 'mdi-chevron-right' : 'mdi-chevron-left'" />
                    </v-btn>
                    <v-spacer />
                    <v-btn href="/admin/logout" prepend-icon="mdi-logout" size="small" variant="text">
                        Logout
                    </v-btn>
                </div>
            </v-app-bar>

            <v-main>
                <v-container class="py-8" :fluid="lgAndDown">
                    <section v-if="activeSection === 'dashboard'">
                        <v-card v-if="!smAndDown" border flat class="dashboard-status-card mb-6">
                            <v-card-text class="dashboard-status-card-content">
                                <span v-if="priceRefreshSettings" class="dashboard-status-item text-caption text-medium-emphasis">
                                    Stocks Last: {{ formatScheduleDateTime(priceRefreshSettings.last_refreshed_at) }}
                                    · Next: {{ formatScheduleDateTime(priceRefreshSettings.next_refresh_at) }}
                                    <span
                                        class="d-inline-flex align-center ga-1 ml-1"
                                        :class="isHeaderStatusUpdating ? 'text-error' : 'text-medium-emphasis'"
                                    >
                                        <span
                                            class="price-refresh-status-dot"
                                            :class="isHeaderStatusUpdating ? 'price-refresh-status-dot--updating' : 'price-refresh-status-dot--waiting'"
                                        />
                                        {{ priceRefreshHeaderStatusLabel }}
                                    </span>
                                </span>
                                <span v-if="indexPriceRefreshSettings" class="dashboard-status-item text-caption text-medium-emphasis">
                                    Indices Last: {{ formatScheduleDateTime(indexPriceRefreshSettings.last_refreshed_at) }}
                                    · Next: {{ formatScheduleDateTime(indexPriceRefreshSettings.next_refresh_at) }}
                                    <span
                                        class="d-inline-flex align-center ga-1 ml-1"
                                        :class="isAutomaticIndexPriceRefreshUpdating ? 'text-error' : 'text-medium-emphasis'"
                                    >
                                        <span
                                            class="price-refresh-status-dot"
                                            :class="isAutomaticIndexPriceRefreshUpdating ? 'price-refresh-status-dot--updating' : 'price-refresh-status-dot--waiting'"
                                        />
                                        {{ indexPriceRefreshHeaderStatusLabel }}
                                    </span>
                                </span>
                                <span
                                    class="dashboard-status-item text-caption queue-header-status"
                                    :class="queueStatusClass"
                                    :title="queueStatusTitle"
                                >
                                    <span
                                        class="price-refresh-status-dot"
                                        :class="queueStatus?.status === 'ok' && !queueStatusError ? 'price-refresh-status-dot--waiting' : 'price-refresh-status-dot--updating'"
                                    />
                                    {{ queueStatusLabel }}
                                </span>
                            </v-card-text>
                        </v-card>
                        <div class="dashboard-heading mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Dashboard</p>
                                <h1 class="text-h4">Watch-list</h1>
                            </div>
                            <div class="dashboard-actions">
                                <v-btn
                                    class="dashboard-action-button"
                                    color="error"
                                    prepend-icon="mdi-delete-sweep-outline"
                                    variant="tonal"
                                    :disabled="!canClearQueue"
                                    :loading="queueStatusLoading"
                                    :title="queueClearButtonLabel"
                                    @click="clearQueue"
                                >
                                    Clear queue
                                </v-btn>
                                <v-btn
                                    class="dashboard-action-button"
                                    color="primary"
                                    prepend-icon="mdi-file-pdf-box"
                                    variant="outlined"
                                    :disabled="holdings.length === 0"
                                    @click="exportHoldingsPdf"
                                >
                                    Export PDF
                                </v-btn>
                                <v-btn
                                    class="dashboard-action-button"
                                    color="primary"
                                    prepend-icon="mdi-refresh"
                                    variant="tonal"
                                    :disabled="isAutomaticPriceRefreshUpdating"
                                    :loading="holdingsLoading && !isPriceRefreshRunning"
                                    @click="refreshHoldingPrices"
                                >
                                    {{ isPriceRefreshRunning ? `Refreshing ${priceRefresh.step}` : 'Refresh prices' }}
                                </v-btn>
                                <v-btn
                                    class="dashboard-action-button"
                                    color="primary"
                                    prepend-icon="mdi-plus"
                                    variant="flat"
                                    @click="openHoldingDialog"
                                >
                                    Add stock
                                </v-btn>
                            </div>
                        </div>

                        <v-alert v-if="visibleHoldingMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ visibleHoldingMessage }}
                        </v-alert>
                        <div class="index-watch-strip mb-4">
                            <button
                                v-for="indexItem in indexWatchItems"
                                :key="indexItem.id"
                                type="button"
                                class="index-watch-card"
                                @click="openIndexPriceDialog(indexItem)"
                            >
                                <span class="index-watch-card-header">
                                    <span class="index-watch-card-symbol">{{ indexItem.symbol }}</span>
                                    <span class="index-watch-card-country">{{ indexItem.country || '-' }}</span>
                                </span>
                                <span class="index-watch-card-label">{{ indexItem.name || 'Index' }}</span>
                                <span class="index-watch-card-price" :class="indexChangeClass(indexItem)">
                                    <span v-if="formatIndexChangePercent(indexItem)">
                                        {{ formatIndexChangePercent(indexItem) }}
                                    </span>
                                    <span>{{ formatIndexPrice(indexItem) }}</span>
                                </span>
                            </button>
                            <button type="button" class="index-add-tile" @click="openIndexDialog">
                                <span class="index-add-tile-plus">+</span>
                                <span class="index-add-tile-label">INDEX</span>
                            </button>
                        </div>
                        <v-alert
                            v-if="isPriceRefreshRunning"
                            type="info"
                            variant="tonal"
                            density="compact"
                            class="mb-4"
                        >
                            <div class="d-flex align-center justify-space-between ga-4">
                                <span>Price refresh: {{ priceRefresh.step }}</span>
                                <span v-if="priceRefresh.current">{{ priceRefresh.current }}</span>
                            </div>
                            <v-progress-linear
                                class="mt-2"
                                color="primary"
                                height="6"
                                rounded
                                :indeterminate="priceRefresh.status === 'queued'"
                                :model-value="priceRefreshProgressValue"
                            />
                        </v-alert>
                        <v-alert v-if="holdingError || holdingsError" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ holdingError || holdingsError }}
                        </v-alert>
                        <div class="mobile-watch-list">
                            <div v-if="!holdingsLoading && holdings.length === 0" class="mobile-stock-card">
                                No stocks in the watch-list.
                            </div>
                            <article
                                v-for="holding in holdings"
                                :key="`mobile-holding-${holding.id}`"
                                class="mobile-stock-card"
                            >
                                <div class="mobile-stock-name">
                                    {{ holding.name || holding.symbol || '-' }}
                                </div>
                                <div class="mobile-stock-price-row">
                                    <span
                                        class="latest-price-value mobile-stock-price"
                                        :class="mobileHoldingPriceClass(holding)"
                                    >
                                        <span>{{ formatMobileHoldingPrice(holding) }}</span>
                                        <span
                                            v-if="mobileHoldingPriceChangeText(holding)"
                                            class="mobile-stock-price-change"
                                        >
                                            {{ mobileHoldingPriceChangeText(holding) }}
                                        </span>
                                    </span>
                                </div>
                                <div class="mobile-stock-actions">
                                    <v-btn
                                        aria-label="Add"
                                        color="success"
                                        icon="mdi-cart-plus"
                                        size="small"
                                        variant="tonal"
                                        :disabled="!activeDepot || holdingsLoading"
                                        @click="openStockTransactionDialog(holding, 'buy')"
                                    />
                                    <v-btn
                                        aria-label="Withdraw"
                                        color="warning"
                                        icon="mdi-cart-minus"
                                        size="small"
                                        variant="tonal"
                                        :disabled="!activeDepot || holdingsLoading || !hasPositionPieces(holding)"
                                        @click="openStockTransactionDialog(holding, 'sell')"
                                    />
                                    <v-btn
                                        aria-label="Delete"
                                        color="error"
                                        icon="mdi-delete-outline"
                                        size="small"
                                        variant="tonal"
                                        :disabled="holdingsLoading || hasPositionPieces(holding)"
                                        @click="openDeleteHoldingDialog(holding)"
                                    />
                                </div>
                            </article>
                        </div>
                        <v-table class="desktop-watch-list-table">
                            <thead>
                                <tr>
                                    <th v-if="!isCompactWatchListTable">Symbol</th>
                                    <th>Name</th>
                                    <th v-if="isHandsetLandscape">Price</th>
                                    <th v-else>Latest price</th>
                                    <th>
                                        <span class="d-inline-flex flex-column">
                                            <span>Start price</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.start }}
                                            </span>
                                        </span>
                                    </th>
                                    <th v-if="!isHandsetLandscape">
                                        <span class="d-inline-flex flex-column">
                                            <span>End price</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.end }}
                                            </span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="d-inline-flex flex-column">
                                            <span>End 24</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.end24 }}
                                            </span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="d-inline-flex flex-column">
                                            <span>End 48</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.end48 }}
                                            </span>
                                        </span>
                                    </th>
                                    <th v-if="!isCompactWatchListTable">Source time</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!holdingsLoading && holdings.length === 0">
                                    <td :colspan="watchListTableColumnCount">No stocks in the watch-list.</td>
                                </tr>
                                <template v-for="holding in holdings" :key="holding.id">
                                    <tr
                                        class="stock-holding-row"
                                        :aria-expanded="isHoldingExpanded(holding)"
                                        tabindex="0"
                                        @click="toggleHoldingDetails(holding)"
                                        @keydown.enter.prevent="toggleHoldingDetails(holding)"
                                        @keydown.space.prevent="toggleHoldingDetails(holding)"
                                    >
                                        <td v-if="!isCompactWatchListTable">
                                            <div>{{ holding.symbol || '-' }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                Exchange: {{ holding.exchange || '-' }}
                                            </div>
                                            <div class="text-caption text-medium-emphasis">
                                                Pieces: {{ formatPositionPieces(holding) }}
                                            </div>
                                        </td>
                                        <td>
                                            <div>{{ holding.name || '-' }}</div>
                                            <div
                                                v-if="isCompactWatchListTable"
                                                class="text-caption text-medium-emphasis"
                                            >
                                                Pieces: {{ formatPositionPieces(holding) }}
                                            </div>
                                            <div v-else class="text-caption text-medium-emphasis">
                                                {{ holding.isin || '-' }} · WKN: {{ holding.wkn || '-' }}
                                            </div>
                                            <div
                                                v-if="recentPriceTrendDots(holding).length"
                                                class="recent-price-trend-dots"
                                                aria-label="Recent price trends"
                                            >
                                                <span
                                                    v-for="recentPrice in recentPriceTrendDots(holding)"
                                                    :key="`trend-dot-${recentPrice.id}`"
                                                    class="recent-price-trend-dot"
                                                    :class="recentStoredPriceTrendDotClass(recentPrice)"
                                                    :title="recentStoredPriceTrendLabel(recentPrice)"
                                                />
                                            </div>
                                        </td>
                                        <td v-if="isHandsetLandscape">
                                            <span class="latest-price-value d-inline-flex flex-column" :class="mobileHoldingPriceClass(holding)">
                                                <span>{{ formatMobileHoldingPrice(holding) }}</span>
                                                <span
                                                    v-if="mobileHoldingPriceChangeText(holding)"
                                                    class="latest-price-change"
                                                >
                                                    {{ mobileHoldingPriceChangeText(holding) }}
                                                </span>
                                            </span>
                                        </td>
                                        <td v-else>
                                            <span class="latest-price-value d-inline-flex flex-column" :class="latestPriceClass(holding)">
                                                <span class="d-inline-flex align-center ga-1">
                                                    <span>{{ formatLatestPrice(holding) }}</span>
                                                    <span
                                                        v-if="latestPriceTickSymbol(holding)"
                                                        class="latest-price-tick"
                                                        :aria-label="latestPriceTickLabel(holding)"
                                                        :title="latestPriceTickLabel(holding)"
                                                    >
                                                        {{ latestPriceTickSymbol(holding) }}
                                                    </span>
                                                </span>
                                                <span
                                                    v-if="formatLatestPriceChangePercent(holding)"
                                                    class="latest-price-change"
                                                >
                                                    {{ formatLatestPriceChangePercent(holding) }}
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-inline-flex align-center ga-1">
                                                <span>{{ formatSessionPrice(holding.start_price, holding) }}</span>
                                                <span
                                                    v-if="startPriceTickSymbol(holding)"
                                                    class="latest-price-tick"
                                                    :class="startPriceTickClass(holding)"
                                                    :aria-label="startPriceTickLabel(holding)"
                                                    :title="startPriceTickLabel(holding)"
                                                >
                                                    {{ startPriceTickSymbol(holding) }}
                                                </span>
                                            </span>
                                        </td>
                                        <td v-if="!isHandsetLandscape">
                                            <span class="latest-price-value d-inline-flex flex-column" :class="endPriceValueClass(holding)">
                                                <span>{{ formatSessionPrice(holding.end_price, holding) }}</span>
                                                <span
                                                    v-if="formatEndPriceChangePercent(holding)"
                                                    class="session-price-change"
                                                >
                                                    {{ formatEndPriceChangePercent(holding) }}
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-inline-flex flex-column">
                                                <span class="d-inline-flex align-center ga-1">
                                                    <span>{{ formatSessionPrice(holding.end_price_24, holding) }}</span>
                                                    <span
                                                        v-if="end24PriceTickSymbol(holding)"
                                                        class="latest-price-tick"
                                                        :class="end24PriceTickClass(holding)"
                                                        :aria-label="end24PriceTickLabel(holding)"
                                                        :title="end24PriceTickLabel(holding)"
                                                    >
                                                        {{ end24PriceTickSymbol(holding) }}
                                                    </span>
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-inline-flex flex-column">
                                                <span>{{ formatSessionPrice(holding.end_price_48, holding) }}</span>
                                            </span>
                                        </td>
                                        <td v-if="!isCompactWatchListTable">
                                            <div>{{ formatSourceDateTime(holding.latest_price_as_of) }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                <a
                                                    v-if="holding.latest_price_source_url"
                                                    :href="holding.latest_price_source_url"
                                                    rel="noopener noreferrer"
                                                    target="_blank"
                                                    @click.stop
                                                >
                                                    {{ formatLatestPriceSource(holding) }}
                                                </a>
                                                <span v-else>{{ formatLatestPriceSource(holding) }}</span>
                                            </div>
                                        </td>
                                        <td class="text-right">
                                            <v-btn
                                                icon
                                                variant="text"
                                                color="success"
                                                aria-label="Buy stock"
                                                :disabled="!activeDepot || holdingsLoading"
                                                @click.stop="openStockTransactionDialog(holding, 'buy')"
                                            >
                                                <v-icon icon="mdi-cart-plus" />
                                            </v-btn>
                                            <v-btn
                                                icon
                                                variant="text"
                                                color="warning"
                                                aria-label="Sell stock"
                                                :disabled="!activeDepot || holdingsLoading || !hasPositionPieces(holding)"
                                                @click.stop="openStockTransactionDialog(holding, 'sell')"
                                            >
                                                <v-icon icon="mdi-cart-minus" />
                                            </v-btn>
                                            <v-btn
                                                icon
                                                variant="text"
                                                color="error"
                                                aria-label="Delete stock"
                                                :disabled="holdingsLoading || hasPositionPieces(holding)"
                                                @click.stop="openDeleteHoldingDialog(holding)"
                                            >
                                                <v-icon icon="mdi-delete-outline" />
                                            </v-btn>
                                        </td>
                                    </tr>
                                    <tr v-if="isHoldingExpanded(holding)" class="stock-holding-detail-row">
                                        <td :colspan="watchListTableColumnCount">
                                            <div
                                                v-if="expandedHoldingIntradayLoading(holding)"
                                                class="d-flex align-center ga-3 text-body-2 text-medium-emphasis"
                                            >
                                                <v-progress-circular color="primary" indeterminate size="18" width="2" />
                                                <span>Loading intraday prices...</span>
                                            </div>
                                            <v-alert
                                                v-else-if="expandedHoldingIntradayError(holding)"
                                                type="warning"
                                                variant="tonal"
                                                density="compact"
                                            >
                                                {{ expandedHoldingIntradayError(holding) }}
                                            </v-alert>
                                            <div
                                                v-else-if="expandedHoldingIntradayRows(holding).length"
                                                class="holding-intraday-detail"
                                            >
                                                <div class="holding-intraday-detail-header">
                                                    <span>{{ expandedHoldingIntradayTitle(holding) }}</span>
                                                    <span class="text-caption text-medium-emphasis">
                                                        {{ expandedHoldingIntradayRows(holding).length }} rows
                                                    </span>
                                                </div>
                                                <div class="holding-intraday-table-wrap">
                                                    <table class="holding-intraday-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Time</th>
                                                                <th class="text-right">Open</th>
                                                                <th class="text-right">High</th>
                                                                <th class="text-right">Low</th>
                                                                <th class="text-right">Close</th>
                                                                <th class="text-right">Volume</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr
                                                                v-for="row in expandedHoldingIntradayRows(holding)"
                                                                :key="`${holding.id}-${row.timestamp ?? row.datetime}`"
                                                            >
                                                                <td>{{ formatAnalyzeIntradayCandleDateTime(row) }}</td>
                                                                <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.open) }}</td>
                                                                <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.high) }}</td>
                                                                <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.low) }}</td>
                                                                <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.close) }}</td>
                                                                <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.volume) }}</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <span v-else class="text-body-2 text-medium-emphasis">
                                                No EODHD intraday prices available for this session.
                                            </span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </v-table>

                        <v-sheet
                            v-if="
                                !smAndDown &&
                                (exchangeTradingTimes.length || exchangeTradingTimesLoading || exchangeTradingTimesError)
                            "
                            border
                            rounded
                            class="pa-4 mt-4"
                        >
                            <div class="d-flex align-center justify-space-between ga-4 mb-3">
                                <div>
                                    <div class="text-caption text-medium-emphasis">EODHD exchange details</div>
                                    <div class="text-body-2 font-weight-medium">Exchange trading times</div>
                                </div>
                                <v-progress-circular
                                    v-if="exchangeTradingTimesLoading"
                                    color="primary"
                                    indeterminate
                                    size="20"
                                    width="2"
                                />
                            </div>
                            <v-alert
                                v-if="exchangeTradingTimesError"
                                type="warning"
                                variant="tonal"
                                density="compact"
                                class="mb-3"
                            >
                                {{ exchangeTradingTimesError }}
                            </v-alert>
                            <v-table v-if="exchangeTradingTimes.length" density="compact">
                                <thead>
                                    <tr>
                                        <th>Exchange</th>
                                        <th>MIC</th>
                                        <th>Local time</th>
                                        <th>Next trading</th>
                                        <th>Days</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="exchange in exchangeTradingTimes" :key="exchange.code">
                                        <td>
                                            <div>{{ exchange.code }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                {{ exchange.name || '-' }}
                                            </div>
                                        </td>
                                        <td>
                                            <div>{{ exchange.operating_mic || '-' }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                {{ exchange.timezone || '-' }}
                                            </div>
                                        </td>
                                        <td>{{ formatExchangeTradingTime(exchange) }}</td>
                                        <td>{{ formatExchangeNextTradingText(exchange) }}</td>
                                        <td>{{ exchange.working_days || '-' }}</td>
                                        <td>
                                            <span v-if="exchange.error" class="text-warning">
                                                Unavailable
                                            </span>
                                            <span v-else>
                                                {{ formatExchangeStatusText(exchange) }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                        </v-sheet>

                        <v-progress-linear v-if="holdingsLoading" indeterminate color="primary" class="mt-4" />

                        <v-pagination
                            v-if="holdingsPagination.last_page > 1"
                            v-model="holdingsPagination.current_page"
                            class="mt-6"
                            :length="holdingsPagination.last_page"
                            @update:model-value="depotsStore.loadWatchlistHoldings"
                        />

                        <v-dialog v-model="isHoldingDialogOpen" persistent max-width="900">
                            <v-card>
                                <v-card-title>Add stock</v-card-title>
                                <v-card-text>
                                    <form
                                        id="holding-search-form"
                                        class="d-flex align-center ga-3 mb-5"
                                        @submit.prevent="searchStocks"
                                        @keydown.capture="handleHoldingSearchKeydown"
                                    >
                                        <v-text-field
                                            ref="holdingSearchInput"
                                            v-model="holdingSearchQuery"
                                            density="comfortable"
                                            hide-details
                                            label="ISIN, WKN, Valor, symbol, or name"
                                            required
                                        />
                                        <v-btn type="submit" color="primary" variant="tonal" :loading="stockSearchLoading">
                                            Search
                                        </v-btn>
                                    </form>

                                    <v-alert v-if="stockSearchError" type="error" variant="tonal" density="compact" class="mb-4">
                                        {{ stockSearchError }}
                                    </v-alert>

                                    <v-table v-if="stockSearchResults.length > 0">
                                        <thead>
                                            <tr>
                                                <th>Symbol</th>
                                                <th>Name</th>
                                                <th>ISIN</th>
                                                <th>WKN / Valor</th>
                                                <th>Exchange</th>
                                                <th>Type</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="result in stockSearchResults" :key="`${result.symbol}-${result.exchange}-${result.mic_code}`">
                                                <td>{{ result.symbol }}</td>
                                                <td>{{ result.name }}</td>
                                                <td>{{ result.isin || '-' }}</td>
                                                <td>{{ result.wkn || result.valor || '-' }}</td>
                                                <td>{{ result.exchange }}</td>
                                                <td>{{ result.instrument_type }}</td>
                                                <td class="text-right">
                                                    <v-btn
                                                        size="small"
                                                        color="primary"
                                                        variant="text"
                                                        :loading="holdingsLoading"
                                                        @click="saveHolding(result)"
                                                    >
                                                        Add
                                                    </v-btn>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortHoldingDialog">
                                        Cancel
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-model="isIndexDialogOpen" persistent max-width="900">
                            <v-card>
                                <v-card-title>Add index</v-card-title>
                                <v-card-text>
                                    <form
                                        id="index-search-form"
                                        class="d-flex align-center ga-3 mb-5"
                                        @submit.prevent="searchIndexes"
                                    >
                                        <v-text-field
                                            ref="indexSearchInput"
                                            v-model="indexSearchQuery"
                                            density="comfortable"
                                            hide-details
                                            label="ISIN, symbol, or name"
                                            required
                                        />
                                        <v-btn type="submit" color="primary" variant="tonal" :loading="stockSearchLoading">
                                            Search
                                        </v-btn>
                                    </form>

                                    <v-alert v-if="stockSearchError || indexError" type="error" variant="tonal" density="compact" class="mb-4">
                                        {{ stockSearchError || indexError }}
                                    </v-alert>

                                    <v-alert v-if="indexMessage" type="success" variant="tonal" density="compact" class="mb-4">
                                        {{ indexMessage }}
                                    </v-alert>

                                    <v-table v-if="stockSearchResults.length > 0">
                                        <thead>
                                            <tr>
                                                <th>Symbol</th>
                                                <th>Name</th>
                                                <th>ISIN</th>
                                                <th>WKN / Valor</th>
                                                <th>Exchange</th>
                                                <th>Type</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="result in stockSearchResults" :key="`index-${result.symbol}-${result.exchange}-${result.mic_code}`">
                                                <td>{{ result.symbol }}</td>
                                                <td>{{ result.name }}</td>
                                                <td>{{ result.isin || '-' }}</td>
                                                <td>{{ result.wkn || result.valor || '-' }}</td>
                                                <td>{{ result.exchange }}</td>
                                                <td>{{ result.instrument_type }}</td>
                                                <td class="text-right">
                                                    <v-btn
                                                        size="small"
                                                        color="primary"
                                                        variant="text"
                                                        :loading="holdingsLoading"
                                                        @click="saveIndexWatchItem(result)"
                                                    >
                                                        Add
                                                    </v-btn>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortIndexDialog">
                                        Cancel
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-model="isIndexPriceDialogOpen" persistent max-width="900">
                            <v-card v-if="selectedIndexWatchItem">
                                <v-card-title class="index-price-dialog-title">
                                    <span>
                                        <span class="index-price-dialog-symbol">
                                            {{ selectedIndexWatchItem.symbol }}
                                        </span>
                                        <span class="index-price-dialog-country">
                                            {{ selectedIndexWatchItem.country || '-' }}
                                        </span>
                                    </span>
                                    <span class="index-price-dialog-name">
                                        {{ selectedIndexWatchItem.name || 'Index' }}
                                    </span>
                                    <span v-if="selectedIndexWatchItem.eodhd_code" class="index-price-dialog-code">
                                        {{ selectedIndexWatchItem.eodhd_code }}
                                        <button
                                            type="button"
                                            class="index-price-dialog-code-copy"
                                            aria-label="Copy code"
                                            @click.stop="copyToClipboard(selectedIndexWatchItem.eodhd_code)"
                                        >
                                            <v-icon icon="mdi-content-copy" size="12" />
                                        </button>
                                    </span>
                                </v-card-title>
                                <v-card-text>
                                    <v-progress-linear
                                        v-if="isIndexPriceDialogLoading"
                                        class="mb-4"
                                        color="primary"
                                        indeterminate
                                    />
                                    <v-alert
                                        v-if="indexPriceDialogError"
                                        class="mb-4"
                                        type="error"
                                        variant="tonal"
                                        density="compact"
                                    >
                                        {{ indexPriceDialogError }}
                                    </v-alert>
                                    <div class="index-price-current mb-4">
                                        <span class="text-caption text-medium-emphasis">Actual price</span>
                                        <span class="index-price-current-value" :class="indexChangeClass(selectedIndexWatchItem)">
                                            <span v-if="formatIndexChangePercent(selectedIndexWatchItem)">
                                                {{ formatIndexChangePercent(selectedIndexWatchItem) }}
                                            </span>
                                            <span>{{ formatIndexDialogActualPrice(selectedIndexWatchItem) }}</span>
                                        </span>
                                    </div>

                                    <div
                                        v-if="selectedIndexRecentPrices.length"
                                        class="index-price-history-table-wrap"
                                    >
                                        <v-table
                                            class="index-price-history-table"
                                            density="compact"
                                        >
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th class="text-right">Start</th>
                                                    <th class="text-right">Last</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="(price, index) in selectedIndexRecentPrices"
                                                    :key="`price-${price.trading_date || index}`"
                                                >
                                                    <td>{{ formatIndexHistoryDate(price.trading_date) }}</td>
                                                    <td class="text-right">
                                                        {{ formatIndexHistoryPrice(price.start_price, selectedIndexWatchItem) }}
                                                    </td>
                                                    <td class="text-right">
                                                        {{ formatIndexHistoryPrice(price.last_price, selectedIndexWatchItem) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-table>
                                    </div>
                                    <v-alert
                                        v-else-if="!isIndexPriceDialogLoading"
                                        type="info"
                                        variant="tonal"
                                        density="compact"
                                    >
                                        No stored index prices yet.
                                    </v-alert>

                                    <div class="index-price-chart-panel mt-5">
                                        <div class="text-caption text-medium-emphasis mb-2">Evolution</div>
                                        <svg
                                            v-if="selectedIndexChart.points.length"
                                            class="index-price-chart"
                                            :viewBox="`0 0 ${selectedIndexChart.width} ${selectedIndexChart.height}`"
                                            role="img"
                                            :aria-label="`${selectedIndexWatchItem.symbol} price evolution`"
                                        >
                                            <line
                                                v-for="(gridLine, index) in selectedIndexChart.horizontalGridLines"
                                                :key="`horizontal-grid-${index}`"
                                                class="index-price-chart-grid-line"
                                                :x1="selectedIndexChart.plot.left"
                                                :y1="gridLine.y"
                                                :x2="selectedIndexChart.plot.right"
                                                :y2="gridLine.y"
                                            />
                                            <line
                                                v-for="(gridLine, index) in selectedIndexChart.verticalGridLines"
                                                :key="`vertical-grid-${index}`"
                                                class="index-price-chart-grid-line"
                                                :x1="gridLine.x"
                                                :y1="selectedIndexChart.plot.top"
                                                :x2="gridLine.x"
                                                :y2="selectedIndexChart.plot.bottom"
                                            />
                                            <line
                                                class="index-price-chart-axis"
                                                :x1="selectedIndexChart.plot.left"
                                                :y1="selectedIndexChart.plot.bottom"
                                                :x2="selectedIndexChart.plot.right"
                                                :y2="selectedIndexChart.plot.bottom"
                                            />
                                            <line
                                                class="index-price-chart-axis"
                                                :x1="selectedIndexChart.plot.left"
                                                :y1="selectedIndexChart.plot.top"
                                                :x2="selectedIndexChart.plot.left"
                                                :y2="selectedIndexChart.plot.bottom"
                                            />
                                            <text
                                                v-for="(gridLine, index) in selectedIndexChart.horizontalGridLines"
                                                :key="`horizontal-label-${index}`"
                                                class="index-price-chart-y-label"
                                                :x="selectedIndexChart.plot.left - 8"
                                                :y="gridLine.y"
                                                text-anchor="end"
                                            >
                                                {{ gridLine.label }}
                                            </text>
                                            <text
                                                v-for="(gridLine, index) in selectedIndexChart.verticalGridLines"
                                                :key="`vertical-label-${index}`"
                                                class="index-price-chart-x-label"
                                                :x="gridLine.x"
                                                :y="selectedIndexChart.height - 13"
                                                text-anchor="middle"
                                            >
                                                {{ gridLine.label }}
                                            </text>
                                            <line
                                                v-if="selectedIndexChart.trendLine"
                                                class="index-price-chart-trend-line"
                                                :x1="selectedIndexChart.trendLine.x1"
                                                :y1="selectedIndexChart.trendLine.y1"
                                                :x2="selectedIndexChart.trendLine.x2"
                                                :y2="selectedIndexChart.trendLine.y2"
                                            />
                                            <polyline
                                                class="index-price-chart-line"
                                                :points="selectedIndexChart.linePoints"
                                            />
                                            <circle
                                                v-for="(point, index) in selectedIndexChart.points"
                                                :key="`point-${point.trading_date || index}`"
                                                class="index-price-chart-point"
                                                :cx="point.x"
                                                :cy="point.y"
                                                r="4"
                                            />
                                            <text
                                                v-if="selectedIndexChart.firstLabel"
                                                class="index-price-chart-endpoint-label index-price-chart-endpoint-label--start"
                                                :x="selectedIndexChart.firstLabel.labelX"
                                                :y="selectedIndexChart.firstLabel.labelY"
                                                :text-anchor="selectedIndexChart.firstLabel.labelAnchor"
                                            >
                                                Start {{ formatIndexChartEndpointPrice(selectedIndexChart.firstLabel) }}
                                            </text>
                                            <text
                                                v-if="selectedIndexChart.latestLabel"
                                                class="index-price-chart-endpoint-label index-price-chart-endpoint-label--latest"
                                                :x="selectedIndexChart.latestLabel.labelX"
                                                :y="selectedIndexChart.latestLabel.labelY"
                                                :text-anchor="selectedIndexChart.latestLabel.labelAnchor"
                                            >
                                                End {{ formatIndexChartEndpointPrice(selectedIndexChart.latestLabel) }}
                                                {{ selectedIndexChart.latestLabel.changePercent }}
                                            </text>
                                        </svg>
                                        <div v-else class="text-body-2 text-medium-emphasis">
                                            No chart data available.
                                        </div>
                                    </div>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn
                                        type="button"
                                        variant="text"
                                        :disabled="isIndexPriceDialogLoading"
                                        @click="closeIndexPriceDialog"
                                    >
                                        Close
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-model="isDeleteHoldingDialogOpen" persistent max-width="440">
                            <v-card>
                                <v-card-title>Confirm delete</v-card-title>
                                <v-card-text>
                                    Delete {{ selectedHolding?.name || selectedHolding?.symbol }}?
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortDeleteHoldingDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn
                                        type="button"
                                        color="error"
                                        variant="flat"
                                        :loading="holdingsLoading"
                                        @click="deleteHolding"
                                    >
                                        Confirm
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                    </section>

                    <section v-if="activeSection === 'analyze'">
                        <div class="mb-6">
                            <p class="text-overline text-primary mb-1">Analyze</p>
                            <h1 class="text-h4">Analyze</h1>
                        </div>

                        <v-tabs
                            :model-value="activeAnalyzeSubsection"
                            color="primary"
                            class="mb-6"
                            @update:model-value="navigateAnalyzeSubsection"
                        >
                            <v-tab
                                v-for="item in analyzeSubmenuItems"
                                :key="item.key"
                                :value="item.key"
                                :prepend-icon="item.icon"
                            >
                                {{ item.label }}
                            </v-tab>
                        </v-tabs>

                        <section
                            v-if="activeAnalyzeSubsection === 'overview'"
                            class="analyze-overview-page"
                            aria-label="Analyze overview"
                        >
                            <div class="analyze-detail-header">
                                <div>
                                    <h2 class="analyze-detail-title">Charts</h2>
                                    <div class="analyze-detail-scope">
                                        {{ selectedAnalyzeScopeLabel }}
                                    </div>
                                </div>
                            </div>

                            <div class="index-watch-strip">
                                <button
                                    v-for="holding in holdings"
                                    :key="holding.id"
                                    type="button"
                                    class="index-watch-card analyze-holding-card"
                                    :class="{ 'analyze-holding-card--active': selectedAnalyzeHoldingId === holding.id }"
                                    :aria-pressed="selectedAnalyzeHoldingId === holding.id"
                                    @click="selectAnalyzeHolding(holding.id)"
                                >
                                    <span class="index-watch-card-label analyze-holding-card-name">
                                        {{ holding.name || holding.symbol || '-' }}
                                    </span>
                                    <span class="index-watch-card-price analyze-holding-card-price">
                                        {{ formatHoldingCardPrice(holding) }}
                                    </span>
                                </button>
                            </div>
                            <div
                                v-if="selectedAnalyzeHolding"
                                class="analyze-range-selector"
                            >
                                <button
                                    type="button"
                                    class="analyze-range-step-button"
                                    aria-label="Previous chart window"
                                    :disabled="!canMoveAnalyzeChartBackward"
                                    @click="moveAnalyzeChartWindowBackward"
                                >
                                    <v-icon icon="mdi-chevron-left" size="16" />
                                </button>
                                <div
                                    class="analyze-range-menu"
                                    aria-label="Analyze history range"
                                >
                                    <button
                                        v-for="rangeItem in analyzeHistoryRangeItems"
                                        :key="rangeItem.key"
                                        type="button"
                                        class="analyze-range-button"
                                        :class="{ 'analyze-range-button--active': selectedAnalyzeHistoryRange === rangeItem.key }"
                                        :aria-pressed="selectedAnalyzeHistoryRange === rangeItem.key"
                                        @click="selectedAnalyzeHistoryRange = rangeItem.key"
                                    >
                                        {{ rangeItem.label }}
                                    </button>
                                </div>
                                <button
                                    type="button"
                                    class="analyze-range-step-button"
                                    aria-label="Next chart window"
                                    :disabled="!canMoveAnalyzeChartForward"
                                    @click="moveAnalyzeChartWindowForward"
                                >
                                    <v-icon icon="mdi-chevron-right" size="16" />
                                </button>
                            </div>
                            <div
                                v-if="selectedAnalyzeHolding"
                                class="analyze-sparkline-panel"
                                aria-label="Selected stock price history"
                            >
                                <div class="analyze-sparkline-header">
                                    <span class="analyze-sparkline-name">
                                        {{ selectedAnalyzeHolding.name || selectedAnalyzeHolding.symbol || '-' }}
                                    </span>
                                    <span class="analyze-sparkline-meta">
                                        {{ formatAnalyzeSparklineDate(selectedAnalyzeSparkline.first) }}
                                        · {{ formatAnalyzeSparklinePrice(selectedAnalyzeSparkline.first) }}
                                        -
                                        {{ formatAnalyzeSparklineDate(selectedAnalyzeSparkline.latest) }}
                                        · {{ formatAnalyzeSparklinePrice(selectedAnalyzeSparkline.latest) }}
                                        · {{ selectedAnalyzeRangeCaptureLabel }}
                                    </span>
                                </div>
                                <svg
                                    v-if="selectedAnalyzeSparkline.points.length"
                                    class="analyze-sparkline"
                                    :viewBox="`0 0 ${selectedAnalyzeSparkline.width} ${selectedAnalyzeSparkline.height}`"
                                    role="img"
                                    :aria-label="`${selectedAnalyzeHolding.name || selectedAnalyzeHolding.symbol || 'Stock'} price history`"
                                >
                                    <defs>
                                        <linearGradient id="analyze-sparkline-area-fill" x1="0" x2="0" y1="0" y2="1">
                                            <stop offset="0%" class="analyze-sparkline-area-stop analyze-sparkline-area-stop--top" />
                                            <stop offset="100%" class="analyze-sparkline-area-stop analyze-sparkline-area-stop--bottom" />
                                        </linearGradient>
                                    </defs>
                                    <rect
                                        class="analyze-sparkline-plot"
                                        :x="selectedAnalyzeSparkline.plot.left"
                                        :y="selectedAnalyzeSparkline.plot.top"
                                        :width="selectedAnalyzeSparkline.plot.right - selectedAnalyzeSparkline.plot.left"
                                        :height="selectedAnalyzeSparkline.plot.bottom - selectedAnalyzeSparkline.plot.top"
                                    />
                                    <line
                                        v-for="(gridLine, index) in selectedAnalyzeSparkline.horizontalGridLines"
                                        :key="`analyze-horizontal-grid-${index}`"
                                        class="analyze-sparkline-grid-line"
                                        :x1="selectedAnalyzeSparkline.plot.left"
                                        :y1="gridLine.y"
                                        :x2="selectedAnalyzeSparkline.plot.right"
                                        :y2="gridLine.y"
                                    />
                                    <text
                                        v-for="(gridLine, index) in selectedAnalyzeSparkline.horizontalGridLines"
                                        :key="`analyze-y-label-${index}`"
                                        class="analyze-sparkline-label analyze-sparkline-y-label"
                                        :x="selectedAnalyzeSparkline.plot.left - 8"
                                        :y="gridLine.y"
                                    >
                                        {{ formatAnalyzeSparklineAxisPrice(gridLine.value) }}
                                    </text>
                                    <line
                                        v-for="(gridLine, index) in selectedAnalyzeSparkline.verticalGridLines"
                                        :key="`analyze-vertical-grid-${index}`"
                                        class="analyze-sparkline-grid-line analyze-sparkline-grid-line--vertical"
                                        :x1="gridLine.x"
                                        :y1="selectedAnalyzeSparkline.plot.top"
                                        :x2="gridLine.x"
                                        :y2="selectedAnalyzeSparkline.plot.bottom"
                                    />
                                    <line
                                        v-for="(marker, index) in selectedAnalyzeSparkline.monthStartMarkers"
                                        :key="`analyze-month-start-line-${index}`"
                                        class="analyze-sparkline-month-line"
                                        :x1="marker.x"
                                        :y1="selectedAnalyzeSparkline.plot.top"
                                        :x2="marker.x"
                                        :y2="selectedAnalyzeSparkline.plot.bottom"
                                    />
                                    <path
                                        class="analyze-sparkline-area"
                                        :d="selectedAnalyzeSparkline.areaPath"
                                    />
                                    <line
                                        v-if="selectedAnalyzeSparkline.trendLine"
                                        class="analyze-sparkline-trend-line"
                                        :x1="selectedAnalyzeSparkline.trendLine.x1"
                                        :y1="selectedAnalyzeSparkline.trendLine.y1"
                                        :x2="selectedAnalyzeSparkline.trendLine.x2"
                                        :y2="selectedAnalyzeSparkline.trendLine.y2"
                                    />
                                    <path
                                        class="analyze-sparkline-line"
                                        :class="`analyze-sparkline-line--${selectedAnalyzeSparkline.trend}`"
                                        :d="selectedAnalyzeSparkline.linePath"
                                    />
                                    <text
                                        v-for="(marker, index) in selectedAnalyzeSparkline.monthStartMarkers"
                                        :key="`analyze-month-price-label-${index}`"
                                        class="analyze-sparkline-month-price-label"
                                        :x="marker.x"
                                        :y="marker.priceLabelY"
                                    >
                                        {{ formatAnalyzeSparklineAxisPrice(marker.chart_price) }}
                                    </text>
                                    <template v-if="showAnalyzeSparklineDots">
                                        <circle
                                            v-for="(point, index) in selectedAnalyzeSparkline.points"
                                            :key="`analyze-point-dot-${point.trading_date || index}-${index}`"
                                            class="analyze-sparkline-dot"
                                            :cx="point.x"
                                            :cy="point.y"
                                            r="3"
                                        />
                                    </template>
                                    <circle
                                        class="analyze-sparkline-point analyze-sparkline-point--first"
                                        :cx="selectedAnalyzeSparkline.first.x"
                                        :cy="selectedAnalyzeSparkline.first.y"
                                        r="3"
                                    />
                                    <circle
                                        class="analyze-sparkline-point analyze-sparkline-point--latest"
                                        :cx="selectedAnalyzeSparkline.latest.x"
                                        :cy="selectedAnalyzeSparkline.latest.y"
                                        r="4"
                                    />
                                    <text
                                        v-if="selectedAnalyzeSparkline.firstLabel"
                                        class="analyze-sparkline-endpoint-label analyze-sparkline-endpoint-label--start"
                                        :x="selectedAnalyzeSparkline.firstLabel.labelX"
                                        :y="selectedAnalyzeSparkline.firstLabel.labelY"
                                        :text-anchor="selectedAnalyzeSparkline.firstLabel.labelAnchor"
                                    >
                                        <template v-if="selectedAnalyzeSparkline.todayStartLabel">
                                            <tspan>
                                                {{ selectedAnalyzeSparkline.firstLabel.labelPrefix }}
                                                {{ formatAnalyzeSparklineAxisPrice(selectedAnalyzeSparkline.firstLabel.chart_price) }}
                                            </tspan>
                                            <tspan> · </tspan>
                                            <tspan :class="selectedAnalyzeSparkline.todayStartLabel.changeClass">
                                                {{ selectedAnalyzeSparkline.todayStartLabel.labelPrefix }}
                                                {{ formatAnalyzeSparklineAxisPrice(selectedAnalyzeSparkline.todayStartLabel.chart_price) }}
                                                {{ selectedAnalyzeSparkline.todayStartLabel.changePercent }}
                                            </tspan>
                                        </template>
                                        <template v-else>
                                            {{ selectedAnalyzeSparkline.firstLabel.labelPrefix }}
                                            {{ formatAnalyzeSparklineAxisPrice(selectedAnalyzeSparkline.firstLabel.chart_price) }}
                                        </template>
                                    </text>
                                    <text
                                        v-if="selectedAnalyzeSparkline.latestLabel"
                                        class="analyze-sparkline-endpoint-label analyze-sparkline-endpoint-label--latest"
                                        :x="selectedAnalyzeSparkline.latestLabel.labelX"
                                        :y="selectedAnalyzeSparkline.latestLabel.labelY"
                                        :text-anchor="selectedAnalyzeSparkline.latestLabel.labelAnchor"
                                    >
                                        {{ selectedAnalyzeSparkline.latestLabel.labelPrefix }}
                                        {{ formatAnalyzeSparklineAxisPrice(selectedAnalyzeSparkline.latestLabel.chart_price) }}
                                        {{ selectedAnalyzeSparkline.latestLabel.changePercent }}
                                    </text>
                                    <g
                                        v-if="selectedAnalyzeSparkline.highMarker"
                                        class="analyze-sparkline-extremum analyze-sparkline-extremum--high"
                                    >
                                        <line
                                            class="analyze-sparkline-extremum-line"
                                            :x1="selectedAnalyzeSparkline.highMarker.x"
                                            :y1="selectedAnalyzeSparkline.highMarker.y"
                                            :x2="selectedAnalyzeSparkline.highMarker.markerX"
                                            :y2="selectedAnalyzeSparkline.highMarker.markerY"
                                        />
                                        <circle
                                            class="analyze-sparkline-extremum-ring"
                                            :cx="selectedAnalyzeSparkline.highMarker.markerX"
                                            :cy="selectedAnalyzeSparkline.highMarker.markerY"
                                            r="6"
                                        />
                                        <circle
                                            class="analyze-sparkline-extremum-dot"
                                            :cx="selectedAnalyzeSparkline.highMarker.markerX"
                                            :cy="selectedAnalyzeSparkline.highMarker.markerY"
                                            r="2.5"
                                        />
                                        <text
                                            class="analyze-sparkline-extremum-label"
                                            :x="selectedAnalyzeSparkline.highMarker.labelX"
                                            :y="selectedAnalyzeSparkline.highMarker.labelY"
                                            :text-anchor="selectedAnalyzeSparkline.highMarker.labelAnchor"
                                        >
                                            {{ formatAnalyzeSparklineAxisPrice(selectedAnalyzeSparkline.highMarker.chart_price) }}
                                        </text>
                                    </g>
                                    <g
                                        v-if="selectedAnalyzeSparkline.lowMarker"
                                        class="analyze-sparkline-extremum analyze-sparkline-extremum--low"
                                    >
                                        <line
                                            class="analyze-sparkline-extremum-line"
                                            :x1="selectedAnalyzeSparkline.lowMarker.x"
                                            :y1="selectedAnalyzeSparkline.lowMarker.y"
                                            :x2="selectedAnalyzeSparkline.lowMarker.markerX"
                                            :y2="selectedAnalyzeSparkline.lowMarker.markerY"
                                        />
                                        <circle
                                            class="analyze-sparkline-extremum-ring"
                                            :cx="selectedAnalyzeSparkline.lowMarker.markerX"
                                            :cy="selectedAnalyzeSparkline.lowMarker.markerY"
                                            r="6"
                                        />
                                        <circle
                                            class="analyze-sparkline-extremum-dot"
                                            :cx="selectedAnalyzeSparkline.lowMarker.markerX"
                                            :cy="selectedAnalyzeSparkline.lowMarker.markerY"
                                            r="2.5"
                                        />
                                        <text
                                            class="analyze-sparkline-extremum-label"
                                            :x="selectedAnalyzeSparkline.lowMarker.labelX"
                                            :y="selectedAnalyzeSparkline.lowMarker.labelY"
                                            :text-anchor="selectedAnalyzeSparkline.lowMarker.labelAnchor"
                                        >
                                            {{ formatAnalyzeSparklineAxisPrice(selectedAnalyzeSparkline.lowMarker.chart_price) }}
                                        </text>
                                    </g>
                                    <text
                                        v-for="(gridLine, index) in selectedAnalyzeSparkline.verticalGridLines"
                                        :key="`analyze-x-label-${index}`"
                                        class="analyze-sparkline-label analyze-sparkline-x-label"
                                        :class="{ 'analyze-sparkline-x-label--end': index === selectedAnalyzeSparkline.verticalGridLines.length - 1 }"
                                        :x="gridLine.x"
                                        :y="selectedAnalyzeSparkline.height - 12"
                                    >
                                        {{ gridLine.label }}
                                    </text>
                                    <text
                                        v-for="(marker, index) in selectedAnalyzeSparkline.monthStartMarkers"
                                        :key="`analyze-month-start-label-${index}`"
                                        class="analyze-sparkline-label analyze-sparkline-month-label"
                                        :x="marker.x"
                                        :y="selectedAnalyzeSparkline.height - 12"
                                    >
                                        {{ marker.label }}
                                    </text>
                                    <text
                                        v-for="(dateLabel, index) in selectedAnalyzeSparkline.dateRangeLabels"
                                        :key="`analyze-date-range-label-${index}`"
                                        class="analyze-sparkline-label analyze-sparkline-date-range-label"
                                        :x="dateLabel.x"
                                        :y="selectedAnalyzeSparkline.height - 30"
                                        :text-anchor="dateLabel.anchor"
                                    >
                                        {{ dateLabel.label }}
                                    </text>
                                </svg>
                                <div v-else class="text-body-2 text-medium-emphasis">
                                    <template v-if="isAnalyzeTodayRange(selectedAnalyzeHistoryRange)">
                                        No EODHD intraday prices available for this session.
                                    </template>
                                    <template v-else>
                                        No stored prices for this range.
                                    </template>
                                </div>
                            </div>
                        </section>
                        <section
                            v-if="activeAnalyzeSubsection === 'intraday'"
                            class="analyze-detail-page"
                            aria-label="Analyze intraday"
                        >
                            <div class="analyze-detail-header">
                                <div>
                                    <h2 class="analyze-detail-title">Intraday prices</h2>
                                    <div class="analyze-detail-scope">
                                        {{ selectedAnalyzeScopeLabel }}
                                    </div>
                                </div>
                            </div>

                            <div class="index-watch-strip analyze-detail-stock-menu" aria-label="Analyze intraday stocks">
                                <button
                                    v-for="holding in holdings"
                                    :key="holding.id"
                                    type="button"
                                    class="index-watch-card analyze-holding-card"
                                    :class="{ 'analyze-holding-card--active': selectedAnalyzeHoldingId === holding.id }"
                                    :aria-pressed="selectedAnalyzeHoldingId === holding.id"
                                    @click="selectAnalyzeHolding(holding.id)"
                                >
                                    <span class="index-watch-card-label analyze-holding-card-name">
                                        {{ holding.name || holding.symbol || '-' }}
                                    </span>
                                    <span class="index-watch-card-price analyze-holding-card-price">
                                        {{ formatHoldingCardPrice(holding) }}
                                    </span>
                                </button>
                            </div>

                            <v-alert
                                v-if="selectedAnalyzeHoldingId === null"
                                class="mb-4"
                                type="info"
                                variant="tonal"
                            >
                                No stock selected.
                            </v-alert>
                            <div v-else class="analyze-intraday-card-grid">
                                <section class="analyze-intraday-card" aria-label="Analyze real-time prices">
                                    <h3 class="analyze-intraday-card-title">Real-time</h3>
                                    <div
                                        v-if="selectedAnalyzeRealtimePriceRows.length === 0"
                                        class="analyze-detail-empty analyze-intraday-card-empty"
                                    >
                                        No real-time prices stored for this stock.
                                    </div>
                                    <div v-else class="analyze-detail-table-wrap analyze-intraday-price-table-wrap">
                                        <v-table class="analyze-detail-table analyze-intraday-price-table" density="compact">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th class="text-right">Price</th>
                                                    <th class="text-right">Prev %</th>
                                                    <th class="text-right">First %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="realtimePrice in selectedAnalyzeRealtimePriceRowsWithChanges"
                                                    :key="realtimePrice.id ?? realtimePrice.as_of"
                                                >
                                                    <td>{{ formatRecentStoredPriceDate(realtimePrice) }}</td>
                                                    <td>{{ formatRecentStoredPriceTime(realtimePrice) }}</td>
                                                    <td class="text-right">{{ formatRecentStoredPrice(realtimePrice, selectedAnalyzeHolding) }}</td>
                                                    <td class="text-right" :class="priceChangePercentClass(realtimePrice.previousChangePercent)">
                                                        {{ formatPriceChangePercent(realtimePrice.previousChangePercent) }}
                                                    </td>
                                                    <td class="text-right" :class="priceChangePercentClass(realtimePrice.firstChangePercent)">
                                                        {{ formatPriceChangePercent(realtimePrice.firstChangePercent) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-table>
                                    </div>
                                </section>

                                <section class="analyze-intraday-card" aria-label="Analyze stored intraday prices">
                                    <h3 class="analyze-intraday-card-title">Intraday</h3>
                                    <div
                                        v-if="selectedAnalyzeIntradayPriceRows.length === 0"
                                        class="analyze-detail-empty analyze-intraday-card-empty"
                                    >
                                        No intraday prices stored for this stock.
                                    </div>
                                    <div v-else class="analyze-detail-table-wrap analyze-intraday-price-table-wrap">
                                        <v-table class="analyze-detail-table analyze-intraday-price-table" density="compact">
                                            <thead>
                                                <tr>
                                                    <th>Date</th>
                                                    <th>Time</th>
                                                    <th class="text-right">Price</th>
                                                    <th class="text-right">Prev %</th>
                                                    <th class="text-right">First %</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <tr
                                                    v-for="intradayPrice in selectedAnalyzeIntradayPriceRowsWithChanges"
                                                    :key="intradayPrice.id ?? intradayPrice.as_of"
                                                >
                                                    <td>{{ formatRecentStoredPriceDate(intradayPrice) }}</td>
                                                    <td>{{ formatRecentStoredPriceTime(intradayPrice) }}</td>
                                                    <td class="text-right">{{ formatRecentStoredPrice(intradayPrice, selectedAnalyzeHolding) }}</td>
                                                    <td class="text-right" :class="priceChangePercentClass(intradayPrice.previousChangePercent)">
                                                        {{ formatPriceChangePercent(intradayPrice.previousChangePercent) }}
                                                    </td>
                                                    <td class="text-right" :class="priceChangePercentClass(intradayPrice.firstChangePercent)">
                                                        {{ formatPriceChangePercent(intradayPrice.firstChangePercent) }}
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </v-table>
                                    </div>
                                </section>
                            </div>
                        </section>
                        <section
                            v-if="activeAnalyzeSubsection === 'detail'"
                            class="analyze-detail-page"
                            aria-label="Analyze detail"
                        >
                            <div class="analyze-detail-header">
                                <div>
                                    <h2 class="analyze-detail-title">
                                        Details
                                    </h2>
                                    <div class="analyze-detail-scope">
                                        {{ selectedAnalyzeScopeLabel }}
                                    </div>
                                </div>
                                <v-progress-circular
                                    v-if="analyzeIntradayCandlesLoading"
                                    color="primary"
                                    indeterminate
                                    size="28"
                                    width="3"
                                />
                            </div>

                            <div class="index-watch-strip analyze-detail-stock-menu" aria-label="Analyze detail stocks">
                                <button
                                    v-for="holding in holdings"
                                    :key="holding.id"
                                    type="button"
                                    class="index-watch-card analyze-holding-card"
                                    :class="{ 'analyze-holding-card--active': selectedAnalyzeHoldingId === holding.id }"
                                    :aria-pressed="selectedAnalyzeHoldingId === holding.id"
                                    @click="selectAnalyzeHolding(holding.id)"
                                >
                                    <span class="index-watch-card-label analyze-holding-card-name">
                                        {{ holding.name || holding.symbol || '-' }}
                                    </span>
                                    <span class="index-watch-card-price analyze-holding-card-price">
                                        {{ formatHoldingCardPrice(holding) }}
                                    </span>
                                </button>
                            </div>

                            <v-alert
                                v-if="selectedAnalyzeHoldingId === null"
                                class="mb-4"
                                type="info"
                                variant="tonal"
                            >
                                No stock selected.
                            </v-alert>
                            <v-alert
                                v-else-if="analyzeIntradayCandlesError"
                                class="mb-4"
                                type="error"
                                variant="tonal"
                            >
                                {{ analyzeIntradayCandlesError }}
                            </v-alert>
                            <div
                                v-else-if="!analyzeIntradayCandlesLoading && analyzeIntradayDetailRows.length === 0"
                                class="analyze-detail-empty"
                            >
                                No 5-minute intraday values stored for the last trading day.
                            </div>
                            <div
                                v-else
                                class="analyze-detail-days"
                            >
                                <section
                                    v-for="(day, dayIndex) in analyzeIntradayDetailDays"
                                    :key="day.trading_date"
                                    class="data-intraday-day"
                                >
                                    <button
                                        type="button"
                                        class="data-intraday-day-header"
                                        :aria-expanded="isAnalyzeIntradayDayExpanded(day)"
                                        @click="toggleAnalyzeIntradayDay(day)"
                                    >
                                        <span class="data-intraday-day-title">{{ day.title }}</span>
                                        <span class="data-intraday-day-count">{{ formatInteger(day.rows?.length ?? 0) }} rows</span>
                                        <v-icon
                                            :icon="isAnalyzeIntradayDayExpanded(day) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                                            size="20"
                                        />
                                    </button>
                                    <dl class="analyze-detail-day-summary" aria-label="Close price summary">
                                        <div
                                            v-for="item in analyzeIntradayCloseSummaryItems(day, dayIndex)"
                                            :key="`${day.trading_date}-${item.key}`"
                                            class="analyze-detail-day-summary-item"
                                            :class="item.itemClass"
                                        >
                                            <dt>{{ item.label }}</dt>
                                            <dd>
                                                <span :class="item.valueClass">{{ item.value }}</span>
                                                <span
                                                    v-if="item.changePercent"
                                                    class="analyze-detail-day-summary-change"
                                                    :class="item.changeClass"
                                                >
                                                    {{ item.changePercent }}
                                                </span>
                                            </dd>
                                        </div>
                                    </dl>
                                    <div v-if="isAnalyzeIntradayDayExpanded(day)" class="data-intraday-day-body">
                                        <div class="analyze-detail-table-wrap">
                                            <v-table class="analyze-detail-table" density="compact">
                                                <thead>
                                                    <tr>
                                                        <th>Timestamp</th>
                                                        <th>GMT offset</th>
                                                        <th>Datetime</th>
                                                        <th class="text-right">Open</th>
                                                        <th class="text-right">High</th>
                                                        <th class="text-right">Low</th>
                                                        <th class="text-right">Close</th>
                                                        <th class="text-right">Volume</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr
                                                        v-for="(row, index) in day.rows"
                                                        :key="row.timestamp ?? row.datetime ?? index"
                                                    >
                                                        <td>{{ formatAnalyzeIntradayCandleValue(row.timestamp) }}</td>
                                                        <td>{{ formatAnalyzeIntradayCandleValue(row.gmtoffset) }}</td>
                                                        <td>{{ formatAnalyzeIntradayCandleDateTime(row) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.open) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.high) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.low) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.close) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.volume) }}</td>
                                                    </tr>
                                                </tbody>
                                            </v-table>
                                        </div>
                                    </div>
                                </section>
                            </div>
                        </section>
                        <section
                            v-if="activeAnalyzeSubsection === 'tests'"
                            class="tests-page"
                            aria-label="Analyze tests"
                        >
                            <v-alert
                                v-if="testOptionsError"
                                class="mb-4"
                                density="compact"
                                type="error"
                                variant="tonal"
                            >
                                {{ testOptionsError }}
                            </v-alert>

                            <div class="tests-chip-groups">
                                <div class="tests-chip-group">
                                    <h2 class="tests-chip-heading">Indices</h2>
                                    <div class="tests-chip-list" aria-label="Test indices">
                                        <button
                                            v-for="indexItem in testOptions.indices"
                                            :key="`test-index-${indexItem.id}`"
                                            type="button"
                                            class="tests-chip"
                                            :class="{ 'tests-chip--active': selectedTestIndexId === indexItem.id }"
                                            :aria-pressed="selectedTestIndexId === indexItem.id"
                                            :title="indexItem.name"
                                            @click="selectedTestIndexId = indexItem.id"
                                        >
                                            <span class="tests-chip-symbol">{{ indexItem.symbol || '-' }}</span>
                                            <span class="tests-chip-name">{{ indexItem.name || indexItem.symbol || `Index ${indexItem.id}` }}</span>
                                        </button>
                                        <span v-if="!testOptionsLoading && testOptions.indices.length === 0" class="tests-chip-empty">
                                            No indices.
                                        </span>
                                    </div>
                                </div>
                                <div class="tests-chip-group">
                                    <h2 class="tests-chip-heading">Stocks</h2>
                                    <div class="tests-chip-list" aria-label="Test stocks">
                                        <button
                                            v-for="stock in testOptions.stocks"
                                            :key="`test-stock-${stock.id}`"
                                            type="button"
                                            class="tests-chip"
                                            :class="{ 'tests-chip--active': selectedTestStockId === stock.id }"
                                            :aria-pressed="selectedTestStockId === stock.id"
                                            :title="stock.name"
                                            @click="selectedTestStockId = stock.id"
                                        >
                                            <span class="tests-chip-symbol">{{ stock.symbol || '-' }}</span>
                                            <span class="tests-chip-name">{{ stock.name || stock.symbol || `Stock ${stock.id}` }}</span>
                                        </button>
                                        <span v-if="!testOptionsLoading && testOptions.stocks.length === 0" class="tests-chip-empty">
                                            No stocks.
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <v-tabs
                                v-model="selectedTestTab"
                                class="tests-tabs"
                                color="primary"
                            >
                                <v-tab value="tickers">Tickers</v-tab>
                            </v-tabs>
                            <div
                                v-if="selectedTestTab === 'tickers'"
                                class="tests-ticker-panel"
                                aria-label="Ticker result"
                            >
                                <div class="tests-ticker-actions">
                                    <div>
                                        <div class="tests-chip-heading">EODHD symbols</div>
                                        <div class="text-caption text-medium-emphasis">
                                            Exchange: {{ testTickerExchangeCode }}
                                        </div>
                                    </div>
                                    <v-btn
                                        color="primary"
                                        prepend-icon="mdi-download-outline"
                                        type="button"
                                        variant="tonal"
                                        :loading="testTickersLoading"
                                        @click="depotsStore.loadTestTickers"
                                    >
                                        Load
                                    </v-btn>
                                </div>
                                <v-alert
                                    v-if="testTickersError"
                                    class="mt-3"
                                    density="compact"
                                    type="error"
                                    variant="tonal"
                                >
                                    {{ testTickersError }}
                                </v-alert>
                                <div
                                    v-if="testTickers.length > 0"
                                    class="tests-ticker-table-wrap"
                                >
                                    <v-table class="tests-ticker-table" density="compact">
                                        <thead>
                                            <tr>
                                                <th>Code</th>
                                                <th>Name</th>
                                                <th>Exchange</th>
                                                <th>Type</th>
                                                <th>Currency</th>
                                                <th>ISIN</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="(ticker, index) in testTickers"
                                                :key="ticker.Code ?? ticker.code ?? index"
                                            >
                                                <td>{{ ticker.Code ?? ticker.code ?? '-' }}</td>
                                                <td>{{ ticker.Name ?? ticker.name ?? '-' }}</td>
                                                <td>{{ ticker.Exchange ?? ticker.exchange ?? '-' }}</td>
                                                <td>{{ ticker.Type ?? ticker.type ?? '-' }}</td>
                                                <td>{{ ticker.Currency ?? ticker.currency ?? '-' }}</td>
                                                <td>{{ ticker.Isin ?? ticker.ISIN ?? ticker.isin ?? '-' }}</td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </div>
                            </div>
                        </section>
                    </section>

                    <v-dialog v-model="isCashTransactionDialogOpen" persistent max-width="480">
                        <v-card>
                            <v-card-title>
                                {{ cashTransactionForm.type === 'deposit' ? 'Add cash' : 'Withdraw cash' }}
                            </v-card-title>
                            <v-card-text>
                                <form id="cash-transaction-form" @submit.prevent="bookCashTransaction">
                                    <v-text-field
                                        v-model="cashTransactionForm.total_amount"
                                        label="Amount"
                                        min="0.01"
                                        step="0.01"
                                        suffix="EUR"
                                        type="number"
                                        required
                                    />
                                    <v-text-field
                                        v-model="cashTransactionForm.booked_at"
                                        label="Date"
                                        type="date"
                                        required
                                    />
                                    <v-text-field
                                        v-model="cashTransactionForm.note"
                                        label="Note"
                                        maxlength="255"
                                    />
                                </form>
                            </v-card-text>
                            <v-card-actions>
                                <v-spacer />
                                <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortCashTransactionDialog">
                                    Cancel
                                </v-btn>
                                <v-btn type="submit" form="cash-transaction-form" color="primary" variant="flat" :loading="holdingsLoading">
                                    Book
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-dialog>

                    <v-dialog v-model="isStockTransactionDialogOpen" persistent max-width="520">
                        <v-card>
                            <v-card-title>
                                {{ stockTransactionForm.type === 'buy' ? 'Buy stock' : 'Sell stock' }}
                            </v-card-title>
                            <v-card-text>
                                <div class="mb-4">
                                    <div class="font-weight-medium">{{ transactionHolding?.name || transactionHolding?.symbol }}</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ transactionHolding?.isin || '-' }} · Pieces: {{ transactionHolding ? formatPositionPieces(transactionHolding) : '0' }}
                                    </div>
                                </div>
                                <form id="stock-transaction-form" @submit.prevent="bookStockTransaction">
                                    <v-text-field
                                        v-model="stockTransactionForm.pieces"
                                        label="Pieces"
                                        min="0.00000001"
                                        step="0.00000001"
                                        type="number"
                                        required
                                    />
                                    <v-text-field
                                        v-model="stockTransactionForm.total_amount"
                                        label="Sum price"
                                        min="0.01"
                                        step="0.01"
                                        suffix="EUR"
                                        type="number"
                                        required
                                    />
                                    <v-text-field
                                        v-model="stockTransactionForm.booked_at"
                                        label="Date"
                                        type="date"
                                        required
                                    />
                                    <v-text-field
                                        v-model="stockTransactionForm.note"
                                        label="Note"
                                        maxlength="255"
                                    />
                                </form>
                            </v-card-text>
                            <v-card-actions>
                                <v-spacer />
                                <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortStockTransactionDialog">
                                    Cancel
                                </v-btn>
                                <v-btn type="submit" form="stock-transaction-form" color="primary" variant="flat" :loading="holdingsLoading">
                                    Book
                                </v-btn>
                            </v-card-actions>
                        </v-card>
                    </v-dialog>

                    <v-tabs
                        v-if="(activeSection === 'depots' || activeSection === 'users' || activeSection === 'roles' || activeSection === 'updates') && canManageDashboardAdmin"
                        :model-value="activeSection"
                        color="primary"
                        class="mb-6"
                        @update:model-value="(s) => s !== activeSection && navigateSection(s)"
                    >
                        <v-tab value="depots" prepend-icon="mdi-briefcase-outline">Depots</v-tab>
                        <v-tab v-if="canManageUsers" value="users" prepend-icon="mdi-account-group-outline">Users</v-tab>
                        <v-tab v-if="canManageUsers" value="roles" prepend-icon="mdi-shield-account-outline">Roles</v-tab>
                        <v-tab value="updates" prepend-icon="mdi-update">Updates</v-tab>
                    </v-tabs>

                    <section v-if="activeSection === 'data' && canManageDashboardAdmin">
                        <v-tabs
                            :model-value="activeDataSubsection"
                            color="primary"
                            class="mb-6"
                            @update:model-value="navigateDataSubsection"
                        >
                            <v-tab
                                v-for="item in dataSubmenuItems"
                                :key="item.key"
                                :value="item.key"
                                :prepend-icon="item.icon"
                            >
                                {{ item.label }}
                            </v-tab>
                        </v-tabs>

                        <section
                            v-if="activeDataSubsection === 'exchanges'"
                            aria-label="Data exchanges"
                        >
                            <div class="data-exchange-actions mb-4">
                                <div>
                                    <h2 class="text-h5">Exchanges</h2>
                                    <div class="text-caption text-medium-emphasis">
                                        Last updated:
                                        {{ dataExchangesLastUpdatedAt ? formatDateTime(dataExchangesLastUpdatedAt) : 'Never' }}
                                    </div>
                                </div>
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-refresh"
                                    type="button"
                                    variant="flat"
                                    :disabled="isDataExchangeReloadRunning"
                                    :loading="dataExchangeReloadLoading"
                                    @click="reloadDataExchanges"
                                >
                                    Reload Exchanges
                                </v-btn>
                            </div>

                        <v-alert
                            v-if="dataExchangesError"
                            class="mb-4"
                            density="compact"
                            type="error"
                            variant="tonal"
                        >
                            {{ dataExchangesError }}
                        </v-alert>

                        <v-sheet
                            v-if="dataExchangeRefreshVisible"
                            border
                            rounded
                            class="pa-4 mb-4"
                        >
                            <div class="d-flex align-center justify-space-between ga-3 flex-wrap">
                                <div>
                                    <div class="text-subtitle-2">{{ dataExchangeRefresh.message }}</div>
                                    <div class="text-caption text-medium-emphasis">
                                        {{ dataExchangeRefresh.step }}
                                        <template v-if="dataExchangeRefresh.current">
                                            · {{ dataExchangeRefresh.current }}
                                        </template>
                                    </div>
                                </div>
                                <div class="d-flex align-center ga-2">
                                    <v-chip
                                        size="small"
                                        :color="dataExchangeRefresh.status === 'failed' ? 'error' : (isDataExchangeReloadRunning ? 'primary' : 'success')"
                                        variant="tonal"
                                    >
                                        {{ dataExchangeRefresh.status }}
                                    </v-chip>
                                    <v-btn
                                        icon="mdi-close"
                                        size="x-small"
                                        variant="text"
                                        @click="dismissDataExchangeRefresh"
                                    />
                                </div>
                            </div>
                            <v-progress-linear
                                class="mt-3"
                                color="primary"
                                height="8"
                                rounded
                                :indeterminate="dataExchangeRefresh.status === 'queued'"
                                :model-value="dataExchangeReloadProgressValue"
                            />
                        </v-sheet>

                        <v-progress-linear v-if="dataExchangesLoading" indeterminate color="primary" class="mb-4" />

                        <div class="data-exchange-list">
                            <section
                                v-for="exchange in dataExchanges"
                                :key="exchange.code"
                                class="data-exchange-item"
                            >
                                <div class="data-exchange-summary">
                                    <div>
                                        <div class="data-exchange-meta">
                                            {{ [exchange.country, exchange.currency, exchange.timezone, `details: ${exchange.detail_code}`].filter(Boolean).join(' · ') }}
                                        </div>
                                        <h2 class="data-exchange-title">{{ formatDataExchangeTitle(exchange) }}</h2>
                                    </div>
                                    <div class="data-exchange-code">{{ exchange.operating_mic || exchange.detail_code }}</div>
                                </div>

                                <div class="data-exchange-detail-grid">
                                    <div class="data-exchange-detail-block">
                                        <h3>TradingHours</h3>
                                        <dl v-if="formatDataExchangeTradingHours(exchange).length" class="data-definition-list">
                                            <template
                                                v-for="(row, index) in formatDataExchangeTradingHours(exchange)"
                                                :key="row.key"
                                            >
                                                <div v-if="index === 2" class="data-definition-spacer" aria-hidden="true" />
                                                <div class="data-definition-pair">
                                                    <dt>{{ row.label }}</dt>
                                                    <dd>{{ formatDataExchangeHolidayValue(row.value) }}</dd>
                                                </div>
                                            </template>
                                        </dl>
                                        <p v-else class="text-body-2 text-medium-emphasis mb-0 font-medium">No trading hours stored.</p>
                                    </div>

                                    <div class="data-exchange-detail-block">
                                        <h3>Holidays</h3>
                                        <div v-if="formatDataExchangeHolidays(exchange).length" class="data-holiday-list">
                                            <div
                                                v-for="holiday in formatDataExchangeHolidays(exchange)"
                                                :key="holiday.key"
                                                class="data-holiday-card"
                                            >
                                                <div class="data-holiday-card-date">{{ holiday.date }}</div>
                                                <div class="data-holiday-card-label">{{ holiday.label }}</div>
                                            </div>
                                        </div>
                                        <p v-else class="text-body-2 text-medium-emphasis mb-0">No holidays stored.</p>
                                    </div>
                                </div>
                            </section>
                            <v-sheet
                                v-if="!dataExchangesLoading && dataExchanges.length === 0"
                                border
                                rounded
                                class="pa-4 text-medium-emphasis"
                            >
                                No exchanges stored yet.
                            </v-sheet>
                        </div>
                        </section>

                        <section
                            v-if="activeDataSubsection === 'intraday'"
                            aria-label="Data intraday"
                        >
                            <div class="data-exchange-actions mb-4">
                                <div>
                                    <h2 class="text-h5">Intraday</h2>
                                    <div class="text-caption text-medium-emphasis">
                                        Last updated:
                                        {{ dataIntradayLastUpdatedAt ? formatDateTime(dataIntradayLastUpdatedAt) : 'Never' }}
                                    </div>
                                </div>
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-refresh"
                                    type="button"
                                    variant="flat"
                                    :disabled="!selectedDataIntradayStockId || isDataIntradayReloadRunning"
                                    :loading="dataIntradayReloadLoading"
                                    @click="reloadDataIntraday"
                                >
                                    Reload Intraday
                                </v-btn>
                            </div>

                            <v-alert
                                v-if="dataIntradayError"
                                class="mb-4"
                                density="compact"
                                type="error"
                                variant="tonal"
                            >
                                {{ dataIntradayError }}
                            </v-alert>

                            <v-sheet
                                v-if="dataIntradayRefreshVisible"
                                border
                                class="mb-4"
                                rounded
                            >
                                <div class="d-flex align-center justify-space-between ga-3 flex-wrap pa-4">
                                    <div>
                                        <div class="text-subtitle-2">{{ formatDataIntradayReloadMessage() }}</div>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ dataIntradayRefresh.step }}
                                            <template v-if="dataIntradayRefresh.current">
                                                · {{ dataIntradayRefresh.current }}
                                            </template>
                                        </div>
                                    </div>
                                    <div class="d-flex align-center ga-2">
                                        <v-chip
                                            size="small"
                                            :color="dataIntradayRefresh.status === 'failed' ? 'error' : (isDataIntradayReloadRunning ? 'primary' : 'success')"
                                            variant="tonal"
                                        >
                                            {{ dataIntradayRefresh.status }}
                                        </v-chip>
                                        <v-btn
                                            v-if="!isDataIntradayReloadRunning"
                                            icon="mdi-close"
                                            size="x-small"
                                            variant="text"
                                            @click="dismissDataIntradayRefresh"
                                        />
                                    </div>
                                </div>
                                <div class="data-progress-wrap">
                                    <v-progress-linear
                                        color="primary"
                                        height="8"
                                        rounded
                                        :indeterminate="dataIntradayRefresh.status === 'queued'"
                                        :model-value="dataIntradayReloadProgressValue"
                                    />
                                </div>
                            </v-sheet>

                            <div class="tests-chip-group mb-4">
                                <h2 class="tests-chip-heading">Stocks</h2>
                                <div class="tests-chip-list" aria-label="Intraday stocks">
                                    <button
                                        v-for="stock in dataIntradayStocks"
                                        :key="`data-intraday-stock-${stock.id}`"
                                        type="button"
                                        class="tests-chip"
                                        :class="{ 'tests-chip--active': selectedDataIntradayStockId === stock.id || dataIntradaySelectedStockId === stock.id }"
                                        :aria-pressed="selectedDataIntradayStockId === stock.id || dataIntradaySelectedStockId === stock.id"
                                        :title="stock.name"
                                        @click="selectDataIntradayStock(stock.id)"
                                    >
                                        <span class="tests-chip-symbol">{{ stock.symbol || '-' }}</span>
                                        <span class="tests-chip-name">{{ stock.name || stock.symbol || `Stock ${stock.id}` }}</span>
                                    </button>
                                    <span v-if="!dataIntradayLoading && dataIntradayStocks.length === 0" class="tests-chip-empty">
                                        No stocks.
                                    </span>
                                </div>
                            </div>

                            <div v-if="selectedDataIntradayStock" class="text-caption text-medium-emphasis mb-3">
                                {{ selectedDataIntradayStock.name }} · {{ selectedDataIntradayStock.symbol || '-' }} · interval 5m
                            </div>

                            <v-progress-linear v-if="dataIntradayLoading" indeterminate color="primary" class="mb-4" />

                            <div class="data-intraday-panel">
                                <section
                                    v-for="day in dataIntradayDays"
                                    :key="day.trading_date"
                                    class="data-intraday-day"
                                >
                                    <button
                                        type="button"
                                        class="data-intraday-day-header"
                                        :aria-expanded="isDataIntradayDayExpanded(day)"
                                        @click="toggleDataIntradayDay(day)"
                                    >
                                        <span class="data-intraday-day-title">{{ formatDataIntradayDayTitle(day) }}</span>
                                        <span class="data-intraday-day-count">{{ formatInteger(day.rows?.length ?? 0) }} rows</span>
                                        <v-icon
                                            :icon="isDataIntradayDayExpanded(day) ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                                            size="20"
                                        />
                                    </button>
                                    <div v-if="isDataIntradayDayExpanded(day)" class="data-intraday-day-body">
                                        <div
                                            v-if="day.overview"
                                            class="data-intraday-day-overview"
                                        >
                                            {{ day.overview }}
                                        </div>
                                        <div class="tests-ticker-table-wrap">
                                            <v-table class="tests-ticker-table" density="compact">
                                                <thead>
                                                    <tr>
                                                        <th>Timestamp</th>
                                                        <th>GMT Offset</th>
                                                        <th>Datetime</th>
                                                        <th class="text-right">Open</th>
                                                        <th class="text-right">High</th>
                                                        <th class="text-right">Low</th>
                                                        <th class="text-right">Close</th>
                                                        <th class="text-right">Volume</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr
                                                        v-for="(row, index) in day.rows"
                                                        :key="row.timestamp ?? row.datetime ?? index"
                                                    >
                                                        <td>{{ formatAnalyzeIntradayCandleValue(row.timestamp) }}</td>
                                                        <td>{{ formatAnalyzeIntradayCandleValue(row.gmtoffset) }}</td>
                                                        <td>{{ formatAnalyzeIntradayCandleDateTime(row) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.open) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.high) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.low) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.close) }}</td>
                                                        <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.volume) }}</td>
                                                    </tr>
                                                </tbody>
                                            </v-table>
                                        </div>
                                    </div>
                                </section>
                                <v-sheet
                                    v-if="!dataIntradayLoading && dataIntradayDays.length === 0"
                                    border
                                    rounded
                                    class="pa-4 text-medium-emphasis"
                                >
                                    No intraday data stored for this stock yet.
                                </v-sheet>
                            </div>
                        </section>
                    </section>

                    <section v-if="activeSection === 'updates' && canManageDashboardAdmin">
                        <div class="mb-6">
                            <p class="text-overline text-primary mb-1">Admin</p>
                            <h1 class="text-h4">Updates</h1>
                        </div>

                        <v-sheet border rounded class="pa-4 mb-4">
                            <form
                                id="price-refresh-schedule-form"
                                class="d-flex align-center flex-wrap ga-3"
                                @submit.prevent="savePriceRefreshSchedule"
                            >
                                <div class="text-subtitle-2 mr-2">Automatic price refresh</div>
                                <template v-if="!isPriceRefreshScheduleEditing">
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">During trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ priceRefreshScheduleForm.trading_interval_minutes }} min
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Start before trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ priceRefreshScheduleForm.trading_starts_before_minutes }} min
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">End after trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ priceRefreshScheduleForm.trading_ends_after_minutes }} min
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Outside trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ priceRefreshScheduleForm.closed_refresh_enabled ? 'On' : 'Off' }}
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Outside trading interval</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ priceRefreshScheduleForm.closed_interval_minutes }} min
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <v-text-field
                                        v-model="priceRefreshScheduleForm.trading_interval_minutes"
                                        density="compact"
                                        hide-details
                                        label="During trading"
                                        min="1"
                                        max="1440"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 180px"
                                    />
                                    <v-text-field
                                        v-model="priceRefreshScheduleForm.trading_starts_before_minutes"
                                        density="compact"
                                        hide-details
                                        label="Start before trading"
                                        min="0"
                                        max="1440"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 210px"
                                    />
                                    <v-text-field
                                        v-model="priceRefreshScheduleForm.trading_ends_after_minutes"
                                        density="compact"
                                        hide-details
                                        label="End after trading"
                                        min="0"
                                        max="1440"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 200px"
                                    />
                                    <v-switch
                                        v-model="priceRefreshScheduleForm.closed_refresh_enabled"
                                        color="primary"
                                        density="compact"
                                        hide-details
                                        label="Outside trading"
                                    />
                                    <v-text-field
                                        v-model="priceRefreshScheduleForm.closed_interval_minutes"
                                        density="compact"
                                        hide-details
                                        label="Outside trading interval"
                                        min="1"
                                        max="1440"
                                        :disabled="!priceRefreshScheduleForm.closed_refresh_enabled"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 220px"
                                    />
                                </template>
                                <v-btn
                                    v-if="!isPriceRefreshScheduleEditing"
                                    type="button"
                                    color="primary"
                                    prepend-icon="mdi-pencil-outline"
                                    variant="tonal"
                                    :disabled="isIndexPriceRefreshScheduleEditing"
                                    @click="editPriceRefreshSchedule"
                                >
                                    Edit
                                </v-btn>
                                <v-btn
                                    v-else
                                    type="submit"
                                    color="primary"
                                    prepend-icon="mdi-content-save-outline"
                                    variant="tonal"
                                    :loading="holdingsLoading"
                                >
                                    Save
                                </v-btn>
                                <span class="text-caption text-medium-emphasis">
                                    Current interval: {{ priceRefreshSettings?.current_interval_minutes ?? '-' }} min
                                </span>
                            </form>
                        </v-sheet>
                        <v-sheet border rounded class="pa-4 mb-4">
                            <form
                                id="index-price-refresh-schedule-form"
                                class="d-flex align-center flex-wrap ga-3"
                                @submit.prevent="saveIndexPriceRefreshSchedule"
                            >
                                <div class="text-subtitle-2 mr-2">Automatic index price refresh</div>
                                <template v-if="!isIndexPriceRefreshScheduleEditing">
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">During trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ indexPriceRefreshScheduleForm.trading_interval_minutes }} min
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Start before trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ indexPriceRefreshScheduleForm.trading_starts_before_minutes }} min
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">End after trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ indexPriceRefreshScheduleForm.trading_ends_after_minutes }} min
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Outside trading</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ indexPriceRefreshScheduleForm.closed_refresh_enabled ? 'On' : 'Off' }}
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Outside trading interval</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ indexPriceRefreshScheduleForm.closed_interval_minutes }} min
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <v-text-field
                                        v-model="indexPriceRefreshScheduleForm.trading_interval_minutes"
                                        density="compact"
                                        hide-details
                                        label="During trading"
                                        min="1"
                                        max="1440"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 180px"
                                    />
                                    <v-text-field
                                        v-model="indexPriceRefreshScheduleForm.trading_starts_before_minutes"
                                        density="compact"
                                        hide-details
                                        label="Start before trading"
                                        min="0"
                                        max="1440"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 210px"
                                    />
                                    <v-text-field
                                        v-model="indexPriceRefreshScheduleForm.trading_ends_after_minutes"
                                        density="compact"
                                        hide-details
                                        label="End after trading"
                                        min="0"
                                        max="1440"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 200px"
                                    />
                                    <v-switch
                                        v-model="indexPriceRefreshScheduleForm.closed_refresh_enabled"
                                        color="primary"
                                        density="compact"
                                        hide-details
                                        label="Outside trading"
                                    />
                                    <v-text-field
                                        v-model="indexPriceRefreshScheduleForm.closed_interval_minutes"
                                        density="compact"
                                        hide-details
                                        label="Outside trading interval"
                                        min="1"
                                        max="1440"
                                        :disabled="!indexPriceRefreshScheduleForm.closed_refresh_enabled"
                                        suffix="min"
                                        type="number"
                                        style="max-width: 220px"
                                    />
                                </template>
                                <v-btn
                                    v-if="!isIndexPriceRefreshScheduleEditing"
                                    type="button"
                                    color="primary"
                                    prepend-icon="mdi-pencil-outline"
                                    variant="tonal"
                                    :disabled="isPriceRefreshScheduleEditing"
                                    @click="editIndexPriceRefreshSchedule"
                                >
                                    Edit
                                </v-btn>
                                <v-btn
                                    v-else
                                    type="submit"
                                    color="primary"
                                    prepend-icon="mdi-content-save-outline"
                                    variant="tonal"
                                    :loading="holdingsLoading"
                                >
                                    Save
                                </v-btn>
                                <span class="text-caption text-medium-emphasis">
                                    Current interval: {{ indexPriceRefreshSettings?.current_interval_minutes ?? '-' }} min
                                </span>
                            </form>
                        </v-sheet>
                        <v-sheet border rounded class="pa-4 mb-4">
                            <form
                                id="intraday-backfill-schedule-form"
                                class="d-flex align-center flex-wrap ga-3"
                                @submit.prevent="saveIntradayBackfillSchedule"
                            >
                                <div class="text-subtitle-2 mr-2">Daily intraday 5m backfill</div>
                                <template v-if="!isIntradayBackfillScheduleEditing">
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Local time</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ intradayBackfillScheduleForm.daily_time }}
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Timezone</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ intradayBackfillSettings?.timezone ?? 'Europe/Vienna' }}
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Last queued</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ intradayBackfillSettings?.last_dispatched_at ? formatDateTime(intradayBackfillSettings.last_dispatched_at) : 'Never' }}
                                        </div>
                                    </div>
                                    <div class="px-3 py-2 rounded border">
                                        <div class="text-caption text-medium-emphasis">Next</div>
                                        <div class="text-body-2 font-weight-medium">
                                            {{ intradayBackfillSettings?.next_refresh_at ? formatDateTime(intradayBackfillSettings.next_refresh_at) : '-' }}
                                        </div>
                                    </div>
                                </template>
                                <template v-else>
                                    <v-text-field
                                        v-model="intradayBackfillScheduleForm.daily_time"
                                        density="compact"
                                        hide-details
                                        label="Local time"
                                        type="time"
                                        style="max-width: 180px"
                                    />
                                </template>
                                <v-btn
                                    v-if="!isIntradayBackfillScheduleEditing"
                                    type="button"
                                    color="primary"
                                    prepend-icon="mdi-pencil-outline"
                                    variant="tonal"
                                    :disabled="isPriceRefreshScheduleEditing || isIndexPriceRefreshScheduleEditing"
                                    @click="editIntradayBackfillSchedule"
                                >
                                    Edit
                                </v-btn>
                                <v-btn
                                    v-else
                                    type="submit"
                                    color="primary"
                                    prepend-icon="mdi-content-save-outline"
                                    variant="tonal"
                                    :loading="holdingsLoading"
                                >
                                    Save
                                </v-btn>
                                <v-btn
                                    type="button"
                                    color="primary"
                                    prepend-icon="mdi-database-sync-outline"
                                    variant="flat"
                                    :loading="isIntradayBackfillRunningNow"
                                    :disabled="isIntradayBackfillRunning"
                                    @click="runIntradayBackfillNow"
                                >
                                    Fetch missing now
                                </v-btn>
                                <span class="text-caption text-medium-emphasis">
                                    {{ intradayBackfillSettings?.status_label ?? 'waiting' }}
                                </span>
                            </form>
                            <div v-if="intradayBackfillRefresh" class="mt-4">
                                <div v-if="intradayBackfillRefresh.message" class="text-body-2 font-weight-medium mb-2">
                                    {{ intradayBackfillRefresh.message }}
                                </div>
                                <div class="d-flex align-center flex-wrap ga-2 text-caption text-medium-emphasis mb-2">
                                    <span>Backfill: {{ intradayBackfillRefresh.step }}</span>
                                    <span v-if="intradayBackfillRefresh.current">{{ intradayBackfillRefresh.current }}</span>
                                    <span>{{ formatInteger(intradayBackfillRefresh.stored_count ?? 0) }} candles loaded/updated</span>
                                    <span v-if="intradayBackfillRefresh.date_from && intradayBackfillRefresh.date_to">
                                        {{ intradayBackfillRefresh.date_from }} to {{ intradayBackfillRefresh.date_to }}
                                    </span>
                                </div>
                                <v-progress-linear
                                    height="6"
                                    color="primary"
                                    rounded
                                    :indeterminate="intradayBackfillRefresh.status === 'queued'"
                                    :model-value="intradayBackfillProgressValue"
                                />
                            </div>
                        </v-sheet>
                        <v-alert v-if="priceRefreshScheduleMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ priceRefreshScheduleMessage }}
                        </v-alert>
                        <v-alert v-if="priceRefreshScheduleError" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ priceRefreshScheduleError }}
                        </v-alert>
                    </section>

                    <section v-if="activeSection === 'depot'">
                        <div class="mb-4">
                            <p class="text-overline text-primary mb-1">Depot</p>
                            <h1 class="text-h4">{{ activeDepot?.name ?? '–' }}</h1>
                        </div>

                        <div v-if="activeDepot" class="d-flex flex-wrap align-start ga-4">
                            <v-card class="depot-balance-card" variant="outlined" width="100%" max-width="480">
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Depot balance</td>
                                            <td class="text-right">{{ formatDepotStockBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Cash balance</td>
                                            <td class="text-right">{{ formatDepotCashBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Account balance</td>
                                            <td class="text-right">{{ formatDepotAccountBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Status</td>
                                            <td class="text-right">
                                                <v-chip color="success" density="comfortable" size="x-small" variant="tonal">Active</v-chip>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>

                            <v-card class="depot-balance-card" variant="outlined" width="100%" max-width="480">
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance 01.01.</td>
                                            <td class="text-right">{{ formatDepotYearStartBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatCurrentDayMonth() }}</td>
                                            <td class="text-right">{{ formatDepotCurrentBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">+/-</td>
                                            <td class="text-right">
                                                <span :class="depotBalanceChangeClass()">
                                                    {{ formatDepotBalanceChangePercent() }} · {{ formatDepotBalanceChangeAmount() }}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>
                        </div>

                        <div v-if="activeDepot" class="mt-6">
                            <p class="text-overline text-medium-emphasis mb-2">Depot stocks</p>
                            <v-progress-linear v-if="transactionsLoading" indeterminate class="mb-2" />
                            <v-alert v-if="transactionsError" type="error" variant="tonal" density="compact" class="mb-2">
                                {{ transactionsError }}
                            </v-alert>
                            <div v-if="depotHoldings.length > 0" class="mobile-depot-stocks">
                                <div class="mobile-depot-price-source">
                                    <button
                                        type="button"
                                        class="depot-price-source-button"
                                        :class="depotPriceSourceButtonClass('latest')"
                                        :aria-pressed="isDepotPriceSource('latest')"
                                        @click="selectDepotPriceSource('latest')"
                                    >
                                        Latest price
                                    </button>
                                    <button
                                        type="button"
                                        class="depot-price-source-button"
                                        :class="depotPriceSourceButtonClass('flatex')"
                                        :aria-pressed="isDepotPriceSource('flatex')"
                                        @click="selectDepotPriceSource('flatex')"
                                    >
                                        Flatex price
                                    </button>
                                </div>
                                <article
                                    v-for="holding in depotHoldings"
                                    :key="`mobile-depot-holding-${holding.id}`"
                                    class="mobile-depot-stock-card"
                                >
                                    <div class="mobile-depot-stock-name">
                                        {{ holding.name || '-' }}
                                    </div>
                                    <div class="mobile-depot-stock-row mobile-depot-stock-row--prices">
                                        <span>{{ formatPositionPieces(holding) }}</span>
                                        <span>{{ formatLatestPrice(holding) }}</span>
                                        <span>{{ formatStockHoldingValue(holding) }}</span>
                                    </div>
                                    <div class="mobile-depot-stock-row font-weight-medium" :class="yearStartChangeClass(holding)">
                                        <span>
                                            {{ yearStartChangeSymbol(holding) }}
                                            {{ formatYearStartChangePercent(holding) }}
                                        </span>
                                        <span>{{ formatYearStartChangeAmount(holding) }}</span>
                                    </div>
                                    <div class="mobile-depot-stock-actions">
                                        <v-btn
                                            icon
                                            variant="tonal"
                                            color="success"
                                            aria-label="Buy stock"
                                            :disabled="!activeDepot || holdingsLoading"
                                            @click.stop="openStockTransactionDialog(holding, 'buy')"
                                        >
                                            <v-icon icon="mdi-cart-plus" />
                                        </v-btn>
                                        <v-btn
                                            icon
                                            variant="tonal"
                                            color="warning"
                                            aria-label="Sell stock"
                                            :disabled="!activeDepot || holdingsLoading"
                                            @click.stop="openStockTransactionDialog(holding, 'sell')"
                                        >
                                            <v-icon icon="mdi-cart-minus" />
                                        </v-btn>
                                    </div>
                                </article>
                            </div>
                            <v-table v-if="depotHoldings.length > 0" class="desktop-depot-stocks-table" density="compact">
                                <thead>
                                    <tr>
                                        <th v-if="!isCompactDepotStocksTable">Symbol</th>
                                        <th>Name</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">Value</th>
                                        <th class="text-right">
                                            <button
                                                type="button"
                                                class="depot-price-source-button"
                                                :class="depotPriceSourceButtonClass('latest')"
                                                :aria-pressed="isDepotPriceSource('latest')"
                                                @click="selectDepotPriceSource('latest')"
                                            >
                                                Latest price
                                            </button>
                                        </th>
                                        <th class="text-right">
                                            <button
                                                type="button"
                                                class="depot-price-source-button"
                                                :class="depotPriceSourceButtonClass('flatex')"
                                                :aria-pressed="isDepotPriceSource('flatex')"
                                                @click="selectDepotPriceSource('flatex')"
                                            >
                                                Flatex price
                                            </button>
                                        </th>
                                        <th v-if="!isCompactDepotStocksTable" class="text-right">1.1.</th>
                                        <th class="text-right">Change</th>
                                        <th class="text-right">+/- EUR</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="holding in depotHoldings" :key="holding.id" :class="depotHoldingRowClass(holding)">
                                        <td v-if="!isCompactDepotStocksTable">{{ holding.symbol || '-' }}</td>
                                        <td>{{ holding.name || '-' }}</td>
                                        <td class="text-right">{{ formatPositionPieces(holding) }}</td>
                                        <td class="text-right">{{ formatStockHoldingValue(holding) }}</td>
                                        <td class="text-right">{{ formatLatestPrice(holding) }}</td>
                                        <td class="text-right">
                                            <input
                                                v-if="isEditingFlatexPrice(holding)"
                                                ref="flatexPriceEditInput"
                                                v-model="flatexPriceEditValue"
                                                aria-label="Flatex price"
                                                class="flatex-price-input"
                                                min="0"
                                                step="0.000001"
                                                type="number"
                                                @keydown.enter.prevent="saveFlatexPriceEdit(holding)"
                                                @keydown.esc.prevent="abortFlatexPriceEdit"
                                            >
                                            <button
                                                v-else
                                                type="button"
                                                class="flatex-price-button"
                                                @click="startFlatexPriceEdit(holding)"
                                            >
                                                {{ formatPriceValue(holding.flatex_price, holding.currency) }}
                                            </button>
                                        </td>
                                        <td v-if="!isCompactDepotStocksTable" class="text-right">{{ formatPriceValue(holding.year_start_price, holding.currency) }}</td>
                                        <td class="text-right">
                                            <span class="d-inline-flex align-center justify-end ga-1 font-weight-medium" :class="yearStartChangeClass(holding)">
                                                <span>{{ yearStartChangeSymbol(holding) }}</span>
                                                <span>{{ formatYearStartChangePercent(holding) }}</span>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="font-weight-medium" :class="yearStartChangeClass(holding)">
                                                {{ formatYearStartChangeAmount(holding) }}
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <v-btn
                                                icon
                                                variant="text"
                                                color="success"
                                                aria-label="Buy stock"
                                                :disabled="!activeDepot || holdingsLoading"
                                                @click.stop="openStockTransactionDialog(holding, 'buy')"
                                            >
                                                <v-icon icon="mdi-cart-plus" />
                                            </v-btn>
                                            <v-btn
                                                icon
                                                variant="text"
                                                color="warning"
                                                aria-label="Sell stock"
                                                :disabled="!activeDepot || holdingsLoading"
                                                @click.stop="openStockTransactionDialog(holding, 'sell')"
                                            >
                                                <v-icon icon="mdi-cart-minus" />
                                            </v-btn>
                                        </td>
                                    </tr>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <td :colspan="isCompactDepotStocksTable ? 6 : 8" class="text-right font-weight-bold">Sum</td>
                                        <td class="text-right">
                                            <span class="font-weight-bold" :class="depotHoldingsChangeAmountTotalClass()">
                                                {{ formatDepotHoldingsChangeAmountTotal() }}
                                            </span>
                                        </td>
                                        <td />
                                    </tr>
                                </tfoot>
                            </v-table>
                            <p v-else-if="!transactionsLoading" class="text-medium-emphasis text-body-2 mt-2">No stocks in this depot.</p>
                        </div>

                        <div v-if="activeDepot" class="mt-6">
                            <div class="d-flex align-center justify-space-between flex-wrap ga-3 mb-2">
                                <p class="text-overline text-medium-emphasis mb-0">Cash ledger</p>
                                <div class="d-flex align-center ga-2">
                                    <v-btn
                                        color="success"
                                        prepend-icon="mdi-cash-plus"
                                        variant="tonal"
                                        @click="openCashTransactionDialog('deposit')"
                                    >
                                        Add cash
                                    </v-btn>
                                    <v-btn
                                        color="error"
                                        prepend-icon="mdi-cash-minus"
                                        variant="tonal"
                                        @click="openCashTransactionDialog('withdrawal')"
                                    >
                                        Withdraw
                                    </v-btn>
                                </div>
                            </div>
                            <v-progress-linear v-if="transactionsLoading" indeterminate class="mb-2" />
                            <v-alert v-if="transactionsError" type="error" variant="tonal" density="compact" class="mb-2">
                                {{ transactionsError }}
                            </v-alert>
                            <div v-if="transactions.length > 0" class="mobile-cash-ledger">
                                <article
                                    v-for="tx in transactions"
                                    :key="`mobile-transaction-${tx.id}`"
                                    class="mobile-cash-ledger-card"
                                >
                                    <div class="mobile-cash-ledger-row">
                                        <span>
                                            <v-chip :color="transactionTypeColor(tx.type)" density="comfortable" size="x-small" variant="tonal">
                                                {{ transactionTypeLabel(tx.type) }}
                                            </v-chip>
                                        </span>
                                        <span>{{ formatTransactionDate(tx.booked_at) }}</span>
                                    </div>
                                    <div v-if="tx.stock_label" class="mobile-cash-ledger-stock">
                                        {{ tx.stock_label }}
                                    </div>
                                    <div class="mobile-cash-ledger-row">
                                        <span :class="Number(tx.cash_delta) >= 0 ? 'text-success' : 'text-error'">
                                            {{ formatCashDelta(tx.cash_delta) }}
                                        </span>
                                        <span>{{ formatAccountBalance(tx.balance_after) }}</span>
                                    </div>
                                </article>
                            </div>
                            <v-table v-if="transactions.length > 0" class="desktop-cash-ledger-table" density="compact">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Stock</th>
                                        <th class="text-right">Pieces</th>
                                        <th v-if="!isCompactCashLedgerTable" class="text-right">Amount</th>
                                        <th class="text-right">Cash effect</th>
                                        <th class="text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="tx in transactions" :key="tx.id">
                                        <td class="text-caption text-medium-emphasis">{{ formatTransactionDate(tx.booked_at) }}</td>
                                        <td>
                                            <v-chip :color="transactionTypeColor(tx.type)" density="comfortable" size="x-small" variant="tonal">
                                                {{ transactionTypeLabel(tx.type) }}
                                            </v-chip>
                                        </td>
                                        <td>{{ tx.stock_label ?? '–' }}</td>
                                        <td class="text-right">{{ tx.pieces != null ? Math.trunc(Number(tx.pieces)) : '–' }}</td>
                                        <td v-if="!isCompactCashLedgerTable" class="text-right">{{ formatAccountBalance(tx.total_amount) }}</td>
                                        <td class="text-right" :class="Number(tx.cash_delta) >= 0 ? 'text-success' : 'text-error'">
                                            {{ formatCashDelta(tx.cash_delta) }}
                                        </td>
                                        <td class="text-right">{{ formatAccountBalance(tx.balance_after) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <p v-else-if="!transactionsLoading" class="text-medium-emphasis text-body-2 mt-2">No transactions yet.</p>
                        </div>

                        <v-alert v-else type="info" variant="tonal" density="compact" class="mt-4">
                            No active depot found.
                        </v-alert>
                    </section>

                    <section v-if="activeSection === 'depots'">
                        <div class="d-flex align-center justify-space-between mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Admin</p>
                                <h1 class="text-h4">Depots</h1>
                            </div>
                            <v-btn color="primary" prepend-icon="mdi-briefcase-plus-outline" variant="flat" @click="openCreateDepotDialog">
                                New depot
                            </v-btn>
                        </div>

                        <v-alert v-if="depotMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ depotMessage }}
                        </v-alert>
                        <v-alert v-if="depotError || depotsError" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ depotError || depotsError }}
                        </v-alert>

                        <v-table>
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th>Name</th>
                                    <th class="text-right">Account balance</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!depotsLoading && depots.length === 0">
                                    <td colspan="4">No depots found.</td>
                                </tr>
                                <tr v-for="depot in depots" :key="depot.id">
                                    <td>
                                        <v-chip v-if="depot.is_active" color="success" density="comfortable" size="small" variant="tonal">
                                            Active
                                        </v-chip>
                                        <span v-else class="text-medium-emphasis">Inactive</span>
                                    </td>
                                    <td>{{ depot.name }}</td>
                                    <td class="text-right">{{ formatAccountBalance(depot.current_account_balance ?? depot.account_balance) }} EUR</td>
                                    <td class="text-right">
                                        <v-btn icon variant="text" aria-label="Edit depot" @click="openEditDepotDialog(depot)">
                                            <v-icon icon="mdi-pencil-outline" />
                                        </v-btn>
                                        <v-btn
                                            icon
                                            variant="text"
                                            color="primary"
                                            aria-label="Make depot active"
                                            :disabled="depot.is_active || depotsLoading"
                                            @click="activateDepot(depot)"
                                        >
                                            <v-icon icon="mdi-check-circle-outline" />
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <v-progress-linear v-if="depotsLoading" indeterminate color="primary" class="mt-4" />

                        <v-pagination
                            v-if="depotPagination.last_page > 1"
                            v-model="depotPagination.current_page"
                            class="mt-6"
                            :length="depotPagination.last_page"
                            @update:model-value="depotsStore.loadDepots"
                        />

                        <v-dialog v-model="isDepotDialogOpen" persistent max-width="520">
                            <v-card>
                                <v-card-title>{{ depotDialogMode === 'create' ? 'Create depot' : 'Edit depot' }}</v-card-title>
                                <v-card-text>
                                    <form id="depot-form" @submit.prevent="saveDepot">
                                        <v-text-field v-model="depotForm.name" label="Name" required />
                                        <v-text-field
                                            v-model="depotForm.account_balance"
                                            label="Account balance"
                                            min="0"
                                            required
                                            step="0.01"
                                            type="number"
                                        />
                                    </form>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="depotsLoading" @click="abortDepotDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="submit" form="depot-form" color="primary" variant="flat" :loading="depotsLoading">
                                        Save
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                    </section>

                    <section v-if="activeSection === 'users' && canManageUsers">
                        <div class="d-flex align-center justify-space-between mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Admin</p>
                                <h1 class="text-h4">Users</h1>
                            </div>
                            <v-btn color="primary" prepend-icon="mdi-account-plus-outline" variant="flat" @click="openCreateUserDialog">
                                New user
                            </v-btn>
                        </div>

                        <v-alert v-if="userMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ userMessage }}
                        </v-alert>
                        <v-alert v-if="userError || usersError" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ userError || usersError }}
                        </v-alert>

                        <v-table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Roles</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="userRecord in users" :key="userRecord.id">
                                    <td>{{ userRecord.name }}</td>
                                    <td>{{ userRecord.email }}</td>
                                    <td>{{ userRecord.roles.join(', ') }}</td>
                                    <td class="text-right">
                                        <v-btn icon variant="text" aria-label="Edit user" @click="openEditUserDialog(userRecord)">
                                            <v-icon icon="mdi-pencil-outline" />
                                        </v-btn>
                                        <v-btn
                                            icon
                                            variant="text"
                                            color="error"
                                            aria-label="Delete user"
                                            :disabled="!userRecord.can_delete"
                                            @click="openDeleteUserDialog(userRecord)"
                                        >
                                            <v-icon icon="mdi-delete-outline" />
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <v-pagination
                            v-if="userPagination.last_page > 1"
                            v-model="userPagination.current_page"
                            class="mt-6"
                            :length="userPagination.last_page"
                            @update:model-value="usersStore.loadUsers"
                        />

                        <v-dialog v-model="isUserDialogOpen" persistent max-width="560">
                            <v-card>
                                <v-card-title>{{ userDialogMode === 'create' ? 'Create user' : 'Edit user' }}</v-card-title>
                                <v-card-text>
                                    <form id="user-form" @submit.prevent="saveUser">
                                        <v-text-field v-model="userForm.last_name" label="Last name" required />
                                        <v-text-field v-model="userForm.first_name" label="First name" required />
                                        <v-text-field v-model="userForm.email" autocomplete="email" label="Email" required />
                                        <v-select
                                            v-model="userForm.roles"
                                            chips
                                            label="Roles"
                                            multiple
                                            required
                                            :items="availableUserRoles"
                                        />
                                    </form>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="usersLoading" @click="abortUserDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="submit" form="user-form" color="primary" variant="flat" :loading="usersLoading">
                                        Save
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-model="isDeleteUserDialogOpen" persistent max-width="440">
                            <v-card>
                                <v-card-title>Delete user</v-card-title>
                                <v-card-text>Delete {{ selectedUser?.name }}?</v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="usersLoading" @click="abortDeleteUserDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="button" color="error" variant="flat" :loading="usersLoading" @click="deleteUser">
                                        Delete
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                    </section>

                    <section v-if="activeSection === 'roles' && canManageUsers">
                        <div class="d-flex align-center justify-space-between mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Admin</p>
                                <h1 class="text-h4">Roles</h1>
                            </div>
                            <v-btn color="primary" prepend-icon="mdi-shield-plus-outline" variant="flat" @click="openCreateRoleDialog">
                                New role
                            </v-btn>
                        </div>

                        <v-alert v-if="roleMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ roleMessage }}
                        </v-alert>
                        <v-alert v-if="roleError || rolesError" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ roleError || rolesError }}
                        </v-alert>

                        <v-table>
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Users</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="role in roles" :key="role.id">
                                    <td>{{ role.name }}</td>
                                    <td>{{ role.users_count }}</td>
                                    <td class="text-right">
                                        <v-btn
                                            icon
                                            variant="text"
                                            aria-label="Edit role"
                                            :disabled="!role.can_edit"
                                            @click="openEditRoleDialog(role)"
                                        >
                                            <v-icon icon="mdi-pencil-outline" />
                                        </v-btn>
                                        <v-btn
                                            icon
                                            variant="text"
                                            color="error"
                                            aria-label="Delete role"
                                            :disabled="!role.can_delete"
                                            @click="openDeleteRoleDialog(role)"
                                        >
                                            <v-icon icon="mdi-delete-outline" />
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <v-pagination
                            v-if="rolePagination.last_page > 1"
                            v-model="rolePagination.current_page"
                            class="mt-6"
                            :length="rolePagination.last_page"
                            @update:model-value="rolesStore.loadRoles"
                        />

                        <v-dialog v-model="isRoleDialogOpen" persistent max-width="480">
                            <v-card>
                                <v-card-title>{{ roleDialogMode === 'create' ? 'Create role' : 'Edit role' }}</v-card-title>
                                <v-card-text>
                                    <form id="role-form" @submit.prevent="saveRole">
                                        <v-text-field v-model="roleForm.name" label="Name" required />
                                    </form>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="rolesLoading" @click="abortRoleDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="submit" form="role-form" color="primary" variant="flat" :loading="rolesLoading">
                                        Save
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-model="isDeleteRoleDialogOpen" persistent max-width="440">
                            <v-card>
                                <v-card-title>Delete role</v-card-title>
                                <v-card-text>Delete {{ selectedRole?.name }}?</v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="rolesLoading" @click="abortDeleteRoleDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="button" color="error" variant="flat" :loading="rolesLoading" @click="deleteRole">
                                        Delete
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                    </section>

                    <section v-if="activeSection === 'profile'">
                        <div class="mb-6">
                            <p class="text-overline text-primary mb-1">Account</p>
                            <h1 class="text-h4">Profile</h1>
                        </div>

                        <v-alert v-if="profileMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ profileMessage }}
                        </v-alert>
                        <v-alert v-if="profileError || error" type="error" variant="tonal" density="compact" class="mb-4">
                            {{ profileError || error }}
                        </v-alert>

                        <v-row>
                            <v-col cols="12" md="6">
                                <v-card border flat>
                                    <v-card-title>Name</v-card-title>
                                    <v-card-text>
                                        <div class="text-h6">{{ profileDisplayName }}</div>
                                        <div class="text-body-2 text-medium-emphasis">{{ user?.email }}</div>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-btn color="primary" prepend-icon="mdi-pencil-outline" variant="tonal" @click="openNameDialog">
                                            Edit name
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-col>
                            <v-col cols="12" md="6">
                                <v-card border flat>
                                    <v-card-title>Password</v-card-title>
                                    <v-card-text>
                                        <div class="text-h6">Protected</div>
                                        <div class="text-body-2 text-medium-emphasis">Change the password used for admin login.</div>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-btn color="primary" prepend-icon="mdi-lock-reset" variant="tonal" @click="openPasswordDialog">
                                            Edit password
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-col>
                        </v-row>

                        <v-dialog v-model="isNameDialogOpen" persistent max-width="520">
                            <v-card>
                                <v-card-title>Edit name</v-card-title>
                                <v-card-text>
                                    <form id="profile-name-form" @submit.prevent="saveProfileName">
                                        <v-text-field v-model="profileLastName" autocomplete="family-name" label="Last name" required />
                                        <v-text-field v-model="profileFirstName" autocomplete="given-name" label="First name" required />
                                    </form>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="loading" @click="abortNameEdit">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="submit" form="profile-name-form" color="primary" variant="flat" :loading="loading">
                                        Save
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-model="isPasswordDialogOpen" persistent max-width="520">
                            <v-card>
                                <v-card-title>Edit password</v-card-title>
                                <v-card-text>
                                    <form id="profile-password-form" @submit.prevent="savePassword">
                                        <v-text-field
                                            v-model="newPassword"
                                            autocomplete="new-password"
                                            label="Password"
                                            required
                                            type="password"
                                        />
                                    </form>
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="loading" @click="abortPasswordEdit">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="submit" form="profile-password-form" color="primary" variant="flat" :loading="loading">
                                        Save
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
                    </section>
                </v-container>
            </v-main>
        </template>
    </v-app>
</template>

<style scoped>
.app-bar-row {
    align-items: center;
    display: flex;
    font-size: 0.8125rem;
    gap: 12px;
    line-height: 1.3;
    padding: 0 16px;
    width: 100%;
}

.dashboard-status-card-content {
    align-items: flex-start;
    display: flex;
    flex-wrap: wrap;
    gap: 10px 18px;
}

.dashboard-status-item {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    line-height: 1.35;
    min-width: min(100%, 220px);
}

.dashboard-menu-toggle {
    flex: 0 0 auto;
}

.dashboard-heading {
    align-items: center;
    display: flex;
    gap: 16px;
    justify-content: space-between;
}

.dashboard-actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    justify-content: flex-end;
}

.dashboard-navigation-drawer {
    transition: width 0.2s ease;
}

.dashboard-brand-mark {
    border-radius: 10px;
    display: block;
    flex: 0 0 auto;
    height: 44px;
    object-fit: contain;
    width: 44px;
}

.dashboard-brand-copy {
    min-width: 0;
}

.dashboard-brand-version {
    font-size: 0.75rem;
    line-height: 1.15;
}

.dashboard-navigation-drawer--compact .dashboard-navigation-header {
    padding-inline: 12px !important;
}

.dashboard-compact-menu {
    align-items: center;
    display: flex;
    flex-direction: column;
    gap: 4px;
    padding-inline: 8px;
}

.dashboard-compact-menu-item {
    align-items: center;
    background: transparent;
    border: 0;
    border-radius: 4px;
    color: rgba(var(--v-theme-on-surface), 0.72);
    cursor: pointer;
    display: flex;
    height: 44px;
    justify-content: center;
    padding: 0;
    width: 48px;
}

.dashboard-compact-menu-item--active {
    background: rgba(var(--v-theme-on-surface), 0.08);
    color: rgb(var(--v-theme-primary));
}

.mobile-cash-ledger,
.mobile-depot-stocks,
.mobile-watch-list {
    display: none;
}

@media (max-width: 600px) {
    .dashboard-heading {
        align-items: stretch;
        flex-direction: column;
        gap: 12px;
    }

    .dashboard-actions {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        justify-content: stretch;
        width: 100%;
    }

    .dashboard-action-button {
        min-width: 0;
        width: 100%;
    }

    .desktop-watch-list-table {
        display: none;
    }

    .desktop-depot-stocks-table {
        display: none;
    }

    .desktop-cash-ledger-table {
        display: none;
    }

    .mobile-cash-ledger {
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
    }

    .mobile-cash-ledger-card {
        background: rgb(var(--v-theme-surface));
        border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
        border-radius: 8px;
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
        padding: 12px;
    }

    .mobile-cash-ledger-row {
        align-items: center;
        display: flex;
        gap: 12px;
        justify-content: space-between;
    }

    .mobile-cash-ledger-row > span:last-child {
        text-align: right;
    }

    .mobile-cash-ledger-stock {
        font-weight: 600;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .mobile-depot-stocks {
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
    }

    .mobile-depot-price-source {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .mobile-depot-price-source .depot-price-source-button {
        text-align: center;
    }

    .mobile-depot-stock-card {
        background: rgb(var(--v-theme-surface));
        border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
        border-radius: 8px;
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
        padding: 12px;
    }

    .mobile-depot-stock-name {
        color: rgb(var(--v-theme-primary));
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .mobile-depot-stock-row {
        align-items: center;
        display: flex;
        gap: 12px;
        justify-content: space-between;
    }

    .mobile-depot-stock-row > span:last-child {
        text-align: right;
    }

    .mobile-depot-stock-row--prices {
        display: grid;
        grid-template-columns: minmax(44px, 0.65fr) minmax(0, 1fr) minmax(0, 1fr);
    }

    .mobile-depot-stock-row--prices > span {
        min-width: 0;
        overflow-wrap: anywhere;
    }

    .mobile-depot-stock-row--prices > span:not(:first-child) {
        text-align: right;
    }

    .mobile-depot-stock-actions {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .mobile-depot-stock-actions :deep(.v-btn) {
        min-width: 0;
        width: 100%;
    }

    .mobile-watch-list {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .mobile-stock-card {
        background: rgb(var(--v-theme-surface));
        border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
        border-radius: 8px;
        display: flex;
        flex-direction: column;
        gap: 8px;
        padding: 12px;
    }

    .mobile-stock-name {
        color: rgb(var(--v-theme-primary));
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.25;
    }

    .mobile-stock-price-row {
        align-items: center;
        display: flex;
    }

    .mobile-stock-price {
        flex-direction: column;
        display: inline-flex;
        font-weight: 600;
        gap: 2px;
        line-height: 1.2;
        padding: 3px 6px;
    }

    .mobile-stock-price-change {
        font-size: 0.78rem;
        font-weight: 700;
    }

    .mobile-stock-actions {
        display: grid;
        gap: 8px;
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .mobile-stock-actions :deep(.v-btn) {
        min-width: 0;
        width: 100%;
    }

}

.analyze-detail-page,
.analyze-overview-page {
    min-height: 320px;
}

.analyze-detail-header {
    align-items: center;
    display: flex;
    gap: 16px;
    justify-content: space-between;
    margin-bottom: 18px;
}

.analyze-detail-title {
    color: #1b1f24;
    font-size: 1.25rem;
    font-weight: 800;
    line-height: 1.3;
    margin: 0;
}

.analyze-detail-scope {
    color: #145b4b;
    font-size: 0.95rem;
    font-weight: 700;
    margin-top: 4px;
}

.analyze-detail-stock-menu {
    margin-bottom: 18px;
}

.analyze-detail-empty {
    border: 1px dashed rgba(20, 91, 75, 0.28);
    border-radius: 6px;
    color: #5d6773;
    padding: 18px;
}

.analyze-detail-days {
    display: grid;
    gap: 12px;
}

.analyze-detail-day-summary {
    display: grid;
    gap: 8px;
    grid-template-columns: repeat(8, minmax(0, 1fr));
    margin: 0;
    padding: 10px 14px 12px;
}

.analyze-detail-day-summary-item {
    border: 1px solid rgba(20, 91, 75, 0.12);
    border-radius: 5px;
    grid-column: span 2;
    min-width: 0;
    padding: 8px 10px;
}

.analyze-detail-day-summary-item.is-compact {
    grid-column: span 1;
}

.analyze-detail-day-summary-item dt {
    color: #667480;
    font-size: 0.68rem;
    font-weight: 600;
    line-height: 1.2;
    text-transform: uppercase;
}

.analyze-detail-day-summary-item dd {
    color: #102a34;
    display: flex;
    flex-wrap: wrap;
    font-size: 0.9rem;
    font-weight: 600;
    gap: 6px;
    line-height: 1.3;
    margin: 3px 0 0;
}

.analyze-detail-day-summary-change {
    font-size: 0.78rem;
    font-weight: 600;
}

.analyze-detail-day-summary-change.is-up {
    color: #16794c;
}

.analyze-detail-day-summary-item dd .is-up {
    color: #16794c;
}

.analyze-detail-day-summary-change.is-down {
    color: #b42318;
}

.analyze-detail-day-summary-item dd .is-down {
    color: #b42318;
}

.analyze-detail-day-summary-change.is-flat {
    color: #667480;
}

.analyze-detail-table-wrap {
    border: 1px solid rgba(20, 91, 75, 0.16);
    border-radius: 6px;
    overflow-x: auto;
}

.analyze-detail-table {
    min-width: 820px;
}

.analyze-intraday-card-grid {
    align-items: start;
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(auto-fit, minmax(300px, 520px));
}

.analyze-intraday-card {
    background: #ffffff;
    border: 1px solid rgba(20, 91, 75, 0.16);
    border-radius: 6px;
    padding: 14px;
}

.analyze-intraday-card-title {
    color: #1b1f24;
    font-size: 0.95rem;
    font-weight: 800;
    line-height: 1.25;
    margin: 0 0 12px;
}

.analyze-intraday-card-empty {
    min-height: 88px;
}

.analyze-intraday-price-table-wrap {
    max-width: 520px;
    width: 100%;
}

.analyze-intraday-price-table {
    min-width: 0;
}

@media (max-width: 720px) {
    .analyze-detail-day-summary {
        grid-template-columns: repeat(4, minmax(0, 1fr));
    }
}

.analyze-detail-table :deep(th) {
    color: #52606d;
    font-size: 0.72rem;
    letter-spacing: 0;
    text-transform: uppercase;
    white-space: nowrap;
}

.analyze-detail-table :deep(td) {
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}

.tests-chip-groups {
    display: grid;
    gap: 18px;
}

.tests-chip-group {
    min-width: 0;
}

.tests-chip-heading {
    color: rgba(var(--v-theme-on-surface), 0.68);
    font-size: 0.72rem;
    font-weight: 800;
    line-height: 1.2;
    margin: 0 0 8px;
    text-transform: uppercase;
}

.tests-chip-list {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}

.tests-chip {
    align-items: center;
    background: #ffffff;
    border: 1px solid #d4e0e4;
    border-radius: 5px;
    color: #0f2630;
    cursor: pointer;
    display: inline-flex;
    gap: 8px;
    height: 34px;
    max-width: min(100%, 260px);
    min-width: 0;
    padding: 0 11px;
    transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}

.tests-chip:hover,
.tests-chip--active {
    background: rgba(var(--v-theme-primary), 0.06);
    border-color: rgba(var(--v-theme-primary), 0.55);
    box-shadow: 0 1px 2px rgba(15, 38, 48, 0.08);
}

.tests-chip-symbol {
    flex: 0 0 auto;
    font-size: 0.78rem;
    font-weight: 900;
    line-height: 1;
}

.tests-chip-name {
    flex: 1 1 auto;
    font-size: 0.78rem;
    font-weight: 600;
    line-height: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.tests-chip-empty {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.82rem;
}

.tests-tabs {
    margin-top: 20px;
}

.tests-ticker-panel {
    height: 2000px;
    margin-top: 14px;
    overflow: auto;
}

.tests-ticker-actions {
    align-items: center;
    display: flex;
    gap: 12px;
    justify-content: space-between;
}

.tests-ticker-table-wrap {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 5px;
    margin-top: 12px;
    overflow-x: auto;
}

.tests-ticker-table {
    min-width: 760px;
}

.tests-ticker-table :deep(th) {
    color: rgba(var(--v-theme-on-surface), 0.68);
    font-size: 0.72rem;
    letter-spacing: 0;
    text-transform: uppercase;
    white-space: nowrap;
}

.tests-ticker-table :deep(td) {
    font-size: 0.78rem;
    white-space: nowrap;
}

.tests-exchange-details {
    margin-top: 16px;
}

.tests-exchange-detail {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 5px;
    margin-top: 8px;
    overflow: hidden;
}

.tests-exchange-detail-code {
    background: rgba(var(--v-theme-primary), 0.06);
    color: rgba(var(--v-theme-on-surface), 0.78);
    font-size: 0.78rem;
    font-weight: 800;
    padding: 8px 10px;
}

.tests-exchange-detail-json {
    font-size: 0.78rem;
    line-height: 1.5;
    margin: 0;
    max-height: 420px;
    overflow: auto;
    padding: 10px;
    white-space: pre-wrap;
}

.data-page-header {
    align-items: center;
    display: flex;
    gap: 16px;
    justify-content: space-between;
}

.data-exchange-actions {
    align-items: center;
    display: flex;
    gap: 16px;
    justify-content: space-between;
}

.data-exchange-list {
    display: grid;
    gap: 12px;
}

.data-intraday-panel {
    height: 2000px;
    overflow: auto;
}

.data-progress-wrap {
    overflow: hidden;
    padding: 0 16px 16px;
    width: 100%;
}

.data-intraday-day {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 6px;
    margin-bottom: 18px;
    overflow: hidden;
}

.data-intraday-day-header {
    align-items: center;
    background: rgba(var(--v-theme-primary), 0.04);
    color: inherit;
    cursor: pointer;
    display: grid;
    font: inherit;
    gap: 12px;
    grid-template-columns: minmax(0, 1fr) auto auto;
    padding: 12px 14px;
    text-align: left;
    width: 100%;
}

.data-intraday-day-header:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

.data-intraday-day-header:hover {
    background: rgba(var(--v-theme-primary), 0.07);
}

.data-intraday-day-title {
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.3;
    min-width: 0;
}

.data-intraday-day-count {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.78rem;
    font-weight: 700;
    white-space: nowrap;
}

.data-intraday-day-body {
    padding: 12px 14px 14px;
}

.data-intraday-day-overview {
    color: rgba(var(--v-theme-on-surface), 0.68);
    font-size: 0.82rem;
    font-weight: 600;
    margin: 0 0 8px;
}

.data-exchange-item {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 6px;
    overflow: hidden;
}

.data-exchange-summary {
    align-items: center;
    background: rgba(var(--v-theme-primary), 0.04);
    display: flex;
    gap: 12px;
    justify-content: space-between;
    padding: 12px 14px;
}

.data-exchange-title {
    font-size: 1rem;
    font-weight: 500;
    line-height: 1.3;
    margin: 0;
}

.data-exchange-meta {
    color: rgba(var(--v-theme-on-surface), 0.64);
    font-size: 0.8rem;
    margin-top: 2px;
}

.data-exchange-code {
    border: 1px solid rgba(var(--v-theme-primary), 0.3);
    border-radius: 4px;
    color: rgb(var(--v-theme-primary));
    flex: 0 0 auto;
    font-size: 0.78rem;
    font-weight: 800;
    padding: 4px 8px;
}

.data-exchange-detail-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: minmax(0, 1fr);
    padding: 14px;
}

.data-exchange-detail-block h3 {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.76rem;
    font-weight: 500;
    line-height: 1.2;
    margin: 0 0 8px;
    text-transform: uppercase;
}

.data-definition-list {
    display: flex;
    flex-wrap: nowrap;
    align-items: flex-start;
    gap: 0 0;
    margin: 0;
    padding: 0;
}

.data-definition-pair {
    min-width: 160px;
    padding-right: 20px;
}

.data-definition-spacer {
    width: 20px;
    flex-shrink: 0;
}

.data-definition-list dt {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.8rem;
}

.data-definition-list dd {
    font-size: 0.82rem;
    font-weight: 700;
    margin: 0;
}

.data-holiday-list {
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.data-holiday-card {
    background: rgba(200, 100, 20, 0.08);
    border: 1px solid rgba(200, 100, 20, 0.18);
    border-radius: 6px;
    padding: 5px 9px;
}

.data-holiday-card-date {
    color: rgba(var(--v-theme-on-surface), 0.58);
    font-size: 0.74rem;
    line-height: 1.3;
}

.data-holiday-card-label {
    font-size: 0.8rem;
    font-weight: 500;
    line-height: 1.3;
}

@media (max-width: 900px) {
    .data-page-header,
    .data-exchange-actions,
    .data-exchange-summary {
        align-items: flex-start;
        flex-direction: column;
    }

    .data-definition-list {
        flex-wrap: wrap;
    }

    .data-definition-spacer {
        display: none;
    }

    .data-definition-pair {
        min-width: 140px;
    }
}

.price-refresh-status-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    display: inline-block;
}

.price-refresh-status-dot--updating {
    background: #d32f2f;
}

.price-refresh-status-dot--waiting {
    background: #2e7d32;
}

.depot-price-source-button {
    border: 1px solid transparent;
    border-radius: 4px;
    color: inherit;
    font: inherit;
    font-weight: 600;
    padding: 2px 6px;
    text-align: right;
}

.depot-price-source-button--active {
    border-color: rgb(var(--v-theme-success));
}

.index-watch-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}

.index-add-tile {
    align-items: center;
    background: transparent;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    color: rgb(var(--v-theme-primary));
    cursor: pointer;
    display: inline-flex;
    flex-direction: column;
    height: 116px;
    justify-content: center;
    width: 112px;
}

.index-watch-card {
    align-items: center;
    background: transparent;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    color: inherit;
    cursor: pointer;
    display: inline-flex;
    flex-direction: column;
    font: inherit;
    height: 116px;
    justify-content: space-between;
    padding: 7px 6px;
    text-align: center;
    width: 112px;
}

.index-watch-card:focus-visible,
.index-add-tile:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 2px;
}

.index-watch-card:hover {
    border-color: rgb(var(--v-theme-primary));
}

.index-add-tile-plus {
    font-size: 3rem;
    font-weight: 300;
    line-height: 0.9;
}

.index-add-tile-label {
    font-size: 0.8125rem;
    font-weight: 700;
    line-height: 1.2;
}

.index-watch-card-symbol {
    color: rgb(var(--v-theme-primary));
    font-size: 0.95rem;
    font-weight: 700;
    line-height: 1.1;
}

.index-watch-card-header {
    align-items: center;
    display: inline-flex;
    flex-direction: column;
    gap: 1px;
    max-width: 100%;
}

.index-watch-card-country {
    color: rgba(var(--v-theme-on-surface), 0.7);
    font-size: 0.625rem;
    font-weight: 600;
    line-height: 1;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.index-watch-card-label {
    display: -webkit-box;
    font-size: 0.6875rem;
    line-height: 1.15;
    margin-top: 2px;
    overflow: hidden;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
}

.index-watch-card-price {
    color: rgba(var(--v-theme-on-surface), 0.62);
    display: inline-flex;
    flex-direction: column;
    font-size: 0.625rem;
    font-weight: 700;
    line-height: 1.1;
    margin-top: 2px;
}

.index-watch-card-price.is-up {
    color: rgb(var(--v-theme-success));
}

.index-watch-card-price.is-down {
    color: rgb(var(--v-theme-error));
}

.analyze-holding-card {
    justify-content: center;
    gap: 10px;
}

.analyze-holding-card--all {
    gap: 0;
}

.analyze-holding-card--active {
    background: rgba(var(--v-theme-primary), 0.08);
    border-color: rgba(var(--v-theme-primary), 0.72);
}

.analyze-holding-card-name {
    -webkit-line-clamp: 3;
    font-weight: 700;
    margin-top: 0;
}

.analyze-holding-card-price {
    color: rgb(var(--v-theme-primary));
    font-size: 0.7rem;
}

.analyze-range-selector {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
}

.analyze-range-menu {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
}

.analyze-range-step-button {
    align-items: center;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    color: rgba(var(--v-theme-on-surface), 0.72);
    display: inline-flex;
    height: 26px;
    justify-content: center;
    line-height: 1;
    padding: 0;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease, opacity 0.15s ease;
    width: 30px;
}

.analyze-range-step-button:not(:disabled):hover {
    background: rgba(var(--v-theme-primary), 0.08);
    border-color: rgba(var(--v-theme-primary), 0.58);
    color: rgb(var(--v-theme-primary));
}

.analyze-range-step-button:disabled {
    cursor: default;
    opacity: 0.38;
}

.analyze-range-button {
    align-items: center;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    color: rgba(var(--v-theme-on-surface), 0.72);
    display: inline-flex;
    font-size: 0.6875rem;
    font-weight: 700;
    height: 26px;
    justify-content: center;
    line-height: 1;
    min-width: 58px;
    padding: 0 9px;
    text-transform: uppercase;
    transition: background-color 0.15s ease, border-color 0.15s ease, color 0.15s ease;
}

.analyze-range-button--active {
    background: rgba(var(--v-theme-primary), 0.08);
    border-color: rgba(var(--v-theme-primary), 0.7);
    color: rgb(var(--v-theme-primary));
}

.analyze-sparkline-panel {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 0;
    margin-top: 10px;
    padding: 14px;
}

.analyze-sparkline-header {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 6px 12px;
    justify-content: space-between;
    margin-bottom: 6px;
}

.analyze-sparkline-name {
    color: rgb(var(--v-theme-primary));
    font-size: 0.8125rem;
    font-weight: 800;
    line-height: 1.2;
}

.analyze-sparkline-meta {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1.2;
}

.analyze-sparkline {
    display: block;
    height: 600px;
    width: 100%;
}

@media (max-width: 600px), (max-width: 960px) and (max-height: 600px) and (orientation: landscape) {
    .analyze-sparkline {
        aspect-ratio: 12 / 5;
        height: auto !important;
    }
}

.analyze-sparkline-plot {
    fill: rgba(var(--v-theme-primary), 0.025);
    stroke: rgba(var(--v-border-color), 0.45);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-grid-line {
    stroke: rgba(var(--v-theme-on-surface), 0.1);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-grid-line--vertical {
    stroke: rgba(var(--v-theme-on-surface), 0.055);
}

.analyze-sparkline-month-line {
    stroke: rgba(var(--v-theme-on-surface), 0.085);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-label {
    fill: rgba(var(--v-theme-on-surface), 0.6);
    font-size: 11px;
    font-weight: 700;
}

.analyze-sparkline-y-label {
    dominant-baseline: middle;
    text-anchor: end;
}

.analyze-sparkline-x-label {
    dominant-baseline: middle;
    text-anchor: start;
}

.analyze-sparkline-x-label--end {
    text-anchor: end;
}

.analyze-sparkline-month-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.56);
    text-anchor: middle;
}

.analyze-sparkline-month-price-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 10px;
    font-weight: 400;
    paint-order: stroke;
    stroke: rgb(var(--v-theme-surface));
    stroke-linejoin: round;
    stroke-width: 3;
    text-anchor: middle;
}

.analyze-sparkline-date-range-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 11px;
    font-weight: 800;
}

.analyze-sparkline-area {
    fill: url("#analyze-sparkline-area-fill");
}

.analyze-sparkline-area-stop--top {
    stop-color: rgb(var(--v-theme-primary));
    stop-opacity: 0.22;
}

.analyze-sparkline-area-stop--bottom {
    stop-color: rgb(var(--v-theme-primary));
    stop-opacity: 0.015;
}

.analyze-sparkline-line {
    fill: none;
    stroke: rgb(var(--v-theme-primary));
    stroke-linecap: butt;
    stroke-linejoin: miter;
    stroke-width: 2.25;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-trend-line {
    fill: none;
    opacity: 0.62;
    stroke: #f97316;
    stroke-dasharray: 1 8;
    stroke-linecap: round;
    stroke-width: 1.7;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-line--down {
    stroke: rgb(var(--v-theme-error));
}

.analyze-sparkline-line--flat {
    stroke: rgba(var(--v-theme-on-surface), 0.68);
}

.analyze-sparkline-dot {
    fill: rgb(var(--v-theme-primary));
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 1.5;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-line--down ~ .analyze-sparkline-dot {
    fill: rgb(var(--v-theme-error));
}

.analyze-sparkline-point {
    fill: rgb(var(--v-theme-primary));
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-line--down ~ .analyze-sparkline-point,
.analyze-sparkline-line--down + .analyze-sparkline-point {
    fill: rgb(var(--v-theme-error));
}

.analyze-sparkline-point--first {
    fill: rgba(var(--v-theme-on-surface), 0.45);
}

.analyze-sparkline-point--latest {
    filter: drop-shadow(0 1px 2px rgba(0, 0, 0, 0.22));
}

.analyze-sparkline-extremum {
    color: rgb(var(--v-theme-primary));
}

.analyze-sparkline-extremum--high {
    color: rgb(var(--v-theme-success));
}

.analyze-sparkline-extremum--low {
    color: rgb(var(--v-theme-error));
}

.analyze-sparkline-extremum-line {
    stroke: currentColor;
    stroke-dasharray: 2 3;
    stroke-linecap: round;
    stroke-width: 1.25;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-extremum-ring {
    fill: rgb(var(--v-theme-surface));
    stroke: currentColor;
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-extremum-dot {
    fill: currentColor;
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 0.75;
    vector-effect: non-scaling-stroke;
}

.analyze-sparkline-extremum-label {
    dominant-baseline: middle;
    fill: currentColor;
    font-size: 12px;
    font-weight: 800;
    paint-order: stroke;
    stroke: rgb(var(--v-theme-surface));
    stroke-linejoin: round;
    stroke-width: 4;
}

.analyze-sparkline-endpoint-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.82);
    font-size: 12px;
    font-weight: 800;
    paint-order: stroke;
    stroke: rgb(var(--v-theme-surface));
    stroke-linejoin: round;
    stroke-width: 4;
}

.analyze-sparkline-endpoint-label--latest {
    fill: rgb(var(--v-theme-primary));
}

.analyze-sparkline-endpoint-label-change--up {
    fill: rgb(var(--v-theme-success));
}

.analyze-sparkline-endpoint-label-change--down {
    fill: rgb(var(--v-theme-error));
}

.index-price-dialog-title {
    align-items: flex-start;
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.index-price-dialog-symbol {
    color: rgb(var(--v-theme-primary));
    font-size: 1.15rem;
    font-weight: 700;
    margin-right: 8px;
}

.index-price-dialog-country {
    color: rgba(var(--v-theme-on-surface), 0.68);
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
}

.index-price-dialog-name {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.875rem;
    font-weight: 500;
}

.index-price-dialog-code {
    align-items: center;
    color: rgba(var(--v-theme-on-surface), 0.45);
    display: inline-flex;
    font-size: 0.75rem;
    font-weight: 400;
    gap: 4px;
}

.index-price-dialog-code-copy {
    background: none;
    border: none;
    color: inherit;
    cursor: pointer;
    line-height: 1;
    opacity: 0.6;
    padding: 0;
}

.index-price-dialog-code-copy:hover {
    opacity: 1;
}

.index-price-current {
    align-items: flex-start;
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    display: inline-flex;
    flex-direction: column;
    min-width: 180px;
    padding: 10px 12px;
}

.index-price-current-value {
    display: inline-flex;
    flex-direction: column;
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.2;
}

.index-price-current-value.is-up {
    color: rgb(var(--v-theme-success));
}

.index-price-current-value.is-down {
    color: rgb(var(--v-theme-error));
}

.index-price-current-value.is-flat {
    color: rgba(var(--v-theme-on-surface), 0.72);
}

.index-price-history-table {
    min-width: 100%;
}

.index-price-history-table-wrap {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    max-height: 280px;
    overflow: auto;
}

.index-price-history-table thead th {
    background: rgb(var(--v-theme-surface));
    position: sticky;
    top: 0;
    z-index: 1;
}

.index-price-chart-panel {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    padding: 12px;
}

.index-price-chart {
    display: block;
    height: 260px;
    width: 100%;
}

.index-price-chart-axis {
    stroke: rgba(var(--v-theme-on-surface), 0.18);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.index-price-chart-grid-line {
    stroke: rgba(var(--v-theme-on-surface), 0.09);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.index-price-chart-x-label,
.index-price-chart-y-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.58);
    font-size: 11px;
    font-weight: 600;
}

.index-price-chart-line {
    fill: none;
    stroke: rgb(var(--v-theme-primary));
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 3;
    vector-effect: non-scaling-stroke;
}

.index-price-chart-trend-line {
    opacity: 0.62;
    stroke: #f97316;
    stroke-dasharray: 1 7;
    stroke-linecap: round;
    stroke-width: 1.55;
    vector-effect: non-scaling-stroke;
}

.index-price-chart-point {
    fill: rgb(var(--v-theme-primary));
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
}

.index-price-chart-endpoint-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.82);
    font-size: 11px;
    font-weight: 800;
    paint-order: stroke;
    stroke: rgb(var(--v-theme-surface));
    stroke-linejoin: round;
    stroke-width: 4;
}

.index-price-chart-endpoint-label--latest {
    fill: rgb(var(--v-theme-primary));
}

.flatex-price-button {
    color: inherit;
    font: inherit;
    text-align: right;
    width: 100%;
}

.depot-holding-row--positive td {
    background-color: rgba(var(--v-theme-success), 0.06);
}

.depot-holding-row--negative td {
    background-color: rgba(var(--v-theme-error), 0.06);
}

.flatex-price-input {
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    font: inherit;
    max-width: 130px;
    padding: 2px 6px;
    text-align: right;
    width: 100%;
}

.latest-price-tick {
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1;
}

.latest-price-value {
    border-radius: 4px;
    line-height: 1.2;
    padding: 2px 5px;
}

.latest-price-change {
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1;
}

.session-price-change {
    font-size: 0.6875rem;
    font-weight: 700;
    line-height: 1.1;
    margin-top: 2px;
}

.stock-holding-row {
    cursor: pointer;
}

.stock-holding-row:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: -2px;
}

.stock-holding-detail-row td {
    background: rgb(var(--v-theme-surface-variant));
}

.holding-intraday-detail {
    display: grid;
    gap: 8px;
}

.holding-intraday-detail-header {
    align-items: baseline;
    display: flex;
    gap: 12px;
    justify-content: space-between;
}

.holding-intraday-table-wrap {
    max-height: 360px;
    overflow: auto;
}

.holding-intraday-table {
    background: rgb(var(--v-theme-surface));
    border-collapse: collapse;
    font-size: 0.8125rem;
    min-width: 620px;
    width: 100%;
}

.holding-intraday-table th,
.holding-intraday-table td {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    padding: 4px 8px;
    white-space: nowrap;
}

.holding-intraday-table th {
    background: rgb(var(--v-theme-surface));
    position: sticky;
    top: 0;
    z-index: 1;
}

.recent-price-item {
    align-items: baseline;
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    display: inline-flex;
    gap: 6px;
    min-width: 118px;
    padding: 4px 6px;
}

.recent-price-fallback-note {
    flex-basis: 100%;
}

.recent-price-trend {
    margin-left: auto;
    text-align: right;
    width: 12px;
}

.recent-price-trend-dots {
    align-items: center;
    display: flex;
    gap: 4px;
    margin-bottom: 6px;
    margin-top: 3px;
}

.recent-price-trend-dot {
    border-radius: 999px;
    display: inline-block;
    height: 8px;
    width: 8px;
}

.recent-price-trend-dot-up {
    background: rgba(var(--v-theme-success), 0.65);
}

.recent-price-trend-dot-down {
    background: rgba(var(--v-theme-error), 0.65);
}

.recent-price-trend-dot-flat {
    background: rgba(17, 17, 17, 0.55);
}
</style>
