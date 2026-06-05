<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useDisplay } from 'vuetify';
import { storeToRefs } from 'pinia';
import { useAuthStore } from './stores/auth';
import { useDepotStore } from './stores/depots';
import { useRoleStore } from './stores/roles';
import { useUserStore } from './stores/users';

const indexRecentPriceLimit = 30;

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
    eodhdApiUsage,
    priceRefresh,
    priceRefreshSettings,
    indexPriceRefreshSettings,
    queueStatus,
    stockHistoricalPriceCoverage,
    stockHistoricalPriceRefresh,
    uiPreferences,
    stockSearchResults,
    pagination: depotPagination,
    holdingsPagination,
    loading: depotsLoading,
    holdingsLoading,
    transactionsLoading,
    exchangeTradingTimesLoading,
    queueStatusLoading,
    stockSearchLoading,
    error: depotsError,
    holdingsError,
    transactionsError,
    exchangeTradingTimesError,
    queueStatusError,
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
const activeAnalyzeSubsection = ref('overview');
const selectedAnalyzeHistoryRange = ref('1y');
const selectedAnalyzeHoldingId = ref(null);
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
const priceRefreshScheduleMessage = ref('');
const priceRefreshScheduleError = ref('');
const isPriceRefreshScheduleEditing = ref(false);
const isIndexPriceRefreshScheduleEditing = ref(false);
const priceRefreshTimer = ref(null);
const priceRefreshSettingsTimer = ref(null);
const isPriceRefreshSettingsPolling = ref(false);
const historicalPriceFetchTimer = ref(null);
const isHistoricalPriceFetchPolling = ref(false);
const stockHistoricalPriceFetchTimer = ref(null);
const isStockHistoricalPriceFetchPolling = ref(false);
const isStockHistoricalPriceEnsureLoading = ref(false);
const isAnalyzeHistoryInfoDismissed = ref(false);
const isDashboardMenuCompact = ref(false);
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
const dashboardMenuToggleLabel = computed(() => (isDashboardMenuCompact.value
    ? 'Enhance dashboard menu'
    : 'Minify dashboard menu'));
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
const isStockHistoricalPriceFetchRunning = computed(() => ['queued', 'running'].includes(stockHistoricalPriceRefresh.value?.status));
const showAnalyzeHistoryProgress = computed(() => isStockHistoricalPriceEnsureLoading.value || isStockHistoricalPriceFetchRunning.value);
const showAnalyzeHistoryResultInfo = computed(() => !showAnalyzeHistoryProgress.value
    && !isAnalyzeHistoryInfoDismissed.value
    && Boolean(stockHistoricalPriceCoverage.value || stockHistoricalPriceRefresh.value));
