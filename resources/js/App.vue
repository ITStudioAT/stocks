<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useDisplay } from 'vuetify';
import { storeToRefs } from 'pinia';
import { csrfToken, request, useAuthStore } from './stores/auth';
import { useDepotStore } from './stores/depots';
import { useRoleStore } from './stores/roles';
import { useUserStore } from './stores/users';
import { formatAdaptiveNumber, formatPriceValue } from './utils/numberFormatters';
import HoldingPriceReload from './HoldingPriceReload.vue';
import InstrumentSearchSource from './InstrumentSearchSource.vue';
import DataSourceMarkers from './DataSourceMarkers.vue';
import PerformanceConsistency from './PerformanceConsistency.vue';
import CloudwaysSynchronization from './CloudwaysSynchronization.vue';
import { marketDataMarkers, marketDataSourceText, mergeMarketDataMarkers } from './utils/marketDataProvenance';
const logoMarkUrl = '/images/gkstocks-logo-mark.png';
const displayTimeZone = 'Europe/Vienna';
const kestTaxRate = 0.275;
const indexPriceRangeItems = [
    { key: 'intraday', label: 'Intraday' },
    { key: '1w', label: '1 week' },
    { key: '1m', label: '1 month' },
    { key: '6m', label: '6 month' },
    { key: '1y', label: '1 year' },
];
const cashLedgerPageSize = 20;
const indexRealtimeOverdueCheckIntervalMilliseconds = 1000;
const activeItemRefreshIntervalMilliseconds = 60_000;
const indexRealtimeDispatchRetryDelayMilliseconds = 5000;
const EODHD_REQUESTS_ENABLED = false;
const STOCK_EODHD_SYNC_DISMISSED_REFRESH_ID_KEY = 'stock_eodhd_sync_dismissed_refresh_id';
const cashTransactionTypeOptions = [
    { title: 'Start balance', value: 'opening_balance' },
    { title: 'Deposit', value: 'deposit' },
    { title: 'Withdrawal', value: 'withdrawal' },
    { title: 'Dividend', value: 'dividend' },
    { title: 'Interest', value: 'interest' },
    { title: 'Fee', value: 'fee' },
    { title: 'Tax', value: 'tax' },
    { title: 'Broker bonus', value: 'broker_bonus' },
];
const dashboardPerformanceCardLabels = [
    'Montag',
    'Dienstag',
    'Mittwoch',
    'Donnerstag',
    'Freitag',
];
const dashboardPerformanceSumLabels = [
    { key: 'week', label: 'Week' },
    { key: 'last_week', label: 'Last Week' },
    { key: 'month', label: 'Month' },
    { key: 'last_month', label: 'Last Month' },
    { key: 'year', label: 'Year' },
];

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
    indexEodhdSync,
    stockEodhdSync,
    indexEodhdSyncSettings,
    depotHoldings,
    depotValuations,
    depotPerformanceSeries,
    dashboardDailyPerformance,
    dashboardPerformanceSums,
    depotStockPeriodStocks,
    depotStockPeriod,
    depotStockPeriodLabel,
    depotStockPeriodYear,
    transactions,
    appVersion,
    eodhdApiUsage,
    priceRefresh,
    priceRefreshSettings,
    indexPriceRefreshSettings,
    intradayBackfillSettings,
    intradayBackfillRefresh,
    holdingIntradayCandles,
    holdingIntradayCandlesLoading,
    holdingIntradayCandlesErrors,
    uiPreferences,
    stockSearchResults,
    stockSearchSource,
    stockSearchNotice,
    stockSearchCatalogDate,
    stockSearchStatus,
    pagination: depotPagination,
    holdingsPagination,
    loading: depotsLoading,
    holdingsLoading,
    transactionsLoading,
    dashboardDailyPerformanceLoading,
    depotStockPeriodLoading,
    stockSearchLoading,
    error: depotsError,
    holdingsError,
    transactionsError,
    dashboardDailyPerformanceError,
    depotStockPeriodError,
    stockSearchError,
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
let privateIndexRefreshTimer = null;
const activeDataSubsection = ref('overview');
const infoTables = ref([]);
const infoMethods = ref([]);
const infoTablesLoading = ref(false);
const infoTablesError = ref('');
const infoTableSearch = ref('');
const activeInfoSubsection = ref('eodhd');
const activeDepotSubsection = ref('overview');
const activeDepotStocksSubsection = ref('actual-year');
const expandedDataIntradayDays = ref({});
const profileLastName = ref('');
const profileFirstName = ref('');
const currentPassword = ref('');
const newPassword = ref('');
const newPasswordConfirmation = ref('');
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
const depotDialogMode = ref('create');
const isDepotDialogOpen = ref(false);
const selectedDepot = ref(null);
const depotForm = ref(emptyDepotForm());
const depotMessage = ref('');
const depotError = ref('');
const isHoldingDialogOpen = ref(false);
const isEditHoldingDialogOpen = ref(false);
const isIndexDialogOpen = ref(false);
const isIndexPriceChartLoading = ref(false);
const isDeleteIndexDialogOpen = ref(false);
const isDeleteHoldingDialogOpen = ref(false);
const isCashTransactionDialogOpen = ref(false);
const isStockTransactionDialogOpen = ref(false);
const selectedHolding = ref(null);
const selectedStockWatchItem = ref(null);
const selectedStockPriceRange = ref('intraday');
const isStockPriceChartLoading = ref(false);
const stockPriceChartError = ref('');
const selectedIndexWatchItem = ref(null);
const selectedIndexPriceRange = ref('intraday');
const selectedIndexWatchItemForRemoval = ref(null);
const transactionHolding = ref(null);
const holdingSearchQuery = ref('');
watch(stockSearchSource, () => {
    depotsStore.stockSearchResults = [];
    depotsStore.stockSearchError = '';
    depotsStore.stockSearchNotice = '';
    depotsStore.stockSearchCatalogDate = null;
    depotsStore.stockSearchStatus = '';
});
const holdingSubtitle = ref('');
const holdingForm = ref(emptyHoldingForm());
const indexSearchQuery = ref('');
const holdingSearchInput = ref(null);
const indexSearchInput = ref(null);
const holdingMessage = ref('');
const holdingError = ref('');
const dashboardVersions = ref(null);
const dashboardVersionsLoading = ref(false);
const dashboardVersionsError = ref('');
const isDashboardReloading = ref(false);
const isDashboardVersionDetailsVisible = ref(false);
const indexMessage = ref('');
const indexError = ref('');
const indexPriceChartError = ref('');
const indexEodhdSyncError = ref('');
const stockEodhdSyncError = ref('');
const indexEodhdSyncScheduleMessage = ref('');
const indexEodhdSyncScheduleError = ref('');
const indexEodhdSyncScheduleForm = ref(emptyIndexEodhdSyncScheduleForm());
const isIndexEodhdSyncScheduleDialogOpen = ref(false);
const isIndexEodhdSyncScheduleSaving = ref(false);
const indexV2RealtimeScheduleForm = ref(emptyIndexV2RealtimeScheduleForm());
const indexV2RealtimeScheduleError = ref('');
const isIndexV2RealtimeScheduleDialogOpen = ref(false);
const isIndexV2RealtimeScheduleSaving = ref(false);
const expandedHoldingIds = ref([]);
const priceRefreshScheduleForm = ref(emptyPriceRefreshScheduleForm());
const indexPriceRefreshScheduleForm = ref(emptyPriceRefreshScheduleForm());
const intradayBackfillScheduleForm = ref(emptyIntradayBackfillScheduleForm());
const liveDataUpdateScheduleForm = ref(emptyPriceRefreshScheduleForm());
const priceRefreshScheduleMessage = ref('');
const priceRefreshScheduleError = ref('');
const isPriceRefreshScheduleEditing = ref(false);
const isIndexPriceRefreshScheduleEditing = ref(false);
const isIntradayBackfillScheduleEditing = ref(false);
const isLiveDataUpdateScheduleSaving = ref(false);
const isIntradayBackfillRunningNow = ref(false);
const priceRefreshTimer = ref(null);
const intradayBackfillTimer = ref(null);
const liveDataStatusNow = ref(Date.now());
const isLiveDataUpdateDialogOpen = ref(false);
const indexDataSyncMessage = ref('');
const indexHistoricalDataSyncMessage = ref('');
const indexEodhdSyncTimer = ref(null);
const stockEodhdSyncTimer = ref(null);
const indexRealtimeOverdueTimer = ref(null);
const activeItemRefreshTimer = ref(null);
const isActiveItemRefreshing = ref(false);
const isIndexRealtimeDueDispatching = ref(false);
const nextIndexRealtimeDispatchAttemptAt = ref(0);
const isDashboardMenuCompact = ref(smAndDown.value);
const viewportWidth = ref(window.visualViewport?.width ?? window.innerWidth);
const viewportHeight = ref(window.visualViewport?.height ?? window.innerHeight);
const cashTransactionForm = ref(emptyCashTransactionForm());
const stockTransactionForm = ref(emptyStockTransactionForm());
const cashLedgerPage = ref(1);
const editingFlatexHoldingId = ref(null);
const flatexPriceEditValue = ref('');
const flatexPriceEditInput = ref(null);
const depotPriceSource = ref('latest');

const isLoginPage = computed(() => window.location.pathname === '/admin/login');
const canManageUsers = computed(() => user.value?.roles?.includes('super_admin') ?? false);
const canUseCloudways = computed(() => canManageUsers.value && user.value?.cloudways_available === true);
const canManageDashboardAdmin = computed(() => user.value?.roles?.some((role) => ['admin', 'super_admin'].includes(role)) ?? false);
const canInitializePassword = computed(() => user.value?.can_initialize_password === true);
const profileDisplayName = computed(() => user.value?.name || 'Loading...');
const appVersionLabel = computed(() => dashboardVersions.value?.current_version ?? appVersion.value ?? '');
const dashboardCurrentVersion = computed(() => dashboardVersions.value?.current_version ?? appVersion.value ?? 'x.x.x');
const installedVersionItems = computed(() => dashboardVersions.value?.versions ?? []);
const dashboardPerformanceCards = computed(() => dashboardPerformanceCardLabels.map((fallbackLabel, index) => {
    const performance = dashboardDailyPerformance.value?.[index] ?? null;
    const weekday = performance
        ? new Intl.DateTimeFormat('de-DE', { weekday: 'long', timeZone: 'UTC' })
            .format(new Date(`${performance.date}T00:00:00Z`))
        : fallbackLabel;

    return {
        key: performance?.date ?? `empty-${index}`,
        label: performance?.is_live ? `${weekday} · Live` : weekday,
        performance,
    };
}));
const dashboardPerformanceSumCards = computed(() => dashboardPerformanceSumLabels.map(({ key, label }) => ({
    key,
    label,
    performance: dashboardPerformanceSums.value.find((performance) => performance.period === key) ?? null,
})));
const dashboardMenuToggleLabel = computed(() => (isDashboardMenuCompact.value
    ? 'Enhance dashboard menu'
    : 'Minify dashboard menu'));
const isCompactWatchListTable = computed(() => viewportWidth.value < 1024);
const isHandsetLandscape = computed(() => viewportWidth.value <= 960
    && viewportHeight.value <= 600
    && viewportWidth.value > viewportHeight.value);
const isCompactDepotStocksTable = computed(() => isHandsetLandscape.value);
const isCompactCashLedgerTable = computed(() => isHandsetLandscape.value);
const watchListTableColumnCount = computed(() => (isCompactWatchListTable.value ? 5 : 7));
const roleList = computed(() => user.value?.roles?.join(', ') ?? '');
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
const isDataIntradayReloadRunning = computed(() => ['queued', 'running'].includes(dataIntradayRefresh.value?.status));
const expandedHoldingIntradayCharts = computed(() => Object.fromEntries(
    holdings.value
        .filter((holding) => expandedHoldingIds.value.includes(holding.id))
        .map((holding) => [
            holding.id,
            buildHoldingIntradayChart(expandedHoldingIntradayChartRows(holding), holding),
        ]),
));
const DATA_INTRADAY_REFRESH_DISMISSED_KEY = 'data_intraday_refresh_info_dismissed';
const isDataIntradayRefreshDismissed = ref(localStorage.getItem(DATA_INTRADAY_REFRESH_DISMISSED_KEY) === '1');
function dismissDataIntradayRefresh() {
    isDataIntradayRefreshDismissed.value = true;
    localStorage.setItem(DATA_INTRADAY_REFRESH_DISMISSED_KEY, '1');
}
const sessionHeaderDates = computed(() => {
    const datedHolding = holdings.value.find((holding) => holding.start_price_date || holding.end_price_24_date);

    return {
        start: formatSessionHeaderDateValue(datedHolding?.start_price_date) ?? formatSessionHeaderDate(0),
        lastDay: formatSessionHeaderDateValue(datedHolding?.end_price_24_date) ?? formatSessionHeaderDate(1),
    };
});
const selectedIndexRecentPrices = computed(() => indexPricesForRange(
    selectedIndexWatchItem.value?.recent_prices ?? [],
    selectedIndexPriceRange.value,
));
const sortedIndexWatchItems = computed(() => [...indexWatchItems.value].sort((firstIndex, secondIndex) => {
    const symbolComparison = String(firstIndex.symbol ?? '')
        .localeCompare(String(secondIndex.symbol ?? ''), undefined, { sensitivity: 'base' });

    return symbolComparison || firstIndex.id - secondIndex.id;
}));
const selectedIndexChartPrices = computed(() => selectedIndexPriceRange.value === 'intraday'
    ? indexIntradayChartPrices(selectedIndexRecentPrices.value, selectedIndexWatchItem.value)
    : selectedIndexRecentPrices.value);
const selectedIndexChart = computed(() => buildIndexPriceChart(selectedIndexChartPrices.value));
const selectedIndexChartLineClass = computed(() => {
    const firstPrice = selectedIndexChart.value.first?.chart_price;
    const latestPrice = selectedIndexChart.value.latest?.chart_price;

    return latestPrice < firstPrice
        ? 'index-price-chart-line--negative'
        : 'index-price-chart-line--positive';
});
const showSelectedIndexChartPoints = computed(() => !['6m', '1y'].includes(selectedIndexPriceRange.value));
const selectedIndexPriceDateRange = computed(() => {
    if (selectedIndexRecentPrices.value.length === 0) {
        return '';
    }

    const latestDate = selectedIndexRecentPrices.value[0].trading_date;
    const earliestDate = selectedIndexRecentPrices.value.at(-1).trading_date;

    if (selectedIndexPriceRange.value === 'intraday' || earliestDate === latestDate) {
        return formatIndexHistoryDate(latestDate);
    }

    return `${formatIndexHistoryDate(earliestDate)} – ${formatIndexHistoryDate(latestDate)}`;
});
const selectedStockChartPrices = computed(() => stockChartPrices(
    selectedStockWatchItem.value,
    selectedStockPriceRange.value,
));
const selectedStockChart = computed(() => buildIndexPriceChart(selectedStockChartPrices.value));
const selectedStockChartLineClass = computed(() => {
    const firstPrice = selectedStockChart.value.first?.chart_price;
    const latestPrice = selectedStockChart.value.latest?.chart_price;

    return latestPrice < firstPrice
        ? 'stock-price-chart-line--negative'
        : 'stock-price-chart-line--positive';
});
const showSelectedStockChartPoints = computed(() => !['6m', '1y'].includes(selectedStockPriceRange.value));
const selectedStockPriceDateRange = computed(() => chartPriceDateRange(selectedStockChartPrices.value));
const stockPriceChartTargetSelector = computed(() => {
    if (!selectedStockWatchItem.value) {
        return null;
    }

    const targetType = viewportWidth.value <= 600 ? 'mobile' : 'desktop';

    return `#${targetType}-stock-chart-target-${selectedStockWatchItem.value.id}`;
});
const stockHoldingRefreshSchedules = computed(() => new Map(
    holdings.value.map((holding) => [
        holding.id,
        buildStockHoldingRefreshSchedule(holding, priceRefreshSettings.value, new Date(liveDataStatusNow.value)),
    ]),
));
const indexWatchItemRefreshSchedules = computed(() => new Map(
    indexWatchItems.value.map((indexItem) => [
        indexItem.id,
        buildStockHoldingRefreshSchedule(
            indexItem,
            indexEodhdSyncSettings.value?.realtime,
            new Date(liveDataStatusNow.value),
        ),
    ]),
));
const stockExchangeRefreshSchedules = computed(() => {
    const schedulesByExchange = new Map();
    const referenceDate = new Date(liveDataStatusNow.value);

    for (const holding of holdings.value) {
        const exchangeKey = String(holding.exchange || holding.mic_code || 'Unknown exchange').trim().toUpperCase();

        if (schedulesByExchange.has(exchangeKey)) {
            continue;
        }

        const schedule = buildStockHoldingRefreshSchedule(
            { ...holding, venue: null },
            priceRefreshSettings.value,
            referenceDate,
        );

        schedulesByExchange.set(exchangeKey, {
            key: exchangeKey,
            text: schedule.text,
        });
    }

    return [...schedulesByExchange.values()];
});
const isIndexEodhdSyncRunning = computed(() => ['queued', 'running'].includes(indexEodhdSync.value?.status));
const isStockEodhdSyncRunning = computed(() => ['queued', 'running'].includes(stockEodhdSync.value?.status));
const dismissedStockEodhdSyncRefreshId = ref(localStorage.getItem(STOCK_EODHD_SYNC_DISMISSED_REFRESH_ID_KEY));
const stockEodhdSyncVisible = computed(() => {
    if (!stockEodhdSync.value) {
        return false;
    }

    if (isStockEodhdSyncRunning.value) {
        return true;
    }

    return String(stockEodhdSync.value.refresh_id ?? '') !== dismissedStockEodhdSyncRefreshId.value;
});
const stockEodhdSyncProgress = computed(() => stockEodhdSync.value?.progress ?? {
    completed: 0,
    total: 0,
    percent: 0,
});
const indexEodhdSyncProgress = computed(() => indexEodhdSync.value?.progress ?? {
    completed: 0,
    total: 0,
    successful: 0,
    deferred: 0,
    issues: 0,
    percent: 0,
    estimated_remaining_seconds: null,
});
const depotPerformanceChart = computed(() => buildDepotPerformanceChart(depotPerformanceSeries?.value ?? []));
const cashLedgerPageCount = computed(() => Math.max(Math.ceil(transactions.value.length / cashLedgerPageSize), 1));
const paginatedCashLedgerTransactions = computed(() => {
    const start = (cashLedgerPage.value - 1) * cashLedgerPageSize;

    return transactions.value.slice(start, start + cashLedgerPageSize);
});
const cashTransactionStockOptions = computed(() => {
    const optionById = new Map();

    [...depotHoldings.value, ...holdings.value].forEach((holding) => {
        if (!holding?.id || optionById.has(holding.id)) {
            return;
        }

        const label = [
            stockDisplayLabel(holding, `Stock ${holding.id}`),
            holding.isin,
        ].filter(Boolean).join(' · ');

        optionById.set(holding.id, {
            title: label,
            value: holding.id,
        });
    });

    return Array.from(optionById.values());
});

