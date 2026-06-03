<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useAuthStore } from './stores/auth';
import { useDepotStore } from './stores/depots';
import { useRoleStore } from './stores/roles';
import { useUserStore } from './stores/users';

const auth = useAuthStore();
const depotsStore = useDepotStore();
const usersStore = useUserStore();
const rolesStore = useRoleStore();

const { user, loading, notice, error } = storeToRefs(auth);
const {
    activeDepot,
    depots,
    holdings,
    priceRefresh,
    stockSearchResults,
    pagination: depotPagination,
    holdingsPagination,
    loading: depotsLoading,
    holdingsLoading,
    stockSearchLoading,
    error: depotsError,
    holdingsError,
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
const isDeleteHoldingDialogOpen = ref(false);
const selectedHolding = ref(null);
const holdingSearchQuery = ref('');
const holdingMessage = ref('');
const holdingError = ref('');
const priceRefreshTimer = ref(null);

const isLoginPage = computed(() => window.location.pathname === '/admin/login');
const canManageUsers = computed(() => user.value?.roles?.includes('super_admin') ?? false);
const profileDisplayName = computed(() => user.value?.name || 'Loading...');
const roleList = computed(() => user.value?.roles?.join(', ') ?? '');
const isPriceRefreshRunning = computed(() => ['queued', 'running'].includes(priceRefresh.value?.status));
const priceRefreshProgressValue = computed(() => {
    if (!priceRefresh.value || priceRefresh.value.total === 0) {
        return 0;
    }

    return Math.round((priceRefresh.value.processed / priceRefresh.value.total) * 100);
});

const menuItems = computed(() => [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: 'mdi-view-dashboard-outline',
    },
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

onMounted(async () => {
    if (isLoginPage.value) {
        return;
    }

    await auth.loadUser();
    applyRouteFromPath();

    await depotsStore.loadActiveDepot();
    if (activeDepot.value) {
        await depotsStore.loadActiveDepotHoldings();
    }

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
    window.removeEventListener('popstate', applyRouteFromPath);
});

function navigateSection(section) {
    activeSection.value = section;
    clearSectionMessages();
    updateUrlPath();

    if (section === 'users') {
        usersStore.loadUsers(userPagination.value.current_page);
    }

    if (section === 'roles') {
        rolesStore.loadRoles(rolePagination.value.current_page);
    }

    if (section === 'depots') {
        depotsStore.loadDepots(depotPagination.value.current_page);
    }
}

function applyRouteFromPath() {
    const path = window.location.pathname.replace(/\/+$/, '') || '/admin';

    if (path === '/admin/profile') {
        activeSection.value = 'profile';

        return;
    }

    if (path.startsWith('/admin/menu/')) {
        const section = decodeURIComponent(path.replace('/admin/menu/', ''));
        activeSection.value = menuItems.value.some((item) => item.key === section) ? section : 'dashboard';

        return;
    }

    activeSection.value = 'dashboard';
}

function updateUrlPath() {
    const path = activeSection.value === 'dashboard'
        ? '/admin/dashboard'
        : activeSection.value === 'profile'
            ? '/admin/profile'
            : `/admin/menu/${activeSection.value}`;

    if (window.location.pathname === path) {
        return;
    }

    window.history.pushState({}, '', path);
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
        await depotsStore.loadActiveDepotHoldings();
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
}

function abortHoldingDialog() {
    isHoldingDialogOpen.value = false;
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

async function saveHolding(result) {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.createActiveDepotHolding(result);
        holdingMessage.value = data.message;
        isHoldingDialogOpen.value = false;
        await depotsStore.loadActiveDepotHoldings(holdingsPagination.value.current_page);
    } catch (err) {
        holdingError.value = err.message;
    }
}

async function refreshHoldingPrices() {
    holdingError.value = '';
    holdingMessage.value = '';

    try {
        const data = await depotsStore.refreshActiveDepotHoldingPrices();
        holdingMessage.value = data.message;

        if (isFinishedPriceRefresh(data.refresh)) {
            await finishPriceRefresh(data.refresh);

            return;
        }

        startPriceRefreshPolling(data.refresh.refresh_id);
    } catch (err) {
        holdingError.value = err.message;
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

async function pollPriceRefreshStatus(refreshId) {
    try {
        const data = await depotsStore.loadActiveDepotHoldingPriceRefresh(refreshId);
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
    holdingMessage.value = refresh.error || refresh.message;
    await depotsStore.loadActiveDepotHoldings(holdingsPagination.value.current_page);
}

function isFinishedPriceRefresh(refresh) {
    return ['finished', 'failed'].includes(refresh?.status);
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
        const data = await depotsStore.deleteActiveDepotHolding(selectedHolding.value.id);
        holdingMessage.value = data.message;
        abortDeleteHoldingDialog();
        await depotsStore.loadActiveDepotHoldings(holdingsPagination.value.current_page);
    } catch (err) {
        holdingError.value = err.message;
    }
}

function formatAccountBalance(value) {
    return new Intl.NumberFormat('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number(value ?? 0));
}

function formatLatestPrice(holding) {
    if (holding.latest_price_status === 'stale') {
        return 'Stale';
    }

    if (holding.latest_price === null || holding.latest_price === undefined || holding.latest_price === '') {
        return holding.latest_price_fetched_at ? 'Unavailable' : '-';
    }

    const amount = Number(holding.latest_price);
    const formattedAmount = Number.isNaN(amount)
        ? holding.latest_price
        : new Intl.NumberFormat('en-US', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 6,
        }).format(amount);

    return holding.currency ? `${formattedAmount} ${holding.currency}` : formattedAmount;
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

function formatTradingTimes(holding) {
    return holding.trading_times || '-';
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

function clearSectionMessages() {
    profileMessage.value = '';
    profileError.value = '';
    depotMessage.value = '';
    depotError.value = '';
    holdingMessage.value = '';
    holdingError.value = '';
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
            <v-navigation-drawer permanent width="272">
                <div class="pa-6">
                    <div class="d-flex align-center ga-3">
                        <v-avatar color="primary" size="40">
                            <span>STK</span>
                        </v-avatar>
                        <div>
                            <strong>Stocks</strong>
                            <div class="text-caption text-medium-emphasis">{{ roleList }}</div>
                        </div>
                    </div>
                </div>

                <v-list nav>
                    <v-list-item
                        v-for="item in menuItems"
                        :key="item.key"
                        :active="activeSection === item.key"
                        :prepend-icon="item.icon"
                        :title="item.label"
                        @click="navigateSection(item.key)"
                    />
                </v-list>
            </v-navigation-drawer>

            <v-app-bar flat border>
                <v-app-bar-title>{{ profileDisplayName }}</v-app-bar-title>
                <v-spacer />
                <v-btn href="/admin/logout" prepend-icon="mdi-logout" variant="text">
                    Logout
                </v-btn>
            </v-app-bar>

            <v-main>
                <v-container class="py-8">
                    <section v-if="activeSection === 'dashboard'">
                        <div class="d-flex align-center justify-space-between mb-6">
                            <div>
                                <p class="text-overline text-primary mb-1">Active depot</p>
                                <h1 class="text-h4">{{ activeDepot?.name ?? 'No active depot' }}</h1>
                            </div>
                            <div class="d-flex align-center ga-2">
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-refresh"
                                    variant="tonal"
                                    :disabled="!activeDepot || holdings.length === 0 || isPriceRefreshRunning"
                                    :loading="holdingsLoading && !isPriceRefreshRunning"
                                    @click="refreshHoldingPrices"
                                >
                                    {{ isPriceRefreshRunning ? `Refreshing ${priceRefresh.step}` : 'Refresh prices' }}
                                </v-btn>
                                <v-btn
                                    color="primary"
                                    prepend-icon="mdi-plus"
                                    variant="flat"
                                    :disabled="!activeDepot"
                                    @click="openHoldingDialog"
                                >
                                    Add stock
                                </v-btn>
                            </div>
                        </div>

                        <v-alert v-if="holdingMessage" type="success" variant="tonal" density="compact" class="mb-4">
                            {{ holdingMessage }}
                        </v-alert>
                        <v-alert
                            v-if="priceRefresh"
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
                        <v-alert v-if="!activeDepot" type="info" variant="tonal" density="compact" class="mb-4">
                            Activate a depot before adding stocks.
                        </v-alert>

                        <v-table>
                            <thead>
                                <tr>
                                    <th>Symbol</th>
                                    <th>Name</th>
                                    <th>Instrument</th>
                                    <th>Latest price</th>
                                    <th>Status</th>
                                    <th>Source time</th>
                                    <th>Trading times</th>
                                    <th>Source</th>
                                    <th class="text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-if="activeDepot && !holdingsLoading && holdings.length === 0">
                                    <td colspan="9">No stocks in this depot.</td>
                                </tr>
                                <tr v-for="holding in holdings" :key="holding.id">
                                    <td>{{ holding.symbol || '-' }}</td>
                                    <td>{{ holding.name || '-' }}</td>
                                    <td>
                                        <div>{{ holding.isin || '-' }}</div>
                                        <div class="text-caption text-medium-emphasis">
                                            WKN: {{ holding.wkn || '-' }}
                                        </div>
                                        <div class="text-caption text-medium-emphasis">
                                            Exchange: {{ holding.exchange || '-' }}
                                        </div>
                                    </td>
                                    <td>{{ formatLatestPrice(holding) }}</td>
                                    <td :title="formatValidationErrors(holding)">
                                        <div>{{ formatPriceStatus(holding) }}</div>
                                        <div class="text-caption text-medium-emphasis">
                                            {{ formatPriceType(holding) }} · {{ holding.venue || '-' }}
                                        </div>
                                        <div class="text-caption text-medium-emphasis">
                                            Spread: {{ formatSpread(holding) }}
                                        </div>
                                    </td>
                                    <td>{{ formatSourceDateTime(holding.latest_price_as_of) }}</td>
                                    <td>{{ formatTradingTimes(holding) }}</td>
                                    <td>
                                        <a
                                            v-if="holding.latest_price_source_url"
                                            :href="holding.latest_price_source_url"
                                            rel="noopener noreferrer"
                                            target="_blank"
                                        >
                                            {{ formatLatestPriceSource(holding) }}
                                        </a>
                                        <span v-else>{{ formatLatestPriceSource(holding) }}</span>
                                    </td>
                                    <td class="text-right">
                                        <v-btn
                                            icon
                                            variant="text"
                                            color="error"
                                            aria-label="Delete stock"
                                            :disabled="holdingsLoading"
                                            @click="openDeleteHoldingDialog(holding)"
                                        >
                                            <v-icon icon="mdi-delete-outline" />
                                        </v-btn>
                                    </td>
                                </tr>
                            </tbody>
                        </v-table>

                        <v-progress-linear v-if="holdingsLoading" indeterminate color="primary" class="mt-4" />

                        <v-pagination
                            v-if="holdingsPagination.last_page > 1"
                            v-model="holdingsPagination.current_page"
                            class="mt-6"
                            :length="holdingsPagination.last_page"
                            @update:model-value="depotsStore.loadActiveDepotHoldings"
                        />

                        <v-dialog v-model="isHoldingDialogOpen" persistent max-width="900">
                            <v-card>
                                <v-card-title>Add stock</v-card-title>
                                <v-card-text>
                                    <form id="holding-search-form" class="d-flex align-center ga-3 mb-5" @submit.prevent="searchStocks">
                                        <v-text-field
                                            v-model="holdingSearchQuery"
                                            density="comfortable"
                                            hide-details
                                            label="ISIN, WKN, symbol, or name"
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

                        <v-dialog v-model="isDeleteHoldingDialogOpen" persistent max-width="440">
                            <v-card>
                                <v-card-title>Delete stock</v-card-title>
                                <v-card-text>Delete {{ selectedHolding?.name || selectedHolding?.symbol }}?</v-card-text>
                                <v-card-actions>
                                    <v-spacer />
                                    <v-btn type="button" variant="text" :disabled="holdingsLoading" @click="abortDeleteHoldingDialog">
                                        Cancel
                                    </v-btn>
                                    <v-btn type="button" color="error" variant="flat" :loading="holdingsLoading" @click="deleteHolding">
                                        Delete
                                    </v-btn>
                                </v-card-actions>
                            </v-card>
                        </v-dialog>
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