const showAnalyzeHistoryStatus = computed(() => showAnalyzeHistoryProgress.value || showAnalyzeHistoryResultInfo.value);
const stockHistoricalPriceStoredCount = computed(() => Number(stockHistoricalPriceRefresh.value?.stored_count ?? 0));
const stockHistoricalPriceResultMessage = computed(() => {
    const recordCount = stockHistoricalPriceStoredCount.value;
    const recordLabel = recordCount === 1 ? 'record' : 'records';

    return `${formatInteger(recordCount)} historical price ${recordLabel} loaded/updated.`;
});
const stockHistoricalPriceStatusLabel = computed(() => {
    if (isStockHistoricalPriceFetchRunning.value) {
        return [
            stockHistoricalPriceRefresh.value?.step,
            stockHistoricalPriceRefresh.value?.current,
        ].filter(Boolean).join(' · ');
    }

    return '';
});
const stockHistoricalPriceFetchProgressValue = computed(() => {
    if (!stockHistoricalPriceRefresh.value || stockHistoricalPriceRefresh.value.total === 0) {
        return 0;
    }

    return Math.round((stockHistoricalPriceRefresh.value.processed / stockHistoricalPriceRefresh.value.total) * 100);
});
const sessionHeaderDates = computed(() => ({
    yesterday: formatSessionHeaderDate(1),
    dayBeforeYesterday: formatSessionHeaderDate(2),
}));
const selectedIndexRecentPrices = computed(() => selectedIndexWatchItem.value?.recent_prices ?? []);
const selectedIndexChart = computed(() => buildIndexPriceChart(selectedIndexRecentPrices.value));
const selectedAnalyzeHolding = computed(() => holdings.value.find((holding) => holding.id === selectedAnalyzeHoldingId.value) ?? null);
const selectedAnalyzeDailyPrices = computed(() => filterAnalyzeDailyPrices(
    selectedAnalyzeHolding.value?.daily_prices ?? [],
    selectedAnalyzeHistoryRange.value,
));
const selectedAnalyzeSparkline = computed(() => buildAnalyzeSparkline(selectedAnalyzeDailyPrices.value));
const showAnalyzeSparklineDots = computed(() => ['3m', '1m', '1w'].includes(selectedAnalyzeHistoryRange.value));
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
const analyzeSubmenuItems = [
    {
        key: 'overview',
        label: 'Overview',
        icon: 'mdi-view-grid-outline',
    },
    {
        key: 'detail',
        label: 'Detail',
        icon: 'mdi-chart-box-outline',
    },
];
const analyzeHistoryRangeItems = [
    {
        key: '1y',
        label: '1 year',
    },
    {
        key: '6m',
        label: '6 months',
    },
    {
        key: '3m',
        label: '3 months',
    },
    {
        key: '1m',
        label: '1 month',
    },
    {
        key: '1w',
        label: '1 week',
    },
    {
        key: 'today',
        label: 'today',
    },
];
const analyzeHistoryRangeDays = {
    '1y': 365,
    '6m': 183,
    '3m': 92,
    '1m': 31,
    '1w': 7,
    today: 0,
};

const menuItems = computed(() => [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: 'mdi-view-dashboard-outline',
    },
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
    },
);

watch(
    [activeSection, activeAnalyzeSubsection],
    ([section, subsection]) => {
        if (section === 'analyze' && subsection === 'overview') {
            ensureAnalyzeOverviewHistoricalPrices();
        }
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

onMounted(async () => {
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
        depotsStore.loadQueueStatus(),
    ]);
    ensureAnalyzeOverviewHistoricalPrices();
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
    stopPriceRefreshSettingsPolling();
    stopHistoricalPriceFetchPolling();
    stopStockHistoricalPriceFetchPolling();
    stopHoldingDialogKeyboardShortcuts();
    window.removeEventListener('popstate', applyRouteFromPath);
});

function navigateSection(section) {
    activeSection.value = section;

    if (section === 'analyze' && !isAnalyzeSubsection(activeAnalyzeSubsection.value)) {
        activeAnalyzeSubsection.value = 'overview';
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

            return;
        }

        const isTopLevel = menuItems.value.some((item) => item.key === normalizedSection);
        const isChild = menuItems.value.flatMap((item) => item.children ?? []).some((child) => child.key === normalizedSection);
        activeSection.value = (isTopLevel || isChild) ? normalizedSection : 'dashboard';

        return;
    }

    activeSection.value = 'dashboard';
}

function updateUrlPath() {
    const path = activeSection.value === 'dashboard'
        ? '/admin/dashboard'
        : activeSection.value === 'profile'
            ? '/admin/profile'
            : activeSection.value === 'analyze'
                ? `/admin/menu/analyze/${activeAnalyzeSubsection.value}`
                : `/admin/menu/${activeSection.value}`;

    if (window.location.pathname === path) {
        return;
    }

    window.history.pushState({}, '', path);
}