function localDateKey(date, timeZone = displayTimeZone) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).formatToParts(date);
    const dateParts = Object.fromEntries(parts.map((part) => [part.type, part.value]));

    return `${dateParts.year}-${dateParts.month}-${dateParts.day}`;
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
const infoSubmenuItems = [
    {
        key: 'eodhd',
        label: 'EODHD',
        icon: 'mdi-database-arrow-down-outline',
    },
    {
        key: 'methoden',
        label: 'Methoden',
        icon: 'mdi-function-variant',
    },
];
const depotSubmenuItems = [
    {
        key: 'overview',
        label: 'Overview',
        icon: 'mdi-view-dashboard-outline',
    },
    {
        key: 'cash',
        label: 'Cash',
        icon: 'mdi-cash-multiple',
    },
    {
        key: 'all-stocks',
        label: 'All Stocks',
        icon: 'mdi-format-list-bulleted',
    },
];
const depotStocksSubmenuItems = [
    {
        key: 'actual-year',
        label: 'Actual Year',
        icon: 'mdi-calendar-today-outline',
    },
    {
        key: 'last-year',
        label: 'Last Year',
        icon: 'mdi-calendar-arrow-left',
    },
    {
        key: '4-ever',
        label: '4-Ever',
        icon: 'mdi-infinity',
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

const filteredInfoTables = computed(() => {
    const search = infoTableSearch.value.trim().toLowerCase();

    if (search === '') {
        return infoTables.value;
    }

    return infoTables.value.filter((table) => [
        table.name,
        table.category,
        table.purpose,
        table.purpose_de,
        table.eodhd?.endpoint,
        table.eodhd?.access,
        ...(table.eodhd?.documentation ?? []).flatMap((link) => [link.label, link.url]),
        table.cadence,
        table.queue?.job,
        table.queue?.mode,
    ].some((value) => String(value ?? '').toLowerCase().includes(search)));
});
const directEodhdTableCount = computed(() => infoTables.value.filter((table) => table.eodhd.mode === 'direct').length);
const queuedInfoTableCount = computed(() => infoTables.value.filter((table) => table.queue.used).length);
const synchronousInfoTableCount = computed(() => infoTables.value.filter((table) => infoQueueKind(table.queue) === 'synchronous').length);
const scheduledInfoMethodCount = computed(() => infoMethods.value.filter((method) => method.execution.scheduled).length);
const queuedInfoMethodCount = computed(() => infoMethods.value.filter((method) => ['queued', 'mixed'].includes(method.execution.mode)).length);
const synchronousInfoMethodCount = computed(() => infoMethods.value.filter((method) => ['synchronous', 'mixed'].includes(method.execution.mode)).length);

const menuItems = computed(() => [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: 'mdi-view-dashboard-outline',
    },
    {
        key: 'indices',
        label: 'Indices',
        icon: 'mdi-chart-areaspline',
    },
    {
        key: 'stocks',
        label: 'Stocks',
        icon: 'mdi-finance',
    },
    {
        key: 'depot',
        label: 'Depot',
        subtitle: activeDepot.value?.name ?? '–',
        icon: 'mdi-briefcase-outline',
    },
    ...(canManageDashboardAdmin.value ? [
        {
            key: 'data',
            label: 'Data',
            icon: 'mdi-database-outline',
        },
        {
            key: 'infos',
            label: 'Infos',
            icon: 'mdi-information-outline',
        },
    ] : []),
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
    indexEodhdSyncSettings,
    (settings) => {
        if (isIndexEodhdSyncScheduleDialogOpen.value) {
            return;
        }

        indexEodhdSyncScheduleForm.value = indexEodhdSyncScheduleFormFromSettings(settings);
    },
    { immediate: true },
);

watch(
    activeSection,
    () => syncIndexRealtimeOverdueMonitoring(),
);

watch(
    [activeSection, selectedIndexWatchItem, selectedStockWatchItem, activeDepot, activeDepotSubsection],
    () => syncActiveItemRefresh(),
);

watch(
    indexEodhdSyncSettings,
    (settings) => {
        if (isIndexV2RealtimeScheduleDialogOpen.value) {
            return;
        }

        indexV2RealtimeScheduleForm.value = indexV2RealtimeScheduleFormFromSettings(settings?.realtime);
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
    () => transactions.value.length,
    () => {
        if (cashLedgerPage.value > cashLedgerPageCount.value) {
            cashLedgerPage.value = cashLedgerPageCount.value;
        }

        if (cashLedgerPage.value < 1) {
            cashLedgerPage.value = 1;
        }
    },
);

watch(
    holdings,
    (currentHoldings) => {
        if (selectedStockWatchItem.value) {
            selectedStockWatchItem.value = currentHoldings.find(
                (holding) => holding.id === selectedStockWatchItem.value.id,
            ) ?? null;
        }
    },
);

watch(activeSection, () => depotsStore.cancelIntradayRefreshes(), { flush: 'sync' });

watch(activeSection, (section) => {
    window.clearInterval(privateIndexRefreshTimer);
    if (section === 'indices') {
        request('/admin/index-watch-items/refresh-free', { method: 'POST', body: JSON.stringify({ force: false }) }).catch(() => {});
    }
    privateIndexRefreshTimer = section === 'indices' ? window.setInterval(() => {
        if (!document.hidden) depotsStore.loadIndexWatchItems().catch(() => {});
    }, 30000) : null;
});

watch(
    [activeSection, activeInfoSubsection],
    ([section]) => {
        if (section === 'stocks') {
            restoreStockEodhdSync().catch(() => {});
        }

        if (section === 'dashboard' && dashboardVersions.value === null) {
            loadDashboardVersions().catch(() => {});
        }

        if (section === 'dashboard' && dashboardDailyPerformance.value === null) {
            depotsStore.loadDashboardDailyPerformance().catch(() => {});
        }

        if (section === 'infos' && infoTables.value.length === 0 && infoMethods.value.length === 0) {
            loadInfoData();
        }
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

    await Promise.all([
        depotsStore.loadActiveDepot(),
        activeSection.value === 'dashboard'
            ? loadDashboardVersions()
            : Promise.resolve(),
        activeSection.value === 'dashboard'
            ? depotsStore.loadDashboardDailyPerformance()
            : Promise.resolve(),
    ]);

    const isLoadingDepotOverview = activeSection.value === 'depot'
        && activeDepotSubsection.value === 'overview';

    if (activeSection.value === 'depot' && depotSubsectionRequiresTransactions(activeDepotSubsection.value)) {
        const transactionsRequest = depotsStore.loadTransactions();

        if (isLoadingDepotOverview) {
            await transactionsRequest.catch(() => {});
        }
    }

    if (activeSection.value === 'depot' && isDepotStockPeriodPage()) {
        depotsStore.loadDepotStockPeriod(activeDepotStocksSubsection.value);
    }

    await Promise.all([
        loadWatchlistHoldingsForActiveSection().catch(() => {}),
        activeSection.value === 'indices'
            ? depotsStore.loadIndexWatchItems().catch(() => {})
            : Promise.resolve(),
        activeSection.value === 'indices'
            ? depotsStore.loadIndexEodhdSyncSettings().catch(() => {})
            : Promise.resolve(),
        activeSection.value === 'stocks'
            ? restoreStockEodhdSync().catch(() => {})
            : Promise.resolve(),
    ]);

    syncActiveItemRefresh();

    if (activeSection.value === 'depots') {
        await depotsStore.loadDepots();
    }

    if (canManageUsers.value && activeSection.value === 'users') {
        await usersStore.loadUsers();
        await rolesStore.loadRoles();
    }

    if (canManageUsers.value && activeSection.value === 'roles') {
        await rolesStore.loadRoles();
    }

    window.addEventListener('popstate', applyRouteFromPath);
});

onBeforeUnmount(() => {
    window.clearInterval(privateIndexRefreshTimer);
    depotsStore.cancelIntradayRefreshes();
    stopPriceRefreshPolling();
    stopIntradayBackfillPolling();
    stopIndexEodhdSyncPolling();
    stopStockEodhdSyncPolling();
    stopIndexRealtimeOverdueMonitoring();
    stopActiveItemRefresh();
    stopHoldingDialogKeyboardShortcuts();
    window.removeEventListener('resize', updateViewportMetrics);
    window.removeEventListener('popstate', applyRouteFromPath);
});

function updateViewportMetrics() {
    viewportWidth.value = window.visualViewport?.width ?? window.innerWidth;
    viewportHeight.value = window.visualViewport?.height ?? window.innerHeight;
}

function syncIndexRealtimeOverdueMonitoring() {
    if (activeSection.value !== 'indices') {
        stopIndexRealtimeOverdueMonitoring();

        return;
    }

    if (indexRealtimeOverdueTimer.value !== null) {
        return;
    }

    indexRealtimeOverdueTimer.value = window.setInterval(
        checkForOverdueIndexRealtimeUpdate,
        indexRealtimeOverdueCheckIntervalMilliseconds,
    );
    checkForOverdueIndexRealtimeUpdate();
}

function stopIndexRealtimeOverdueMonitoring() {
    if (indexRealtimeOverdueTimer.value !== null) {
        window.clearInterval(indexRealtimeOverdueTimer.value);
        indexRealtimeOverdueTimer.value = null;
    }
}

function shouldRefreshActiveItem() {
    if (activeSection.value === 'indices') {
        return selectedIndexWatchItem.value !== null;
    }

    if (activeSection.value === 'stocks') {
        return selectedStockWatchItem.value !== null;
    }

    return activeSection.value === 'depot'
        && depotSubsectionRequiresTransactions(activeDepotSubsection.value)
        && activeDepot.value !== null;
}

function syncActiveItemRefresh() {
    if (!shouldRefreshActiveItem()) {
        stopActiveItemRefresh();

        return;
    }

    if (activeItemRefreshTimer.value !== null) {
        return;
    }

    activeItemRefreshTimer.value = window.setInterval(
        refreshActiveItemPage,
        activeItemRefreshIntervalMilliseconds,
    );
}

function stopActiveItemRefresh() {
    if (activeItemRefreshTimer.value === null) {
        return;
    }

    window.clearInterval(activeItemRefreshTimer.value);
    activeItemRefreshTimer.value = null;
}

async function refreshActiveItemPage() {
    if (!shouldRefreshActiveItem() || isActiveItemRefreshing.value) {
        return;
    }

    isActiveItemRefreshing.value = true;

    try {
        if (activeSection.value === 'indices') {
            await reloadSelectedIndexWatchItem();

            return;
        }

        if (activeSection.value === 'stocks') {
            await loadWatchlistHoldingsForActiveSection(holdingsPagination.value.current_page, { silent: true });

            return;
        }

        await Promise.all([
            depotsStore.loadActiveDepot(),
            depotsStore.loadTransactions(),
        ]);
    } catch {
        // Store errors remain visible while the next minute can retry automatically.
    } finally {
        isActiveItemRefreshing.value = false;
    }
}

async function checkForOverdueIndexRealtimeUpdate() {
    if (!EODHD_REQUESTS_ENABLED) {
        return;
    }

    const realtimeSettings = indexEodhdSyncSettings.value?.realtime;
    const nextRefreshAt = Date.parse(realtimeSettings?.next_refresh_at ?? '');

    if (activeSection.value !== 'indices' || isIndexRealtimeDueDispatching.value) {
        return;
    }

    if (realtimeSettings?.status === 'updating') {
        isIndexRealtimeDueDispatching.value = true;

        try {
            const previousLatestUpdateAt = realtimeSettings.latest_update_at ?? null;
            const data = await depotsStore.loadIndexEodhdSyncSettings();
            const latestUpdateAt = data.index_eodhd_sync_settings?.realtime?.latest_update_at ?? null;

            if (latestUpdateAt && latestUpdateAt !== previousLatestUpdateAt) {
                await reloadSelectedIndexWatchItem();
            }
        } catch {
            nextIndexRealtimeDispatchAttemptAt.value = Date.now() + indexRealtimeDispatchRetryDelayMilliseconds;
        } finally {
            isIndexRealtimeDueDispatching.value = false;
        }

        return;
    }

    if (realtimeSettings?.status === 'inactive'
        || Number.isNaN(nextRefreshAt)
        || Date.now() < nextRefreshAt
        || Date.now() < nextIndexRealtimeDispatchAttemptAt.value
    ) {
        return;
    }

    isIndexRealtimeDueDispatching.value = true;

    try {
        const previousLatestUpdateAt = realtimeSettings?.latest_update_at ?? null;
        const data = await depotsStore.dispatchDueIndexRealtimeSync();
        const latestUpdateAt = data.index_eodhd_sync_settings?.realtime?.latest_update_at ?? null;

        if (data.queued && latestUpdateAt && latestUpdateAt !== previousLatestUpdateAt) {
            await reloadSelectedIndexWatchItem();
        }

        nextIndexRealtimeDispatchAttemptAt.value = data.queued
            ? 0
            : Date.now() + indexRealtimeDispatchRetryDelayMilliseconds;
    } catch {
        nextIndexRealtimeDispatchAttemptAt.value = Date.now() + indexRealtimeDispatchRetryDelayMilliseconds;
    } finally {
        isIndexRealtimeDueDispatching.value = false;
    }
}

async function reloadSelectedIndexWatchItem() {
    const indexWatchItemId = selectedIndexWatchItem.value?.id;
    const data = await depotsStore.loadIndexWatchItems();

    if (!indexWatchItemId) {
        return;
    }

    selectedIndexWatchItem.value = (data.indexes ?? [])
        .find((indexWatchItem) => indexWatchItem.id === indexWatchItemId)
        ?? selectedIndexWatchItem.value;
}

function navigateSection(section) {
    const knownSections = ['dashboard', 'indices', 'stocks', 'profile', 'data', 'infos', 'depot', 'depots', 'users', 'roles'];

    if (!knownSections.includes(section)) {
        activeSection.value = 'dashboard';
        clearSectionMessages();
        updateUrlPath();

        return;
    }

    activeSection.value = section;

    if (section === 'infos' && !isInfoSubsection(activeInfoSubsection.value)) {
        activeInfoSubsection.value = 'eodhd';
    }

    if (section === 'depot' && !isDepotSubsection(activeDepotSubsection.value)) {
        activeDepotSubsection.value = 'overview';
    }

    clearSectionMessages();
    updateUrlPath();

    if (section === 'users') {
        usersStore.loadUsers(userPagination.value.current_page);
        rolesStore.loadRoles(rolePagination.value.current_page);
    }

    if (section === 'roles') {
        rolesStore.loadRoles(rolePagination.value.current_page);
    }

    if (section === 'depot') {
        depotsStore.loadActiveDepot();

        if (activeDepotSubsection.value === 'overview') {
            loadDepotOverviewData(1).catch(() => {});
        } else if (depotSubsectionRequiresTransactions(activeDepotSubsection.value)) {
            depotsStore.loadTransactions();
        }

        if (isDepotStockPeriodPage()) {
            depotsStore.loadDepotStockPeriod(activeDepotStocksSubsection.value);
        }
    }

    if (section === 'depots') {
        depotsStore.loadDepots(depotPagination.value.current_page);
    }

    if (section === 'indices') {
        Promise.all([
            depotsStore.loadIndexWatchItems(),
            depotsStore.loadIndexEodhdSyncSettings(),
        ]).catch(() => {});
    }

    if (section === 'stocks') {
        loadWatchlistHoldingsForActiveSection(1).catch(() => {});
    }

}

async function refreshPrivateIndexQuotes() {
    indexError.value = '';
    try {
        const result = await request('/admin/index-watch-items/refresh-free', { method: 'POST', body: JSON.stringify({ force: true }) });
        indexMessage.value = result.message;
    } catch (error) {
        indexError.value = error.message;
    }
}

async function loadInfoData() {
    infoTablesLoading.value = true;
    infoTablesError.value = '';

    try {
        const data = await request('/admin/infos');
        infoTables.value = data.tables ?? [];
        infoMethods.value = data.methods ?? [];
    } catch (error) {
        infoTablesError.value = error.message;
    } finally {
        infoTablesLoading.value = false;
    }
}

async function refreshVisibleApplicationData() {
    const refreshRequests = [depotsStore.loadActiveDepot()];

    if (activeSection.value === 'dashboard') {
        refreshRequests.push(
            loadDashboardVersions(),
            depotsStore.loadDashboardDailyPerformance(),
        );
    }

    if (activeSection.value === 'indices') {
        refreshRequests.push(
            depotsStore.loadIndexWatchItems(),
            depotsStore.loadIndexEodhdSyncSettings(),
        );
    }

    if (activeSection.value === 'stocks') {
        refreshRequests.push(
            loadWatchlistHoldingsForActiveSection(holdingsPagination.value.current_page, { silent: true }),
        );
    }

    if (activeSection.value === 'infos') {
        refreshRequests.push(loadInfoData());
    }

    if (activeSection.value === 'depot') {
        if (activeDepotSubsection.value === 'overview') {
            refreshRequests.push(loadDepotOverviewData(
                holdingsPagination.value.current_page,
                { silent: true },
            ));
        } else if (depotSubsectionRequiresTransactions(activeDepotSubsection.value)) {
            refreshRequests.push(depotsStore.loadTransactions());
        }

        if (isDepotStockPeriodPage()) {
            refreshRequests.push(depotsStore.loadDepotStockPeriod(activeDepotStocksSubsection.value));
        }
    }

    if (activeSection.value === 'depots') {
        refreshRequests.push(depotsStore.loadDepots(depotPagination.value.current_page));
    }

    if (activeSection.value === 'users') {
        refreshRequests.push(usersStore.loadUsers(userPagination.value.current_page));
    }

    if (activeSection.value === 'roles') {
        refreshRequests.push(rolesStore.loadRoles(rolePagination.value.current_page));
    }

    if (activeSection.value === 'profile') {
        refreshRequests.push(auth.loadUser());
    }

    await Promise.allSettled(refreshRequests);
}

async function reloadDashboard() {
    if (isDashboardReloading.value) {
        return;
    }

    isDashboardReloading.value = true;

    try {
        await refreshVisibleApplicationData();
    } finally {
        isDashboardReloading.value = false;
    }
}

function infoEodhdModeLabel(mode) {
    return {
        direct: 'Direct',
        indirect: 'Indirect',
        none: 'None',
    }[mode] ?? 'None';
}

function infoEodhdModeColor(mode) {
    return {
        direct: 'primary',
        indirect: 'warning',
        none: 'default',
    }[mode] ?? 'default';
}

function infoQueueKind(queue) {
    if (queue?.used) {
        return 'queued';
    }

    if (queue?.mode?.startsWith('Synchronous')) {
        return 'synchronous';
    }

    return 'none';
}

function infoQueueLabel(queue) {
    return {
        queued: `Queued · ${queue.name}`,
        synchronous: 'Synchronous',
        none: 'No queue',
    }[infoQueueKind(queue)];
}

function infoQueueColor(queue) {
    return {
        queued: 'success',
        synchronous: 'info',
        none: 'default',
    }[infoQueueKind(queue)];
}

function infoExecutionModeLabel(mode) {
    return {
        queued: 'Queued',
        synchronous: 'Synchronous',
        mixed: 'Queued + synchronous',
    }[mode] ?? mode;
}

function infoExecutionModeColor(mode) {
    return {
        queued: 'success',
        synchronous: 'info',
        mixed: 'warning',
    }[mode] ?? 'default';
}

function infoMethodClassName(className) {
    return String(className ?? '').split('\\').pop();
}

function loadWatchlistHoldingsForActiveSection(page = holdingsPagination.value.current_page, options = {}) {
    if (activeSection.value !== 'stocks'
        && !(activeSection.value === 'depot' && activeDepotSubsection.value === 'overview')) {
        return Promise.resolve();
    }

    const selectedStock = activeSection.value === 'stocks' ? selectedStockWatchItem.value : null;

    return depotsStore.loadWatchlistHoldings(page, {
        ...options,
        allHoldings: true,
        includeCharts: selectedStock !== null,
        chartStockId: selectedStock?.id ?? null,
        chartRange: selectedStock ? stockChartRequestRange(selectedStockPriceRange.value) : null,
    });
}

async function loadDepotOverviewData(page = holdingsPagination.value.current_page, options = {}) {
    await depotsStore.loadTransactions().catch(() => {});

    return loadWatchlistHoldingsForActiveSection(page, options);
}

async function loadDashboardVersions() {
    if (dashboardVersionsLoading.value) {
        return;
    }

    dashboardVersionsLoading.value = true;
    dashboardVersionsError.value = '';

    try {
        dashboardVersions.value = await request('/admin/dashboard/version');
    } catch (error) {
        dashboardVersionsError.value = error.message;
    } finally {
        dashboardVersionsLoading.value = false;
    }
}

function navigateInfoSubsection(subsection) {
    if (!isInfoSubsection(subsection) || activeInfoSubsection.value === subsection) {
        return;
    }

    activeInfoSubsection.value = subsection;
    activeSection.value = 'infos';
    clearSectionMessages();
    updateUrlPath();
}

function navigateDepotSubsection(subsection) {
    if (!isDepotSubsection(subsection) || activeDepotSubsection.value === subsection) {
        return;
    }

    activeDepotSubsection.value = subsection;
    activeSection.value = 'depot';
    clearSectionMessages();
    updateUrlPath();

    if (subsection === 'overview') {
        loadDepotOverviewData(1).catch(() => {});
    } else if (depotSubsectionRequiresTransactions(subsection)) {
        depotsStore.loadTransactions();
    }

    if (subsection === 'all-stocks') {
        depotsStore.loadDepotStockPeriod(activeDepotStocksSubsection.value);
    }
}

function navigateDepotStocksSubsection(subsection) {
    if (!isDepotStocksSubsection(subsection) || activeDepotStocksSubsection.value === subsection) {
        return;
    }

    activeDepotStocksSubsection.value = subsection;
    activeDepotSubsection.value = 'all-stocks';
    activeSection.value = 'depot';
    clearSectionMessages();
    updateUrlPath();

    depotsStore.loadDepotStockPeriod(subsection);
}

function toggleDashboardMenuCompact() {
    isDashboardMenuCompact.value = !isDashboardMenuCompact.value;
}

function applyRouteFromPath() {
    const path = window.location.pathname.replace(/\/+$/, '') || '/admin';

    if (path === '/admin' || path === '/admin/dashboard') {
        activeSection.value = 'dashboard';
        updateUrlPath({ replace: true });

        return;
    }

    if (path === '/admin/profile') {
        activeSection.value = 'profile';

        return;
    }

    if (path.startsWith('/admin/menu/')) {
        const [sectionSegment, subsectionSegment, detailSegment] = path
            .replace('/admin/menu/', '')
            .split('/')
            .map((segment) => decodeURIComponent(segment));
        const normalizedSection = sectionSegment ?? '';

        if (normalizedSection === 'dashboard') {
            activeSection.value = 'dashboard';
            updateUrlPath({ replace: true });

            return;
        }

        if (normalizedSection === 'data' || normalizedSection === 'cloudways') {
            activeSection.value = canManageDashboardAdmin.value ? 'data' : 'dashboard';
            activeDataSubsection.value = (normalizedSection === 'cloudways' || subsectionSegment === 'cloudways') && canUseCloudways.value
                ? 'cloudways'
                : 'overview';
            updateUrlPath({ replace: true });

            return;
        }

        if (normalizedSection === 'infos') {
            activeSection.value = 'infos';
            activeInfoSubsection.value = isInfoSubsection(subsectionSegment)
                ? subsectionSegment
                : 'eodhd';
            updateUrlPath({ replace: true });

            return;
        }

        if (normalizedSection === 'depot') {
            activeSection.value = 'depot';
            activeDepotSubsection.value = isDepotSubsection(subsectionSegment)
                ? subsectionSegment
                : 'overview';
            activeDepotStocksSubsection.value = activeDepotSubsection.value === 'all-stocks'
                && isDepotStocksSubsection(detailSegment)
                ? detailSegment
                : 'actual-year';
            updateUrlPath({ replace: true });

            return;
        }

        const isTopLevel = menuItems.value.some((item) => item.key === normalizedSection);
        const isChild = menuItems.value.flatMap((item) => item.children ?? []).some((child) => child.key === normalizedSection);
        activeSection.value = (isTopLevel || isChild) ? normalizedSection : 'dashboard';
        updateUrlPath({ replace: true });

        return;
    }

    activeSection.value = 'dashboard';
    updateUrlPath({ replace: true });
}

function updateUrlPath(options = {}) {
    const target = activeSection.value === 'dashboard'
        ? '/admin/dashboard'
        : activeSection.value === 'profile'
            ? '/admin/profile'
            : activeSection.value === 'data'
                ? `/admin/menu/data/${activeDataSubsection.value}`
                : activeSection.value === 'infos'
                    ? `/admin/menu/infos/${activeInfoSubsection.value}`
                    : activeSection.value === 'depot'
                        ? activeDepotSubsection.value === 'all-stocks'
                            ? `/admin/menu/depot/all-stocks/${activeDepotStocksSubsection.value}`
                            : `/admin/menu/depot/${activeDepotSubsection.value}`
                        : `/admin/menu/${activeSection.value}`;

    if (`${window.location.pathname}${window.location.search}` === target) {
        return;
    }

    if (options.replace) {
        window.history.replaceState({}, '', target);

        return;
    }

    window.history.pushState({}, '', target);
}

function isInfoSubsection(subsection) {
    return infoSubmenuItems.some((item) => item.key === subsection);
}

function isDepotSubsection(subsection) {
    return depotSubmenuItems.some((item) => item.key === subsection);
}

function depotSubsectionRequiresTransactions(subsection) {
    return ['overview', 'cash'].includes(subsection);
}

function isDepotStockPeriodPage() {
    return activeDepotSubsection.value === 'all-stocks'
        && isDepotStocksSubsection(activeDepotStocksSubsection.value);
}

function isDepotStocksSubsection(subsection) {
    return depotStocksSubmenuItems.some((item) => item.key === subsection);
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
    currentPassword.value = '';
    newPassword.value = '';
    newPasswordConfirmation.value = '';
    profileError.value = '';
    profileMessage.value = '';
    isPasswordDialogOpen.value = true;
}

function abortPasswordEdit() {
    isPasswordDialogOpen.value = false;
    currentPassword.value = '';
    newPassword.value = '';
    newPasswordConfirmation.value = '';
}

async function savePassword() {
    profileError.value = '';
    profileMessage.value = '';

    try {
        await auth.updatePassword(
            currentPassword.value,
            newPassword.value,
            newPasswordConfirmation.value,
        );
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
        await loadWatchlistHoldingsForActiveSection();
        await depotsStore.loadDepots(depotPagination.value.current_page);
    } catch (err) {
        depotError.value = err.message;
    }
}

function openHoldingDialog() {
    holdingSearchQuery.value = '';
    holdingSubtitle.value = '';
    depotsStore.stockSearchResults = [];
    depotsStore.stockSearchError = '';
    depotsStore.stockSearchSource = 'free';
    depotsStore.stockSearchNotice = '';
    depotsStore.stockSearchCatalogDate = null;
    holdingError.value = '';
    holdingMessage.value = '';
    isHoldingDialogOpen.value = true;
    startHoldingDialogKeyboardShortcuts();
    focusHoldingSearchInput();
}

function openEditHoldingDialog(holding = selectedStockWatchItem.value) {
    if (!holding) {
        return;
    }

    selectedHolding.value = holding;
    holdingForm.value = {
        symbol: holding.symbol ?? '',
        name: holding.name ?? '',
        subtitle: holding.subtitle ?? '',
        isin: holding.isin ?? '',
        wkn: holding.wkn ?? '',
        exchange: holding.exchange ?? '',
        mic_code: holding.mic_code ?? '',
        instrument_type: holding.instrument_type ?? '',
        country: holding.country ?? '',
        currency: holding.currency ?? '',
    };
    holdingError.value = '';
    holdingMessage.value = '';
    isEditHoldingDialogOpen.value = true;
}

function abortEditHoldingDialog() {
    isEditHoldingDialogOpen.value = false;
    selectedHolding.value = null;
    holdingForm.value = emptyHoldingForm();
}

function abortHoldingDialog() {
    isHoldingDialogOpen.value = false;
    stopHoldingDialogKeyboardShortcuts();
}

function openIndexDialog() {
    indexSearchQuery.value = '';
    depotsStore.stockSearchResults = [];
    depotsStore.stockSearchError = '';
    depotsStore.stockSearchSource = 'free';
    depotsStore.stockSearchNotice = '';
    depotsStore.stockSearchCatalogDate = null;
    indexError.value = '';
    indexMessage.value = '';
    isIndexDialogOpen.value = true;
    focusIndexSearchInput();
}

function abortIndexDialog() {
    isIndexDialogOpen.value = false;
}

async function selectIndexWatchItem(indexItem) {
    if (selectedIndexWatchItem.value?.id === indexItem.id) {
        selectedIndexWatchItem.value = null;
        selectedIndexPriceRange.value = 'intraday';
        indexPriceChartError.value = '';
        isIndexPriceChartLoading.value = false;

        return;
    }

    selectedIndexWatchItem.value = indexItem;
    selectedIndexPriceRange.value = 'intraday';
    await loadSelectedIndexPrices();
}

async function selectIndexPriceRange(range) {
    selectedIndexPriceRange.value = range;
    await loadSelectedIndexPrices();
}

async function loadSelectedIndexPrices() {
    if (!selectedIndexWatchItem.value) {
        return;
    }

    indexPriceChartError.value = '';
    isIndexPriceChartLoading.value = true;
    const indexWatchItemId = selectedIndexWatchItem.value.id;
    const priceRange = selectedIndexPriceRange.value;

    try {
        const data = await depotsStore.ensureIndexWatchItemPrices(
            indexWatchItemId,
            priceRange,
        );

        if (selectedIndexWatchItem.value?.id === indexWatchItemId && selectedIndexPriceRange.value === priceRange) {
            selectedIndexWatchItem.value = data.index ?? selectedIndexWatchItem.value;
        }
    } catch (error) {
        if (selectedIndexWatchItem.value?.id === indexWatchItemId && selectedIndexPriceRange.value === priceRange) {
            indexPriceChartError.value = error.message;
        }
    } finally {
        if (selectedIndexWatchItem.value?.id === indexWatchItemId && selectedIndexPriceRange.value === priceRange) {
            isIndexPriceChartLoading.value = false;
        }
    }
}

async function restoreStockEodhdSync() {
    stockEodhdSyncError.value = '';

    try {
        const data = await depotsStore.loadLatestStockEodhdSync();

        if (data.refresh?.refresh_id && ['queued', 'running'].includes(data.refresh.status)) {
            startStockEodhdSyncPolling(data.refresh.refresh_id);
        } else {
            stopStockEodhdSyncPolling();
        }
    } catch (error) {
        stockEodhdSyncError.value = error.message;
        throw error;
    }
}

async function startStockEodhdSync() {
    if (!EODHD_REQUESTS_ENABLED) {
        return;
    }

    stockEodhdSyncError.value = '';

    try {
        const data = await depotsStore.startStockEodhdSync();

        if (data.refresh?.refresh_id && ['queued', 'running'].includes(data.refresh.status)) {
            startStockEodhdSyncPolling(data.refresh.refresh_id);
        }
    } catch (error) {
        stockEodhdSyncError.value = error.message;
    }
}

function startStockEodhdSyncPolling(refreshId) {
    stopStockEodhdSyncPolling();
    stockEodhdSyncTimer.value = window.setInterval(() => pollStockEodhdSync(refreshId), 2000);
}

function stopStockEodhdSyncPolling() {
    if (!stockEodhdSyncTimer.value) {
        return;
    }

    window.clearInterval(stockEodhdSyncTimer.value);
    stockEodhdSyncTimer.value = null;
}

async function pollStockEodhdSync(refreshId) {
    try {
        const data = await depotsStore.loadStockEodhdSync(refreshId);

        if (!['queued', 'running'].includes(data.refresh?.status)) {
            stopStockEodhdSyncPolling();
            await refreshVisibleApplicationData();
        }
    } catch (error) {
        stockEodhdSyncError.value = error.message;
        stopStockEodhdSyncPolling();
    }
}

function closeStockEodhdSyncResult() {
    const refreshId = String(stockEodhdSync.value?.refresh_id ?? '');

    if (refreshId !== '') {
        dismissedStockEodhdSyncRefreshId.value = refreshId;
        localStorage.setItem(STOCK_EODHD_SYNC_DISMISSED_REFRESH_ID_KEY, refreshId);
    }

    stopStockEodhdSyncPolling();
    stockEodhdSyncError.value = '';
    depotsStore.clearStockEodhdSync();
}

async function startIndexEodhdSync() {
    if (!EODHD_REQUESTS_ENABLED) {
        return;
    }

    indexEodhdSyncError.value = '';

    try {
        const data = await depotsStore.startIndexEodhdSync();

        if (data.refresh?.refresh_id && isIndexEodhdSyncRunning.value) {
            startIndexEodhdSyncPolling(data.refresh.refresh_id);
        }
    } catch (error) {
        indexEodhdSyncError.value = error.message;
    }
}

function openIndexEodhdSyncScheduleDialog() {
    indexEodhdSyncScheduleForm.value = indexEodhdSyncScheduleFormFromSettings(indexEodhdSyncSettings.value);
    indexEodhdSyncScheduleError.value = '';
    isIndexEodhdSyncScheduleDialogOpen.value = true;
}

function closeIndexEodhdSyncScheduleDialog() {
    isIndexEodhdSyncScheduleDialogOpen.value = false;
    indexEodhdSyncScheduleError.value = '';
}

function addIndexEodhdSyncScheduleTime() {
    if (indexEodhdSyncScheduleForm.value.times.length >= 8) {
        return;
    }

    indexEodhdSyncScheduleForm.value.times.push('12:00');
}

function removeIndexEodhdSyncScheduleTime(index) {
    if (indexEodhdSyncScheduleForm.value.times.length <= 1) {
        return;
    }

    indexEodhdSyncScheduleForm.value.times.splice(index, 1);
}

async function saveIndexEodhdSyncSchedule() {
    isIndexEodhdSyncScheduleSaving.value = true;
    indexEodhdSyncScheduleError.value = '';
    indexEodhdSyncScheduleMessage.value = '';

    try {
        const data = await depotsStore.updateIndexEodhdSyncSettings({
            times: indexEodhdSyncScheduleForm.value.times,
        });
        indexEodhdSyncScheduleMessage.value = data.message;
        isIndexEodhdSyncScheduleDialogOpen.value = false;
    } catch (error) {
        indexEodhdSyncScheduleError.value = error.message;
    } finally {
        isIndexEodhdSyncScheduleSaving.value = false;
    }
}

function openIndexV2RealtimeScheduleDialog() {
    indexV2RealtimeScheduleForm.value = indexV2RealtimeScheduleFormFromSettings(
        indexEodhdSyncSettings.value?.realtime,
    );
    indexV2RealtimeScheduleError.value = '';
    isIndexV2RealtimeScheduleDialogOpen.value = true;
}

function closeIndexV2RealtimeScheduleDialog() {
    isIndexV2RealtimeScheduleDialogOpen.value = false;
    indexV2RealtimeScheduleError.value = '';
}

async function saveIndexV2RealtimeSchedule() {
    isIndexV2RealtimeScheduleSaving.value = true;
    indexV2RealtimeScheduleError.value = '';
    indexEodhdSyncScheduleMessage.value = '';

    try {
        const form = indexV2RealtimeScheduleForm.value;
        const data = await depotsStore.updateIndexEodhdSyncSettings({
            realtime: {
                trading_interval_minutes: Number(form.trading_interval_minutes),
                trading_starts_before_minutes: Number(form.trading_starts_before_minutes),
                trading_ends_after_minutes: Number(form.trading_ends_after_minutes),
                closed_refresh_enabled: Boolean(form.closed_refresh_enabled),
                closed_interval_minutes: Number(form.closed_interval_minutes),
            },
        });
        indexEodhdSyncScheduleMessage.value = data.message;
        isIndexV2RealtimeScheduleDialogOpen.value = false;
    } catch (error) {
        indexV2RealtimeScheduleError.value = error.message;
    } finally {
        isIndexV2RealtimeScheduleSaving.value = false;
    }
}

function startIndexEodhdSyncPolling(refreshId) {
    stopIndexEodhdSyncPolling();
    indexEodhdSyncTimer.value = window.setInterval(() => pollIndexEodhdSync(refreshId), 2000);
}

function stopIndexEodhdSyncPolling() {
    if (!indexEodhdSyncTimer.value) {
        return;
    }

    window.clearInterval(indexEodhdSyncTimer.value);
    indexEodhdSyncTimer.value = null;
}

function closeIndexEodhdSyncResult() {
    stopIndexEodhdSyncPolling();
    indexEodhdSyncError.value = '';
    depotsStore.clearIndexEodhdSync();
}

async function pollIndexEodhdSync(refreshId) {
    try {
        const data = await depotsStore.loadIndexEodhdSync(refreshId);

        if (!['queued', 'running'].includes(data.refresh?.status)) {
            stopIndexEodhdSyncPolling();
            await refreshVisibleApplicationData();
        }
    } catch (error) {
        indexEodhdSyncError.value = error.message;
        stopIndexEodhdSyncPolling();
    }
}

function indexEodhdSyncStepIcon(status) {
    return {
        pending: 'mdi-circle-outline',
        running: 'mdi-loading',
        finished: 'mdi-check-circle',
        partial: 'mdi-alert-circle',
        no_data: 'mdi-database-alert',
        deferred: 'mdi-clock',
        market_closed: 'mdi-calendar-remove',
        timeout: 'mdi-timer-alert',
        rate_limited: 'mdi-speedometer-slow',
        access_denied: 'mdi-lock-alert',
        not_found: 'mdi-cloud-alert',
        unavailable: 'mdi-alert-circle',
        skipped: 'mdi-skip-next-circle',
        failed: 'mdi-alert-circle',
    }[status] ?? 'mdi-circle-outline';
}

function indexEodhdSyncStepColor(status) {
    return {
        running: 'primary',
        finished: 'success',
        partial: 'warning',
        no_data: 'warning',
        deferred: 'info',
        market_closed: 'info',
        timeout: 'error',
        rate_limited: 'warning',
        access_denied: 'error',
        not_found: 'warning',
        unavailable: 'warning',
        skipped: 'medium-emphasis',
        failed: 'error',
    }[status] ?? 'medium-emphasis';
}

function indexEodhdSyncChecklistStatusLabel(status) {
    return {
        pending: 'pending',
        running: 'checking',
        finished: 'done',
        partial: 'incomplete',
        no_data: 'no EODHD data',
        deferred: 'waiting',
        market_closed: 'market closed',
        timeout: 'Timeout',
        rate_limited: 'rate limited',
        access_denied: 'access denied',
        not_found: 'HTTP 404',
        unavailable: 'unavailable',
        skipped: 'skipped',
        failed: 'failed',
    }[status] ?? status;
}

function indexEodhdSyncShowsStatusMessage(status) {
    return [
        'partial',
        'no_data',
        'deferred',
        'market_closed',
        'timeout',
        'rate_limited',
        'access_denied',
        'not_found',
        'unavailable',
        'skipped',
        'failed',
    ].includes(status);
}

function formatIndexEodhdSyncRemaining(seconds) {
    if (seconds === null || seconds === undefined) {
        return '';
    }

    if (seconds === 0) {
        return 'completed';
    }

    if (seconds < 60) {
        return 'less than 1 minute';
    }

    const minutes = Math.ceil(seconds / 60);

    return `about ${minutes} minute${minutes === 1 ? '' : 's'}`;
}

function indexEodhdSyncStatusColor(status) {
    return {
        queued: 'primary',
        running: 'primary',
        partial: 'warning',
        finished: 'success',
        failed: 'error',
    }[status] ?? 'medium-emphasis';
}

function indexEodhdSyncStatusLabel(status) {
    return {
        queued: 'queued',
        running: 'running',
        partial: 'completed with gaps',
        finished: 'completed',
        failed: 'failed',
    }[status] ?? status;
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
        const data = await depotsStore.createWatchlistHolding({
            ...result,
            subtitle: holdingSubtitle.value.trim() || null,
        });
        holdingMessage.value = data.message;
        isHoldingDialogOpen.value = false;
        stopHoldingDialogKeyboardShortcuts();
        await loadWatchlistHoldingsForActiveSection(holdingsPagination.value.current_page);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function saveEditedHolding() {
    if (!selectedHolding.value) {
        return;
    }

    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.updateWatchlistHolding(selectedHolding.value.id, holdingForm.value);
        holdingMessage.value = data.message;
        abortEditHoldingDialog();
        await loadWatchlistHoldingsForActiveSection(holdingsPagination.value.current_page);
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
        isIndexDialogOpen.value = false;
    } catch (err) {
        indexError.value = err.message;
    }
}

function openCashTransactionDialog(type) {
    cashTransactionForm.value = {
        type,
        total_amount: '',
        booked_at: localDateInputValue(),
        note: '',
        stock_holding_id: null,
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
            stock_holding_id: cashTransactionForm.value.stock_holding_id,
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
        await loadWatchlistHoldingsForActiveSection(holdingsPagination.value.current_page);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function savePriceRefreshSchedule() {
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';
    const payload = {
        trading_interval_minutes: Number(priceRefreshScheduleForm.value.trading_interval_minutes),
        trading_starts_before_minutes: Number(priceRefreshScheduleForm.value.trading_starts_before_minutes),
        trading_ends_after_minutes: Number(priceRefreshScheduleForm.value.trading_ends_after_minutes),
        closed_refresh_enabled: Boolean(priceRefreshScheduleForm.value.closed_refresh_enabled),
        closed_interval_minutes: Number(priceRefreshScheduleForm.value.closed_interval_minutes),
    };

    if (priceRefreshScheduleForm.value.trading_start_time) {
        payload.trading_start_time = priceRefreshScheduleForm.value.trading_start_time;
    }

    if (priceRefreshScheduleForm.value.trading_end_time) {
        payload.trading_end_time = priceRefreshScheduleForm.value.trading_end_time;
    }

    try {
        const data = await depotsStore.updatePriceRefreshSettings(payload);

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

function openLiveDataUpdateDialog() {
    liveDataUpdateScheduleForm.value = priceRefreshScheduleFormFromSettings(priceRefreshSettings.value);
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';
    isLiveDataUpdateDialogOpen.value = true;
}

async function saveLiveDataUpdateSchedule() {
    priceRefreshScheduleError.value = '';
    priceRefreshScheduleMessage.value = '';
    isLiveDataUpdateScheduleSaving.value = true;

    try {
        const data = await depotsStore.updatePriceRefreshSettings({
            trading_interval_minutes: Number(liveDataUpdateScheduleForm.value.trading_interval_minutes),
            trading_starts_before_minutes: Number(liveDataUpdateScheduleForm.value.trading_starts_before_minutes),
            trading_ends_after_minutes: Number(liveDataUpdateScheduleForm.value.trading_ends_after_minutes),
            trading_start_time: liveDataUpdateScheduleForm.value.trading_start_time || null,
            trading_end_time: liveDataUpdateScheduleForm.value.trading_end_time || null,
            closed_refresh_enabled: Boolean(liveDataUpdateScheduleForm.value.closed_refresh_enabled),
            closed_interval_minutes: Number(liveDataUpdateScheduleForm.value.closed_interval_minutes),
        });

        priceRefreshScheduleMessage.value = data.message;
        isLiveDataUpdateDialogOpen.value = false;
        priceRefreshScheduleForm.value = priceRefreshScheduleFormFromSettings(
            data.price_refresh_settings ?? priceRefreshSettings.value,
        );
        liveDataUpdateScheduleForm.value = priceRefreshScheduleFormFromSettings(
            data.price_refresh_settings ?? priceRefreshSettings.value,
        );

        if (data.refresh) {
            holdingMessage.value = data.refresh.message;
            startPriceRefreshPolling(data.refresh.refresh_id);
        }
    } catch (err) {
        priceRefreshScheduleError.value = err.message;
    } finally {
        isLiveDataUpdateScheduleSaving.value = false;
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
            await refreshVisibleApplicationData();
        }
    } catch (err) {
        stopIntradayBackfillPolling();

    }
}

function stopPriceRefreshPolling() {
    if (!priceRefreshTimer.value) {
        return;
    }

    window.clearInterval(priceRefreshTimer.value);
    priceRefreshTimer.value = null;
}

async function pollPriceRefreshStatus(refreshId) {
    try {
        const data = await depotsStore.loadWatchlistPriceRefresh(refreshId);
        holdingMessage.value = data.message;

        if (!data.refresh) {
            stopPriceRefreshPolling();
            holdingMessage.value = '';
            holdingError.value = '';

            return;
        }

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
        depotsStore.loadQueueStatus(),
        refreshVisibleApplicationData(),
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

async function toggleStockWatchItemSelection(holding) {
    if (selectedStockWatchItem.value?.id === holding.id) {
        selectedStockWatchItem.value = null;
        selectedStockPriceRange.value = 'intraday';
        stockPriceChartError.value = '';
        isStockPriceChartLoading.value = false;

        return;
    }

    selectedStockWatchItem.value = holding;
    selectedStockPriceRange.value = 'intraday';
    await loadSelectedStockPrices();
}

async function selectStockPriceRange(range) {
    selectedStockPriceRange.value = range;
    await loadSelectedStockPrices();
}

async function loadSelectedStockPrices() {
    if (!selectedStockWatchItem.value) {
        return;
    }

    const stockId = selectedStockWatchItem.value.id;
    const priceRange = selectedStockPriceRange.value;
    stockPriceChartError.value = '';
    isStockPriceChartLoading.value = true;

    try {
        await loadWatchlistHoldingsForActiveSection(1, { silent: true });
    } catch (error) {
        if (selectedStockWatchItem.value?.id === stockId && selectedStockPriceRange.value === priceRange) {
            stockPriceChartError.value = error.message;
        }
    } finally {
        if (selectedStockWatchItem.value?.id === stockId && selectedStockPriceRange.value === priceRange) {
            isStockPriceChartLoading.value = false;
        }
    }
}

function handleStockHoldingRowClick(holding) {
    if (activeSection.value === 'stocks') {
        toggleStockWatchItemSelection(holding);

        return;
    }

    toggleHoldingDetails(holding);
}

function abortDeleteHoldingDialog() {
    isDeleteHoldingDialogOpen.value = false;
    selectedHolding.value = null;
}

async function deleteHolding() {
    holdingError.value = '';
    holdingMessage.value = '';

    if (!selectedHolding.value) {
        return;
    }

    try {
        const data = await depotsStore.deleteWatchlistHolding(selectedHolding.value.id);
        holdingMessage.value = data.message;

        if (selectedStockWatchItem.value?.id === selectedHolding.value.id) {
            selectedStockWatchItem.value = null;
        }

        abortDeleteHoldingDialog();
        await loadWatchlistHoldingsForActiveSection(holdingsPagination.value.current_page);
    } catch (err) {
        holdingError.value = err.message;
    }
}

function openDeleteIndexDialog() {
    if (!selectedIndexWatchItem.value) {
        return;
    }

    selectedIndexWatchItemForRemoval.value = selectedIndexWatchItem.value;
    indexError.value = '';
    indexMessage.value = '';
    isDeleteIndexDialogOpen.value = true;
}

function abortDeleteIndexDialog() {
    isDeleteIndexDialogOpen.value = false;
    selectedIndexWatchItemForRemoval.value = null;
}

async function deleteIndexWatchItem() {
    indexError.value = '';
    indexMessage.value = '';

    try {
        const data = await depotsStore.deleteIndexWatchItem(selectedIndexWatchItemForRemoval.value.id);
        indexMessage.value = data.message;

        if (selectedIndexWatchItem.value?.id === selectedIndexWatchItemForRemoval.value.id) {
            selectedIndexWatchItem.value = null;
            selectedIndexPriceRange.value = 'intraday';
        }

        abortDeleteIndexDialog();
    } catch (error) {
        indexError.value = error.message;
    }
}

function formatTransactionDate(isoString) {
    const inputValue = transactionDateInputValue(isoString);

    if (!inputValue) return '–';

    const [year, month, day] = inputValue.split('-');

    return `${day}.${month}.${year}`;
}

function transactionDateInputValue(isoString) {
    if (!isoString) return '';

    const isoDateMatch = String(isoString).match(/^(\d{4})-(\d{2})-(\d{2})/);

    if (isoDateMatch) {
        return `${isoDateMatch[1]}-${isoDateMatch[2]}-${isoDateMatch[3]}`;
    }

    const date = new Date(isoString);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return localDateInputValue(date);
}

function transactionDateInputId(transactionId, scope) {
    return `transaction-date-${scope}-${transactionId}`;
}

function openTransactionDatePicker(transactionId, scope) {
    const dateInput = document.getElementById(transactionDateInputId(transactionId, scope));

    if (!dateInput) {
        return;
    }

    if (typeof dateInput.showPicker === 'function') {
        dateInput.showPicker();

        return;
    }

    dateInput.focus();
    dateInput.click();
}

async function updateTransactionDate(transaction, bookedAt) {
    if (!bookedAt || bookedAt === transactionDateInputValue(transaction.booked_at)) {
        return;
    }

    transactionsError.value = '';

    try {
        await depotsStore.updateTransactionDate(transaction.id, bookedAt);
    } catch (error) {
        transactionsError.value = error.message;
    }
}

function transactionTypeColor(type) {
    return {
        opening_balance: 'primary',
        deposit: 'success',
        withdrawal: 'error',
        dividend: 'teal',
        interest: 'cyan',
        fee: 'warning',
        tax: 'deep-orange',
        broker_bonus: 'purple',
        buy: 'warning',
        sell: 'teal',
    }[type] ?? 'default';
}

function transactionTypeLabel(type) {
    return {
        opening_balance: 'Start balance',
        deposit: 'Deposit',
        withdrawal: 'Withdrawal',
        dividend: 'Dividend',
        interest: 'Interest',
        fee: 'Fee',
        tax: 'Tax',
        broker_bonus: 'Broker bonus',
        buy: 'Buy',
        sell: 'Sell',
    }[type] ?? type;
}

function formatCashDelta(value) {
    const num = Number(value);
    const prefix = num > 0 ? '+' : '';

    return `${prefix}${formatAccountBalance(num)}`;
}

function formatAccountBalance(value) {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
        return '-';
    }

    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value ?? 0));
}

function dashboardPerformanceAmount(performance) {
    if (!performance || performance.change_amount === null) {
        return '— EUR';
    }

    const amount = Number(performance.change_amount);
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function dashboardPerformancePercent(performance) {
    if (!performance || performance.change_percent === null) {
        return '— %';
    }

    const percent = Number(performance.change_percent);
    const sign = percent > 0 ? '+' : '';

    return `${sign}${percent.toFixed(2)}%`;
}

function dashboardPerformanceValueClass(performance) {
    if (!performance || performance.change_amount === null) {
        return { 'text-medium-emphasis': true };
    }

    const amount = Number(performance?.change_amount ?? 0);

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function dashboardPerformanceIcon(performance) {
    if (!performance || performance.change_amount === null) {
        return 'mdi-minus';
    }

    const amount = Number(performance?.change_amount ?? 0);

    if (amount > 0) {
        return 'mdi-trending-up';
    }

    if (amount < 0) {
        return 'mdi-trending-down';
    }

    return 'mdi-minus';
}

function formatInteger(value) {
    return new Intl.NumberFormat('de-AT').format(Number(value ?? 0));
}

function formatIntradayTitle(title) {
    return String(title ?? 'Intraday').replace(/\s+-\s+5m$/, '');
}

function isDataIntradayDayExpanded(day) {
    return expandedDataIntradayDays.value[day.trading_date] === true;
}

function formatEodhdUsageReset(value) {
    return formatScheduleDateTime(value);
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

function depotHoldingValueMarkers(holding) {
    return depotPriceSource.value === 'latest' && hasPositionPieces(holding) && stockHoldingValue(holding) !== null
        ? marketDataMarkers(holding, 'latest_price') : [];
}

function depotHoldingsValueMarkers() {
    return mergeMarketDataMarkers(...depotHoldings.value.map((holding) => depotHoldingValueMarkers(holding)));
}

function depotValuationMarkers(field) {
    return marketDataMarkers(selectedDepotValuation(), field);
}

function selectedDepotHoldingPrice(holding) {
    return depotPriceSource.value === 'flatex'
        ? holding.flatex_price
        : holding.latest_price;
}

function selectedDepotValuation() {
    return depotValuations.value?.[depotPriceSource.value] ?? null;
}

function marketDataNumber(value) {
    return value === null || value === undefined || value === '' ? Number.NaN : Number(value);
}

function depotValuationNumber(key) {
    return marketDataNumber(selectedDepotValuation()?.[key]);
}

function formatDepotStockBalance() {
    return `${formatAccountBalance(depotValuationNumber('stock_balance'))} EUR`;
}

function formatDepotCashBalance() {
    return `${formatAccountBalance(depotValuationNumber('cash_balance'))} EUR`;
}

function formatDepotAccountBalance() {
    return `${formatAccountBalance(depotValuationNumber('account_balance'))} EUR`;
}

function formatDepotPreviousDayBalance() {
    return `${formatAccountBalance(depotValuationNumber('previous_day_balance'))} EUR`;
}

function hasDepotPreviousDayExternalCashFlow() {
    const amount = depotValuationNumber('previous_day_external_cash_flow_amount');

    return Number.isFinite(amount) && amount !== 0;
}

function formatDepotPreviousDayExternalCashFlowAmount() {
    const amount = depotValuationNumber('previous_day_external_cash_flow_amount');
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotPreviousDayChangeAmount() {
    const amount = depotValuationNumber('previous_day_change_amount');
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotPreviousDayChangePercent() {
    const amount = depotValuationNumber('previous_day_change_percent');
    const sign = amount > 0 ? '+' : '';

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
}

function depotPreviousDayChangeClass() {
    const amount = depotValuationNumber('previous_day_change_amount');

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function formatDepotYearStockPrice(stock, key) {
    return formatPriceValue(stock?.[key], stock?.currency, { showCurrency: true });
}

function depotStockPeriodHeading() {
    if (depotStockPeriod.value === '4-ever') {
        return 'All traded stocks';
    }

    return depotStockPeriodYear.value === null
        ? 'Traded stocks'
        : `Traded stocks ${depotStockPeriodYear.value}`;
}

function depotStockPeriodEntryLabel() {
    return depotStockPeriod.value === '4-ever' ? 'Ø Buy' : 'Ø Buy / 1.1.';
}

function depotStockPeriodEmptyMessage() {
    return depotStockPeriod.value === '4-ever'
        ? 'No traded stocks found.'
        : `No traded stocks in ${depotStockPeriodYear.value ?? new Date().getFullYear()}.`;
}

function formatDepotStockTradedVolume(stock) {
    return `${formatAccountBalance(stock?.traded_volume)} ${stock?.currency ?? 'EUR'}`;
}

function formatDepotStockTradedPieces(stock) {
    return `${formatAdaptiveNumber(stock?.traded_volume_pieces)} pieces`;
}

function formatDepotYearStockPosition(stock) {
    const pieces = Number(stock?.position_pieces);

    if (!Number.isFinite(pieces) || pieces <= 0) {
        return 'Sold';
    }

    return `${formatAdaptiveNumber(pieces)} in stock`;
}

function formatDepotYearStockChangePercent(stock) {
    const percent = marketDataNumber(stock?.change_percent);

    if (!Number.isFinite(percent)) {
        return '-';
    }

    return `${percent > 0 ? '+' : ''}${percent.toFixed(2)}%`;
}

function formatDepotYearStockChangeAmount(stock) {
    const amount = marketDataNumber(stock?.change_amount);

    if (!Number.isFinite(amount)) {
        return '-';
    }

    return `${amount > 0 ? '+' : ''}${formatAccountBalance(amount)}`;
}

function depotYearStockChangeClass(stock) {
    const amount = marketDataNumber(stock?.change_amount);

    return {
        'text-success': Number.isFinite(amount) && amount > 0,
        'text-error': Number.isFinite(amount) && amount < 0,
        'text-medium-emphasis': !Number.isFinite(amount) || amount === 0,
    };
}

function formatDepotYearStartBalance() {
    return `${formatAccountBalance(depotValuationNumber('year_start_balance'))} EUR`;
}

function formatDepotCurrentBalance() {
    return `${formatAccountBalance(depotValuationNumber('current_balance'))} EUR`;
}

function formatDepotOneWeekStartBalance() {
    return `${formatAccountBalance(depotValuationNumber('one_week_start_balance'))} EUR`;
}

function formatDepotMonthStartBalance() {
    return `${formatAccountBalance(depotValuationNumber('month_start_balance'))} EUR`;
}

function depotBalanceChangeTaxAmount() {
    const amount = depotValuationNumber('taxable_stock_gain_amount');

    return amount > 0 ? roundCurrencyAmount(amount * kestTaxRate) : 0;
}

function depotAfterTaxBalanceChangeAmount() {
    return depotValuationNumber('balance_change_amount') - depotBalanceChangeTaxAmount();
}

function roundCurrencyAmount(value) {
    return Math.round((value + Number.EPSILON) * 100) / 100;
}

function formatDepotBalanceChangeAmount() {
    const amount = depotValuationNumber('balance_change_amount');
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotCorrectedBalance() {
    return `${formatAccountBalance(depotValuationNumber('current_balance') - depotBalanceChangeTaxAmount())} EUR`;
}

function formatDepotCorrectedBalanceChangeAmount() {
    const amount = depotAfterTaxBalanceChangeAmount();
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotOneWeekChangeAmount() {
    const amount = depotValuationNumber('one_week_change_amount');
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotMonthChangeAmount() {
    const amount = depotValuationNumber('month_change_amount');
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotBalanceChangePercent() {
    const amount = depotValuationNumber('balance_change_percent');
    const sign = amount > 0 ? '+' : '';

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
}

function formatDepotCorrectedBalanceChangePercent() {
    const netCapital = marketDataNumber(selectedDepotValuation()?.year_net_capital ?? selectedDepotValuation()?.year_start_balance);
    const amount = netCapital <= 0
        ? Number.NaN
        : (depotAfterTaxBalanceChangeAmount() / netCapital) * 100;
    const sign = amount > 0 ? '+' : '';

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
}

function formatDepotOneWeekChangePercent() {
    const amount = depotValuationNumber('one_week_change_percent');
    const sign = amount > 0 ? '+' : '';

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
}

function formatDepotMonthChangePercent() {
    const amount = depotValuationNumber('month_change_percent');
    const sign = amount > 0 ? '+' : '';

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
}

function formatDepotPerformanceBalance(value) {
    return `${formatAccountBalance(value)} EUR`;
}

function formatDepotPerformanceDate(value) {
    return formatIndexHistoryDate(value);
}

function formatDepotPerformanceEndpoint(point) {
    if (!point || Number.isNaN(point.chart_price)) {
        return '-';
    }

    return formatDepotPerformanceBalance(point.chart_price);
}

function formatDepotAccountEndpoint(point) {
    return formatDepotPerformanceBalance(point?.account_balance);
}

function depotPerformanceChangeClass() {
    const result = depotPerformanceResult();

    if (!result) {
        return 'text-medium-emphasis';
    }

    if (result.amount > 0) {
        return 'text-success';
    }

    if (result.amount < 0) {
        return 'text-error';
    }

    return 'text-medium-emphasis';
}

function formatDepotPerformanceChangeAmount() {
    const result = depotPerformanceResult();

    if (!result) {
        return '- EUR';
    }

    const amount = result.amount;
    const sign = amount > 0 ? '+' : '';

    return `${sign}${formatAccountBalance(amount)} EUR`;
}

function formatDepotPerformanceChangePercent() {
    const result = depotPerformanceResult();

    if (!result || result.capital <= 0) {
        return '-';
    }

    const percent = (result.amount / result.capital) * 100;
    const sign = percent > 0 ? '+' : '';

    return `${sign}${percent.toFixed(2)}%`;
}

function depotPerformanceResult() {
    const series = [...(depotPerformanceSeries.value ?? [])].sort((first, second) => first.date.localeCompare(second.date));
    const first = series[0];
    const latest = series.at(-1);
    const opening = marketDataNumber(first?.account_balance);
    const closing = marketDataNumber(latest?.account_balance);
    if (!first?.date?.endsWith('-01-01') || !Number.isFinite(opening) || !Number.isFinite(closing)) {
        return null;
    }
    const flows = marketDataNumber(latest.net_external_cash_flow ?? 0) - marketDataNumber(first.net_external_cash_flow ?? 0);
    if (!Number.isFinite(flows)) {
        return null;
    }

    return {
        amount: closing - opening - flows,
        capital: opening + flows,
        source_markers: marketDataMarkers([first, latest]),
    };
}

function depotBalanceChangeClass() {
    const amount = depotValuationNumber('balance_change_amount');

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function depotCorrectedBalanceChangeClass() {
    const amount = depotAfterTaxBalanceChangeAmount();

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function depotOneWeekChangeClass() {
    const amount = depotValuationNumber('one_week_change_amount');

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function depotMonthChangeClass() {
    const amount = depotValuationNumber('month_change_amount');

    return {
        'text-success': amount > 0,
        'text-error': amount < 0,
        'text-medium-emphasis': amount === 0,
    };
}

function formatCurrentDayMonth() {
    return formatDayMonth(currentDisplayDate());
}

function formatOneWeekAgoDayMonth() {
    const dateParts = currentDisplayDateParts({ weekday: 'short' });
    const date = dateFromDisplayDateParts(dateParts);
    const daysSinceMonday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].indexOf(dateParts.weekday);

    date.setUTCDate(date.getUTCDate() - daysSinceMonday - 1);

    return formatDayMonth(date);
}

function formatPreviousMonthEndDayMonth() {
    const date = currentDisplayDate();
    date.setUTCDate(0);

    return formatDayMonth(date);
}

function currentDisplayDate() {
    return dateFromDisplayDateParts(currentDisplayDateParts());
}

function currentDisplayDateParts(options = {}) {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: displayTimeZone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        ...options,
    }).formatToParts(new Date());

    return Object.fromEntries(parts.map((part) => [part.type, part.value]));
}

function dateFromDisplayDateParts(dateParts) {
    return new Date(Date.UTC(
        Number(dateParts.year),
        Number(dateParts.month) - 1,
        Number(dateParts.day),
    ));
}

function formatDayMonth(date) {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
    }).format(date);
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

function stockDisplayName(stock, fallback = '-') {
    return stock?.name || stock?.symbol || fallback;
}

function stockDisplayLabel(stock, fallback = '-') {
    const name = stockDisplayName(stock, fallback);
    const subtitle = stock?.subtitle || stock?.stock_subtitle;

    return subtitle ? `${name} · ${subtitle}` : name;
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

function formatPreviousDayPrice(holding) {
    if (holding.previous_day_price === null || holding.previous_day_price === undefined || holding.previous_day_price === '') {
        return '-';
    }

    return formatPriceValue(holding.previous_day_price, holding.currency);
}

function previousDayChangePercent(holding) {
    if (
        holding.previous_day_change_percent === null
        || holding.previous_day_change_percent === undefined
        || holding.previous_day_change_percent === ''
    ) {
        return null;
    }

    const changePercent = Number(holding.previous_day_change_percent);

    return Number.isNaN(changePercent) ? null : changePercent;
}

function formatPreviousDayChangePercent(holding) {
    return formatPriceChangePercent(previousDayChangePercent(holding));
}

function previousDayChangePercentClass(holding) {
    return priceChangePercentClass(previousDayChangePercent(holding));
}

function mobileHoldingPriceSource(holding) {
    if (holding.latest_price !== null && holding.latest_price !== undefined && holding.latest_price !== '') {
        return 'latest';
    }

    return null;
}

function formatMobileHoldingPrice(holding) {
    return formatLatestPrice(holding);
}

function formatMobileHoldingPriceLoadedAt(holding) {
    if (holding.latest_price === null || holding.latest_price === undefined || holding.latest_price === '') {
        return '';
    }

    const loadedAt = holding.latest_price_as_of || holding.latest_price_fetched_at;

    if (!loadedAt) {
        return '';
    }

    const formattedLoadedAt = formatCompactSourceDateTime(loadedAt);

    return formattedLoadedAt === '-' ? '' : formattedLoadedAt;
}

function mobileHoldingPriceClass(holding) {
    return watchListLatestPriceClass(holding);
}

function mobileHoldingPriceChangePercent(holding) {
    if (mobileHoldingPriceSource(holding) === 'latest' && formatLatestPriceChangePercent(holding)) {
        return formatLatestPriceChangePercent(holding);
    }

    return '';
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

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
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

function indexPricesForRange(prices, range) {
    const orderedPrices = [...prices]
        .filter((price) => price?.trading_date)
        .sort((first, second) => second.trading_date.localeCompare(first.trading_date));

    if (range === 'intraday') {
        return orderedPrices.slice(0, 1);
    }

    const rangeDays = {
        '1w': 7,
        '1m': 31,
        '6m': 183,
        '1y': 366,
    }[range];

    if (!rangeDays || orderedPrices.length === 0) {
        return orderedPrices;
    }

    const latestDate = new Date(`${orderedPrices[0].trading_date}T00:00:00Z`);
    const cutoffDate = new Date(latestDate);
    cutoffDate.setUTCDate(cutoffDate.getUTCDate() - rangeDays);
    const cutoff = cutoffDate.toISOString().slice(0, 10);

    return orderedPrices.filter((price) => price.trading_date >= cutoff);
}

function stockChartRequestRange(range) {
    return range === 'intraday' ? 'today' : range;
}

function stockChartPrices(holding, range) {
    if (!holding) {
        return [];
    }

    const chartWindow = stockChartWindow(holding, range);
    const chartPrices = chartWindow.prices
        .map((price) => {
            const asOf = price.as_of ?? null;
            const asOfDate = asOf ? new Date(asOf) : null;
            const tradingDate = typeof price.trading_date === 'string'
                ? price.trading_date.slice(0, 10)
                : (asOfDate && !Number.isNaN(asOfDate.getTime()) ? localDateKey(asOfDate, displayTimeZone) : null);

            return {
                ...price,
                trading_date: tradingDate,
                actual_price: price.price,
                actual_price_as_of: asOf,
                chart_as_of: asOf,
                chart_label: range === 'intraday' ? formatIndexIntradayTime(asOf) : null,
            };
        })
        .filter((price) => price.trading_date && !Number.isNaN(indexHistoryChartPrice(price)))
        .sort((firstPrice, secondPrice) => String(secondPrice.chart_as_of ?? secondPrice.trading_date)
            .localeCompare(String(firstPrice.chart_as_of ?? firstPrice.trading_date)));

    if (range !== 'intraday' || chartPrices.length === 0) {
        return chartPrices;
    }

    const previousClose = stockPreviousTradingDayClose(holding, chartPrices[0].trading_date);

    if (!previousClose) {
        return chartPrices;
    }

    return [
        ...chartPrices,
        {
            source_markers: previousClose.source_markers,
            trading_date: previousClose.tradingDate,
            actual_price: previousClose.price,
            chart_label: formatIndexChartDate(previousClose.tradingDate) || 'Previous close',
        },
    ];
}

function stockPreviousTradingDayClose(holding, currentTradingDate) {
    const previousClose = Number(holding.end_price_24);

    if (!Number.isNaN(previousClose) && holding.end_price_24 !== null && holding.end_price_24 !== '') {
        return {
            source_markers: holding.end_price_24_source_markers ?? [],
            price: holding.end_price_24,
            tradingDate: holding.end_price_24_date ?? null,
        };
    }

    const dailyPrices = Array.isArray(holding.daily_prices) ? holding.daily_prices : [];
    const previousDailyPrice = [...dailyPrices]
        .filter((price) => (
            price?.trading_date
            && (!currentTradingDate || price.trading_date < currentTradingDate)
            && !Number.isNaN(Number(price.price))
        ))
        .sort((firstPrice, secondPrice) => secondPrice.trading_date.localeCompare(firstPrice.trading_date))[0];

    if (!previousDailyPrice) {
        return null;
    }

    return {
        source_markers: marketDataMarkers(previousDailyPrice),
        price: previousDailyPrice.price,
        tradingDate: previousDailyPrice.trading_date,
    };
}

function stockChartWindow(holding, range) {
    const dailyPrices = Array.isArray(holding.daily_prices)
        ? holding.daily_prices.map((price) => ({
            ...price,
            as_of: `${price.trading_date}T00:00:00.000Z`,
        }))
        : [];
    const intradayPrices = Array.isArray(holding.intraday_prices) ? holding.intraday_prices : [];
    const intradayCandles = Array.isArray(holding.intraday_candles) ? holding.intraday_candles : [];
    const realtimePrices = Array.isArray(holding.recent_prices) ? holding.recent_prices : [];
    const sources = range === 'intraday'
        ? [realtimePrices, intradayPrices, intradayCandles, dailyPrices]
        : (range === '1w'
            ? [intradayPrices, intradayCandles, dailyPrices, realtimePrices]
            : [dailyPrices, intradayPrices, intradayCandles, realtimePrices]);
    const windows = sources.map((prices) => analyzeChartWindow(prices, stockChartRequestRange(range)));

    return windows.find((window) => window.prices.length >= 2)
        ?? windows.find((window) => window.prices.length > 0)
        ?? { prices: [], canMoveBackward: false, canMoveForward: false };
}

function chartPriceDateRange(prices) {
    const tradingDates = prices
        .map((price) => price.trading_date)
        .filter(Boolean)
        .sort();

    if (tradingDates.length === 0) {
        return '';
    }

    const earliestDate = tradingDates[0];
    const latestDate = tradingDates.at(-1);

    return earliestDate === latestDate
        ? formatIndexHistoryDate(latestDate)
        : `${formatIndexHistoryDate(earliestDate)} – ${formatIndexHistoryDate(latestDate)}`;
}

function indexIntradayChartPrices(prices, indexItem) {
    const latestPrice = prices[0];

    if (!latestPrice) {
        return [];
    }

    const realtimePrices = (indexItem?.realtime_prices ?? [])
        .map((realtimePrice) => ({
            ...latestPrice,
            ...realtimePrice,
            actual_price: realtimePrice.price,
            actual_price_as_of: realtimePrice.as_of,
            chart_label: formatIndexIntradayTime(realtimePrice.as_of) ?? 'Latest',
        }))
        .filter((price) => indexHistoryActualPrice(price) !== null && indexHistoryActualPrice(price) !== undefined);
    const latestTime = formatIndexIntradayTime(latestPrice.actual_price_as_of ?? latestPrice.last_price_as_of);
    const previousClose = indexItem?.last_price ?? latestPrice.last_price ?? latestPrice.start_price;
    const latestFallback = realtimePrices.length === 0
        ? [{
            ...latestPrice,
            actual_price: latestPrice.actual_price ?? latestPrice.last_price,
            chart_label: latestTime ?? 'Latest',
        }]
        : [];

    return [
        ...realtimePrices,
        ...latestFallback,
        {
            ...latestPrice,
            actual_price: previousClose,
            chart_label: 'Previous close',
        },
    ].filter((price) => indexHistoryActualPrice(price) !== null && indexHistoryActualPrice(price) !== undefined);
}

function formatIndexIntradayTime(value) {
    const date = dateFromUtcValue(value);

    if (!date) {
        return null;
    }

    return formatViennaDateTime(date, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
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

function formatStockChartEndpointPrice(point) {
    if (!point || Number.isNaN(point.chart_price)) {
        return '-';
    }

    return formatIndexHistoryPrice(point.chart_price, selectedStockWatchItem.value);
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
    const chartWidth = 1200;
    const chartHeight = 460;
    const chartPadding = {
        top: 64,
        right: 132,
        bottom: 76,
        left: 112,
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
            lineSegments: [],
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
            label: price.chart_label ?? formatIndexChartDate(price.trading_date),
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

function buildDepotPerformanceChart(series) {
    const chartWidth = 1800;
    const chartHeight = 520;
    const chartPadding = {
        top: 40,
        right: 92,
        bottom: 58,
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
    const latestPoint = [...series].sort((first, second) => first.date.localeCompare(second.date)).at(-1);
    const totalExternalCashFlow = marketDataNumber(latestPoint?.net_external_cash_flow ?? 0);
    const chartPrices = [...series]
        .map((point) => ({
            ...point,
            chart_price: Object.hasOwn(point, 'performance_balance')
                ? marketDataNumber(point.performance_balance)
                : marketDataNumber(point.account_balance) + totalExternalCashFlow - marketDataNumber(point.net_external_cash_flow ?? 0),
        }))
        .filter((point) => point.date && Number.isFinite(point.chart_price))
        .sort((first, second) => first.date.localeCompare(second.date));

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
            highMarker: null,
            lowMarker: null,
            trendLine: null,
        };
    }

    const domainStartDate = new Date(`${chartPrices[0].date.slice(0, 4)}-01-01T00:00:00Z`);
    const domainYear = domainStartDate.getUTCFullYear();
    const values = chartPrices.map((point) => point.chart_price);
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
            label: formatAdaptiveNumber(value),
        };
    });
    const points = chartPrices.map((point) => {
        const x = plot.left + depotPerformanceYearRatio(point.date) * plotWidth;
        const normalized = (point.chart_price - chartMin) / chartRange;
        const y = plot.bottom - normalized * plotHeight;

        return {
            ...point,
            x,
            y,
            label: formatDepotPerformanceDate(point.date),
        };
    });
    const segments = [];
    for (const [index, point] of points.entries()) {
        const previous = points[index - 1];
        const distance = previous ? Date.parse(point.date) - Date.parse(previous.date) : 0;
        if (!previous || distance > 24 * 60 * 60 * 1000) {
            segments.push([]);
        }
        segments.at(-1).push(point);
    }
    const lineSegments = segments.map((segment) => segment.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' '));

    const highPoint = points.find((point) => point.chart_price === max);
    const lowPoint = points.find((point) => point.chart_price === min);

    return {
        width: chartWidth,
        height: chartHeight,
        plot,
        points,
        lineSegments,
        linePoints: points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' '),
        horizontalGridLines,
        verticalGridLines: depotPerformanceMonthMarkers(domainYear, plot),
        first: points[0],
        latest: points[points.length - 1],
        firstLabel: chartEndpointLabel(points[0], plot, 'start', null, {
            labelPrefix: formatDepotPerformanceDate(points[0].date),
        }),
        latestLabel: chartEndpointLabel(points[points.length - 1], plot, 'end', points[0], {
            labelPrefix: formatDepotPerformanceDate(points[points.length - 1].date),
        }),
        highMarker: depotPerformanceExtremumMarker(highPoint, 'high', plot),
        lowMarker: depotPerformanceExtremumMarker(lowPoint, 'low', plot),
        trendLine: segments.length === 1 ? chartRegressionLine(points, chartMin, chartRange, plot) : null,
    };
}

function depotPerformanceYearRatio(dateString) {
    const date = new Date(`${dateString}T00:00:00Z`);

    if (Number.isNaN(date.getTime())) {
        return 0;
    }

    const monthIndex = date.getUTCMonth();
    const dayOfMonth = date.getUTCDate();
    const daysInMonth = new Date(Date.UTC(date.getUTCFullYear(), monthIndex + 1, 0)).getUTCDate();
    const monthProgress = daysInMonth <= 1
        ? 0
        : (dayOfMonth - 1) / (daysInMonth - 1);

    return (monthIndex + monthProgress) / 12;
}

function depotPerformanceMonthMarkers(year, plot) {
    const plotWidth = plot.right - plot.left;

    const monthMarkers = Array.from({ length: 12 }, (_, monthIndex) => ({
        x: plot.left + (monthIndex / 12) * plotWidth,
        label: `1.${monthIndex + 1}.${year}`,
        labelAnchor: monthIndex === 0 ? 'start' : 'middle',
    }));

    return [
        ...monthMarkers,
        {
            x: plot.right,
            label: `31.12.${year}`,
            labelAnchor: 'end',
        },
    ];
}

function depotPerformanceExtremumMarker(point, direction, plot) {
    if (!point) {
        return null;
    }

    const markerDistance = 24;
    const markerPadding = 18;
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

    return {
        ...point,
        direction,
        markerX: point.x,
        markerY,
        labelX: shouldPlaceLabelLeft ? point.x - 12 : point.x + 12,
        labelY: markerY,
        labelAnchor: shouldPlaceLabelLeft ? 'end' : 'start',
        labelPrefix: direction === 'high' ? 'High' : 'Low',
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
    const priceDateKeys = chartPrices.map((price) => ({
        ...price,
        local_date_key: localDateKey(new Date(price.as_of), displayTimeZone),
    }));
    const tradingDates = [...new Set(priceDateKeys.map((price) => price.local_date_key))].sort();
    const maximumWindowOffset = Math.max(tradingDates.length - 1, 0);
    const currentWindowOffset = Math.min(windowOffset, maximumWindowOffset);
    const selectedTradingDate = tradingDates[tradingDates.length - 1 - currentWindowOffset];

    return {
        prices: priceDateKeys.filter((price) => price.local_date_key === selectedTradingDate),
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

function isFreeIntradayPoint(price) {
    return ['deutsche_boerse_trade', 'xetra_posttrade', 'yahoo_intraday', 'yahoo_xetra_intraday'].includes(price.source_key)
        || ['observed_trade', 'regular_market_last'].includes(price.price_type)
        || /Deutsche Börse|Yahoo Finance/.test(price.source_name ?? '');
}

function isAnalyzeTodayRange(rangeKey) {
    return ['today', 'today-1'].includes(rangeKey);
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

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
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
    if (depotHoldings.value.some((holding) => hasPositionPieces(holding) && yearStartChangeAmount(holding) === null)) {
        return null;
    }

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

    return Number.isFinite(amount) ? `${sign}${amount.toFixed(2)}%` : '-';
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
    const days = expandedHoldingIntradayDays(holding);

    if (days.length > 0) {
        return days[0];
    }

    const candlePayload = holdingIntradayCandles.value[holding.id] ?? null;

    return candlePayload?.intraday ?? null;
}

function expandedHoldingIntradayDays(holding) {
    const candlePayload = holdingIntradayCandles.value[holding.id] ?? null;
    const days = candlePayload?.intraday_days;

    if (Array.isArray(days)) {
        return days;
    }

    return candlePayload?.intraday ? [candlePayload.intraday] : [];
}

function expandedHoldingIntradayRows(holding) {
    return expandedHoldingIntradayDay(holding)?.rows ?? [];
}

function expandedHoldingIntradayChartRows(holding) {
    const rows = expandedHoldingIntradayRows(holding);
    const previousCloseRow = expandedHoldingPreviousCloseRow(holding, rows);

    return previousCloseRow ? [previousCloseRow, ...rows] : rows;
}

function expandedHoldingPreviousCloseRow(holding, currentRows) {
    if (currentRows.length === 0) {
        return null;
    }

    const currentDay = expandedHoldingIntradayDay(holding);
    const currentTradingDate = currentDay?.trading_date ?? null;
    const previousDay = expandedHoldingIntradayDays(holding)
        .filter((day) => day !== currentDay)
        .filter((day) => Array.isArray(day?.rows) && day.rows.length > 0)
        .filter((day) => currentTradingDate === null || String(day.trading_date ?? '') < String(currentTradingDate))
        .sort((firstDay, secondDay) => String(firstDay.trading_date ?? '').localeCompare(String(secondDay.trading_date ?? '')))
        .at(-1);
    const previousCloseRow = previousDay?.rows
        ?.filter((row) => !Number.isNaN(Number(row.close)))
        .at(-1);

    if (!previousCloseRow) {
        return null;
    }

    const firstCurrentTime = analyzeIntradayCandleDate(currentRows[0])?.getTime();

    if (!firstCurrentTime) {
        return null;
    }

    return {
        ...previousCloseRow,
        chart_time: firstCurrentTime - (5 * 60 * 1000),
        chart_label: 'Prev',
        is_previous_trading_close: true,
    };
}

function expandedHoldingIntradayChart(holding) {
    return expandedHoldingIntradayCharts.value[holding.id] ?? emptyHoldingIntradayChart();
}

function expandedHoldingIntradayTitle(holding) {
    return formatIntradayTitle(expandedHoldingIntradayDay(holding)?.title ?? 'Intraday');
}

function emptyHoldingIntradayChart() {
    const chartWidth = 920;
    const chartHeight = 260;
    const chartPadding = {
        top: 24,
        right: 52,
        bottom: 42,
        left: 82,
    };
    const plot = {
        left: chartPadding.left,
        top: chartPadding.top,
        right: chartWidth - chartPadding.right,
        bottom: chartHeight - chartPadding.bottom,
    };

    return {
        width: chartWidth,
        height: chartHeight,
        plot,
        points: [],
        linePoints: '',
        horizontalGridLines: [],
        verticalGridLines: [],
        highMarker: null,
        lowMarker: null,
    };
}

function buildHoldingIntradayChart(rows, holding) {
    const chart = emptyHoldingIntradayChart();
    const plotWidth = chart.plot.right - chart.plot.left;
    const plotHeight = chart.plot.bottom - chart.plot.top;
    const chartRows = rows
        .map((row) => ({
            ...row,
            chart_time: Number(row.chart_time) > 0
                ? Number(row.chart_time)
                : analyzeIntradayCandleDate(row)?.getTime() ?? null,
            chart_price: Number(row.close),
        }))
        .filter((row) => row.chart_time !== null && !Number.isNaN(row.chart_price))
        .sort((a, b) => a.chart_time - b.chart_time);

    if (chartRows.length === 0) {
        return chart;
    }

    const values = chartRows.map((row) => row.chart_price);
    const min = Math.min(...values);
    const max = Math.max(...values);
    const range = max - min;
    const rangePadding = range === 0 ? Math.max(Math.abs(max) * 0.02, 0.01) : range * 0.08;
    const chartMin = min - rangePadding;
    const chartMax = max + rangePadding;
    const chartRange = chartMax - chartMin;
    const firstTime = chartRows[0].chart_time;
    const latestTime = chartRows[chartRows.length - 1].chart_time;
    const timeRange = latestTime - firstTime;
    const points = chartRows.map((row, index) => {
        const x = timeRange === 0
            ? chart.width / 2
            : chart.plot.left + ((row.chart_time - firstTime) / timeRange) * plotWidth;
        const normalized = (row.chart_price - chartMin) / chartRange;
        const y = chart.plot.bottom - normalized * plotHeight;

        return {
            ...row,
            x,
            y,
            label: row.chart_label ?? formatHoldingIntradayChartTime(row.chart_time),
        };
    });
    const highPoint = points.find((point) => point.chart_price === max);
    const lowPoint = points.find((point) => point.chart_price === min);
    const horizontalGridLines = Array.from({ length: 4 }, (_, index) => {
        const ratio = index / 3;
        const value = chartMax - chartRange * ratio;

        return {
            value,
            y: chart.plot.top + plotHeight * ratio,
            label: formatPriceValue(value, holding.currency),
        };
    });

    return {
        ...chart,
        points,
        linePoints: points.some(isFreeIntradayPoint) ? '' : points.map((point) => `${point.x.toFixed(2)},${point.y.toFixed(2)}`).join(' '),
        horizontalGridLines,
        verticalGridLines: holdingIntradayChartTickPoints(points).map((point) => ({
            x: point.x,
            label: point.label,
        })),
        highMarker: holdingIntradayExtremumMarker(highPoint, 'high', chart.plot, holding),
        lowMarker: holdingIntradayExtremumMarker(lowPoint, 'low', chart.plot, holding),
    };
}

function holdingIntradayExtremumMarker(point, direction, plot, holding) {
    if (!point) {
        return null;
    }

    const markerDistance = 20;
    const markerPadding = 12;
    const labelOffset = 12;
    const preferredMarkerY = direction === 'high'
        ? point.y - markerDistance
        : point.y + markerDistance;
    const fallbackMarkerY = direction === 'high'
        ? point.y + markerDistance
        : point.y - markerDistance;
    const markerY = preferredMarkerY >= plot.top + markerPadding && preferredMarkerY <= plot.bottom - markerPadding
        ? preferredMarkerY
        : fallbackMarkerY;
    const boundedMarkerY = Math.min(plot.bottom - markerPadding, Math.max(plot.top + markerPadding, markerY));
    const shouldPlaceLabelLeft = point.x > (plot.left + plot.right) / 2;

    return {
        ...point,
        direction,
        markerX: point.x,
        markerY: boundedMarkerY,
        labelX: point.x + (shouldPlaceLabelLeft ? -labelOffset : labelOffset),
        labelY: boundedMarkerY,
        labelAnchor: shouldPlaceLabelLeft ? 'end' : 'start',
        label: `${direction === 'high' ? 'High' : 'Low'} ${formatPriceValue(point.chart_price, holding.currency)}`,
    };
}

function holdingIntradayChartTickPoints(points) {
    const maximumTickCount = 5;

    if (points.length <= maximumTickCount) {
        return points;
    }

    const lastPointIndex = points.length - 1;
    const pointIndexes = Array.from({ length: maximumTickCount }, (_, index) => (
        Math.round((index / (maximumTickCount - 1)) * lastPointIndex)
    ));

    return [...new Set(pointIndexes)].map((index) => points[index]);
}

function formatHoldingIntradayChartTime(timestamp) {
    return formatViennaDateTime(new Date(timestamp), {
        hour: '2-digit',
        minute: '2-digit',
    });
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

function holdingDayTrend(holding) {
    const latestRecentPriceTrend = recentPricesForExpandedHolding(holding).at(-1)?.trend ?? null;

    if (latestRecentPriceTrend && latestRecentPriceTrend !== 'flat') {
        return latestRecentPriceTrend;
    }

    return holding.latest_price_tick_trend
        ?? holding.latest_price_trend
        ?? latestRecentPriceTrend;
}

function holdingDayTrendDotClass(holding) {
    return recentStoredPriceTrendDotClass({ trend: holdingDayTrend(holding) });
}

function holdingDayTrendLabel(holding) {
    return `Day indicator: ${recentStoredPriceTrendLabel({ trend: holdingDayTrend(holding) })}`;
}

function latestPriceClass(holding) {
    if (holding.price_type === 'intraday') {
        return {
            'text-success': holding.latest_price_trend === 'up',
            'text-error': holding.latest_price_trend === 'down',
        };
    }

    return {
        'bg-success text-white': holding.latest_price_trend === 'up',
        'bg-error text-white': holding.latest_price_trend === 'down',
    };
}

function watchListLatestPriceClass(holding) {
    if (activeSection.value === 'stocks' && !stockHoldingRefreshSchedule(holding).isActive) {
        return {
            'text-success': holding.latest_price_trend === 'up',
            'text-error': holding.latest_price_trend === 'down',
        };
    }

    return latestPriceClass(holding);
}

function latestPriceIsLive(holding) {
    return isLiveUpdateActive(priceRefreshSettings.value)
        && !isFreeIntradayPoint(holding)
        && stockHoldingRefreshSchedule(holding).isActive
        && holding.latest_price !== null
        && holding.latest_price !== undefined
        && holding.latest_price !== ''
        && holding.price_type !== null
        && holding.price_type !== undefined
        && holding.price_type !== 'intraday';
}

function formatSessionPrice(value, holding) {
    return formatPriceValue(value, holding.currency);
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

function formatCompactSourceDateTime(value) {
    const formattedDateTime = formatSourceDateTime(value);

    return formattedDateTime
        .replace(/^(\d{2}\.\d{2}\.)\d{4},\s*/, '$1 ')
        .replace(/^(\d{2}\.\d{2}\.)\d{4}$/, '$1');
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

function formatExchangeNextHolidays(exchange) {
    const exchangeToday = exchangeLocalDateParts(exchange.timezone);
    const todayKey = exchangeToday ? exchangeDateKey(exchangeToday) : new Date().toISOString().slice(0, 10);
    const nextHolidays = exchangeHolidayDates(exchange)
        .filter((holiday) => holiday >= todayKey)
        .sort()
        .slice(0, 3);

    if (!nextHolidays.length) {
        return '-';
    }

    return nextHolidays
        .map((holiday) => formatExchangeHolidayDate(holiday))
        .join(', ');
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

function formatExchangeHolidayDate(holiday) {
    const matches = String(holiday).match(/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/);

    if (!matches?.groups) {
        return String(holiday);
    }

    return formatExchangeDate({
        year: Number(matches.groups.year),
        month: Number(matches.groups.month),
        day: Number(matches.groups.day),
    });
}

function formatExchangeDate(exchangeDate) {
    return new Intl.DateTimeFormat('de-AT', {
        timeZone: 'UTC',
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
    }).format(new Date(Date.UTC(exchangeDate.year, exchangeDate.month - 1, exchangeDate.day)));
}

function exchangeLocalDateParts(timezone, date = new Date()) {
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
        }).formatToParts(date);
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

function formatIndexEodhdUpdateSchedule(settings) {
    const times = settings?.times ?? [];
    const runTimes = times.length > 0 ? times.join(' · ') : 'loading…';

    return `EOD + 5-minute intraday run times · ${runTimes}`;
}

function indexEodhdUpdateStatusDetail(settings) {
    return settings?.status === 'updating'
        ? 'The EOD and 5-minute intraday synchronization is running.'
        : '';
}

function indexEodhdActivityLabel(settings) {
    if (!settings) {
        return 'Loading';
    }

    return settings.status === 'updating' ? 'Active' : 'Inactive';
}

function indexEodhdActivityColor(settings) {
    if (!settings) {
        return 'default';
    }

    return settings.status === 'updating' ? 'success' : 'error';
}

function formatLiveDataUpdateSchedule(settings) {
    const interval = settings?.trading_interval_minutes ?? 20;
    const startTime = settings?.trading_start_time;
    const endTime = settings?.trading_end_time;
    const closedSchedule = settings?.closed_refresh_enabled
        ? `closed: every ${settings?.closed_interval_minutes ?? 60} min`
        : 'closed: off';

    if (startTime && endTime) {
        return `Mo-Fr ${startTime}-${endTime} · ${interval} min · ${closedSchedule}`;
    }

    const startOffset = settings?.trading_starts_before_minutes ?? 0;
    const endOffset = settings?.trading_ends_after_minutes ?? 0;

    return `Mo-Fr start ${startOffset} min before trading until ${endOffset} min after trading · ${interval} min · ${closedSchedule}`;
}

function buildStockHoldingRefreshSchedule(holding, settings, referenceDate) {
    const exchangeLabel = stockHoldingExchangeLabel(holding);
    const tradingTimes = stockHoldingTradingTimes(holding);

    if (!tradingTimes) {
        return {
            isActive: false,
            sessionLabel: null,
            sessionWindow: null,
            scheduleStatus: 'Waiting',
            scheduleColor: 'default',
            status: 'Unavailable',
            color: 'default',
            text: `${exchangeLabel}: no trading hours stored; live status unavailable.`,
        };
    }

    const tradingWindow = parseStockTradingWindow(tradingTimes);
    const viennaWindow = tradingWindow
        ? stockExchangeViennaWindow(tradingWindow, settings, referenceDate)
        : null;

    if (!tradingWindow || !viennaWindow) {
        return {
            isActive: false,
            sessionLabel: null,
            sessionWindow: null,
            scheduleStatus: 'Waiting',
            scheduleColor: 'default',
            status: 'Unavailable',
            color: 'default',
            text: `${exchangeLabel}: stored trading hours ${tradingTimes}.`,
        };
    }

    const activityTimezone = settings?.trading_start_time && settings?.trading_end_time
        ? displayTimeZone
        : tradingWindow.timezone;
    const localReferenceDate = exchangeLocalDateParts(activityTimezone, referenceDate);
    const isWeekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'].includes(localReferenceDate?.weekday);
    const isWithinTradingWindow = isWeekday
        && referenceDate >= viennaWindow.refreshStartsAt
        && referenceDate < viennaWindow.refreshEndsAt;
    const isActive = settings?.closed_refresh_enabled === true || isWithinTradingWindow;
    const tradingSession = indexTradingSessionDisplay(
        tradingWindow,
        settings,
        referenceDate,
        isWithinTradingWindow ? viennaWindow : null,
    );

    return {
        isActive,
        sessionLabel: tradingSession?.label ?? null,
        sessionWindow: tradingSession?.window ?? null,
        scheduleStatus: isActive ? 'Scheduled' : 'Waiting',
        scheduleColor: isActive ? 'success' : 'default',
        status: isActive ? 'Active' : 'Inactive',
        color: isActive ? 'success' : 'error',
        text: `${exchangeLabel} (${tradingWindow.timezone}): trading ${viennaWindow.trading} Europe/Vienna`
            + ` → refresh ${viennaWindow.refresh} Europe/Vienna.`,
    };
}

function stockHoldingRefreshSchedule(holding) {
    return stockHoldingRefreshSchedules.value.get(holding.id) ?? {
        isActive: false,
        scheduleStatus: 'Waiting',
        scheduleColor: 'default',
        status: 'Unavailable',
        color: 'default',
        text: 'Trading hours unavailable.',
    };
}

function indexWatchItemRefreshSchedule(indexItem) {
    return indexWatchItemRefreshSchedules.value.get(indexItem.id) ?? {
        isActive: false,
        sessionLabel: null,
        sessionWindow: null,
        scheduleStatus: 'Waiting',
        scheduleColor: 'default',
        status: 'Unavailable',
        color: 'default',
    };
}

function stockHoldingExchangeLabel(holding) {
    const exchange = String(holding?.exchange || holding?.mic_code || 'Unknown exchange').trim();
    const venue = String(holding?.venue ?? '').trim();

    return venue && venue.toLocaleLowerCase() !== exchange.toLocaleLowerCase()
        ? `${exchange} (${venue})`
        : exchange;
}

function stockHoldingTradingTimes(holding) {
    const tradingTimes = String(holding?.trading_times ?? '').trim();

    if (tradingTimes) {
        return tradingTimes;
    }

    const exchange = String(holding?.exchange ?? '').trim().toUpperCase();
    const micCode = String(holding?.mic_code ?? '').trim().toUpperCase();
    const usExchangeCodes = ['US', 'NASDAQ', 'NYSE', 'AMEX'];
    const usMicCodes = ['XNAS', 'XNYS', 'XASE', 'ARCX', 'BATS'];

    return usExchangeCodes.includes(exchange) || usMicCodes.includes(micCode)
        ? 'Monday-Friday 09:30-16:00 America/New_York'
        : null;
}

function parseStockTradingWindow(tradingTimes) {
    const match = String(tradingTimes).match(
        /^(?<days>.*?)\s+(?<open>\d{1,2}:\d{2})(?::\d{2})?\s*(?:-|to|until|bis)\s*(?<close>\d{1,2}:\d{2})(?::\d{2})?\s+(?<timezone>UTC|[A-Za-z_]+(?:\/[A-Za-z0-9_+-]+)+)\s*$/i,
    );

    return match?.groups
        ? {
            days: match.groups.days,
            open: match.groups.open,
            close: match.groups.close,
            timezone: match.groups.timezone,
        }
        : null;
}

function stockExchangeViennaWindow(tradingWindow, settings, referenceDate) {
    const tradingStartsAt = dateFromTimeZoneClock(tradingWindow.open, tradingWindow.timezone, referenceDate);
    const tradingEndsAt = dateFromTimeZoneClock(tradingWindow.close, tradingWindow.timezone, referenceDate);

    if (!tradingStartsAt || !tradingEndsAt) {
        return null;
    }

    if (settings?.trading_start_time && settings?.trading_end_time) {
        const refreshStartsAt = dateFromTimeZoneClock(
            settings.trading_start_time,
            displayTimeZone,
            referenceDate,
        );
        const refreshEndsAt = dateFromTimeZoneClock(
            settings.trading_end_time,
            displayTimeZone,
            referenceDate,
        );

        return refreshStartsAt && refreshEndsAt
            ? {
                trading: formatStockViennaWindow(tradingStartsAt, tradingEndsAt, tradingStartsAt),
                refresh: formatStockViennaWindow(refreshStartsAt, refreshEndsAt, refreshStartsAt),
                tradingStartsAt,
                tradingEndsAt,
                refreshStartsAt,
                refreshEndsAt,
            }
            : null;
    }

    const startsBeforeMinutes = Number(settings?.trading_starts_before_minutes ?? 0);
    const endsAfterMinutes = Number(settings?.trading_ends_after_minutes ?? 0);
    const refreshStartsAt = new Date(tradingStartsAt.getTime() - (startsBeforeMinutes * 60 * 1000));
    const refreshEndsAt = new Date(tradingEndsAt.getTime() + (endsAfterMinutes * 60 * 1000));

    return {
        trading: formatStockViennaWindow(tradingStartsAt, tradingEndsAt, tradingStartsAt),
        refresh: formatStockViennaWindow(refreshStartsAt, refreshEndsAt, tradingStartsAt),
        tradingStartsAt,
        tradingEndsAt,
        refreshStartsAt,
        refreshEndsAt,
    };
}

function indexTradingSessionDisplay(tradingWindow, settings, referenceDate, currentViennaWindow) {
    const sessionWindow = currentViennaWindow
        ?? nextStockExchangeViennaWindow(tradingWindow, settings, referenceDate);

    if (!sessionWindow) {
        return null;
    }

    if (currentViennaWindow) {
        return {
            label: 'Current',
            window: sessionWindow.trading,
        };
    }

    const referenceDateKey = localDateKey(referenceDate, displayTimeZone);
    const sessionDateKey = localDateKey(sessionWindow.tradingStartsAt, displayTimeZone);
    const sessionDateLabel = sessionDateKey === referenceDateKey
        ? 'today'
        : formatViennaDateTime(sessionWindow.tradingStartsAt, {
            day: '2-digit',
            month: '2-digit',
        });

    return {
        label: `Next ${sessionDateLabel}`,
        window: sessionWindow.trading,
    };
}

function nextStockExchangeViennaWindow(tradingWindow, settings, referenceDate) {
    const millisecondsPerDay = 24 * 60 * 60 * 1000;

    for (let dayOffset = 0; dayOffset <= 7; dayOffset += 1) {
        const candidateReferenceDate = new Date(referenceDate.getTime() + (dayOffset * millisecondsPerDay));
        const candidateLocalDate = exchangeLocalDateParts(tradingWindow.timezone, candidateReferenceDate);
        const isWeekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'].includes(candidateLocalDate?.weekday);

        if (!isWeekday) {
            continue;
        }

        const candidateWindow = stockExchangeViennaWindow(tradingWindow, settings, candidateReferenceDate);

        if (candidateWindow && candidateWindow.refreshStartsAt > referenceDate) {
            return candidateWindow;
        }
    }

    return null;
}

function dateFromTimeZoneClock(time, timezone, referenceDate) {
    const sourceDate = exchangeLocalDateParts(timezone, referenceDate);
    const [hours, minutes] = String(time).split(':').map(Number);

    if (!sourceDate || !Number.isFinite(hours) || !Number.isFinite(minutes)) {
        return null;
    }

    const intendedTimestamp = Date.UTC(
        sourceDate.year,
        sourceDate.month - 1,
        sourceDate.day,
        hours,
        minutes,
    );
    let resolvedTimestamp = intendedTimestamp;

    for (let attempt = 0; attempt < 3; attempt += 1) {
        const observedDate = exchangeLocalDateParts(timezone, new Date(resolvedTimestamp));

        if (!observedDate) {
            return null;
        }

        const observedTimestamp = Date.UTC(
            observedDate.year,
            observedDate.month - 1,
            observedDate.day,
            observedDate.hour,
            observedDate.minute,
        );
        const adjustment = intendedTimestamp - observedTimestamp;
        resolvedTimestamp += adjustment;

        if (adjustment === 0) {
            break;
        }
    }

    return new Date(resolvedTimestamp);
}

function formatStockViennaWindow(startsAt, endsAt, tradingStartsAt) {
    const startTime = formatStockViennaTime(startsAt, tradingStartsAt);
    const endTime = formatStockViennaTime(endsAt, tradingStartsAt);

    return `${startTime}–${endTime}`;
}

function formatStockViennaTime(date, tradingStartsAt) {
    const time = formatViennaDateTime(date, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
    const tradingDateKey = localDateKey(tradingStartsAt, displayTimeZone);
    const dateKey = localDateKey(date, displayTimeZone);
    const dayOffset = stockCalendarDayDifference(tradingDateKey, dateKey);
    const dayLabel = dayOffset < 0 ? ' previous day' : (dayOffset > 0 ? ' next day' : '');

    return `${time}${dayLabel}`;
}

function stockCalendarDayDifference(fromDateKey, toDateKey) {
    const fromTimestamp = Date.parse(`${fromDateKey}T00:00:00Z`);
    const toTimestamp = Date.parse(`${toDateKey}T00:00:00Z`);

    return Math.round((toTimestamp - fromTimestamp) / (24 * 60 * 60 * 1000));
}

function isLiveUpdateActive(settings) {
    if (!settings || ['inactive', 'paused'].includes(settings.status)) {
        return false;
    }

    return settings.status === 'updating'
        || settings.is_trading_time === true
        || settings.closed_refresh_enabled === true;
}

function stockLiveUpdateStatusDetail(settings) {
    if (settings?.status === 'updating') {
        return 'The live refresh job is running.';
    }

    return '';
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
    indexMessage.value = '';
    indexError.value = '';
    priceRefreshScheduleMessage.value = '';
    priceRefreshScheduleError.value = '';
    indexDataSyncMessage.value = '';
    indexHistoricalDataSyncMessage.value = '';
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

function emptyHoldingForm() {
    return {
        symbol: '',
        name: '',
        subtitle: '',
        isin: '',
        wkn: '',
        exchange: '',
        mic_code: '',
        instrument_type: '',
        country: '',
        currency: '',
    };
}

function emptyCashTransactionForm() {
    return {
        type: 'deposit',
        stock_holding_id: null,
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
        trading_start_time: null,
        trading_end_time: null,
        closed_refresh_enabled: true,
        closed_interval_minutes: 60,
    };
}

function emptyIndexEodhdSyncScheduleForm() {
    return {
        times: ['02:00'],
    };
}

function emptyIndexV2RealtimeScheduleForm() {
    return {
        trading_interval_minutes: 20,
        trading_starts_before_minutes: 0,
        trading_ends_after_minutes: 0,
        closed_refresh_enabled: false,
        closed_interval_minutes: 60,
    };
}

function indexV2RealtimeScheduleFormFromSettings(settings) {
    return {
        ...emptyIndexV2RealtimeScheduleForm(),
        ...settings,
    };
}

function indexEodhdSyncScheduleFormFromSettings(settings) {
    return {
        times: Array.isArray(settings?.times) && settings.times.length > 0
            ? [...settings.times]
            : ['02:00'],
    };
}

function priceRefreshScheduleFormFromSettings(settings) {
    return {
        trading_interval_minutes: settings?.trading_interval_minutes ?? 20,
        trading_starts_before_minutes: settings?.trading_starts_before_minutes ?? 0,
        trading_ends_after_minutes: settings?.trading_ends_after_minutes ?? 0,
        trading_start_time: settings?.trading_start_time ?? null,
        trading_end_time: settings?.trading_end_time ?? null,
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
                    <form action="/admin/logout" method="POST">
                        <input type="hidden" name="_token" :value="csrfToken()">
                        <v-btn prepend-icon="mdi-logout" size="small" type="submit" variant="text">
                            Logout
                        </v-btn>
                    </form>
                </div>
            </v-app-bar>

            <v-main>
                <v-container class="py-8" :fluid="lgAndDown">
                    <section v-if="['dashboard', 'indices', 'stocks'].includes(activeSection)">
                        <div v-if="activeSection === 'dashboard'" class="dashboard-version-page" aria-label="Dashboard">
                            <v-card
                                class="dashboard-version-card"
                                flat
                                border
                                rounded="xl"
                                aria-label="Programmversion"
                            >
                                <v-card-text class="pa-0">
                                    <div class="dashboard-version-heading">
                                        <div>
                                            <h1 class="dashboard-version-title">Aktuelle Version</h1>
                                            <div class="dashboard-current-version" data-testid="app-version">
                                                v{{ dashboardCurrentVersion }}
                                            </div>
                                        </div>
                                        <div class="dashboard-version-actions">
                                            <v-btn
                                                class="dashboard-reload-button"
                                                size="small"
                                                variant="outlined"
                                                color="primary"
                                                prepend-icon="mdi-refresh"
                                                aria-label="Reload dashboard data"
                                                :disabled="isDashboardReloading"
                                                :loading="isDashboardReloading"
                                                @click="reloadDashboard"
                                            >
                                                Reload
                                            </v-btn>
                                            <v-btn
                                                class="dashboard-version-toggle"
                                                size="small"
                                                variant="text"
                                                color="primary"
                                                :append-icon="isDashboardVersionDetailsVisible ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                                                :aria-expanded="isDashboardVersionDetailsVisible"
                                                aria-controls="dashboard-version-details"
                                                @click="isDashboardVersionDetailsVisible = !isDashboardVersionDetailsVisible"
                                            >
                                                {{ isDashboardVersionDetailsVisible ? 'Weniger anzeigen' : 'Mehr anzeigen' }}
                                            </v-btn>
                                        </div>
                                    </div>

                                    <v-progress-linear
                                        v-if="dashboardVersionsLoading"
                                        class="mt-5"
                                        color="primary"
                                        indeterminate
                                    />

                                    <v-alert
                                        v-if="dashboardVersionsError"
                                        class="mt-5"
                                        density="compact"
                                        type="error"
                                        variant="tonal"
                                    >
                                        {{ dashboardVersionsError }}
                                    </v-alert>

                                    <div
                                        v-show="isDashboardVersionDetailsVisible"
                                        id="dashboard-version-details"
                                        class="dashboard-version-details"
                                    >
                                        <div class="dashboard-version-grid">
                                            <div
                                                v-for="item in installedVersionItems"
                                                :key="item.key"
                                                class="dashboard-version-item"
                                            >
                                                <span>{{ item.label }}</span>
                                                <strong :title="item.version">{{ item.version }}</strong>
                                            </div>
                                        </div>
                                    </div>
                                </v-card-text>
                            </v-card>

                            <div class="dashboard-performance-section" aria-label="Depotentwicklung der aktuellen Woche von Montag bis Freitag">
                                <div class="dashboard-performance-heading">
                                    <div>
                                        <p class="text-overline text-primary mb-1">Depotentwicklung</p>
                                        <h1 class="dashboard-performance-title">Aktuelle Woche</h1>
                                    </div>
                                    <v-progress-circular
                                        v-if="dashboardDailyPerformanceLoading"
                                        color="primary"
                                        indeterminate
                                        size="22"
                                        width="2"
                                    />
                                </div>

                                <v-alert
                                    v-if="dashboardDailyPerformanceError"
                                    class="mb-3"
                                    density="compact"
                                    type="error"
                                    variant="tonal"
                                >
                                    {{ dashboardDailyPerformanceError }}
                                </v-alert>

                                <div class="dashboard-performance-grid">
                                    <v-card
                                        v-for="card in dashboardPerformanceCards"
                                        :key="card.key"
                                        class="dashboard-performance-card dashboard-performance-day-card"
                                        flat
                                        border
                                        rounded="xl"
                                    >
                                        <v-card-text class="pa-4">
                                            <div class="dashboard-performance-card-heading">
                                                <div>
                                                    <div class="dashboard-performance-card-label">{{ card.label }}</div>
                                                    <div class="dashboard-performance-card-date">
                                                        {{ card.performance ? formatIndexHistoryDate(card.performance.date) : 'Keine Daten' }}
                                                    </div>
                                                </div>
                                                <v-icon
                                                    :class="dashboardPerformanceValueClass(card.performance)"
                                                    :icon="dashboardPerformanceIcon(card.performance)"
                                                    size="20"
                                                />
                                            </div>
                                            <div
                                                class="dashboard-performance-amount"
                                                :class="dashboardPerformanceValueClass(card.performance)"
                                            >
                                                {{ dashboardPerformanceAmount(card.performance) }} <DataSourceMarkers :markers="marketDataMarkers(card.performance)" />
                                            </div>
                                            <div
                                                class="dashboard-performance-percent"
                                                :class="dashboardPerformanceValueClass(card.performance)"
                                            >
                                                {{ dashboardPerformancePercent(card.performance) }}
                                            </div>
                                        </v-card-text>
                                    </v-card>
                                </div>

                                <div
                                    class="dashboard-performance-sum-grid"
                                    aria-label="Performance sums for current and previous week and month, plus year"
                                >
                                    <v-card
                                        v-for="card in dashboardPerformanceSumCards"
                                        :key="card.key"
                                        class="dashboard-performance-card dashboard-performance-sum-card"
                                        flat
                                        border
                                        rounded="xl"
                                    >
                                        <v-card-text class="pa-4">
                                            <div class="dashboard-performance-card-heading">
                                                <div class="dashboard-performance-card-label">{{ card.label }}</div>
                                                <v-icon
                                                    :class="dashboardPerformanceValueClass(card.performance)"
                                                    :icon="dashboardPerformanceIcon(card.performance)"
                                                    size="20"
                                                />
                                            </div>
                                            <div
                                                class="dashboard-performance-amount"
                                                :class="dashboardPerformanceValueClass(card.performance)"
                                            >
                                                {{ dashboardPerformanceAmount(card.performance) }} <DataSourceMarkers :markers="marketDataMarkers(card.performance)" />
                                            </div>
                                            <div
                                                class="dashboard-performance-percent"
                                                :class="dashboardPerformanceValueClass(card.performance)"
                                            >
                                                {{ dashboardPerformancePercent(card.performance) }}
                                            </div>
                                        </v-card-text>
                                    </v-card>
                                </div>
                            </div>
                        </div>

                        <div v-if="activeSection === 'stocks'" aria-label="Stocks dashboard">
                            <v-alert class="mb-4" type="info" variant="tonal" density="compact">
                                Intraday: Deutsche Börse ca. 15 Minuten verzögert, beobachtete Trades mit Lücken;
                                Yahoo Finance USA: echte 5-Minuten-OHLC, inoffiziell, Verzögerung unbekannt.
                                Keine vollständige historische Nachlieferung. Bestehendes Abrufintervall bleibt einstellbar.
                            </v-alert>
                            <div class="dashboard-heading mb-6">
                                <div>
                                    <p class="text-overline text-primary mb-1">Dashboard</p>
                                    <h1 class="text-h4">Stocks</h1>
                                    <p class="text-body-2 text-medium-emphasis mt-2">
                                        View and manage all saved stocks.
                                    </p>
                                </div>
                                <div class="dashboard-actions">
                                    <v-btn
                                        class="dashboard-action-button"
                                        color="secondary"
                                        prepend-icon="mdi-cloud-sync-outline"
                                        type="button" title="EODHD is disabled; no paid provider requests are made."
                                        variant="outlined"
                                        disabled
                                        :loading="isStockEodhdSyncRunning"
                                        @click="startStockEodhdSync"
                                    >
                                        EODHD Sync
                                    </v-btn>
                                    <v-btn
                                        class="dashboard-action-button"
                                        color="primary"
                                        prepend-icon="mdi-plus"
                                        type="button"
                                        variant="flat"
                                        @click="openHoldingDialog"
                                    >
                                        Add stock
                                    </v-btn>
                                    <v-btn
                                        class="dashboard-action-button"
                                        color="error"
                                        prepend-icon="mdi-delete-outline"
                                        type="button"
                                        variant="outlined"
                                        :disabled="!selectedStockWatchItem || hasPositionPieces(selectedStockWatchItem) || holdingsLoading"
                                        @click="openDeleteHoldingDialog(selectedStockWatchItem)"
                                    >
                                        Delete stock
                                    </v-btn>
                                </div>
                            </div>

                            <v-alert
                                v-if="stockEodhdSyncError"
                                class="mb-6"
                                density="compact"
                                type="error"
                                variant="tonal"
                            >
                                {{ stockEodhdSyncError }}
                            </v-alert>

                            <v-card
                                v-if="stockEodhdSyncVisible"
                                class="stock-eodhd-sync-card mb-6"
                                variant="outlined"
                                aria-label="Stock EODHD synchronization status"
                            >
                                <v-card-title class="d-flex flex-wrap align-center justify-space-between ga-2">
                                    <span>Stock EODHD synchronization</span>
                                    <div class="d-flex align-center ga-2">
                                        <v-chip
                                            :color="indexEodhdSyncStatusColor(stockEodhdSync.status)"
                                            size="small"
                                            variant="tonal"
                                        >
                                            {{ indexEodhdSyncStatusLabel(stockEodhdSync.status) }}
                                        </v-chip>
                                        <v-btn
                                            v-if="!isStockEodhdSyncRunning"
                                            aria-label="Close stock EODHD synchronization result"
                                            icon="mdi-close"
                                            size="small"
                                            title="Close result"
                                            variant="text"
                                            @click="closeStockEodhdSyncResult"
                                        />
                                    </div>
                                </v-card-title>
                                <v-card-text>
                                    <div class="text-body-2 text-medium-emphasis mb-3">
                                        Stored-data window: {{ formatIndexHistoryDate(stockEodhdSync.date_from) }} –
                                        {{ formatIndexHistoryDate(stockEodhdSync.date_to) }}
                                    </div>
                                    <v-progress-linear
                                        v-if="isStockEodhdSyncRunning"
                                        class="mb-4"
                                        color="primary"
                                        height="7"
                                        :model-value="stockEodhdSyncProgress.percent"
                                        rounded
                                    />
                                    <div v-if="stockEodhdSyncProgress.total > 0" class="text-body-2 mb-3">
                                        <strong>
                                            {{ stockEodhdSyncProgress.completed }} / {{ stockEodhdSyncProgress.total }} processed
                                        </strong>
                                    </div>
                                    <div v-if="stockEodhdSync.current" class="text-body-2 mb-3">
                                        Currently running: {{ stockEodhdSync.current }}
                                    </div>
                                    <v-list density="compact">
                                        <v-list-item
                                            v-for="step in stockEodhdSync.steps"
                                            :key="step.key"
                                            :aria-label="`${step.label}: ${indexEodhdSyncChecklistStatusLabel(step.status)}`"
                                        >
                                            <template #prepend>
                                                <v-icon
                                                    :class="{ 'mdi-spin': step.status === 'running' }"
                                                    :color="indexEodhdSyncStepColor(step.status)"
                                                    :icon="indexEodhdSyncStepIcon(step.status)"
                                                />
                                            </template>
                                            <v-list-item-title>{{ step.label }}</v-list-item-title>
                                            <v-list-item-subtitle>{{ step.message }}</v-list-item-subtitle>
                                        </v-list-item>
                                    </v-list>
                                    <div v-if="!isStockEodhdSyncRunning" class="d-flex flex-wrap ga-2 mt-4">
                                        <v-chip color="primary" variant="tonal">
                                            EOD: {{ stockEodhdSync.eod?.stored_count ?? 0 }} records
                                        </v-chip>
                                        <v-chip color="secondary" variant="tonal">
                                            Intraday: {{ stockEodhdSync.intraday?.stored_count ?? 0 }} candles
                                        </v-chip>
                                        <v-chip
                                            v-if="(stockEodhdSync.eod?.failed_count ?? 0) + (stockEodhdSync.intraday?.failed_count ?? 0) > 0"
                                            color="warning"
                                            variant="tonal"
                                        >
                                            {{ (stockEodhdSync.eod?.failed_count ?? 0) + (stockEodhdSync.intraday?.failed_count ?? 0) }} failures
                                        </v-chip>
                                    </div>
                                    <v-alert
                                        v-if="stockEodhdSync.error"
                                        class="mt-4"
                                        density="compact"
                                        type="warning"
                                        variant="tonal"
                                    >
                                        {{ stockEodhdSync.error }}
                                    </v-alert>
                                </v-card-text>
                            </v-card>

                            <v-card class="mb-6" variant="outlined" aria-label="Automatic free stock updates">
                                <v-card-text>
                                    <div
                                        class="index-update-schedule-row d-flex flex-wrap align-center justify-space-between ga-3"
                                        aria-label="Stock realtime schedule"
                                    >
                                        <div class="flex-grow-1">
                                            <div class="text-body-2 font-weight-bold">Automatic free updates</div>
                                            <div class="text-body-2">
                                                {{ formatLiveDataUpdateSchedule(priceRefreshSettings) }}
                                            </div>
                                            <div
                                                v-if="stockExchangeRefreshSchedules.length"
                                                class="stock-refresh-exchange-windows text-caption text-medium-emphasis"
                                            >
                                                <div
                                                    v-for="schedule in stockExchangeRefreshSchedules"
                                                    :key="schedule.key"
                                                >
                                                    {{ schedule.text }}
                                                </div>
                                            </div>
                                            <div
                                                v-if="stockLiveUpdateStatusDetail(priceRefreshSettings)"
                                                class="text-caption text-medium-emphasis"
                                            >
                                                {{ stockLiveUpdateStatusDetail(priceRefreshSettings) }}
                                            </div>
                                            <div class="text-caption text-medium-emphasis">
                                                Latest {{ priceRefreshSettings?.last_refreshed_at
                                                    ? formatDateTime(priceRefreshSettings.last_refreshed_at)
                                                    : 'never' }}
                                                · Next {{ priceRefreshSettings?.next_refresh_at
                                                    ? formatDateTime(priceRefreshSettings.next_refresh_at)
                                                    : '-' }}
                                                · Europe/Vienna
                                            </div>
                                        </div>
                                        <v-btn
                                            prepend-icon="mdi-timer-edit-outline"
                                            size="small"
                                            type="button"
                                            variant="text"
                                            @click="openLiveDataUpdateDialog"
                                        >
                                            Edit live period
                                        </v-btn>
                                    </div>
                                </v-card-text>
                            </v-card>

                            <v-dialog v-model="isLiveDataUpdateDialogOpen" persistent max-width="620">
                                <v-card class="stock-live-schedule-dialog">
                                    <v-card-title>Edit live/realtime update period</v-card-title>
                                    <v-card-subtitle>
                                        Refreshes each stock from free sources around its stored trading hours.
                                    </v-card-subtitle>
                                    <v-form @submit.prevent="saveLiveDataUpdateSchedule">
                                        <v-card-text>
                                            <v-alert
                                                v-if="priceRefreshScheduleError"
                                                class="mb-4"
                                                density="compact"
                                                type="error"
                                                variant="tonal"
                                            >
                                                {{ priceRefreshScheduleError }}
                                            </v-alert>
                                            <v-row density="compact">
                                                <v-col cols="12" sm="4">
                                                    <v-text-field
                                                        v-model="liveDataUpdateScheduleForm.trading_interval_minutes"
                                                        label="Every (minutes)"
                                                        min="1"
                                                        max="1440"
                                                        required
                                                        type="number"
                                                    />
                                                </v-col>
                                                <v-col cols="12" sm="4">
                                                    <v-text-field
                                                        v-model="liveDataUpdateScheduleForm.trading_starts_before_minutes"
                                                        label="Start before (minutes)"
                                                        min="0"
                                                        max="720"
                                                        required
                                                        type="number"
                                                    />
                                                </v-col>
                                                <v-col cols="12" sm="4">
                                                    <v-text-field
                                                        v-model="liveDataUpdateScheduleForm.trading_ends_after_minutes"
                                                        label="End after (minutes)"
                                                        min="0"
                                                        max="720"
                                                        required
                                                        type="number"
                                                    />
                                                </v-col>
                                            </v-row>
                                            <v-switch
                                                v-model="liveDataUpdateScheduleForm.closed_refresh_enabled"
                                                color="primary"
                                                density="compact"
                                                hide-details
                                                label="Also refresh while markets are closed"
                                            />
                                            <v-text-field
                                                v-if="liveDataUpdateScheduleForm.closed_refresh_enabled"
                                                v-model="liveDataUpdateScheduleForm.closed_interval_minutes"
                                                class="mt-3"
                                                label="Closed-market interval (minutes)"
                                                min="1"
                                                max="1440"
                                                required
                                                type="number"
                                            />
                                        </v-card-text>
                                        <v-card-actions>
                                            <v-spacer />
                                            <v-btn
                                                type="button"
                                                variant="text"
                                                :disabled="isLiveDataUpdateScheduleSaving"
                                                @click="isLiveDataUpdateDialogOpen = false"
                                            >
                                                Cancel
                                            </v-btn>
                                            <v-btn
                                                color="primary"
                                                type="submit"
                                                variant="flat"
                                                :loading="isLiveDataUpdateScheduleSaving"
                                            >
                                                Save live schedule
                                            </v-btn>
                                        </v-card-actions>
                                    </v-form>
                                </v-card>
                            </v-dialog>
                        </div>

                        <div v-if="activeSection === 'indices'" aria-label="Indices">
                            <div class="dashboard-heading mb-6">
                                <div>
                                    <p class="text-overline text-primary mb-1">Dashboard</p>
                                    <h1 class="text-h4">Indices</h1>
                                    <p class="text-body-2 text-medium-emphasis mt-2">
                                        Alle gespeicherten Indizes verwalten und ihre Kursdaten öffnen.
                                    </p>
                                </div>
                                <div class="dashboard-actions">
                                    <v-btn
                                        class="dashboard-action-button"
                                        color="secondary"
                                        prepend-icon="mdi-refresh"
                                        variant="outlined"
                                        @click="refreshPrivateIndexQuotes"
                                    >
                                        Kurse aktualisieren
                                    </v-btn>
                                    <v-btn
                                        class="dashboard-action-button"
                                        color="primary"
                                        prepend-icon="mdi-plus"
                                        variant="flat"
                                        @click="openIndexDialog"
                                    >
                                        Index hinzufügen
                                    </v-btn>
                                    <v-btn
                                        class="dashboard-action-button"
                                        color="error"
                                        prepend-icon="mdi-delete-outline"
                                        variant="outlined"
                                        :disabled="!selectedIndexWatchItem || holdingsLoading"
                                        @click="openDeleteIndexDialog"
                                    >
                                        Index löschen
                                    </v-btn>
                                </div>
                            </div>




                            <v-alert v-if="indexMessage" type="success" variant="tonal" density="compact" class="mb-4">
                                {{ indexMessage }}
                            </v-alert>
                            <v-alert v-if="indexError || holdingsError" type="error" variant="tonal" density="compact" class="mb-4">
                                {{ indexError || holdingsError }}
                            </v-alert>

                            <v-alert
                                v-if="!holdingsLoading && indexWatchItems.length === 0"
                                class="mb-4"
                                type="info"
                                variant="tonal"
                            >
                                Noch keine Indizes gespeichert.
                            </v-alert>

                            <div class="index-watch-strip mb-4">
                                <div
                                    v-for="indexItem in sortedIndexWatchItems"
                                    :key="indexItem.id"
                                    class="index-watch-item"
                                >
                                    <button
                                        type="button"
                                        class="index-watch-card index-watch-card--market-status"
                                        :class="{ 'index-watch-card--active': selectedIndexWatchItem?.id === indexItem.id }"
                                        :aria-pressed="selectedIndexWatchItem?.id === indexItem.id"
                                        @click="selectIndexWatchItem(indexItem)"
                                    >
                                        <span class="index-watch-card-header">
                                            <span class="index-watch-card-symbol">{{ indexItem.symbol }}</span>
                                            <span class="index-watch-card-country">{{ indexItem.country || '-' }}</span>
                                        </span>
                                        <span class="index-watch-card-label">{{ indexItem.name || 'Index' }} <DataSourceMarkers :markers="marketDataMarkers(indexItem)" /></span>
                                        <span v-if="indexItem.instrument_type === 'ETF'" class="index-watch-card-session">ETF</span>
                                        <span class="index-watch-card-price" :class="indexChangeClass(indexItem)">
                                            <span v-if="formatIndexChangePercent(indexItem)">
                                                {{ formatIndexChangePercent(indexItem) }}
                                            </span>
                                            <span>{{ formatIndexPrice(indexItem) }} <DataSourceMarkers :markers="marketDataMarkers(indexItem, 'latest_price')" /></span>
                                        </span>
                                        <span v-if="indexItem.latest_price_as_of" class="index-watch-card-session">
                                            {{ indexItem.private_quote ? 'Yahoo Finance · möglicherweise verzögert' : 'Gespeicherter Kurs' }}<br>
                                            Kurszeit {{ formatDateTime(indexItem.latest_price_as_of) }}
                                            <template v-if="indexItem.latest_price_fetched_at"><br>Abgerufen {{ formatDateTime(indexItem.latest_price_fetched_at) }}</template>
                                        </span>
                                        <span
                                            v-if="indexWatchItemRefreshSchedule(indexItem).sessionWindow"
                                            class="index-watch-card-session"
                                            :aria-label="`${indexItem.symbol || 'Index'} trading time in Europe/Vienna`"
                                        >
                                            <span>{{ indexWatchItemRefreshSchedule(indexItem).sessionLabel }}</span>
                                            <span>{{ indexWatchItemRefreshSchedule(indexItem).sessionWindow }}</span>
                                        </span>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <v-alert
                            v-if="activeSection === 'stocks' && visibleHoldingMessage"
                            type="success"
                            variant="tonal"
                            density="compact"
                            class="mb-4"
                        >
                            {{ visibleHoldingMessage }}
                        </v-alert>
                        <v-alert
                            v-if="activeSection === 'stocks' && (holdingError || holdingsError)"
                            type="error"
                            variant="tonal"
                            density="compact"
                            class="mb-4"
                        >
                            {{ holdingError || holdingsError }}
                        </v-alert>
                        <section v-if="activeSection === 'stocks'" class="watch-list-section mb-4" aria-label="Stocks">
                            <div class="watch-list-section-header">
                                <div>
                                    <div class="watch-list-section-eyebrow">Watch-list</div>
                                    <div class="watch-list-section-title-row">
                                        <h2 class="watch-list-section-title">Stocks</h2>
                                    </div>
                                </div>
                                <span class="watch-list-section-count">
                                    {{ formatInteger(holdingsPagination.total || holdings.length) }}
                                </span>
                            </div>

                            <div class="mobile-watch-list">
                                <div v-if="!holdingsLoading && holdings.length === 0" class="mobile-stock-card">
                                    No stocks in the watch-list.
                                </div>
                                <template v-for="holding in holdings" :key="`mobile-holding-${holding.id}`">
                                    <article
                                        class="mobile-stock-card"
                                        :class="{
                                            'mobile-stock-card--selected': activeSection === 'stocks'
                                                && selectedStockWatchItem?.id === holding.id,
                                        }"
                                        :aria-selected="activeSection === 'stocks'
                                            ? selectedStockWatchItem?.id === holding.id
                                            : undefined"
                                        :tabindex="activeSection === 'stocks' ? 0 : undefined"
                                        @click="activeSection === 'stocks' && toggleStockWatchItemSelection(holding)"
                                        @keydown.enter.prevent="activeSection === 'stocks' && toggleStockWatchItemSelection(holding)"
                                        @keydown.space.prevent="activeSection === 'stocks' && toggleStockWatchItemSelection(holding)"
                                    >
                                    <div class="mobile-stock-name">
                                        {{ stockDisplayName(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding)" />
                                    </div>
                                    <div v-if="holding.subtitle" class="stock-subtitle text-caption text-info font-weight-medium">
                                        {{ holding.subtitle }}
                                    </div>
                                    <div class="mobile-stock-position">
                                        Pieces: {{ formatPositionPieces(holding) }}
                                    </div>
                                    <div
                                        v-if="activeSection === 'stocks'"
                                        class="stock-holding-refresh-statuses d-flex flex-wrap align-center ga-1"
                                    >
                                        <v-chip
                                            :aria-label="`${holding.symbol || 'Stock'} live update schedule status`"
                                            :color="stockHoldingRefreshSchedule(holding).scheduleColor"
                                            size="x-small"
                                            variant="tonal"
                                        >
                                            {{ stockHoldingRefreshSchedule(holding).scheduleStatus }}
                                        </v-chip>
                                        <v-chip
                                            :aria-label="`${holding.symbol || 'Stock'} live update activity status`"
                                            :color="stockHoldingRefreshSchedule(holding).color"
                                            size="x-small"
                                            variant="tonal"
                                        >
                                            <span class="live-update-status-dot" aria-hidden="true" />
                                            {{ stockHoldingRefreshSchedule(holding).status }}
                                        </v-chip>
                                    </div>
                                    <div class="mobile-stock-price-row">
                                        <span
                                            class="latest-price-value mobile-stock-price"
                                            :class="mobileHoldingPriceClass(holding)"
                                        >
                                            <span class="mobile-stock-price-main">
                                                <span>{{ formatMobileHoldingPrice(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'latest_price')" /></span>
                                                <span
                                                    v-if="formatMobileHoldingPriceLoadedAt(holding)"
                                                    class="mobile-price-loaded-at"
                                                >
                                                    {{ formatMobileHoldingPriceLoadedAt(holding) }}
                                                </span>
                                            </span>
                                            <span
                                                v-if="mobileHoldingPriceChangeText(holding)"
                                                class="mobile-stock-price-change"
                                            >
                                                {{ mobileHoldingPriceChangeText(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'end_price_24')" />
                                            </span>
                                        </span>
                                    </div>
                                    <HoldingPriceReload :holding="holding" />
                                    <div v-if="activeSection === 'stocks'" class="mobile-stock-actions">
                                        <v-btn
                                            aria-label="Edit stock"
                                            color="primary"
                                            icon="mdi-pencil-outline"
                                            size="small"
                                            variant="tonal"
                                            :disabled="holdingsLoading"
                                            @click.stop="openEditHoldingDialog(holding)"
                                        />
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
                                    </div>
                                    </article>
                                    <div
                                        v-show="activeSection === 'stocks' && selectedStockWatchItem?.id === holding.id"
                                        :id="`mobile-stock-chart-target-${holding.id}`"
                                        class="stock-price-chart-mobile-target"
                                    />
                                </template>
                            </div>
                            <v-table class="desktop-watch-list-table">
                            <thead>
                                <tr>
                                    <th v-if="!isCompactWatchListTable" class="watch-list-content-cell">Symbol</th>
                                    <th class="watch-list-content-cell">Name</th>
                                    <th v-if="isHandsetLandscape" class="watch-list-content-cell">Price</th>
                                    <th v-else class="watch-list-content-cell">Latest price</th>
                                    <th class="watch-list-content-cell">
                                        <span class="d-inline-flex flex-column">
                                            <span>Start price</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.start }}
                                            </span>
                                        </span>
                                    </th>
                                    <th class="watch-list-content-cell">
                                        <span class="d-inline-flex flex-column">
                                            <span title="Last observed free price of the previous trading day; not an official close. Missing observations stay unavailable.">Last day</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.lastDay }}
                                            </span>
                                        </span>
                                    </th>
                                    <th v-if="!isCompactWatchListTable" class="watch-list-source-time-cell">Source time</th>
                                    <th v-if="activeSection === 'stocks'" class="text-right watch-list-actions-cell">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!holdingsLoading && holdings.length === 0">
                                    <td :colspan="watchListTableColumnCount">No stocks in the watch-list.</td>
                                </tr>
                                <template v-for="holding in holdings" :key="holding.id">
                                    <tr
                                        class="stock-holding-row"
                                        :class="{
                                            'stock-holding-row--selected': activeSection === 'stocks'
                                                && selectedStockWatchItem?.id === holding.id,
                                        }"
                                        :aria-expanded="activeSection === 'stocks' ? undefined : isHoldingExpanded(holding)"
                                        :aria-selected="activeSection === 'stocks'
                                            ? selectedStockWatchItem?.id === holding.id
                                            : undefined"
                                        tabindex="0"
                                        @click="handleStockHoldingRowClick(holding)"
                                        @keydown.enter.prevent="handleStockHoldingRowClick(holding)"
                                        @keydown.space.prevent="handleStockHoldingRowClick(holding)"
                                    >
                                        <td v-if="!isCompactWatchListTable" class="watch-list-content-cell">
                                            <div>{{ holding.symbol || '-' }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                Exchange: {{ holding.exchange || '-' }}
                                            </div>
                                            <div class="text-caption text-medium-emphasis">
                                                Pieces: {{ formatPositionPieces(holding) }}
                                            </div>
                                        </td>
                                        <td class="watch-list-content-cell">
                                            <div>{{ stockDisplayName(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding)" /></div>
                                            <div v-if="holding.subtitle" class="stock-subtitle text-caption text-info font-weight-medium">
                                                {{ holding.subtitle }}
                                            </div>
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
                                        <td v-if="isHandsetLandscape" class="watch-list-content-cell">
                                            <span class="handset-landscape-price">
                                                <span
                                                    v-if="holdingDayTrend(holding)"
                                                    class="recent-price-trend-dot recent-price-trend-dot--day"
                                                    :class="holdingDayTrendDotClass(holding)"
                                                    :aria-label="holdingDayTrendLabel(holding)"
                                                    :title="holdingDayTrendLabel(holding)"
                                                />
                                                <span class="latest-price-value d-inline-flex flex-column" :class="mobileHoldingPriceClass(holding)">
                                                    <span class="d-inline-flex align-center ga-1">
                                                        <span>{{ formatMobileHoldingPrice(holding) }}</span>
                                                        <span
                                                            v-if="latestPriceIsLive(holding)"
                                                            class="watch-list-live-badge latest-price-live-badge"
                                                            aria-label="Latest price live"
                                                        >
                                                            <span class="watch-list-live-dot" aria-hidden="true" />
                                                            LIVE
                                                        </span>
                                                        <span
                                                            v-if="formatMobileHoldingPriceLoadedAt(holding)"
                                                            class="mobile-price-loaded-at"
                                                        >
                                                            {{ formatMobileHoldingPriceLoadedAt(holding) }}
                                                        </span>
                                                    </span>
                                                    <span
                                                        v-if="mobileHoldingPriceChangeText(holding)"
                                                        class="latest-price-change"
                                                    >
                                                        {{ mobileHoldingPriceChangeText(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'end_price_24')" />
                                                    </span>
                                                </span>
                                            </span>
                                            <HoldingPriceReload :holding="holding" />
                                        </td>
                                        <td v-else class="watch-list-content-cell">
                                            <span class="latest-price-value d-inline-flex flex-column" :class="watchListLatestPriceClass(holding)">
                                                <span class="d-inline-flex align-center ga-1">
                                                    <span>{{ formatLatestPrice(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'latest_price')" /></span>
                                                    <span
                                                        v-if="latestPriceIsLive(holding)"
                                                        class="watch-list-live-badge latest-price-live-badge"
                                                        aria-label="Latest price live"
                                                    >
                                                        <span class="watch-list-live-dot" aria-hidden="true" />
                                                        LIVE
                                                    </span>
                                                    <span
                                                        v-if="isCompactWatchListTable && formatMobileHoldingPriceLoadedAt(holding)"
                                                        class="mobile-price-loaded-at"
                                                    >
                                                        {{ formatMobileHoldingPriceLoadedAt(holding) }}
                                                    </span>
                                                </span>
                                                <span
                                                    v-if="formatLatestPriceChangePercent(holding)"
                                                    class="latest-price-change"
                                                >
                                                    {{ formatLatestPriceChangePercent(holding) }}
                                                </span>
                                            </span>
                                            <HoldingPriceReload v-if="isCompactWatchListTable" :holding="holding" />
                                        </td>
                                        <td class="watch-list-content-cell">
                                            <span>{{ formatSessionPrice(holding.start_price, holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'start_price')" /></span>
                                        </td>
                                        <td class="watch-list-content-cell">
                                            <span class="d-inline-flex flex-column">
                                                <span class="d-inline-flex align-center ga-1">
                                                    <span>{{ formatSessionPrice(holding.end_price_24, holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'end_price_24')" /></span>
                                                </span>
                                            </span>
                                        </td>
                                        <td v-if="!isCompactWatchListTable" class="watch-list-source-time-cell">
                                            <div title="Time of the last reported trade or source observation, independently of when it was checked.">{{ formatSourceDateTime(holding.latest_price_as_of) }}</div>
                                            <HoldingPriceReload :holding="holding" />
                                            <div
                                                v-if="activeSection === 'stocks'"
                                                class="stock-holding-refresh-statuses d-flex flex-wrap align-center ga-1"
                                            >
                                                <v-chip
                                                    :aria-label="`${holding.symbol || 'Stock'} live update schedule status`"
                                                    :color="stockHoldingRefreshSchedule(holding).scheduleColor"
                                                    size="x-small"
                                                    variant="tonal"
                                                >
                                                    {{ stockHoldingRefreshSchedule(holding).scheduleStatus }}
                                                </v-chip>
                                                <v-chip
                                                    :aria-label="`${holding.symbol || 'Stock'} live update activity status`"
                                                    :color="stockHoldingRefreshSchedule(holding).color"
                                                    size="x-small"
                                                    variant="tonal"
                                                >
                                                    <span class="live-update-status-dot" aria-hidden="true" />
                                                    {{ stockHoldingRefreshSchedule(holding).status }}
                                                </v-chip>
                                            </div>
                                        </td>
                                        <td v-if="activeSection === 'stocks'" class="text-right watch-list-actions-cell">
                                            <v-btn
                                                icon
                                                variant="text"
                                                color="primary"
                                                aria-label="Edit stock"
                                                :disabled="holdingsLoading"
                                                @click.stop="openEditHoldingDialog(holding)"
                                            >
                                                <v-icon icon="mdi-pencil-outline" />
                                            </v-btn>
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
                                        </td>
                                    </tr>
                                    <tr
                                        v-show="activeSection === 'stocks' && selectedStockWatchItem?.id === holding.id"
                                        class="stock-price-chart-row"
                                    >
                                        <td :colspan="watchListTableColumnCount">
                                            <div :id="`desktop-stock-chart-target-${holding.id}`" />
                                        </td>
                                    </tr>
                                    <tr v-if="activeSection !== 'stocks' && isHoldingExpanded(holding)" class="stock-holding-detail-row">
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
                                                <div
                                                    v-if="expandedHoldingIntradayChart(holding).points.length"
                                                    class="holding-intraday-chart-panel"
                                                >
                                                    <DataSourceMarkers :markers="marketDataMarkers(expandedHoldingIntradayChart(holding).points)" />
                                                    <svg
                                                        class="holding-intraday-chart"
                                                        :viewBox="`0 0 ${expandedHoldingIntradayChart(holding).width} ${expandedHoldingIntradayChart(holding).height}`"
                                                        role="img"
                                                        :aria-label="`${stockDisplayLabel(holding, 'Stock')} intraday close chart`"
                                                    >
                                                        <line
                                                            v-for="(gridLine, index) in expandedHoldingIntradayChart(holding).horizontalGridLines"
                                                            :key="`holding-intraday-horizontal-${holding.id}-${index}`"
                                                            class="holding-intraday-chart-grid-line"
                                                            :x1="expandedHoldingIntradayChart(holding).plot.left"
                                                            :y1="gridLine.y"
                                                            :x2="expandedHoldingIntradayChart(holding).plot.right"
                                                            :y2="gridLine.y"
                                                        />
                                                        <line
                                                            v-for="(gridLine, index) in expandedHoldingIntradayChart(holding).verticalGridLines"
                                                            :key="`holding-intraday-vertical-${holding.id}-${index}`"
                                                            class="holding-intraday-chart-grid-line"
                                                            :x1="gridLine.x"
                                                            :y1="expandedHoldingIntradayChart(holding).plot.top"
                                                            :x2="gridLine.x"
                                                            :y2="expandedHoldingIntradayChart(holding).plot.bottom"
                                                        />
                                                        <line
                                                            class="holding-intraday-chart-axis"
                                                            :x1="expandedHoldingIntradayChart(holding).plot.left"
                                                            :y1="expandedHoldingIntradayChart(holding).plot.bottom"
                                                            :x2="expandedHoldingIntradayChart(holding).plot.right"
                                                            :y2="expandedHoldingIntradayChart(holding).plot.bottom"
                                                        />
                                                        <line
                                                            class="holding-intraday-chart-axis"
                                                            :x1="expandedHoldingIntradayChart(holding).plot.left"
                                                            :y1="expandedHoldingIntradayChart(holding).plot.top"
                                                            :x2="expandedHoldingIntradayChart(holding).plot.left"
                                                            :y2="expandedHoldingIntradayChart(holding).plot.bottom"
                                                        />
                                                        <text
                                                            v-for="(gridLine, index) in expandedHoldingIntradayChart(holding).horizontalGridLines"
                                                            :key="`holding-intraday-y-label-${holding.id}-${index}`"
                                                            class="holding-intraday-chart-label"
                                                            :x="expandedHoldingIntradayChart(holding).plot.left - 8"
                                                            :y="gridLine.y"
                                                            text-anchor="end"
                                                        >
                                                            {{ gridLine.label }}
                                                        </text>
                                                        <text
                                                            v-for="(gridLine, index) in expandedHoldingIntradayChart(holding).verticalGridLines"
                                                            :key="`holding-intraday-x-label-${holding.id}-${index}`"
                                                            class="holding-intraday-chart-label"
                                                            :x="gridLine.x"
                                                            :y="expandedHoldingIntradayChart(holding).height - 14"
                                                            text-anchor="middle"
                                                        >
                                                            {{ gridLine.label }}
                                                        </text>
                                                        <polyline
                                                            class="holding-intraday-chart-line"
                                                            :points="expandedHoldingIntradayChart(holding).linePoints"
                                                        />
                                                        <g
                                                            v-if="expandedHoldingIntradayChart(holding).highMarker"
                                                            class="holding-intraday-chart-extremum holding-intraday-chart-extremum--high"
                                                        >
                                                            <line
                                                                class="holding-intraday-chart-extremum-line"
                                                                :x1="expandedHoldingIntradayChart(holding).highMarker.x"
                                                                :y1="expandedHoldingIntradayChart(holding).highMarker.y"
                                                                :x2="expandedHoldingIntradayChart(holding).highMarker.markerX"
                                                                :y2="expandedHoldingIntradayChart(holding).highMarker.markerY"
                                                            />
                                                            <circle
                                                                class="holding-intraday-chart-extremum-ring"
                                                                :cx="expandedHoldingIntradayChart(holding).highMarker.markerX"
                                                                :cy="expandedHoldingIntradayChart(holding).highMarker.markerY"
                                                                r="5"
                                                            />
                                                            <circle
                                                                class="holding-intraday-chart-extremum-dot"
                                                                :cx="expandedHoldingIntradayChart(holding).highMarker.markerX"
                                                                :cy="expandedHoldingIntradayChart(holding).highMarker.markerY"
                                                                r="2.4"
                                                            />
                                                            <text
                                                                class="holding-intraday-chart-extremum-label"
                                                                :x="expandedHoldingIntradayChart(holding).highMarker.labelX"
                                                                :y="expandedHoldingIntradayChart(holding).highMarker.labelY"
                                                                :text-anchor="expandedHoldingIntradayChart(holding).highMarker.labelAnchor"
                                                            >
                                                                {{ expandedHoldingIntradayChart(holding).highMarker.label }}
                                                            </text>
                                                        </g>
                                                        <g
                                                            v-if="expandedHoldingIntradayChart(holding).lowMarker"
                                                            class="holding-intraday-chart-extremum holding-intraday-chart-extremum--low"
                                                        >
                                                            <line
                                                                class="holding-intraday-chart-extremum-line"
                                                                :x1="expandedHoldingIntradayChart(holding).lowMarker.x"
                                                                :y1="expandedHoldingIntradayChart(holding).lowMarker.y"
                                                                :x2="expandedHoldingIntradayChart(holding).lowMarker.markerX"
                                                                :y2="expandedHoldingIntradayChart(holding).lowMarker.markerY"
                                                            />
                                                            <circle
                                                                class="holding-intraday-chart-extremum-ring"
                                                                :cx="expandedHoldingIntradayChart(holding).lowMarker.markerX"
                                                                :cy="expandedHoldingIntradayChart(holding).lowMarker.markerY"
                                                                r="5"
                                                            />
                                                            <circle
                                                                class="holding-intraday-chart-extremum-dot"
                                                                :cx="expandedHoldingIntradayChart(holding).lowMarker.markerX"
                                                                :cy="expandedHoldingIntradayChart(holding).lowMarker.markerY"
                                                                r="2.4"
                                                            />
                                                            <text
                                                                class="holding-intraday-chart-extremum-label"
                                                                :x="expandedHoldingIntradayChart(holding).lowMarker.labelX"
                                                                :y="expandedHoldingIntradayChart(holding).lowMarker.labelY"
                                                                :text-anchor="expandedHoldingIntradayChart(holding).lowMarker.labelAnchor"
                                                            >
                                                                {{ expandedHoldingIntradayChart(holding).lowMarker.label }}
                                                            </text>
                                                        </g>
                                                        <circle
                                                            v-for="(point, index) in expandedHoldingIntradayChart(holding).points"
                                                            :key="`holding-intraday-point-${holding.id}-${index}`"
                                                            class="holding-intraday-chart-point"
                                                            :cx="point.x"
                                                            :cy="point.y"
                                                            r="2.8"
                                                        ><title>{{ marketDataSourceText(point) }} · {{ point.source_name || 'Stored source' }} · {{ point.datetime }} · {{ point.interval }}</title></circle>
                                                    </svg>
                                                </div>
                                                <div class="holding-intraday-table-wrap">
                                                    <table class="holding-intraday-table">
                                                        <thead>
                                                            <tr>
                                                                <th>Time</th>
                                                                <th class="text-right">Close</th>
                                                            </tr>
                                                        </thead>
                                                        <tbody>
                                                            <tr
                                                                v-for="row in expandedHoldingIntradayRows(holding)"
                                                                :key="`${holding.id}-${row.timestamp ?? row.datetime}`"
                                                            >
                                                                <td>{{ formatAnalyzeIntradayCandleDateTime(row) }}</td>
                                                                <td class="text-right">{{ formatAnalyzeIntradayCandleValue(row.close) }}</td>
                                                            </tr>
                                                        </tbody>
                                                    </table>
                                                </div>
                                            </div>
                                            <span v-else class="text-body-2 text-medium-emphasis">
                                                No stored intraday observations available for this session. Free source coverage can have gaps.
                                            </span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            </v-table>
                        </section>

                        <Teleport
                            v-if="activeSection === 'stocks' && selectedStockWatchItem && stockPriceChartTargetSelector"
                            :to="stockPriceChartTargetSelector"
                        >
                            <v-card
                                class="stock-price-inline-card index-price-inline-card"
                                variant="outlined"
                                aria-label="Selected stock price chart"
                            >
                            <v-card-title class="index-price-dialog-title">
                                <span>
                                    <span class="index-price-dialog-symbol">
                                        {{ selectedStockWatchItem.symbol || '-' }}
                                    </span>
                                    <span class="index-price-dialog-country">
                                        {{ selectedStockWatchItem.country || selectedStockWatchItem.exchange || '-' }}
                                    </span>
                                </span>
                                <span class="index-price-dialog-name">
                                    {{ stockDisplayName(selectedStockWatchItem, 'Stock') }} <DataSourceMarkers :markers="marketDataMarkers(selectedStockWatchItem)" />
                                </span>
                                <span v-if="selectedStockWatchItem.subtitle" class="index-price-dialog-code stock-subtitle text-info font-weight-medium">
                                    {{ selectedStockWatchItem.subtitle }}
                                </span>
                                <span v-if="selectedStockWatchItem.isin" class="index-price-dialog-code">
                                    {{ selectedStockWatchItem.isin }}
                                </span>
                            </v-card-title>
                            <v-card-text>
                                <v-tabs
                                    :model-value="selectedStockPriceRange"
                                    class="stock-price-range-tabs index-price-range-tabs mb-4"
                                    color="primary"
                                    show-arrows
                                    @update:model-value="selectStockPriceRange"
                                >
                                    <v-tab
                                        v-for="range in indexPriceRangeItems"
                                        :key="`stock-price-range-${range.key}`"
                                        :value="range.key"
                                    >
                                        {{ range.label }}
                                    </v-tab>
                                </v-tabs>
                                <v-progress-linear
                                    v-if="isStockPriceChartLoading"
                                    class="mb-4"
                                    color="primary"
                                    indeterminate
                                />
                                <v-alert
                                    v-if="stockPriceChartError"
                                    class="mb-4"
                                    type="error"
                                    variant="tonal"
                                    density="compact"
                                >
                                    {{ stockPriceChartError }}
                                </v-alert>
                                <div class="index-price-current mb-4">
                                    <span class="text-caption text-medium-emphasis">Actual price</span>
                                    <span class="index-price-current-value" :class="latestPriceClass(selectedStockWatchItem)">
                                        <span v-if="formatLatestPriceChangePercent(selectedStockWatchItem)">
                                            {{ formatLatestPriceChangePercent(selectedStockWatchItem) }}
                                        </span>
                                        <span>{{ formatLatestPrice(selectedStockWatchItem) }} <DataSourceMarkers :markers="marketDataMarkers(selectedStockWatchItem, 'latest_price')" /></span>
                                    </span>
                                </div>

                                <v-alert
                                    v-if="!selectedStockChartPrices.length && !isStockPriceChartLoading"
                                    type="info"
                                    variant="tonal"
                                    density="compact"
                                >
                                    No stored stock prices yet.
                                </v-alert>

                                <div class="index-price-chart-panel mt-5">
                                    <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-2">
                                        <span class="text-caption text-medium-emphasis">Evolution</span>
                                        <span
                                            v-if="selectedStockPriceDateRange"
                                            class="stock-price-chart-date-range index-price-chart-date-range text-h6 font-weight-bold text-primary"
                                        >
                                            {{ selectedStockPriceDateRange }}
                                        </span>
                                    </div>
                                    <div
                                        v-if="selectedStockPriceRange === 'intraday'"
                                        class="text-caption text-medium-emphasis mb-2"
                                    >
                                        Previous trading day's close to latest available price.
                                    </div>
                                    <DataSourceMarkers :markers="marketDataMarkers(selectedStockChart.points)" />
                                    <svg
                                        v-if="selectedStockChart.points.length"
                                        class="stock-price-chart index-price-chart"
                                        :viewBox="`0 0 ${selectedStockChart.width} ${selectedStockChart.height}`"
                                        role="img"
                                        :aria-label="`${stockDisplayLabel(selectedStockWatchItem, 'Stock')} price evolution`"
                                    >
                                        <line
                                            v-for="(gridLine, index) in selectedStockChart.horizontalGridLines"
                                            :key="`stock-horizontal-grid-${index}`"
                                            class="index-price-chart-grid-line"
                                            :x1="selectedStockChart.plot.left"
                                            :y1="gridLine.y"
                                            :x2="selectedStockChart.plot.right"
                                            :y2="gridLine.y"
                                        />
                                        <line
                                            v-for="(gridLine, index) in selectedStockChart.verticalGridLines"
                                            :key="`stock-vertical-grid-${index}`"
                                            class="index-price-chart-grid-line"
                                            :x1="gridLine.x"
                                            :y1="selectedStockChart.plot.top"
                                            :x2="gridLine.x"
                                            :y2="selectedStockChart.plot.bottom"
                                        />
                                        <line
                                            class="index-price-chart-axis"
                                            :x1="selectedStockChart.plot.left"
                                            :y1="selectedStockChart.plot.bottom"
                                            :x2="selectedStockChart.plot.right"
                                            :y2="selectedStockChart.plot.bottom"
                                        />
                                        <line
                                            class="index-price-chart-axis"
                                            :x1="selectedStockChart.plot.left"
                                            :y1="selectedStockChart.plot.top"
                                            :x2="selectedStockChart.plot.left"
                                            :y2="selectedStockChart.plot.bottom"
                                        />
                                        <text
                                            v-for="(gridLine, index) in selectedStockChart.horizontalGridLines"
                                            :key="`stock-horizontal-label-${index}`"
                                            class="index-price-chart-y-label"
                                            :x="selectedStockChart.plot.left - 8"
                                            :y="gridLine.y"
                                            text-anchor="end"
                                        >
                                            {{ gridLine.label }}
                                        </text>
                                        <text
                                            v-for="(gridLine, index) in selectedStockChart.verticalGridLines"
                                            :key="`stock-vertical-label-${index}`"
                                            class="stock-price-chart-x-label index-price-chart-x-label"
                                            :x="gridLine.x"
                                            :y="selectedStockChart.height - 13"
                                            text-anchor="middle"
                                        >
                                            {{ gridLine.label }}
                                        </text>
                                        <line
                                            v-if="selectedStockChart.trendLine"
                                            class="index-price-chart-trend-line"
                                            :x1="selectedStockChart.trendLine.x1"
                                            :y1="selectedStockChart.trendLine.y1"
                                            :x2="selectedStockChart.trendLine.x2"
                                            :y2="selectedStockChart.trendLine.y2"
                                        />
                                        <polyline
                                            class="stock-price-chart-line index-price-chart-line"
                                            :class="selectedStockChartLineClass"
                                            :points="selectedStockChart.linePoints"
                                        />
                                        <template v-if="showSelectedStockChartPoints">
                                            <circle
                                                v-for="(point, index) in selectedStockChart.points"
                                                :key="`stock-point-${point.chart_as_of || point.trading_date || index}`"
                                                class="stock-price-chart-point index-price-chart-point"
                                                :cx="point.x"
                                                :cy="point.y"
                                                r="4"
                                            ><title>{{ marketDataSourceText(point) }} · {{ point.as_of || point.trading_date || point.label }}</title></circle>
                                        </template>
                                        <text
                                            v-if="selectedStockChart.firstLabel"
                                            class="index-price-chart-endpoint-label index-price-chart-endpoint-label--start"
                                            :x="selectedStockChart.firstLabel.labelX"
                                            :y="selectedStockChart.firstLabel.labelY"
                                            :text-anchor="selectedStockChart.firstLabel.labelAnchor"
                                        >
                                            {{ selectedStockPriceRange === 'intraday' ? 'Previous close' : 'Start' }}
                                            {{ formatStockChartEndpointPrice(selectedStockChart.firstLabel) }} <tspan>{{ marketDataSourceText(selectedStockChart.firstLabel) }}</tspan>
                                        </text>
                                        <text
                                            v-if="selectedStockChart.latestLabel"
                                            class="index-price-chart-endpoint-label index-price-chart-endpoint-label--latest"
                                            :x="selectedStockChart.latestLabel.labelX"
                                            :y="selectedStockChart.latestLabel.labelY"
                                            :text-anchor="selectedStockChart.latestLabel.labelAnchor"
                                        >
                                            End {{ formatStockChartEndpointPrice(selectedStockChart.latestLabel) }} <tspan>{{ marketDataSourceText(selectedStockChart.latestLabel) }}</tspan>
                                            {{ selectedStockChart.latestLabel.changePercent }}
                                        </text>
                                    </svg>
                                    <div v-else class="text-body-2 text-medium-emphasis">
                                        No chart data available.
                                    </div>
                                </div>
                            </v-card-text>
                            </v-card>
                        </Teleport>

                        <v-progress-linear
                            v-if="activeSection === 'stocks' && (holdingsLoading || holdings.some(holding => holding.historical_prices_fetching))"
                            indeterminate
                            color="primary"
                            class="mt-4"
                        />

                        <v-pagination
                            v-if="activeSection === 'stocks' && holdingsPagination.last_page > 1"
                            v-model="holdingsPagination.current_page"
                            class="mt-6"
                            :length="holdingsPagination.last_page"
                            @update:model-value="loadWatchlistHoldingsForActiveSection"
                        />

                        <v-dialog
                            v-if="activeSection === 'stocks'"
                            v-model="isHoldingDialogOpen"
                            persistent
                            max-width="900"
                        >
                            <v-card>
                                <v-card-title>Add stock</v-card-title>
                                <v-card-text>
                                    <InstrumentSearchSource v-model="stockSearchSource" :notice="stockSearchNotice" :catalog-date="stockSearchCatalogDate" :status="stockSearchStatus" />
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

                                    <v-text-field
                                        v-model="holdingSubtitle"
                                        class="mb-4"
                                        counter="255"
                                        label="Subtitle"
                                        maxlength="255"
                                    />

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
                                                <th>Exchange / MIC / Currency</th>
                                                <th>Type</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="result in stockSearchResults" :key="`${result.isin}-${result.symbol}-${result.exchange}-${result.mic_code}-${result.currency}`">
                                                <td>{{ result.symbol }}</td>
                                                <td>
                                                    {{ result.name }}
                                                    <div v-if="result.source_name" class="text-caption text-medium-emphasis">
                                                        {{ result.source_name }} <DataSourceMarkers :markers="marketDataMarkers(result)" /><span v-if="result.source_as_of"> · {{ result.source_as_of }}</span>
                                                    </div>
                                                </td>
                                                <td>{{ result.isin || '-' }}</td>
                                                <td>{{ result.wkn || result.valor || '-' }}</td>
                                                <td>{{ result.exchange }} / {{ result.mic_code || '-' }} / {{ result.currency || '-' }}</td>
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

                        <v-dialog
                            v-if="activeSection === 'stocks'"
                            v-model="isEditHoldingDialogOpen"
                            max-width="720"
                            persistent
                        >
                            <v-card>
                                <v-card-title>Edit stock</v-card-title>
                                <v-form @submit.prevent="saveEditedHolding">
                                    <v-card-text>
                                        <v-alert
                                            v-if="holdingError"
                                            class="mb-4"
                                            density="compact"
                                            type="error"
                                            variant="tonal"
                                        >
                                            {{ holdingError }}
                                        </v-alert>
                                        <v-row>
                                            <v-col cols="12" sm="4">
                                                <v-text-field v-model="holdingForm.symbol" label="Symbol" maxlength="32" required />
                                            </v-col>
                                            <v-col cols="12" sm="8">
                                                <v-text-field v-model="holdingForm.name" label="Name" maxlength="255" />
                                            </v-col>
                                            <v-col cols="12">
                                                <v-text-field v-model="holdingForm.subtitle" counter="255" label="Subtitle" maxlength="255" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="holdingForm.isin" label="ISIN" maxlength="12" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="holdingForm.wkn" label="WKN / Valor" maxlength="6" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="holdingForm.exchange" label="Exchange" maxlength="255" />
                                            </v-col>
                                            <v-col cols="12" sm="6">
                                                <v-text-field v-model="holdingForm.mic_code" label="MIC code" maxlength="32" />
                                            </v-col>
                                            <v-col cols="12" sm="4">
                                                <v-text-field v-model="holdingForm.instrument_type" label="Instrument type" maxlength="255" />
                                            </v-col>
                                            <v-col cols="12" sm="4">
                                                <v-text-field v-model="holdingForm.country" label="Country" maxlength="255" />
                                            </v-col>
                                            <v-col cols="12" sm="4">
                                                <v-text-field v-model="holdingForm.currency" label="Currency" maxlength="8" />
                                            </v-col>
                                        </v-row>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortEditHoldingDialog">
                                            Cancel
                                        </v-btn>
                                        <v-btn color="primary" type="submit" variant="flat" :loading="holdingsLoading">
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-form>
                            </v-card>
                        </v-dialog>

                        <v-dialog
                            v-if="activeSection === 'indices'"
                            v-model="isIndexEodhdSyncScheduleDialogOpen"
                            max-width="560"
                            persistent
                        >
                            <v-card>
                                <v-card-title>Edit automatic update times</v-card-title>
                                <v-card-subtitle>
                                    The independent v2 EODHD sync runs at each stored Vienna time.
                                </v-card-subtitle>
                                <v-form @submit.prevent="saveIndexEodhdSyncSchedule">
                                    <v-card-text>
                                        <v-alert
                                            v-if="indexEodhdSyncScheduleError"
                                            class="mb-4"
                                            density="compact"
                                            type="error"
                                            variant="tonal"
                                        >
                                            {{ indexEodhdSyncScheduleError }}
                                        </v-alert>
                                        <div class="d-flex flex-column ga-3">
                                            <div
                                                v-for="(time, index) in indexEodhdSyncScheduleForm.times"
                                                :key="index"
                                                class="d-flex align-center ga-2"
                                            >
                                                <v-text-field
                                                    v-model="indexEodhdSyncScheduleForm.times[index]"
                                                    :label="`Update time ${index + 1}`"
                                                    density="comfortable"
                                                    hide-details="auto"
                                                    required
                                                    type="time"
                                                />
                                                <v-btn
                                                    :aria-label="`Remove update time ${index + 1}`"
                                                    color="error"
                                                    icon="mdi-delete-outline"
                                                    size="small"
                                                    type="button"
                                                    variant="text"
                                                    :disabled="indexEodhdSyncScheduleForm.times.length <= 1"
                                                    @click="removeIndexEodhdSyncScheduleTime(index)"
                                                />
                                            </div>
                                            <v-btn
                                                class="align-self-start"
                                                prepend-icon="mdi-plus"
                                                type="button"
                                                variant="text"
                                                :disabled="indexEodhdSyncScheduleForm.times.length >= 8"
                                                @click="addIndexEodhdSyncScheduleTime"
                                            >
                                                Add time
                                            </v-btn>
                                        </div>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn
                                            type="button"
                                            :disabled="isIndexEodhdSyncScheduleSaving"
                                            @click="closeIndexEodhdSyncScheduleDialog"
                                        >
                                            Cancel
                                        </v-btn>
                                        <v-btn
                                            color="primary"
                                            type="submit"
                                            variant="flat"
                                            :loading="isIndexEodhdSyncScheduleSaving"
                                        >
                                            Save times
                                        </v-btn>
                                    </v-card-actions>
                                </v-form>
                            </v-card>
                        </v-dialog>

                        <v-dialog
                            v-if="activeSection === 'indices'"
                            v-model="isIndexV2RealtimeScheduleDialogOpen"
                            max-width="620"
                            persistent
                        >
                            <v-card>
                                <v-card-title>Edit live/realtime update period</v-card-title>
                                <v-card-subtitle>
                                    V2 refreshes each index from EODHD around its stored trading hours.
                                </v-card-subtitle>
                                <v-form @submit.prevent="saveIndexV2RealtimeSchedule">
                                    <v-card-text>
                                        <v-alert
                                            v-if="indexV2RealtimeScheduleError"
                                            class="mb-4"
                                            density="compact"
                                            type="error"
                                            variant="tonal"
                                        >
                                            {{ indexV2RealtimeScheduleError }}
                                        </v-alert>
                                        <v-row density="compact">
                                            <v-col cols="12" sm="4">
                                                <v-text-field
                                                    v-model="indexV2RealtimeScheduleForm.trading_interval_minutes"
                                                    label="Every (minutes)"
                                                    min="1"
                                                    max="1440"
                                                    required
                                                    type="number"
                                                />
                                            </v-col>
                                            <v-col cols="12" sm="4">
                                                <v-text-field
                                                    v-model="indexV2RealtimeScheduleForm.trading_starts_before_minutes"
                                                    label="Start before (minutes)"
                                                    min="0"
                                                    max="720"
                                                    required
                                                    type="number"
                                                />
                                            </v-col>
                                            <v-col cols="12" sm="4">
                                                <v-text-field
                                                    v-model="indexV2RealtimeScheduleForm.trading_ends_after_minutes"
                                                    label="End after (minutes)"
                                                    min="0"
                                                    max="720"
                                                    required
                                                    type="number"
                                                />
                                            </v-col>
                                        </v-row>
                                        <v-switch
                                            v-model="indexV2RealtimeScheduleForm.closed_refresh_enabled"
                                            color="primary"
                                            density="compact"
                                            hide-details
                                            label="Also refresh while markets are closed"
                                        />
                                        <v-text-field
                                            v-if="indexV2RealtimeScheduleForm.closed_refresh_enabled"
                                            v-model="indexV2RealtimeScheduleForm.closed_interval_minutes"
                                            class="mt-3"
                                            label="Closed-market interval (minutes)"
                                            min="1"
                                            max="10080"
                                            required
                                            type="number"
                                        />
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn
                                            type="button"
                                            :disabled="isIndexV2RealtimeScheduleSaving"
                                            @click="closeIndexV2RealtimeScheduleDialog"
                                        >
                                            Cancel
                                        </v-btn>
                                        <v-btn
                                            color="primary"
                                            type="submit"
                                            variant="flat"
                                            :loading="isIndexV2RealtimeScheduleSaving"
                                        >
                                            Save live schedule
                                        </v-btn>
                                    </v-card-actions>
                                </v-form>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-if="activeSection === 'indices'" v-model="isIndexDialogOpen" persistent max-width="900">
                            <v-card>
                                <v-card-title>Add index</v-card-title>
                                <v-card-text>
                                    <InstrumentSearchSource v-model="stockSearchSource" :notice="stockSearchNotice" :catalog-date="stockSearchCatalogDate" :status="stockSearchStatus" />
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
                                                <th>Exchange / MIC / Currency</th>
                                                <th>Type</th>
                                                <th class="text-right">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="result in stockSearchResults" :key="`index-${result.isin}-${result.symbol}-${result.exchange}-${result.mic_code}-${result.currency}`">
                                                <td>{{ result.symbol }}</td>
                                                <td>
                                                    {{ result.name }}
                                                    <div v-if="result.source_name" class="text-caption text-medium-emphasis">
                                                        {{ result.source_name }} <DataSourceMarkers :markers="marketDataMarkers(result)" /><span v-if="result.source_as_of"> · {{ result.source_as_of }}</span>
                                                    </div>
                                                </td>
                                                <td>{{ result.isin || '-' }}</td>
                                                <td>{{ result.wkn || result.valor || '-' }}</td>
                                                <td>{{ result.exchange }} / {{ result.mic_code || '-' }} / {{ result.currency || '-' }}</td>
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

                        <v-card
                            v-if="activeSection === 'indices' && selectedIndexWatchItem"
                            class="index-price-inline-card mt-6"
                            variant="outlined"
                        >
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
                                        {{ selectedIndexWatchItem.name || 'Index' }} <DataSourceMarkers :markers="marketDataMarkers(selectedIndexWatchItem)" />
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
                                    <v-tabs
                                        :model-value="selectedIndexPriceRange"
                                        class="index-price-range-tabs mb-4"
                                        color="primary"
                                        show-arrows
                                        @update:model-value="selectIndexPriceRange"
                                    >
                                        <v-tab
                                            v-for="range in indexPriceRangeItems"
                                            :key="range.key"
                                            :value="range.key"
                                        >
                                            {{ range.label }}
                                        </v-tab>
                                    </v-tabs>
                                    <v-progress-linear
                                        v-if="isIndexPriceChartLoading"
                                        class="mb-4"
                                        color="primary"
                                        indeterminate
                                    />
                                    <v-alert
                                        v-if="indexPriceChartError"
                                        class="mb-4"
                                        type="error"
                                        variant="tonal"
                                        density="compact"
                                    >
                                        {{ indexPriceChartError }}
                                    </v-alert>
                                    <div class="index-price-current mb-4">
                                        <span class="text-caption text-medium-emphasis">Actual price</span>
                                        <span class="index-price-current-value" :class="indexChangeClass(selectedIndexWatchItem)">
                                            <span v-if="formatIndexChangePercent(selectedIndexWatchItem)">
                                                {{ formatIndexChangePercent(selectedIndexWatchItem) }}
                                            </span>
                                            <span>{{ formatIndexDialogActualPrice(selectedIndexWatchItem) }} <DataSourceMarkers :markers="marketDataMarkers(selectedIndexWatchItem, 'latest_price')" /></span>
                                        </span>
                                    </div>

                                    <v-alert
                                        v-if="!selectedIndexRecentPrices.length && !isIndexPriceChartLoading"
                                        type="info"
                                        variant="tonal"
                                        density="compact"
                                    >
                                        No stored index prices yet.
                                    </v-alert>

                                    <div class="index-price-chart-panel mt-5">
                                        <div class="d-flex flex-wrap align-center justify-space-between ga-2 mb-2">
                                            <span class="text-caption text-medium-emphasis">Evolution</span>
                                            <span
                                                v-if="selectedIndexPriceDateRange"
                                                class="index-price-chart-date-range text-h6 font-weight-bold text-primary"
                                            >
                                                {{ selectedIndexPriceDateRange }}
                                            </span>
                                        </div>
                                        <div
                                            v-if="selectedIndexPriceRange === 'intraday'"
                                            class="text-caption text-medium-emphasis mb-2"
                                        >
                                            Previous trading day's close to latest available price.
                                        </div>
                                        <DataSourceMarkers :markers="marketDataMarkers(selectedIndexChart.points)" />
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
                                                :class="selectedIndexChartLineClass"
                                                :points="selectedIndexChart.linePoints"
                                            />
                                            <template v-if="showSelectedIndexChartPoints">
                                                <circle
                                                    v-for="(point, index) in selectedIndexChart.points"
                                                    :key="`point-${point.trading_date || index}`"
                                                    class="index-price-chart-point"
                                                    :cx="point.x"
                                                    :cy="point.y"
                                                    r="4"
                                                ><title>{{ marketDataSourceText(point) }} · {{ point.as_of || point.trading_date || point.label }}</title></circle>
                                            </template>
                                            <text
                                                v-if="selectedIndexChart.firstLabel"
                                                class="index-price-chart-endpoint-label index-price-chart-endpoint-label--start"
                                                :x="selectedIndexChart.firstLabel.labelX"
                                                :y="selectedIndexChart.firstLabel.labelY"
                                                :text-anchor="selectedIndexChart.firstLabel.labelAnchor"
                                            >
                                                {{ selectedIndexPriceRange === 'intraday' ? 'Previous close' : 'Start' }}
                                                {{ formatIndexChartEndpointPrice(selectedIndexChart.firstLabel) }} <tspan>{{ marketDataSourceText(selectedIndexChart.firstLabel) }}</tspan>
                                            </text>
                                            <text
                                                v-if="selectedIndexChart.latestLabel"
                                                class="index-price-chart-endpoint-label index-price-chart-endpoint-label--latest"
                                                :x="selectedIndexChart.latestLabel.labelX"
                                                :y="selectedIndexChart.latestLabel.labelY"
                                                :text-anchor="selectedIndexChart.latestLabel.labelAnchor"
                                            >
                                                End {{ formatIndexChartEndpointPrice(selectedIndexChart.latestLabel) }} <tspan>{{ marketDataSourceText(selectedIndexChart.latestLabel) }}</tspan>
                                                {{ selectedIndexChart.latestLabel.changePercent }}
                                            </text>
                                        </svg>
                                        <div v-else class="text-body-2 text-medium-emphasis">
                                            No chart data available.
                                        </div>
                                    </div>
                                </v-card-text>
                        </v-card>

                        <v-dialog
                            v-if="activeSection === 'stocks'"
                            v-model="isDeleteHoldingDialogOpen"
                            persistent
                            max-width="440"
                        >
                            <v-card>
                                <v-card-title>Confirm delete</v-card-title>
                                <v-card-text>
                                    <div>
                                        Delete {{ stockDisplayLabel(selectedHolding, 'stock') }}?
                                    </div>
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
                                        :disabled="!selectedHolding"
                                        @click="deleteHolding"
                                    >
                                        Confirm
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                        <v-dialog v-if="activeSection === 'indices'" v-model="isDeleteIndexDialogOpen" persistent max-width="440">
                            <v-card>
                                <v-card-title>Index löschen</v-card-title>
                                <v-card-text>
                                    {{ selectedIndexWatchItemForRemoval?.name || selectedIndexWatchItemForRemoval?.symbol }} wirklich löschen?
                                    Alle gespeicherten Kursdaten dieses Index werden ebenfalls gelöscht.
                                </v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortDeleteIndexDialog">
                                        Abbrechen
                                    </v-btn>
                                    <v-btn
                                        type="button"
                                        color="error"
                                        variant="flat"
                                        :loading="holdingsLoading"
                                        @click="deleteIndexWatchItem"
                                    >
                                        Löschen
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>

                    </section>

                    <v-dialog v-model="isCashTransactionDialogOpen" persistent max-width="480">
                        <v-card>
                            <v-card-title>
                                {{ transactionTypeLabel(cashTransactionForm.type) }}
                            </v-card-title>
                            <v-card-text>
                                <form id="cash-transaction-form" @submit.prevent="bookCashTransaction">
                                    <v-select
                                        v-model="cashTransactionForm.type"
                                        :items="cashTransactionTypeOptions"
                                        item-title="title"
                                        item-value="value"
                                        label="Type"
                                        required
                                    />
                                    <v-select
                                        v-model="cashTransactionForm.stock_holding_id"
                                        :items="cashTransactionStockOptions"
                                        clearable
                                        item-title="title"
                                        item-value="value"
                                        label="Related stock / ISIN"
                                        no-data-text="No depot stocks"
                                    />
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
                                    <div class="font-weight-medium">{{ stockDisplayLabel(transactionHolding, '') }}</div>
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
                        v-if="(activeSection === 'depots' || activeSection === 'users' || activeSection === 'roles') && canManageDashboardAdmin"
                        :model-value="activeSection"
                        color="primary"
                        class="mb-6"
                        @update:model-value="(s) => s !== activeSection && navigateSection(s)"
                    >
                        <v-tab value="depots" prepend-icon="mdi-briefcase-outline">Depots</v-tab>
                        <v-tab v-if="canManageUsers" value="users" prepend-icon="mdi-account-group-outline">Users</v-tab>
                        <v-tab v-if="canManageUsers" value="roles" prepend-icon="mdi-shield-account-outline">Roles</v-tab>
                    </v-tabs>

                    <section v-if="activeSection === 'data' && canManageDashboardAdmin" aria-label="Data">
                        <v-tabs :model-value="activeDataSubsection" color="primary" aria-label="Data sections" @update:model-value="(value) => { activeDataSubsection = value; updateUrlPath(); }">
                            <v-tab value="overview" prepend-icon="mdi-view-dashboard-outline">Overview</v-tab>
                            <v-tab v-if="canUseCloudways" value="cloudways" prepend-icon="mdi-cloud-sync-outline">Cloudways</v-tab>
                        </v-tabs>
                    </section>
                    <PerformanceConsistency v-if="canManageDashboardAdmin" :show-card="activeSection === 'data' && activeDataSubsection === 'overview'" />
                    <CloudwaysSynchronization v-if="canUseCloudways" :show-card="activeSection === 'data' && activeDataSubsection === 'cloudways'" />

                    <section
                        v-if="activeSection === 'infos' && canManageDashboardAdmin"
                        class="info-page"
                        aria-label="Infos"
                    >
                        <v-tabs
                            :model-value="activeInfoSubsection"
                            class="mb-6"
                            color="primary"
                            @update:model-value="navigateInfoSubsection"
                        >
                            <v-tab
                                v-for="item in infoSubmenuItems"
                                :key="item.key"
                                :prepend-icon="item.icon"
                                :value="item.key"
                            >
                                {{ item.label }}
                            </v-tab>
                        </v-tabs>

                        <section v-if="activeInfoSubsection === 'eodhd'" aria-label="Infos EODHD">
                            <div class="info-page-heading mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Infos</p>
                                <h1 class="text-h4">Stock & index data flows</h1>
                                <p class="text-body-2 text-medium-emphasis mt-2">
                                    Active GKStocks market-data tables with their EODHD source, refresh cadence, and queue execution mode.
                                </p>
                            </div>
                            <v-btn
                                prepend-icon="mdi-refresh"
                                type="button"
                                variant="tonal"
                                :loading="infoTablesLoading"
                                :disabled="infoTablesLoading"
                                @click="loadInfoData"
                            >
                                Reload
                            </v-btn>
                            </div>

                        <v-alert
                            v-if="infoTablesError"
                            class="mb-4"
                            density="compact"
                            type="error"
                            variant="tonal"
                        >
                            {{ infoTablesError }}
                        </v-alert>

                        <div class="info-summary-grid mb-6">
                            <v-card class="info-summary-card" variant="outlined">
                                <v-card-text>
                                    <span class="text-caption text-medium-emphasis">Market tables</span>
                                    <strong>{{ infoTables.length }}</strong>
                                </v-card-text>
                            </v-card>
                            <v-card class="info-summary-card" variant="outlined">
                                <v-card-text>
                                    <span class="text-caption text-medium-emphasis">Direct EODHD</span>
                                    <strong>{{ directEodhdTableCount }}</strong>
                                </v-card-text>
                            </v-card>
                            <v-card class="info-summary-card" variant="outlined">
                                <v-card-text>
                                    <span class="text-caption text-medium-emphasis">Queue-backed</span>
                                    <strong>{{ queuedInfoTableCount }}</strong>
                                </v-card-text>
                            </v-card>
                            <v-card class="info-summary-card" variant="outlined">
                                <v-card-text>
                                    <span class="text-caption text-medium-emphasis">Synchronous EODHD</span>
                                    <strong>{{ synchronousInfoTableCount }}</strong>
                                </v-card-text>
                            </v-card>
                        </div>

                        <v-text-field
                            v-model="infoTableSearch"
                            class="mb-4"
                            clearable
                            density="comfortable"
                            hide-details
                            label="Filter tables, API paths, cadence, or queue jobs"
                            prepend-inner-icon="mdi-magnify"
                        />

                        <v-progress-linear v-if="infoTablesLoading" class="mb-3" color="primary" indeterminate />

                            <v-card variant="outlined">
                            <v-table class="info-table" hover>
                                <thead>
                                    <tr>
                                        <th>Table</th>
                                        <th>Purpose / Zweck</th>
                                        <th>EODHD access</th>
                                        <th>Data cadence</th>
                                        <th>Queue?</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="table in filteredInfoTables" :key="table.name">
                                        <td>
                                            <code class="info-table-name">{{ table.name }}</code>
                                            <div class="text-caption text-medium-emphasis mt-1">{{ table.category }}</div>
                                        </td>
                                        <td>
                                            <div>{{ table.purpose }}</div>
                                            <div class="text-caption text-medium-emphasis mt-2" lang="de">
                                                {{ table.purpose_de }}
                                            </div>
                                        </td>
                                        <td>
                                            <v-chip
                                                class="mb-2"
                                                density="comfortable"
                                                size="small"
                                                :color="infoEodhdModeColor(table.eodhd.mode)"
                                                :variant="table.eodhd.mode === 'none' ? 'outlined' : 'tonal'"
                                            >
                                                {{ infoEodhdModeLabel(table.eodhd.mode) }}
                                            </v-chip>
                                            <code v-if="table.eodhd.endpoint" class="info-api-path">
                                                {{ table.eodhd.endpoint }}
                                            </code>
                                            <div v-else class="text-caption text-medium-emphasis">
                                                {{ table.eodhd.access }}
                                            </div>
                                            <div
                                                v-if="table.eodhd.documentation?.length"
                                                class="info-documentation-links mt-2"
                                            >
                                                <v-btn
                                                    v-for="documentationLink in table.eodhd.documentation"
                                                    :key="documentationLink.url"
                                                    class="info-documentation-link"
                                                    density="compact"
                                                    :href="documentationLink.url"
                                                    rel="noopener noreferrer"
                                                    size="x-small"
                                                    target="_blank"
                                                    variant="text"
                                                >
                                                    {{ documentationLink.label }}
                                                    <v-icon class="ml-1" icon="mdi-open-in-new" size="x-small" />
                                                </v-btn>
                                            </div>
                                        </td>
                                        <td>{{ table.cadence }}</td>
                                        <td>
                                            <v-chip
                                                class="mb-2"
                                                density="comfortable"
                                                size="small"
                                                :color="infoQueueColor(table.queue)"
                                                :variant="infoQueueKind(table.queue) === 'none' ? 'outlined' : 'tonal'"
                                            >
                                                {{ infoQueueLabel(table.queue) }}
                                            </v-chip>
                                            <code v-if="table.queue.job" class="info-queue-job">{{ table.queue.job }}</code>
                                            <div class="text-caption text-medium-emphasis mt-1">{{ table.queue.mode }}</div>
                                        </td>
                                    </tr>
                                    <tr v-if="!infoTablesLoading && filteredInfoTables.length === 0">
                                        <td colspan="5" class="text-center text-medium-emphasis py-8">
                                            No matching tables.
                                        </td>
                                    </tr>
                                </tbody>
                            </v-table>
                            </v-card>
                        </section>

                        <section
                            v-if="activeInfoSubsection === 'methoden'"
                            class="info-method-page"
                            aria-label="Infos Methoden"
                        >
                            <div class="mb-6">
                                <p class="text-overline text-primary mb-1">Infos · Methoden</p>
                                <h1 class="text-h4">EODHD in Laravel</h1>
                                <p class="text-body-2 text-medium-emphasis mt-2">
                                    Diese Übersicht zeigt, wo jeder EODHD-Zugriff gestartet wird, welche Laravel-Methoden
                                    ihn ausführen und in welche GKStocks-Tabellen die Daten geschrieben werden.
                                </p>
                            </div>

                            <v-alert
                                v-if="infoTablesError"
                                class="mb-4"
                                density="compact"
                                type="error"
                                variant="tonal"
                            >
                                {{ infoTablesError }}
                            </v-alert>

                            <v-progress-linear v-if="infoTablesLoading" class="mb-4" color="primary" indeterminate />

                            <div class="info-summary-grid mb-6">
                                <v-card class="info-summary-card" variant="outlined">
                                    <v-card-text>
                                        <span class="text-caption text-medium-emphasis">EODHD accesses</span>
                                        <strong>{{ infoMethods.length }}</strong>
                                    </v-card-text>
                                </v-card>
                                <v-card class="info-summary-card" variant="outlined">
                                    <v-card-text>
                                        <span class="text-caption text-medium-emphasis">Scheduler-backed</span>
                                        <strong>{{ scheduledInfoMethodCount }}</strong>
                                    </v-card-text>
                                </v-card>
                                <v-card class="info-summary-card" variant="outlined">
                                    <v-card-text>
                                        <span class="text-caption text-medium-emphasis">Uses a queue</span>
                                        <strong>{{ queuedInfoMethodCount }}</strong>
                                    </v-card-text>
                                </v-card>
                                <v-card class="info-summary-card" variant="outlined">
                                    <v-card-text>
                                        <span class="text-caption text-medium-emphasis">Has synchronous path</span>
                                        <strong>{{ synchronousInfoMethodCount }}</strong>
                                    </v-card-text>
                                </v-card>
                            </div>

                            <v-card v-if="!infoTablesLoading && infoMethods.length === 0" variant="outlined">
                                <v-card-text class="text-medium-emphasis">No method information available.</v-card-text>
                            </v-card>

                            <div v-else class="info-method-grid">
                                <v-card
                                    v-for="method in infoMethods"
                                    :key="method.key"
                                    class="info-method-card"
                                    variant="outlined"
                                >
                                    <v-card-title>{{ method.title }}</v-card-title>
                                    <v-card-subtitle>
                                        <code class="info-api-path">{{ method.access }}</code>
                                    </v-card-subtitle>
                                    <v-card-text>
                                        <p class="mb-3">{{ method.description }}</p>

                                        <div class="d-flex flex-wrap ga-2 mb-3">
                                            <v-chip
                                                density="comfortable"
                                                size="small"
                                                :color="infoExecutionModeColor(method.execution.mode)"
                                                variant="tonal"
                                            >
                                                {{ infoExecutionModeLabel(method.execution.mode) }}
                                            </v-chip>
                                            <v-chip
                                                v-if="method.execution.scheduled"
                                                color="primary"
                                                density="comfortable"
                                                size="small"
                                                variant="tonal"
                                            >
                                                Scheduler
                                            </v-chip>
                                            <v-chip
                                                v-if="method.execution.queue"
                                                color="success"
                                                density="comfortable"
                                                size="small"
                                                variant="outlined"
                                            >
                                                Queue: {{ method.execution.queue }}
                                            </v-chip>
                                        </div>

                                        <p class="text-body-2 text-medium-emphasis mb-4">
                                            {{ method.execution.description }}
                                        </p>

                                        <div class="info-method-section">
                                            <h2 class="text-subtitle-2 mb-2">Wo gestartet</h2>
                                            <ul class="info-method-list">
                                                <li v-for="trigger in method.triggers" :key="trigger">
                                                    <code>{{ trigger }}</code>
                                                </li>
                                            </ul>
                                        </div>

                                        <div class="info-method-section">
                                            <h2 class="text-subtitle-2 mb-2">Laravel-Aufrufkette</h2>
                                            <ol class="info-method-list info-method-chain">
                                                <li
                                                    v-for="laravelMethod in method.laravel_methods"
                                                    :key="`${laravelMethod.class}-${laravelMethod.method}`"
                                                    class="info-method-chain-item"
                                                >
                                                    <v-chip
                                                        class="info-method-layer"
                                                        density="compact"
                                                        size="x-small"
                                                        variant="outlined"
                                                    >
                                                        {{ laravelMethod.layer }}
                                                    </v-chip>
                                                    <code>
                                                        {{ infoMethodClassName(laravelMethod.class) }}::{{ laravelMethod.method }}()
                                                    </code>
                                                    <span class="info-method-namespace text-caption text-medium-emphasis">
                                                        {{ laravelMethod.class }}
                                                    </span>
                                                </li>
                                            </ol>
                                        </div>

                                        <div class="info-method-section">
                                            <h2 class="text-subtitle-2 mb-2">Betroffene Tabellen</h2>
                                            <div class="d-flex flex-wrap ga-2">
                                                <v-chip
                                                    v-for="table in method.tables"
                                                    :key="table"
                                                    density="compact"
                                                    size="small"
                                                >
                                                    {{ table }}
                                                </v-chip>
                                            </div>
                                        </div>

                                        <v-alert
                                            v-if="method.note"
                                            class="mt-4"
                                            density="compact"
                                            type="info"
                                            variant="tonal"
                                        >
                                            {{ method.note }}
                                        </v-alert>
                                    </v-card-text>
                                </v-card>
                            </div>
                        </section>
                    </section>

                    <section v-if="activeSection === 'depot'">
                        <v-tabs
                            :model-value="activeDepotSubsection"
                            color="primary"
                            :class="activeDepotSubsection === 'all-stocks' ? 'mb-2' : 'mb-6'"
                            @update:model-value="navigateDepotSubsection"
                        >
                            <v-tab
                                v-for="item in depotSubmenuItems"
                                :key="item.key"
                                :value="item.key"
                                :prepend-icon="item.icon"
                            >
                                {{ item.label }}
                            </v-tab>
                        </v-tabs>

                        <v-tabs
                            v-if="activeDepotSubsection === 'all-stocks'"
                            :model-value="activeDepotStocksSubsection"
                            aria-label="Depot All Stocks periods"
                            color="primary"
                            class="mb-6"
                            density="compact"
                            @update:model-value="navigateDepotStocksSubsection"
                        >
                            <v-tab
                                v-for="item in depotStocksSubmenuItems"
                                :key="item.key"
                                :value="item.key"
                                :prepend-icon="item.icon"
                            >
                                {{ item.label }}
                            </v-tab>
                        </v-tabs>

                        <template v-if="activeDepotSubsection === 'all-stocks'">
                            <section v-if="activeDepot" class="depot-year-stocks-section" aria-label="Depot stock period performance">
                                <div class="mb-4">
                                    <p class="text-overline text-primary mb-1">{{ depotStockPeriodLabel }}</p>
                                    <h1 class="text-h5">{{ depotStockPeriodHeading() }}</h1>
                                </div>

                                <v-progress-linear v-if="depotStockPeriodLoading" indeterminate class="mb-3" />
                                <v-alert
                                    v-if="depotStockPeriodError"
                                    type="error"
                                    variant="tonal"
                                    density="compact"
                                    class="mb-3"
                                >
                                    {{ depotStockPeriodError }}
                                </v-alert>

                                <div v-if="depotStockPeriodStocks.length > 0" class="mobile-depot-year-stocks">
                                    <article
                                        v-for="stock in depotStockPeriodStocks"
                                        :key="`depot-year-stock-${stock.id}`"
                                        class="mobile-depot-year-stock-card"
                                    >
                                        <div>
                                            <div class="mobile-depot-year-stock-name">
                                                {{ stock.symbol || '-' }} · {{ stockDisplayName(stock, `Stock ${stock.id}`) }}
                                            </div>
                                            <div v-if="stock.subtitle" class="stock-subtitle text-caption text-info font-weight-medium">
                                                {{ stock.subtitle }}
                                            </div>
                                            <div class="text-caption text-medium-emphasis">
                                                {{ formatDepotYearStockPosition(stock) }}
                                            </div>
                                        </div>
                                        <div class="mobile-depot-year-stock-row">
                                            <span class="text-caption text-medium-emphasis">{{ depotStockPeriodEntryLabel() }}</span>
                                            <span>{{ formatDepotYearStockPrice(stock, 'average_buy_or_year_start_price') }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'average_buy_or_year_start_price')" /></span>
                                        </div>
                                        <div class="mobile-depot-year-stock-row">
                                            <span class="text-caption text-medium-emphasis">Ø Sell / in stock</span>
                                            <span>{{ formatDepotYearStockPrice(stock, 'average_sell_or_current_price') }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'average_sell_or_current_price')" /></span>
                                        </div>
                                        <div class="mobile-depot-year-stock-row">
                                            <span class="text-caption text-medium-emphasis">Traded volume (money)</span>
                                            <span>{{ formatDepotStockTradedVolume(stock) }}</span>
                                        </div>
                                        <div class="mobile-depot-year-stock-row">
                                            <span class="text-caption text-medium-emphasis">Traded volume (pieces)</span>
                                            <span>{{ formatDepotStockTradedPieces(stock) }}</span>
                                        </div>
                                        <div class="mobile-depot-year-stock-row font-weight-medium" :class="depotYearStockChangeClass(stock)">
                                            <span>{{ formatDepotYearStockChangePercent(stock) }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'change_percent')" /></span>
                                            <span>{{ formatDepotYearStockChangeAmount(stock) }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'change_amount')" /> EUR</span>
                                        </div>
                                    </article>
                                </div>

                                <v-table v-if="depotStockPeriodStocks.length > 0" class="desktop-depot-year-stocks-table" density="compact">
                                    <thead>
                                        <tr>
                                            <th>Stock</th>
                                            <th class="text-right">{{ depotStockPeriodEntryLabel() }}</th>
                                            <th class="text-right">Ø Sell / in stock</th>
                                            <th class="text-right">Traded volume (money)</th>
                                            <th class="text-right">Traded volume (pieces)</th>
                                            <th class="text-right">+/- %</th>
                                            <th class="text-right">+/- EUR</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="stock in depotStockPeriodStocks" :key="stock.id">
                                            <td>
                                                <div class="font-weight-medium">
                                                    {{ stock.symbol || '-' }} · {{ stockDisplayName(stock, `Stock ${stock.id}`) }}
                                                </div>
                                                <div v-if="stock.subtitle" class="stock-subtitle text-caption text-info font-weight-medium">
                                                    {{ stock.subtitle }}
                                                </div>
                                                <div class="text-caption text-medium-emphasis">
                                                    {{ formatDepotYearStockPosition(stock) }}
                                                </div>
                                            </td>
                                            <td class="text-right">
                                                {{ formatDepotYearStockPrice(stock, 'average_buy_or_year_start_price') }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'average_buy_or_year_start_price')" />
                                            </td>
                                            <td class="text-right">
                                                {{ formatDepotYearStockPrice(stock, 'average_sell_or_current_price') }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'average_sell_or_current_price')" />
                                            </td>
                                            <td class="text-right">
                                                {{ formatDepotStockTradedVolume(stock) }}
                                            </td>
                                            <td class="text-right">
                                                {{ formatDepotStockTradedPieces(stock) }}
                                            </td>
                                            <td class="text-right font-weight-medium" :class="depotYearStockChangeClass(stock)">
                                                {{ formatDepotYearStockChangePercent(stock) }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'change_percent')" />
                                            </td>
                                            <td class="text-right font-weight-medium" :class="depotYearStockChangeClass(stock)">
                                                {{ formatDepotYearStockChangeAmount(stock) }} <DataSourceMarkers :markers="marketDataMarkers(stock, 'change_amount')" />
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>

                                <p
                                    v-if="depotStockPeriodStocks.length === 0 && !depotStockPeriodLoading && !depotStockPeriodError"
                                    class="text-medium-emphasis text-body-2"
                                >
                                    {{ depotStockPeriodEmptyMessage() }}
                                </p>
                            </section>

                            <v-alert v-else type="info" variant="tonal" density="compact">
                                No active depot found.
                            </v-alert>
                        </template>

                        <template v-if="activeDepotSubsection === 'overview'">
                        <div class="mb-4">
                            <p class="text-overline text-primary mb-1">Depot</p>
                            <h1 class="text-h4">{{ activeDepot?.name ?? '–' }}</h1>
                        </div>

                        <div v-if="activeDepot" class="d-flex flex-wrap align-start ga-4">
                            <v-card class="depot-balance-card" variant="outlined" width="100%" max-width="480">
                                <v-card-title class="text-subtitle-2 pb-0">Depot</v-card-title>
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Depot balance</td>
                                            <td class="text-right">{{ formatDepotStockBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('stock_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Cash balance</td>
                                            <td class="text-right">{{ formatDepotCashBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Account balance</td>
                                            <td class="text-right">{{ formatDepotAccountBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('account_balance')" /></td>
                                        </tr>
                                        <tr class="depot-account-yesterday-row">
                                            <td class="text-medium-emphasis text-caption">Account Yesterday</td>
                                            <td class="text-right">{{ formatDepotPreviousDayBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('previous_day_balance')" /></td>
                                        </tr>
                                        <tr v-if="hasDepotPreviousDayExternalCashFlow()" class="depot-account-external-cash-flow-row">
                                            <td class="text-medium-emphasis text-caption">External cash flow today</td>
                                            <td class="text-right">{{ formatDepotPreviousDayExternalCashFlowAmount() }}</td>
                                        </tr>
                                        <tr class="depot-account-yesterday-change-row">
                                            <td class="text-medium-emphasis text-caption">Performance +/-</td>
                                            <td class="text-right">
                                                <span :class="depotPreviousDayChangeClass()">
                                                    {{ formatDepotPreviousDayChangePercent() }} ·
                                                    {{ formatDepotPreviousDayChangeAmount() }} <DataSourceMarkers :markers="depotValuationMarkers('previous_day_change_amount')" />
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>

                            <v-card class="depot-balance-card" variant="outlined" width="100%" max-width="480">
                                <v-card-title class="text-subtitle-2 pb-0">Aktuelles Jahr</v-card-title>
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance 01.01.</td>
                                            <td class="text-right">{{ formatDepotYearStartBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatCurrentDayMonth() }}</td>
                                            <td class="text-right">{{ formatDepotCurrentBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('current_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">+/-</td>
                                            <td class="text-right">
                                                <span :class="depotBalanceChangeClass()">
                                                    {{ formatDepotBalanceChangePercent() }} · {{ formatDepotBalanceChangeAmount() }} <DataSourceMarkers :markers="depotValuationMarkers('balance_change_amount')" />
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Corrected balance (-27,5%)</td>
                                            <td class="text-right">{{ formatDepotCorrectedBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('current_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Corrected +/-</td>
                                            <td class="text-right">
                                                <span :class="depotCorrectedBalanceChangeClass()">
                                                    {{ formatDepotCorrectedBalanceChangePercent() }} ·
                                                    {{ formatDepotCorrectedBalanceChangeAmount() }} <DataSourceMarkers :markers="depotValuationMarkers('balance_change_amount')" />
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>

                            <v-card class="depot-balance-card" variant="outlined" width="100%" max-width="480">
                                <v-card-title class="text-subtitle-2 pb-0">Aktuelle Woche</v-card-title>
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatOneWeekAgoDayMonth() }}</td>
                                            <td class="text-right">{{ formatDepotOneWeekStartBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('one_week_start_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatCurrentDayMonth() }}</td>
                                            <td class="text-right">{{ formatDepotCurrentBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('current_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">1 week</td>
                                            <td class="text-right">
                                                <span :class="depotOneWeekChangeClass()">
                                                    {{ formatDepotOneWeekChangePercent() }} · {{ formatDepotOneWeekChangeAmount() }} <DataSourceMarkers :markers="depotValuationMarkers('one_week_change_amount')" />
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>

                            <v-card class="depot-balance-card" variant="outlined" width="100%" max-width="480">
                                <v-card-title class="text-subtitle-2 pb-0">Aktueller Monat</v-card-title>
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatPreviousMonthEndDayMonth() }}</td>
                                            <td class="text-right">{{ formatDepotMonthStartBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('month_start_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatCurrentDayMonth() }}</td>
                                            <td class="text-right">{{ formatDepotCurrentBalance() }} <DataSourceMarkers :markers="depotValuationMarkers('current_balance')" /></td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Month</td>
                                            <td class="text-right">
                                                <span :class="depotMonthChangeClass()">
                                                    {{ formatDepotMonthChangePercent() }} · {{ formatDepotMonthChangeAmount() }} <DataSourceMarkers :markers="depotValuationMarkers('month_change_amount')" />
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
                                        <div>{{ stockDisplayName(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding)" /></div>
                                        <div
                                            v-if="holding.subtitle"
                                            class="stock-subtitle text-caption text-info font-weight-medium"
                                        >
                                            {{ holding.subtitle }}
                                        </div>
                                        <div class="mobile-depot-stock-isin">{{ holding.isin || '-' }}</div>
                                    </div>
                                    <div class="mobile-depot-stock-row mobile-depot-stock-row--prices">
                                        <span>{{ formatPositionPieces(holding) }}</span>
                                        <span>{{ formatLatestPrice(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'latest_price')" /></span>
                                        <span>{{ formatStockHoldingValue(holding) }} <DataSourceMarkers :markers="depotHoldingValueMarkers(holding)" /></span>
                                    </div>
                                    <div class="mobile-depot-stock-row text-caption">
                                        <span>Prev Day {{ formatPreviousDayPrice(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'previous_day_price')" /></span>
                                        <span :class="previousDayChangePercentClass(holding)">
                                            {{ formatPreviousDayChangePercent(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'previous_day_change_percent')" />
                                        </span>
                                    </div>
                                    <div class="mobile-depot-stock-row font-weight-medium" :class="yearStartChangeClass(holding)">
                                        <span>
                                            {{ yearStartChangeSymbol(holding) }}
                                            {{ formatYearStartChangePercent(holding) }} <DataSourceMarkers :markers="depotHoldingValueMarkers(holding)" />
                                        </span>
                                        <span>{{ formatYearStartChangeAmount(holding) }} <DataSourceMarkers :markers="depotHoldingValueMarkers(holding)" /></span>
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
                                        <th class="text-right">Prev Day</th>
                                        <th class="text-right">+/- %</th>
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
                                        <th v-if="!isCompactDepotStocksTable" class="text-right">1.1/Buy</th>
                                        <th class="text-right">Change</th>
                                        <th class="text-right">+/- EUR</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="holding in depotHoldings" :key="holding.id" :class="depotHoldingRowClass(holding)">
                                        <td v-if="!isCompactDepotStocksTable">{{ holding.symbol || '-' }}</td>
                                        <td>
                                            <div>{{ stockDisplayName(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding)" /></div>
                                            <div
                                                v-if="holding.subtitle"
                                                class="stock-subtitle text-caption text-info font-weight-medium"
                                            >
                                                {{ holding.subtitle }}
                                            </div>
                                            <div class="depot-stock-isin">{{ holding.isin || '-' }}</div>
                                        </td>
                                        <td class="text-right">{{ formatPositionPieces(holding) }}</td>
                                        <td class="text-right">{{ formatStockHoldingValue(holding) }} <DataSourceMarkers :markers="depotHoldingValueMarkers(holding)" /></td>
                                        <td class="text-right">{{ formatLatestPrice(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'latest_price')" /></td>
                                        <td class="text-right">{{ formatPreviousDayPrice(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'previous_day_price')" /></td>
                                        <td class="text-right">
                                            <span class="font-weight-medium" :class="previousDayChangePercentClass(holding)">
                                                {{ formatPreviousDayChangePercent(holding) }} <DataSourceMarkers :markers="marketDataMarkers(holding, 'previous_day_change_percent')" />
                                            </span>
                                        </td>
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
                                                <span>{{ formatYearStartChangePercent(holding) }} <DataSourceMarkers :markers="depotHoldingValueMarkers(holding)" /></span>
                                            </span>
                                        </td>
                                        <td class="text-right">
                                            <span class="font-weight-medium" :class="yearStartChangeClass(holding)">
                                                {{ formatYearStartChangeAmount(holding) }} <DataSourceMarkers :markers="depotHoldingValueMarkers(holding)" />
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
                                        <td :colspan="isCompactDepotStocksTable ? 8 : 10" class="text-right font-weight-bold">Sum</td>
                                        <td class="text-right">
                                            <span class="font-weight-bold" :class="depotHoldingsChangeAmountTotalClass()">
                                                {{ formatDepotHoldingsChangeAmountTotal() }} <DataSourceMarkers :markers="depotHoldingsValueMarkers()" />
                                            </span>
                                        </td>
                                        <td />
                                    </tr>
                                </tfoot>
                            </v-table>
                            <p v-else-if="!transactionsLoading" class="text-medium-emphasis text-body-2 mt-2">No stocks in this depot.</p>
                        </div>

                        <v-card v-if="activeDepot" class="depot-performance-card mt-6" variant="outlined">
                            <v-card-text>
                                <div class="d-flex align-center justify-space-between flex-wrap ga-3 mb-3">
                                    <div>
                                        <p class="text-overline text-medium-emphasis mb-1">Depot performance</p>
                                        <h2 class="text-h6">01.01 to now</h2>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-caption text-medium-emphasis">
                                            Cash-flow adjusted ·
                                            {{ formatDepotPerformanceDate(depotPerformanceChart.first?.date) }}
                                            {{ formatDepotPerformanceEndpoint(depotPerformanceChart.first) }} <DataSourceMarkers :markers="marketDataMarkers(depotPerformanceChart.first)" />
                                            -
                                            {{ formatDepotPerformanceDate(depotPerformanceChart.latest?.date) }}
                                            {{ formatDepotPerformanceEndpoint(depotPerformanceChart.latest) }} <DataSourceMarkers :markers="marketDataMarkers(depotPerformanceChart.latest)" />
                                        </div>
                                        <div class="font-weight-bold" :class="depotPerformanceChangeClass()">
                                            {{ formatDepotPerformanceChangePercent() }} · {{ formatDepotPerformanceChangeAmount() }} <DataSourceMarkers :markers="depotPerformanceResult()?.source_markers ?? []" />
                                        </div>
                                    </div>
                                </div>

                                <p class="text-caption text-medium-emphasis mb-2" aria-label="Depot performance calculation">
                                    Cash-flow adjusted curve. Deposits and withdrawals do not create gains; percentage uses net capital.
                                    Stored history keeps its original source. Gaps indicate missing valuations.
                                </p>
                                <p class="text-caption text-medium-emphasis mb-2" aria-label="Actual depot account balances">
                                    Actual account balances:
                                    {{ formatDepotPerformanceDate(depotPerformanceChart.first?.date) }}
                                    {{ formatDepotAccountEndpoint(depotPerformanceChart.first) }} <DataSourceMarkers :markers="marketDataMarkers(depotPerformanceChart.first)" />
                                    –
                                    {{ formatDepotPerformanceDate(depotPerformanceChart.latest?.date) }}
                                    {{ formatDepotAccountEndpoint(depotPerformanceChart.latest) }} <DataSourceMarkers :markers="marketDataMarkers(depotPerformanceChart.latest)" />
                                </p>
                                <p v-if="!depotPerformanceResult()" class="text-caption text-medium-emphasis mb-2" aria-label="Missing depot performance baseline">
                                    Full-period gain unavailable: the opening or latest valuation is missing.
                                </p>
                                <DataSourceMarkers :markers="marketDataMarkers(depotPerformanceChart.points)" />

                                <svg
                                    v-if="depotPerformanceChart.points.length"
                                    class="depot-performance-chart"
                                    :viewBox="`0 0 ${depotPerformanceChart.width} ${depotPerformanceChart.height}`"
                                    role="img"
                                    aria-label="Depot performance chart from January 1 to now"
                                >
                                    <line
                                        v-for="(gridLine, index) in depotPerformanceChart.horizontalGridLines"
                                        :key="`depot-performance-grid-${index}`"
                                        class="index-price-chart-grid-line"
                                        :x1="depotPerformanceChart.plot.left"
                                        :y1="gridLine.y"
                                        :x2="depotPerformanceChart.plot.right"
                                        :y2="gridLine.y"
                                    />
                                    <line
                                        v-for="(marker, index) in depotPerformanceChart.verticalGridLines"
                                        :key="`depot-performance-marker-${index}`"
                                        class="index-price-chart-grid-line"
                                        :x1="marker.x"
                                        :y1="depotPerformanceChart.plot.top"
                                        :x2="marker.x"
                                        :y2="depotPerformanceChart.plot.bottom"
                                    />
                                    <line
                                        class="index-price-chart-axis"
                                        :x1="depotPerformanceChart.plot.left"
                                        :y1="depotPerformanceChart.plot.bottom"
                                        :x2="depotPerformanceChart.plot.right"
                                        :y2="depotPerformanceChart.plot.bottom"
                                    />
                                    <line
                                        class="index-price-chart-axis"
                                        :x1="depotPerformanceChart.plot.left"
                                        :y1="depotPerformanceChart.plot.top"
                                        :x2="depotPerformanceChart.plot.left"
                                        :y2="depotPerformanceChart.plot.bottom"
                                    />
                                    <text
                                        v-for="(gridLine, index) in depotPerformanceChart.horizontalGridLines"
                                        :key="`depot-performance-y-label-${index}`"
                                        class="index-price-chart-y-label"
                                        :x="depotPerformanceChart.plot.left - 8"
                                        :y="gridLine.y"
                                        text-anchor="end"
                                    >
                                        {{ gridLine.label }}
                                    </text>
                                    <text
                                        v-for="(marker, index) in depotPerformanceChart.verticalGridLines"
                                        :key="`depot-performance-x-label-${index}`"
                                        class="index-price-chart-x-label"
                                        :x="marker.x"
                                        :y="depotPerformanceChart.height - 18"
                                        :text-anchor="marker.labelAnchor ?? 'middle'"
                                    >
                                        {{ marker.label }}
                                    </text>
                                    <line
                                        v-if="depotPerformanceChart.trendLine"
                                        class="index-price-chart-trend-line"
                                        :x1="depotPerformanceChart.trendLine.x1"
                                        :y1="depotPerformanceChart.trendLine.y1"
                                        :x2="depotPerformanceChart.trendLine.x2"
                                        :y2="depotPerformanceChart.trendLine.y2"
                                    />
                                    <polyline
                                        v-for="(segment, index) in depotPerformanceChart.lineSegments"
                                        :key="`depot-performance-segment-${index}`"
                                        class="depot-performance-chart-line"
                                        :points="segment"
                                    />
                                    <circle
                                        class="depot-performance-source-point"
                                        v-for="point in depotPerformanceChart.points"
                                        :key="'depot-source-' + point.date"
                                        :cx="point.x" :cy="point.y" r="6" fill="transparent" pointer-events="all"
                                    ><title>{{ marketDataSourceText(point) }} · {{ point.date }} · {{ formatDepotPerformanceEndpoint(point) }}</title></circle>
                                    <circle
                                        v-if="depotPerformanceChart.first"
                                        class="index-price-chart-point"
                                        :cx="depotPerformanceChart.first.x"
                                        :cy="depotPerformanceChart.first.y"
                                        r="4"
                                    ><title>{{ marketDataSourceText(depotPerformanceChart.first) }} · {{ depotPerformanceChart.first.as_of || depotPerformanceChart.first.date || depotPerformanceChart.first.trading_date || '' }}</title></circle>
                                    <circle
                                        v-if="depotPerformanceChart.latest"
                                        class="index-price-chart-point"
                                        :cx="depotPerformanceChart.latest.x"
                                        :cy="depotPerformanceChart.latest.y"
                                        r="4"
                                    ><title>{{ marketDataSourceText(depotPerformanceChart.latest) }} · {{ depotPerformanceChart.latest.as_of || depotPerformanceChart.latest.date || depotPerformanceChart.latest.trading_date || '' }}</title></circle>
                                    <template v-if="depotPerformanceChart.highMarker">
                                        <line
                                            class="depot-performance-chart-extremum-line depot-performance-chart-extremum-line--high"
                                            :x1="depotPerformanceChart.highMarker.x"
                                            :y1="depotPerformanceChart.highMarker.y"
                                            :x2="depotPerformanceChart.highMarker.markerX"
                                            :y2="depotPerformanceChart.highMarker.markerY"
                                        />
                                        <circle
                                            class="depot-performance-chart-extremum-point depot-performance-chart-extremum-point--high"
                                            :cx="depotPerformanceChart.highMarker.x"
                                            :cy="depotPerformanceChart.highMarker.y"
                                            r="5"
                                        ><title>{{ marketDataSourceText(depotPerformanceChart.highMarker) }} · {{ depotPerformanceChart.highMarker.as_of || depotPerformanceChart.highMarker.date || depotPerformanceChart.highMarker.trading_date || '' }}</title></circle>
                                        <text
                                            class="depot-performance-chart-extremum-label depot-performance-chart-extremum-label--high"
                                            :x="depotPerformanceChart.highMarker.labelX"
                                            :y="depotPerformanceChart.highMarker.labelY"
                                            :text-anchor="depotPerformanceChart.highMarker.labelAnchor"
                                        >
                                            High {{ formatDepotPerformanceEndpoint(depotPerformanceChart.highMarker) }} <tspan>{{ marketDataSourceText(depotPerformanceChart.highMarker) }}</tspan>
                                        </text>
                                    </template>
                                    <template v-if="depotPerformanceChart.lowMarker">
                                        <line
                                            class="depot-performance-chart-extremum-line depot-performance-chart-extremum-line--low"
                                            :x1="depotPerformanceChart.lowMarker.x"
                                            :y1="depotPerformanceChart.lowMarker.y"
                                            :x2="depotPerformanceChart.lowMarker.markerX"
                                            :y2="depotPerformanceChart.lowMarker.markerY"
                                        />
                                        <circle
                                            class="depot-performance-chart-extremum-point depot-performance-chart-extremum-point--low"
                                            :cx="depotPerformanceChart.lowMarker.x"
                                            :cy="depotPerformanceChart.lowMarker.y"
                                            r="5"
                                        ><title>{{ marketDataSourceText(depotPerformanceChart.lowMarker) }} · {{ depotPerformanceChart.lowMarker.as_of || depotPerformanceChart.lowMarker.date || depotPerformanceChart.lowMarker.trading_date || '' }}</title></circle>
                                        <text
                                            class="depot-performance-chart-extremum-label depot-performance-chart-extremum-label--low"
                                            :x="depotPerformanceChart.lowMarker.labelX"
                                            :y="depotPerformanceChart.lowMarker.labelY"
                                            :text-anchor="depotPerformanceChart.lowMarker.labelAnchor"
                                        >
                                            Low {{ formatDepotPerformanceEndpoint(depotPerformanceChart.lowMarker) }} <tspan>{{ marketDataSourceText(depotPerformanceChart.lowMarker) }}</tspan>
                                        </text>
                                    </template>
                                    <text
                                        v-if="depotPerformanceChart.firstLabel"
                                        class="index-price-chart-endpoint-label"
                                        :x="depotPerformanceChart.firstLabel.labelX"
                                        :y="depotPerformanceChart.firstLabel.labelY"
                                        :text-anchor="depotPerformanceChart.firstLabel.labelAnchor"
                                    >
                                        {{ formatDepotPerformanceEndpoint(depotPerformanceChart.firstLabel) }} <tspan>{{ marketDataSourceText(depotPerformanceChart.firstLabel) }}</tspan>
                                    </text>
                                    <text
                                        v-if="depotPerformanceChart.latestLabel"
                                        class="index-price-chart-endpoint-label index-price-chart-endpoint-label--latest"
                                        :x="depotPerformanceChart.latestLabel.labelX"
                                        :y="depotPerformanceChart.latestLabel.labelY"
                                        :text-anchor="depotPerformanceChart.latestLabel.labelAnchor"
                                    >
                                        {{ formatDepotPerformanceEndpoint(depotPerformanceChart.latestLabel) }} <tspan>{{ marketDataSourceText(depotPerformanceChart.latestLabel) }}</tspan>
                                    </text>
                                </svg>
                                <p v-else class="text-medium-emphasis text-body-2 mb-0">
                                    No chart data available.
                                </p>
                            </v-card-text>
                        </v-card>

                        <v-alert v-if="!activeDepot" type="info" variant="tonal" density="compact" class="mt-4">
                            No active depot found.
                        </v-alert>
                        </template>

                        <template v-if="activeDepotSubsection === 'cash'">
                        <div v-if="activeDepot">
                            <v-card
                                class="cash-balance-card mb-6"
                                variant="outlined"
                                width="100%"
                                max-width="480"
                            >
                                <v-card-text class="d-flex align-center justify-space-between ga-4">
                                    <div>
                                        <p class="text-overline text-medium-emphasis mb-1">Cash</p>
                                        <h2 class="text-subtitle-1 font-weight-medium">Current cash balance</h2>
                                    </div>
                                    <div class="text-h5 font-weight-medium text-primary text-right">
                                        {{ formatDepotCashBalance() }}
                                    </div>
                                </v-card-text>
                            </v-card>

                            <div class="cash-ledger-section">
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
                                    v-for="tx in paginatedCashLedgerTransactions"
                                    :key="`mobile-transaction-${tx.id}`"
                                    class="mobile-cash-ledger-card"
                                >
                                    <div class="mobile-cash-ledger-row">
                                        <span>
                                            <v-chip :color="transactionTypeColor(tx.type)" density="comfortable" size="x-small" variant="tonal">
                                                {{ transactionTypeLabel(tx.type) }}
                                            </v-chip>
                                        </span>
                                        <span class="cash-ledger-date-control">
                                            <button
                                                type="button"
                                                class="cash-ledger-date-button"
                                                :disabled="transactionsLoading"
                                                :aria-label="`Edit transaction date ${formatTransactionDate(tx.booked_at)}`"
                                                @click="openTransactionDatePicker(tx.id, 'mobile')"
                                            >
                                                <span>{{ formatTransactionDate(tx.booked_at) }}</span>
                                                <v-icon icon="mdi-calendar-edit" size="x-small" />
                                            </button>
                                            <input
                                                :id="transactionDateInputId(tx.id, 'mobile')"
                                                class="cash-ledger-date-picker"
                                                type="date"
                                                :value="transactionDateInputValue(tx.booked_at)"
                                                :disabled="transactionsLoading"
                                                tabindex="-1"
                                                aria-label="Transaction date"
                                                @change="updateTransactionDate(tx, $event.target.value)"
                                            >
                                        </span>
                                    </div>
                                    <div v-if="tx.stock_label" class="mobile-cash-ledger-stock">
                                        <div>{{ tx.stock_label }}</div>
                                        <div v-if="tx.stock_isin" class="cash-ledger-stock-isin">{{ tx.stock_isin }}</div>
                                    </div>
                                    <div v-if="tx.note" class="cash-ledger-note">
                                        {{ tx.note }}
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
                                        <th class="text-right">Cash effect</th>
                                        <th class="text-right">Balance</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="tx in paginatedCashLedgerTransactions" :key="tx.id">
                                        <td class="text-caption text-medium-emphasis">
                                            <span class="cash-ledger-date-control">
                                                <button
                                                    type="button"
                                                    class="cash-ledger-date-button"
                                                    :disabled="transactionsLoading"
                                                    :aria-label="`Edit transaction date ${formatTransactionDate(tx.booked_at)}`"
                                                    @click="openTransactionDatePicker(tx.id, 'desktop')"
                                                >
                                                    <span>{{ formatTransactionDate(tx.booked_at) }}</span>
                                                    <v-icon icon="mdi-calendar-edit" size="x-small" />
                                                </button>
                                                <input
                                                    :id="transactionDateInputId(tx.id, 'desktop')"
                                                    class="cash-ledger-date-picker"
                                                    type="date"
                                                    :value="transactionDateInputValue(tx.booked_at)"
                                                    :disabled="transactionsLoading"
                                                    tabindex="-1"
                                                    aria-label="Transaction date"
                                                    @change="updateTransactionDate(tx, $event.target.value)"
                                                >
                                            </span>
                                        </td>
                                        <td>
                                            <v-chip :color="transactionTypeColor(tx.type)" density="comfortable" size="x-small" variant="tonal">
                                                {{ transactionTypeLabel(tx.type) }}
                                            </v-chip>
                                        </td>
                                        <td>
                                            <div v-if="tx.stock_label">{{ tx.stock_label }}</div>
                                            <div v-else-if="!tx.note">–</div>
                                            <div v-if="tx.stock_isin" class="cash-ledger-stock-isin">{{ tx.stock_isin }}</div>
                                            <div v-if="tx.note" class="cash-ledger-note">{{ tx.note }}</div>
                                        </td>
                                        <td class="text-right">{{ tx.pieces != null ? Math.trunc(Number(tx.pieces)) : '–' }}</td>
                                        <td class="text-right" :class="Number(tx.cash_delta) >= 0 ? 'text-success' : 'text-error'">
                                            {{ formatCashDelta(tx.cash_delta) }}
                                        </td>
                                        <td class="text-right">{{ formatAccountBalance(tx.balance_after) }}</td>
                                    </tr>
                                </tbody>
                            </v-table>
                            <div
                                v-if="cashLedgerPageCount > 1"
                                class="cash-ledger-pagination d-flex justify-center mt-3"
                            >
                                <v-pagination
                                    v-model="cashLedgerPage"
                                    :length="cashLedgerPageCount"
                                    :total-visible="smAndDown ? 3 : 7"
                                    density="comfortable"
                                />
                            </div>
                            <p v-if="transactions.length === 0 && !transactionsLoading" class="text-medium-emphasis text-body-2 mt-2">No transactions yet.</p>
                            </div>
                        </div>

                        <v-alert v-else type="info" variant="tonal" density="compact" class="mt-4">
                            No active depot found.
                        </v-alert>
                        </template>

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
                                        <div class="text-h6">{{ canInitializePassword ? 'Setup required' : 'Protected' }}</div>
                                        <div class="text-body-2 text-medium-emphasis">
                                            {{ canInitializePassword
                                                ? 'Set a password while this emailed-code session is still recent.'
                                                : 'Change the password used for admin login.' }}
                                        </div>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-btn color="primary" prepend-icon="mdi-lock-reset" variant="tonal" @click="openPasswordDialog">
                                            {{ canInitializePassword ? 'Set password' : 'Edit password' }}
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
                                <v-card-title>{{ canInitializePassword ? 'Set password' : 'Edit password' }}</v-card-title>
                                <v-card-text>
                                    <v-alert v-if="canInitializePassword" class="mb-4" type="warning" variant="tonal">
                                        This one-time setup is available only briefly after signing in with an emailed code.
                                    </v-alert>
                                    <form id="profile-password-form" @submit.prevent="savePassword">
                                        <v-text-field
                                            v-if="!canInitializePassword"
                                            v-model="currentPassword"
                                            autocomplete="current-password"
                                            label="Current password"
                                            maxlength="1024"
                                            required
                                            type="password"
                                        />
                                        <v-text-field
                                            v-model="newPassword"
                                            autocomplete="new-password"
                                            hint="Use at least 12 characters with upper and lower case letters, a number, and a symbol."
                                            label="New password"
                                            maxlength="1024"
                                            minlength="12"
                                            persistent-hint
                                            required
                                            type="password"
                                        />
                                        <v-text-field
                                            v-model="newPasswordConfirmation"
                                            autocomplete="new-password"
                                            label="Confirm new password"
                                            maxlength="1024"
                                            minlength="12"
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

.dashboard-version-page {
    max-width: 920px;
}

.dashboard-performance-section {
    margin-top: 16px;
}

.dashboard-performance-heading {
    align-items: center;
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
}

.dashboard-performance-title {
    color: rgb(var(--v-theme-on-surface));
    font-size: 1.15rem;
    font-weight: 750;
    line-height: 1.25;
}

.dashboard-performance-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(5, minmax(0, 1fr));
}

.dashboard-performance-sum-grid {
    display: grid;
    gap: 10px;
    grid-template-columns: repeat(5, minmax(0, 1fr));
    margin-top: 10px;
}

.dashboard-performance-card {
    background: rgb(var(--v-theme-surface));
    border-color: rgba(var(--v-border-color), var(--v-border-opacity));
    min-width: 0;
}

.dashboard-performance-card-heading {
    align-items: flex-start;
    display: flex;
    gap: 8px;
    justify-content: space-between;
}

.dashboard-performance-card-label {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.72rem;
    font-weight: 650;
}

.dashboard-performance-card-date {
    color: rgba(var(--v-theme-on-surface), 0.52);
    font-size: 0.65rem;
    margin-top: 2px;
}

.dashboard-performance-amount {
    font-size: 1.05rem;
    font-weight: 750;
    line-height: 1.2;
    margin-top: 14px;
}

.dashboard-performance-percent {
    font-size: 0.76rem;
    font-weight: 650;
    margin-top: 3px;
}

.dashboard-version-card {
    background: rgb(var(--v-theme-surface));
    border-color: rgba(var(--v-border-color), var(--v-border-opacity));
    min-height: 104px;
    padding: 14px 16px;
}

.dashboard-version-heading {
    align-items: flex-start;
    display: flex;
    gap: 12px;
    justify-content: space-between;
}

.dashboard-version-title {
    color: rgb(var(--v-theme-on-surface));
    font-size: 0.95rem;
    font-weight: 750;
    line-height: 1.25;
    margin: 1px 0 0;
}

.dashboard-current-version {
    color: rgb(var(--v-theme-primary));
    font-size: 1.35rem;
    font-weight: 750;
    line-height: 1.1;
    margin-top: 5px;
}

.dashboard-version-toggle {
    border-radius: 9px;
    font-size: 0.68rem;
    letter-spacing: 0;
    margin-top: 1px;
    text-transform: none;
}

.dashboard-version-actions {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    justify-content: flex-end;
}

.dashboard-reload-button {
    text-transform: none;
}

.dashboard-version-details {
    margin-top: 12px;
}

.dashboard-version-grid {
    display: grid;
    gap: 6px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.dashboard-version-item {
    background: rgba(var(--v-theme-on-surface), 0.045);
    border-radius: 8px;
    min-width: 0;
    padding: 8px 10px;
}

.dashboard-version-item span,
.dashboard-version-item strong {
    display: block;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.dashboard-version-item span {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.61rem;
}

.dashboard-version-item strong {
    font-size: 0.73rem;
    margin-top: 2px;
}

.dashboard-navigation-drawer {
    transition: width 0.2s ease;
}

.info-page-heading {
    align-items: flex-start;
    display: flex;
    gap: 24px;
    justify-content: space-between;
}

.info-summary-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(4, minmax(0, 1fr));
}

.info-summary-card strong {
    display: block;
    font-size: 1.75rem;
    line-height: 1.2;
    margin-top: 6px;
}

.info-table :deep(table) {
    min-width: 1180px;
}

.info-table th:nth-child(1) {
    width: 17%;
}

.info-table th:nth-child(2) {
    width: 21%;
}

.info-table th:nth-child(3) {
    width: 22%;
}

.info-table th:nth-child(4) {
    width: 20%;
}

.info-table th:nth-child(5) {
    width: 20%;
}

.info-table td {
    line-height: 1.45;
    padding-bottom: 14px !important;
    padding-top: 14px !important;
    vertical-align: top;
}

.info-api-path,
.info-queue-job,
.info-table-name {
    overflow-wrap: anywhere;
}

.info-api-path,
.info-queue-job {
    display: block;
    font-size: 0.75rem;
    white-space: normal;
}

.info-documentation-links {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
}

.info-method-grid {
    display: grid;
    gap: 16px;
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.info-method-card {
    height: 100%;
}

.info-method-section + .info-method-section {
    margin-top: 20px;
}

.info-method-list {
    display: grid;
    gap: 8px;
    margin: 0;
    padding-left: 20px;
}

.info-method-chain {
    list-style: none;
    padding-left: 0;
}

.info-method-chain-item {
    border-left: 2px solid rgba(var(--v-border-color), var(--v-border-opacity));
    display: grid;
    gap: 4px;
    padding-left: 12px;
}

.info-method-layer {
    justify-self: start;
}

.info-method-namespace {
    overflow-wrap: anywhere;
}

@media (max-width: 959px) {
    .dashboard-version-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .info-page-heading {
        align-items: stretch;
        flex-direction: column;
    }

    .info-summary-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .info-method-grid {
        grid-template-columns: minmax(0, 1fr);
    }
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

.watch-list-section {
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 8px;
    overflow: hidden;
}

.watch-list-section-header {
    align-items: center;
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    display: flex;
    gap: 12px;
    justify-content: space-between;
    padding: 12px 14px;
}

.watch-list-section-eyebrow {
    color: rgba(var(--v-theme-on-surface), 0.58);
    font-size: 0.68rem;
    font-weight: 650;
    line-height: 1.1;
    text-transform: uppercase;
}

.watch-list-section-title {
    color: rgba(var(--v-theme-on-surface), 0.9);
    font-size: 1rem;
    font-weight: 650;
    line-height: 1.25;
    margin: 0;
}

.watch-list-section-title-row {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin-top: 2px;
}

.watch-list-live-badge {
    align-items: center;
    background: linear-gradient(180deg, #ff2d2d 0%, #b80000 100%);
    border: 1px solid rgba(255, 255, 255, 0.38);
    border-radius: 4px;
    box-shadow: 0 0 0 1px rgba(184, 0, 0, 0.3), 0 6px 14px rgba(184, 0, 0, 0.26);
    color: #fff;
    display: inline-flex;
    font-size: 0.66rem;
    font-weight: 900;
    gap: 5px;
    letter-spacing: 0;
    line-height: 1;
    padding: 4px 7px;
    text-transform: uppercase;
}

.watch-list-live-dot {
    animation: watch-list-live-pulse 1.2s ease-in-out infinite;
    background: #fff;
    border-radius: 999px;
    box-shadow: 0 0 8px rgba(255, 255, 255, 0.9);
    height: 6px;
    width: 6px;
}

.latest-price-live-badge {
    font-size: 0.58rem;
    padding: 3px 5px;
}

.latest-price-live-badge .watch-list-live-dot {
    height: 5px;
    width: 5px;
}

@keyframes watch-list-live-pulse {
    0%,
    100% {
        opacity: 1;
        transform: scale(1);
    }

    50% {
        opacity: 0.48;
        transform: scale(0.72);
    }
}

.watch-list-section-count {
    align-items: center;
    background: rgba(var(--v-theme-primary), 0.08);
    border-radius: 999px;
    color: rgb(var(--v-theme-primary));
    display: inline-flex;
    font-size: 0.78rem;
    font-weight: 700;
    justify-content: center;
    min-width: 34px;
    padding: 4px 10px;
}

.desktop-watch-list-table :deep(th),
.desktop-watch-list-table :deep(td) {
    padding-inline: 6px !important;
}

.desktop-watch-list-table :deep(th:first-child),
.desktop-watch-list-table :deep(td:first-child) {
    padding-left: 12px !important;
}

.desktop-watch-list-table :deep(th:last-child),
.desktop-watch-list-table :deep(td:last-child) {
    padding-right: 12px !important;
}

.desktop-watch-list-table :deep(table) {
    table-layout: auto;
    width: 100%;
}

.desktop-watch-list-table :deep(.stock-price-chart-row > td) {
    background: rgba(var(--v-theme-surface-variant), 0.22);
    padding: 12px !important;
}

.stock-price-chart-mobile-target {
    min-width: 0;
    width: 100%;
}

.watch-list-content-cell {
    white-space: nowrap;
    width: 1%;
}

.watch-list-source-time-cell {
    white-space: nowrap;
    width: 100%;
}

.watch-list-actions-cell {
    min-width: 132px;
    text-align: right;
    white-space: nowrap;
    width: 132px;
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
.mobile-depot-year-stocks,
.mobile-depot-stocks,
.mobile-watch-list {
    display: none;
}

.cash-ledger-date-control {
    align-items: center;
    display: inline-flex;
    gap: 4px;
    position: relative;
}

.cash-ledger-date-button {
    align-items: center;
    background: transparent;
    border: 0;
    color: inherit;
    cursor: pointer;
    display: inline-flex;
    font: inherit;
    gap: 4px;
    padding: 0;
    text-decoration: underline;
    text-decoration-style: dotted;
    text-underline-offset: 3px;
}

.cash-ledger-date-button:disabled {
    cursor: default;
    opacity: 0.62;
}

.cash-ledger-date-picker {
    height: 1px;
    opacity: 0;
    position: absolute;
    right: 0;
    top: 100%;
    width: 1px;
}

.cash-ledger-section {
    max-width: 920px;
}

.cash-ledger-stock-isin,
.depot-stock-isin,
.mobile-depot-stock-isin {
    color: rgba(var(--v-theme-on-surface), 0.62);
    font-size: 0.78rem;
    font-weight: 400;
    line-height: 1.2;
    margin-top: 2px;
}

.cash-ledger-note {
    color: rgba(var(--v-theme-on-surface), 0.72);
    font-size: 0.82rem;
    line-height: 1.35;
    margin-top: 4px;
    overflow-wrap: anywhere;
}

@media (max-width: 960px) {
    .dashboard-performance-grid,
    .dashboard-performance-sum-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}

@media (max-width: 600px) {
    .dashboard-performance-grid,
    .dashboard-performance-sum-grid {
        grid-template-columns: minmax(0, 1fr);
    }

    .dashboard-version-heading {
        align-items: stretch;
        flex-direction: column;
    }

    .dashboard-version-toggle {
        align-self: flex-start;
    }

    .dashboard-version-grid {
        grid-template-columns: minmax(0, 1fr);
    }

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

    .desktop-depot-year-stocks-table {
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

    .mobile-depot-year-stocks {
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
    }

    .mobile-depot-year-stock-card {
        background: rgb(var(--v-theme-surface));
        border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
        border-radius: 8px;
        display: grid;
        gap: 10px;
        grid-template-columns: minmax(0, 1fr);
        padding: 12px;
    }

    .mobile-depot-year-stock-name {
        color: rgb(var(--v-theme-primary));
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.25;
        overflow-wrap: anywhere;
    }

    .mobile-depot-year-stock-row {
        align-items: center;
        display: flex;
        gap: 12px;
        justify-content: space-between;
    }

    .mobile-depot-year-stock-row > span:last-child {
        text-align: right;
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
        padding: 12px;
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

    .mobile-stock-card--selected {
        border-color: rgb(var(--v-theme-primary));
        box-shadow: 0 0 0 1px rgb(var(--v-theme-primary));
    }

    .mobile-stock-card:focus-visible {
        outline: 2px solid rgb(var(--v-theme-primary));
        outline-offset: 2px;
    }

    .mobile-stock-name {
        color: rgb(var(--v-theme-primary));
        font-size: 0.95rem;
        font-weight: 600;
        line-height: 1.25;
    }

    .mobile-stock-position {
        color: rgba(var(--v-theme-on-surface), var(--v-medium-emphasis-opacity));
        font-size: 0.78rem;
        line-height: 1.2;
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

    .mobile-stock-price-main {
        align-items: baseline;
        display: inline-flex;
        flex-wrap: wrap;
        gap: 6px;
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

.analyze-selected-stock-isin-copy {
    align-items: center;
    background: rgba(20, 91, 75, 0.06);
    border: 1px solid rgba(20, 91, 75, 0.18);
    border-radius: 4px;
    color: #145b4b;
    cursor: pointer;
    display: inline-flex;
    font-size: 0.72rem;
    font-weight: 800;
    gap: 4px;
    line-height: 1;
    padding: 4px 6px;
}

.analyze-selected-stock-isin-copy--copied {
    background: rgba(var(--v-theme-success), 0.12);
    border-color: rgba(var(--v-theme-success), 0.45);
    color: rgb(var(--v-theme-success));
}

.tests-chip-groups {
    display: grid;
    gap: 18px;
}

@keyframes live-data-updating-dot-pulse {
    0%,
    100% {
        box-shadow: 0 0 0 0 rgba(var(--v-theme-error), 0.55);
        transform: scale(1);
    }

    50% {
        box-shadow: 0 0 0 4px rgba(var(--v-theme-error), 0.2);
        transform: scale(1.28);
    }
}

@keyframes live-data-updating-ring-pulse {
    0% {
        opacity: 0.8;
        transform: scale(1);
    }

    100% {
        opacity: 0;
        transform: scale(2.7);
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

.index-watch-card:focus-visible {
    outline: 2px solid rgb(var(--v-theme-primary));
    outline-offset: 2px;
}

.index-watch-card:hover {
    border-color: rgb(var(--v-theme-primary));
}

.index-watch-card--active {
    border-color: rgb(var(--v-theme-primary));
    box-shadow: 0 0 0 1px rgb(var(--v-theme-primary));
}

.index-watch-card--market-status {
    height: 190px;
}

.index-watch-card-session {
    color: rgba(var(--v-theme-on-surface), 0.72);
    display: inline-flex;
    flex-direction: column;
    font-size: 0.625rem;
    font-weight: 600;
    line-height: 1.15;
    white-space: nowrap;
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

.stock-subtitle {
    color: rgb(var(--v-theme-info));
    font-weight: 500;
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

.index-price-inline-card {
    overflow: hidden;
}

.index-price-range-tabs {
    border-bottom: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
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
    overflow-x: auto;
    padding: 16px;
}

.live-update-status-dot {
    background: currentColor;
    border-radius: 9999px;
    display: inline-block;
    height: 0.45rem;
    margin-right: 0.35rem;
    width: 0.45rem;
}

.depot-performance-card {
    max-width: 100%;
}

.depot-performance-chart {
    display: block;
    height: 560px;
    width: 100%;
}

.depot-performance-chart-line {
    fill: none;
    stroke: rgb(var(--v-theme-success));
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 3;
    vector-effect: non-scaling-stroke;
}

.depot-performance-chart-extremum-line {
    stroke-dasharray: 3 5;
    stroke-width: 1.4;
    vector-effect: non-scaling-stroke;
}

.depot-performance-chart-extremum-line--high {
    stroke: rgb(var(--v-theme-success));
}

.depot-performance-chart-extremum-line--low {
    stroke: rgb(var(--v-theme-error));
}

.depot-performance-chart-extremum-point {
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
}

.depot-performance-chart-extremum-point--high {
    fill: rgb(var(--v-theme-success));
}

.depot-performance-chart-extremum-point--low {
    fill: rgb(var(--v-theme-error));
}

.depot-performance-chart-extremum-label {
    dominant-baseline: middle;
    font-size: 11px;
    font-weight: 800;
    paint-order: stroke;
    stroke: rgb(var(--v-theme-surface));
    stroke-linejoin: round;
    stroke-width: 4;
}

.depot-performance-chart-extremum-label--high {
    fill: rgb(var(--v-theme-success));
}

.depot-performance-chart-extremum-label--low {
    fill: rgb(var(--v-theme-error));
}

.index-price-chart {
    display: block;
    height: clamp(360px, 52vw, 520px);
    min-width: 920px;
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

.stock-price-chart-line--positive,
.index-price-chart-line--positive {
    stroke: rgb(var(--v-theme-success));
}

.stock-price-chart-line--negative,
.index-price-chart-line--negative {
    stroke: rgb(var(--v-theme-error));
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

.stock-holding-row--selected td {
    background: rgba(var(--v-theme-primary), 0.08);
    border-bottom: 2px solid rgb(var(--v-theme-primary)) !important;
    border-top: 2px solid rgb(var(--v-theme-primary)) !important;
}

.stock-holding-row--selected td:first-child {
    border-left: 2px solid rgb(var(--v-theme-primary));
}

.stock-holding-row--selected td:last-child {
    border-right: 2px solid rgb(var(--v-theme-primary));
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

.holding-intraday-chart-panel {
    background: rgb(var(--v-theme-surface));
    border: 1px solid rgba(var(--v-border-color), var(--v-border-opacity));
    border-radius: 4px;
    overflow-x: auto;
    padding: 10px;
}

.holding-intraday-chart {
    display: block;
    height: 260px;
    min-width: 720px;
    width: 100%;
}

.holding-intraday-chart-axis {
    stroke: rgba(var(--v-theme-on-surface), 0.18);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-grid-line {
    stroke: rgba(var(--v-theme-on-surface), 0.09);
    stroke-width: 1;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-label {
    dominant-baseline: middle;
    fill: rgba(var(--v-theme-on-surface), 0.58);
    font-size: 11px;
    font-weight: 600;
}

.holding-intraday-chart-line {
    fill: none;
    stroke: rgb(var(--v-theme-primary));
    stroke-linecap: round;
    stroke-linejoin: round;
    stroke-width: 2.4;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-point {
    fill: rgb(var(--v-theme-primary));
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 1.4;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-extremum {
    color: rgb(var(--v-theme-primary));
}

.holding-intraday-chart-extremum--high {
    color: rgb(var(--v-theme-success));
}

.holding-intraday-chart-extremum--low {
    color: rgb(var(--v-theme-error));
}

.holding-intraday-chart-extremum-line {
    stroke: currentColor;
    stroke-dasharray: 2 3;
    stroke-linecap: round;
    stroke-width: 1.2;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-extremum-ring {
    fill: rgb(var(--v-theme-surface));
    stroke: currentColor;
    stroke-width: 1.8;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-extremum-dot {
    fill: currentColor;
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 0.7;
    vector-effect: non-scaling-stroke;
}

.holding-intraday-chart-extremum-label {
    dominant-baseline: middle;
    fill: currentColor;
    font-size: 11px;
    font-weight: 750;
    paint-order: stroke;
    stroke: rgb(var(--v-theme-surface));
    stroke-linejoin: round;
    stroke-width: 3.5;
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

.handset-landscape-price {
    align-items: center;
    display: inline-flex;
    gap: 8px;
}

.mobile-price-loaded-at {
    color: rgba(var(--v-theme-on-surface), 0.58);
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1;
}

.latest-price-value.text-white .mobile-price-loaded-at {
    color: rgba(255, 255, 255, 0.82);
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

.recent-price-trend-dot--day {
    box-shadow:
        0 0 0 2px rgb(var(--v-theme-surface)),
        0 0 0 3px rgba(var(--v-theme-on-surface), 0.16);
    flex: 0 0 auto;
    height: 10px;
    width: 10px;
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