function isAnalyzeSubsection(subsection) {
    return analyzeSubmenuItems.some((item) => item.key === subsection);
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

function exportHoldingsPdf() {
    window.open('/admin/watchlist/holdings/pdf', '_blank', 'noopener');
}

function openCashTransactionDialog(type) {
    cashTransactionForm.value = {
        type,
        total_amount: '',
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

async function ensureAnalyzeOverviewHistoricalPrices() {
    if (activeSection.value !== 'analyze' || activeAnalyzeSubsection.value !== 'overview') {
        return;
    }

    if (isStockHistoricalPriceEnsureLoading.value || isStockHistoricalPriceFetchRunning.value) {
        return;
    }

    isStockHistoricalPriceEnsureLoading.value = true;
    isAnalyzeHistoryInfoDismissed.value = false;

    try {
        const data = await depotsStore.ensureStockHistoricalPrices();
        await depotsStore.loadQueueStatus();

        if (data.refresh && !isFinishedPriceRefresh(data.refresh)) {
            startStockHistoricalPriceFetchPolling(data.refresh.refresh_id);

            return;
        }

        stopStockHistoricalPriceFetchPolling();
    } catch (err) {
        holdingError.value = err.message;
    } finally {
        isStockHistoricalPriceEnsureLoading.value = false;
    }
}

function startStockHistoricalPriceFetchPolling(refreshId) {
    stopStockHistoricalPriceFetchPolling();
    pollStockHistoricalPriceFetch(refreshId);
    stockHistoricalPriceFetchTimer.value = window.setInterval(() => pollStockHistoricalPriceFetch(refreshId), 3000);
}

function stopStockHistoricalPriceFetchPolling() {
    if (!stockHistoricalPriceFetchTimer.value) {
        return;
    }

    window.clearInterval(stockHistoricalPriceFetchTimer.value);
    stockHistoricalPriceFetchTimer.value = null;
}

async function pollStockHistoricalPriceFetch(refreshId) {
    if (isStockHistoricalPriceFetchPolling.value) {
        return;
    }

    isStockHistoricalPriceFetchPolling.value = true;

    try {
        const data = await depotsStore.loadStockHistoricalPriceRefresh(refreshId);

        if (isFinishedPriceRefresh(data.refresh)) {
            stopStockHistoricalPriceFetchPolling();
        }
    } catch (err) {
        stopStockHistoricalPriceFetchPolling();
        holdingError.value = err.message;
    } finally {
        isStockHistoricalPriceFetchPolling.value = false;
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

function formatPriceValue(value, currency) {
    if (value === null || value === undefined || value === '') {
        return '-';
    }

    const amount = Number(value);
    const formattedAmount = Number.isNaN(amount)
        ? value
        : new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 6,
        }).format(amount);

    if (!currency || currency === 'EUR') {
        return formattedAmount;
    }

    return `${formattedAmount} ${currency}`;
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
        if (holding.latest_price_status === 'stale') {
            return 'Stale';
        }

        return holding.latest_price_fetched_at ? 'Unavailable' : '-';
    }

    return formatPriceValue(holding.latest_price, holding.currency);
}

function formatHoldingCardPrice(holding) {
    if (holding.latest_price === null || holding.latest_price === undefined || holding.latest_price === '') {
        return formatLatestPrice(holding);
    }

    const amount = Number(holding.latest_price);
    const formattedAmount = Number.isNaN(amount)
        ? holding.latest_price
        : new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount);

    return holding.currency ? `${formattedAmount} ${holding.currency}` : formattedAmount;
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
    const maximumFractionDigits = Math.abs(value) >= 100 ? 2 : 4;

    return new Intl.NumberFormat('en-US', {
        maximumFractionDigits,
        minimumFractionDigits: 0,
    }).format(value);
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
        right: 18,
        bottom: 44,
        left: 74,
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
    };
}

function filterAnalyzeDailyPrices(prices, rangeKey) {
    const chartPrices = [...prices]
        .map((price) => ({
            ...price,
            chart_price: analyzeDailyPriceValue(price),
        }))
        .filter((price) => price.trading_date && !Number.isNaN(price.chart_price))
        .sort((first, second) => first.trading_date.localeCompare(second.trading_date));

    if (chartPrices.length === 0) {
        return [];
    }

    const latestDate = chartPrices[chartPrices.length - 1].trading_date;
    const days = analyzeHistoryRangeDays[rangeKey] ?? analyzeHistoryRangeDays['1y'];
    const startDate = dateStringDaysBefore(latestDate, days);

    return chartPrices.filter((price) => price.trading_date >= startDate);
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

function buildAnalyzeSparkline(prices) {
    const width = 1440;
    const height = 600;
    const plot = {
        left: 104,
        top: 38,
        right: width - 40,
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
            first: null,
            latest: null,
            highMarker: null,
            lowMarker: null,
            min: null,
            max: null,
            trend: 'flat',
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
    const plotWidth = plot.right - plot.left;
    const plotHeight = plot.bottom - plot.top;
    const points = chartPrices.map((price, index) => {
        const x = chartPrices.length === 1
            ? plot.left + plotWidth / 2
            : plot.left + (index / (chartPrices.length - 1)) * plotWidth;
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

    return {
        width,
        height,
        plot,
        points,
        linePath: analyzeSparklinePath(points),
        areaPath: analyzeSparklineAreaPath(points, plot),
        horizontalGridLines: analyzeSparklineHorizontalGridLines(chartMin, chartMax, plot),
        verticalGridLines: analyzeSparklineTickPoints(points).map((point) => ({
            x: point.x,
            label: formatAnalyzeSparklineDate(point),
        })),
        first: points[0],
        latest: points[points.length - 1],
        highMarker: analyzeSparklineExtremumMarker(highPoint, 'high', plot),
        lowMarker: analyzeSparklineExtremumMarker(lowPoint, 'low', plot),
        min,
        max,
        trend: points[points.length - 1].chart_price > points[0].chart_price
            ? 'up'
            : (points[points.length - 1].chart_price < points[0].chart_price ? 'down' : 'flat'),
    };
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
        return `M ${points[0].x.toFixed(2)} ${points[0].y.toFixed(2)}`;
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

function formatAnalyzeSparklineDate(point) {
    if (!point?.trading_date) {
        return '-';
    }

    return formatIndexHistoryDate(point.trading_date);
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

function formatSessionPriceChangePercent(value, holding) {
    if (
        value === null
        || value === undefined
        || value === ''
        || holding.latest_price === null
        || holding.latest_price === undefined
        || holding.latest_price === ''
    ) {
        return '';
    }

    const referencePrice = Number(value);
    const latestPrice = Number(holding.latest_price);

    if (Number.isNaN(referencePrice) || Number.isNaN(latestPrice) || referencePrice === 0) {
        return '';
    }

    const amount = ((latestPrice - referencePrice) / referencePrice) * 100;
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

function sessionPriceChangeClass(value, holding) {
    const formattedChange = formatSessionPriceChangePercent(value, holding);

    if (formattedChange.startsWith('+')) {
        return 'text-success';
    }

    if (formattedChange.startsWith('-')) {
        return 'text-error';
    }

    return 'text-medium-emphasis';
}

function toggleHoldingDetails(holding) {
    const holdingId = holding.id;

    expandedHoldingIds.value = expandedHoldingIds.value.includes(holdingId)
        ? expandedHoldingIds.value.filter((expandedHoldingId) => expandedHoldingId !== holdingId)
        : [...expandedHoldingIds.value, holdingId];
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

function formatSessionPrice(value, holding) {
    return formatPriceValue(value, holding.currency);
}

function formatRecentStoredPrice(recentPrice, holding) {
    return formatPriceValue(recentPrice.price, recentPrice.currency ?? holding.currency);
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
        note: '',
    };
}

function emptyStockTransactionForm() {
    return {
        type: 'buy',
        stock_holding_id: null,
        pieces: '',
        total_amount: '',
        note: '',
    };
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
</script>

<template>
    <v-app>
        <template v-if="isLoginPage">
            <v-main class="login-screen">
                <v-container class="login-container">
                    <v-card class="login-card" elevation="0">
                        <v-card-title>Stocks admin</v-card-title>
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
                :width="isDashboardMenuCompact ? 88 : 272"
            >
                <div class="pa-6">
                    <div
                        class="d-flex align-center ga-3"
                        :class="{ 'justify-center': isDashboardMenuCompact }"
                    >
                        <v-avatar color="primary" size="40">
                            <span>STK</span>
                        </v-avatar>
                        <div v-if="!isDashboardMenuCompact">
                            <strong>Stocks</strong>
                            <div class="text-caption text-medium-emphasis">{{ profileDisplayName }}</div>
                        </div>
                    </div>
                </div>

                <v-list nav>
                    <template v-for="item in menuItems" :key="item.key">
                        <v-list-item
                            :active="item.children ? item.children.some(c => activeSection === c.key) : activeSection === item.key"
                            :prepend-icon="item.icon"
                            :title="isDashboardMenuCompact ? undefined : item.label"
                            :subtitle="isDashboardMenuCompact ? undefined : (item.subtitle ?? undefined)"
                            @click="item.children ? navigateSection(item.children[0].key) : navigateSection(item.key)"
                        />
                    </template>
                </v-list>
            </v-navigation-drawer>

            <v-app-bar flat border>
                <div class="app-bar-row">
                    <span v-if="priceRefreshSettings" class="app-bar-group text-caption text-medium-emphasis">
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
                    <span v-if="indexPriceRefreshSettings" class="app-bar-group text-caption text-medium-emphasis">
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
                        v-if="eodhdUsageItems.length"
                        class="app-bar-group text-caption text-medium-emphasis eodhd-header-usage"
                    >
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
                        <span class="font-weight-medium">EODHD API</span>
                        <span v-for="item in eodhdUsageItems" :key="item.key">
                            {{ item.label }} {{ formatInteger(item.usage.remaining) }} / {{ formatInteger(item.usage.limit) }}
                            Used {{ formatInteger(item.usage.used) }} · Reset {{ formatEodhdUsageReset(item.usage.reset_at) }}
                        </span>
                    </span>
                    <span
                        class="app-bar-group text-caption queue-header-status"
                        :class="queueStatusClass"
                        :title="queueStatusTitle"
                    >
                        <span
                            class="price-refresh-status-dot"
                            :class="queueStatus?.status === 'ok' && !queueStatusError ? 'price-refresh-status-dot--waiting' : 'price-refresh-status-dot--updating'"
                        />
                        {{ queueStatusLabel }}
                    </span>
                    <v-btn href="/admin/logout" prepend-icon="mdi-logout" size="small" variant="text">
                        Logout
                    </v-btn>
                </div>
            </v-app-bar>

            <v-main>
                <v-container class="py-8" :fluid="lgAndDown">
                    <section v-if="activeSection === 'dashboard'">
                        <div class="d-flex align-center justify-space-between mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Dashboard</p>
                                <h1 class="text-h4">Watch-list</h1>
                            </div>
                            <div class="d-flex align-center ga-2">
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-file-pdf-box"
                                    variant="outlined"
                                    :disabled="holdings.length === 0"
                                    @click="exportHoldingsPdf"
                                >
                                    Export PDF
                                </v-btn>
                                <v-btn
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
                        <v-table>
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Name</th>
                                    <th>Latest price</th>
                                    <th>Start price</th>
                                    <th>
                                        <span class="d-inline-flex flex-column">
                                            <span>End price</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.yesterday }}
                                            </span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="d-inline-flex flex-column">
                                            <span>Start 24</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.yesterday }}
                                            </span>
                                        </span>
                                    </th>
                                    <th>
                                        <span class="d-inline-flex flex-column">
                                            <span>Start 48</span>
                                            <span class="text-caption text-medium-emphasis">
                                                {{ sessionHeaderDates.dayBeforeYesterday }}
                                            </span>
                                        </span>
                                    </th>
                                    <th>Source time</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="!holdingsLoading && holdings.length === 0">
                                    <td colspan="9">No stocks in the watch-list.</td>
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
                                        <td>{{ holding.symbol || '-' }}</td>
                                        <td>
                                            <div>{{ holding.name || '-' }}</div>
                                            <div class="text-caption text-medium-emphasis">
                                                {{ holding.isin || '-' }} · WKN: {{ holding.wkn || '-' }}
                                            </div>
                                            <div class="text-caption text-medium-emphasis">
                                                Exchange: {{ holding.exchange || '-' }}
                                            </div>
                                            <div class="text-caption text-medium-emphasis">
                                                Pieces: {{ formatPositionPieces(holding) }}
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
                                        <td>
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
                                        <td>{{ formatSessionPrice(holding.start_price, holding) }}</td>
                                        <td>{{ formatSessionPrice(holding.end_price, holding) }}</td>
                                        <td>
                                            <span class="d-inline-flex flex-column">
                                                <span>{{ formatSessionPrice(holding.start_price_24, holding) }}</span>
                                                <span
                                                    v-if="formatSessionPriceChangePercent(holding.start_price_24, holding)"
                                                    class="session-price-change"
                                                    :class="sessionPriceChangeClass(holding.start_price_24, holding)"
                                                >
                                                    {{ formatSessionPriceChangePercent(holding.start_price_24, holding) }}
                                                </span>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="d-inline-flex flex-column">
                                                <span>{{ formatSessionPrice(holding.start_price_48, holding) }}</span>
                                                <span
                                                    v-if="formatSessionPriceChangePercent(holding.start_price_48, holding)"
                                                    class="session-price-change"
                                                    :class="sessionPriceChangeClass(holding.start_price_48, holding)"
                                                >
                                                    {{ formatSessionPriceChangePercent(holding.start_price_48, holding) }}
                                                </span>
                                            </span>
                                        </td>
                                        <td>
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
                                        <td colspan="9">
                                            <div
                                                v-if="holding.recent_prices?.length"
                                                class="recent-price-strip d-flex flex-wrap ga-2"
                                            >
                                                <span
                                                    v-for="recentPrice in recentPricesForExpandedHolding(holding)"
                                                    :key="recentPrice.id"
                                                    class="recent-price-item"
                                                >
                                                    <span class="font-weight-medium">
                                                        {{ formatRecentStoredPrice(recentPrice, holding) }}
                                                    </span>
                                                    <span class="text-caption text-medium-emphasis">
                                                        {{ formatRecentStoredPriceTime(recentPrice) }}
                                                    </span>
                                                    <span
                                                        class="recent-price-trend text-caption font-weight-bold"
                                                        :class="recentStoredPriceTrendClass(recentPrice)"
                                                    >
                                                        {{ recentStoredPriceTrendSymbol(recentPrice) }}
                                                    </span>
                                                </span>
                                            </div>
                                            <span v-else class="text-body-2 text-medium-emphasis">
                                                No stored prices in the last 24 hours.
                                            </span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </v-table>

                        <v-sheet
                            v-if="exchangeTradingTimes.length || exchangeTradingTimesLoading || exchangeTradingTimesError"
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
                                            <div class="text-caption text-medium-emphasis">
                                                {{ exchange.timezone || '-' }}
                                            </div>
                                        </td>
                                        <td>{{ exchange.operating_mic || '-' }}</td>
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
                            <v-alert
                                v-if="showAnalyzeHistoryStatus"
                                class="mb-4 analyze-history-status"
                                :closable="showAnalyzeHistoryResultInfo"
                                density="compact"
                                type="info"
                                variant="tonal"
                                @click:close="isAnalyzeHistoryInfoDismissed = true"
                            >
                                <div class="d-flex align-center justify-space-between flex-wrap ga-3">
                                    <span>
                                        <template v-if="showAnalyzeHistoryProgress">
                                            Checking historical prices
                                        </template>
                                        <template v-else>
                                            {{ stockHistoricalPriceResultMessage }}
                                        </template>
                                    </span>
                                    <span v-if="stockHistoricalPriceStatusLabel" class="text-caption">
                                        {{ stockHistoricalPriceStatusLabel }}
                                    </span>
                                </div>
                                <v-progress-linear
                                    v-if="isStockHistoricalPriceEnsureLoading || isStockHistoricalPriceFetchRunning"
                                    class="mt-2"
                                    color="primary"
                                    height="6"
                                    rounded
                                    :indeterminate="isStockHistoricalPriceEnsureLoading || stockHistoricalPriceRefresh?.status === 'queued'"
                                    :model-value="stockHistoricalPriceFetchProgressValue"
                                />
                            </v-alert>
                            <div class="index-watch-strip">
                                <button
                                    type="button"
                                    class="index-watch-card analyze-holding-card analyze-holding-card--all"
                                    :class="{ 'analyze-holding-card--active': selectedAnalyzeHoldingId === null }"
                                    :aria-pressed="selectedAnalyzeHoldingId === null"
                                    @click="selectedAnalyzeHoldingId = null"
                                >
                                    <span class="index-watch-card-symbol">ALL</span>
                                </button>
                                <button
                                    v-for="holding in holdings"
                                    :key="holding.id"
                                    type="button"
                                    class="index-watch-card analyze-holding-card"
                                    :class="{ 'analyze-holding-card--active': selectedAnalyzeHoldingId === holding.id }"
                                    :aria-pressed="selectedAnalyzeHoldingId === holding.id"
                                    @click="selectedAnalyzeHoldingId = holding.id"
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
                                    <path
                                        class="analyze-sparkline-area"
                                        :d="selectedAnalyzeSparkline.areaPath"
                                    />
                                    <path
                                        class="analyze-sparkline-line"
                                        :class="`analyze-sparkline-line--${selectedAnalyzeSparkline.trend}`"
                                        :d="selectedAnalyzeSparkline.linePath"
                                    />
                                    <template v-if="showAnalyzeSparklineDots">
                                        <circle
                                            v-for="(point, index) in selectedAnalyzeSparkline.points"
                                            :key="`analyze-point-dot-${point.trading_date || index}`"
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
                                </svg>
                                <div v-else class="text-body-2 text-medium-emphasis">
                                    No stored prices for this range.
                                </div>
                            </div>
                        </section>
                        <section
                            v-if="activeAnalyzeSubsection === 'detail'"
                            class="analyze-dummy-page"
                            aria-label="Analyze detail"
                        />
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
                            <v-card variant="outlined" width="100%" max-width="480">
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Depot balance</td>
                                            <td>{{ formatDepotStockBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Cash balance</td>
                                            <td>{{ formatDepotCashBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Account balance</td>
                                            <td>{{ formatDepotAccountBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Status</td>
                                            <td>
                                                <v-chip color="success" density="comfortable" size="x-small" variant="tonal">Active</v-chip>
                                            </td>
                                        </tr>
                                    </tbody>
                                </v-table>
                            </v-card>

                            <v-card variant="outlined" width="100%" max-width="480">
                                <v-table density="compact">
                                    <tbody>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance 01.01.</td>
                                            <td>{{ formatDepotYearStartBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">Balance {{ formatCurrentDayMonth() }}</td>
                                            <td>{{ formatDepotCurrentBalance() }}</td>
                                        </tr>
                                        <tr>
                                            <td class="text-medium-emphasis text-caption">+/-</td>
                                            <td>
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
                            <v-table v-if="depotHoldings.length > 0" density="compact">
                                <thead>
                                    <tr>
                                        <th>Symbol</th>
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
                                        <th class="text-right">1.1.</th>
                                        <th class="text-right">Change</th>
                                        <th class="text-right">+/- EUR</th>
                                        <th class="text-right">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="holding in depotHoldings" :key="holding.id" :class="depotHoldingRowClass(holding)">
                                        <td>{{ holding.symbol || '-' }}</td>
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
                                        <td class="text-right">{{ formatPriceValue(holding.year_start_price, holding.currency) }}</td>
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
                                        <td colspan="8" class="text-right font-weight-bold">Sum</td>
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
                            <v-table v-if="transactions.length > 0" density="compact">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Type</th>
                                        <th>Stock</th>
                                        <th class="text-right">Pieces</th>
                                        <th class="text-right">Amount</th>
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
                                        <td class="text-right">{{ formatAccountBalance(tx.total_amount) }}</td>
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
                                    <td class="text-right">{{ formatAccountBalance(depot.account_balance) }}</td>
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
    gap: 20px;
    justify-content: space-between;
    line-height: 1.3;
    overflow-x: auto;
    padding: 0 16px;
    white-space: nowrap;
    width: 100%;
}

.app-bar-group {
    align-items: center;
    display: inline-flex;
    flex-shrink: 0;
    gap: 6px;
}

.dashboard-menu-toggle {
    flex: 0 0 auto;
}

.dashboard-navigation-drawer {
    transition: width 0.2s ease;
}

.dashboard-navigation-drawer--compact :deep(.v-list-item__prepend) {
    margin-inline-end: 0;
}

.analyze-dummy-page,
.analyze-overview-page {
    min-height: 320px;
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

.analyze-range-menu {
    align-items: center;
    display: flex;
    flex-wrap: wrap;
    gap: 6px;
    margin-top: 10px;
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
    stroke-width: 2.75;
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

.index-price-chart-point {
    fill: rgb(var(--v-theme-primary));
    stroke: rgb(var(--v-theme-surface));
    stroke-width: 2;
    vector-effect: non-scaling-stroke;
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
