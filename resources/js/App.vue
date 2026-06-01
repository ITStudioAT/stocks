<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useAuthStore } from './stores/auth';
import { useAnalysisStore } from './stores/analyses';
import { useClientStore } from './stores/clients';
import { useCompanyStore } from './stores/companies';
import { useRoleStore } from './stores/roles';
import { useUserStore } from './stores/users';

const auth = useAuthStore();
const analysesStore = useAnalysisStore();
const clientsStore = useClientStore();
const companiesStore = useCompanyStore();
const rolesStore = useRoleStore();
const usersStore = useUserStore();
const { user, loading, notice, error } = storeToRefs(auth);
const {
    analyses,
    currentAnalysis,
    currentReport,
    loading: analysesLoading,
    error: analysesError,
} = storeToRefs(analysesStore);
const { clients, loading: clientsLoading, error: clientsError } = storeToRefs(clientsStore);
const {
    companies,
    pagination: companyPagination,
    loading: companiesLoading,
    error: companiesError,
} = storeToRefs(companiesStore);
const {
    roles,
    pagination: rolePagination,
    loading: rolesLoading,
    error: rolesError,
} = storeToRefs(rolesStore);
const {
    users,
    roles: userRoles,
    pagination: userPagination,
    loading: usersLoading,
    error: usersError,
} = storeToRefs(usersStore);

const email = ref(auth.email);
const code = ref('');
const step = ref('email');
const loginMode = ref('code');
const loginPassword = ref('');
const localError = ref('');
const activeSection = ref('dashboard');
const activeAdminSection = ref('clients');
const activeFormatSection = ref('format-one');
const activeHelperSection = ref('analyse');
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
const companyDialogMode = ref('create');
const isCompanyDialogOpen = ref(false);
const isDeleteCompanyDialogOpen = ref(false);
const selectedCompany = ref(null);
const companyForm = ref(emptyCompanyForm());
const companyMessage = ref('');
const companyError = ref('');
const roleDialogMode = ref('create');
const isRoleDialogOpen = ref(false);
const isDeleteRoleDialogOpen = ref(false);
const selectedRole = ref(null);
const roleForm = ref(emptyRoleForm());
const roleMessage = ref('');
const roleError = ref('');
const isClientCompanyDialogOpen = ref(false);
const selectedClientCompany = ref(null);
const clientCompanySearch = ref('');
const clientCompanySearchResults = ref([]);
const clientCompanySearchLoading = ref(false);
const clientCompanyMessage = ref('');
const clientCompanyError = ref('');
const clientCompanySearchTimer = ref(null);
const clientCompanyFilterId = ref(null);
const clientCompanyFilterName = ref('');
const isClientDialogOpen = ref(false);
const isDeleteClientDialogOpen = ref(false);
const clientDialogMode = ref('create');
const selectedClient = ref(null);
const clientForm = ref(emptyClientForm());
const clientDeleteConfirmation = ref('');
const clientMessage = ref('');
const clientError = ref('');
const analysisUrl = ref('');
const analysisCheckedUrl = ref(null);
const analysisMessage = ref('');
const analysisError = ref('');
const analysisDetailId = ref(null);
const analysisPollingTimer = ref(null);
const isAnalysisPollingRequestActive = ref(false);
const isAnalysisFormOpen = ref(false);
const isDeleteAnalysisDialogOpen = ref(false);
const selectedAnalysis = ref(null);
const expandedReportPageUrl = ref(null);
const expandedNavigationMenus = ref({});
const reportPageListModes = ref({});
const selectedAnalysisColors = ref([]);
const selectedAnalysisColorsStoragePrefix = 'stocks.analysis.selectedColors';
const selectedAnalysisColorsVersion = ref(0);
const requiredFormatColorsStorageKey = 'stocks.format.requiredColors';
const maximumRequiredFormatColors = 4;
const requiredFormatColors = ref(storedRequiredFormatColors());
const formatColorSchemeModeStorageKey = 'stocks.format.colorSchemeMode';
const formatColorSchemeMode = ref(storedFormatColorSchemeMode());
const savedFormatColorSchemesStorageKey = 'stocks.format.savedColorSchemes';
const savedFormatColorSchemes = ref(storedSavedFormatColorSchemes());
const isFormatSchemeCreationOpen = ref(false);
const isFormatSchemeColorSelectionOpen = ref(false);
const generatedFormatColorScheme = ref(null);
const isGeneratedFormatColorSchemeOpen = ref(true);
const isSaveFormatColorSchemeDialogOpen = ref(false);
const formatColorSchemeName = ref('');
const formatColorSchemeSaveError = ref('');
const formatColorSchemeError = ref('');

const isLoginPage = computed(() => window.location.pathname === '/admin/login');
const roleList = computed(() => user.value?.roles?.join(', ') ?? '');
const publishedClients = computed(() => clients.value.filter((client) => client.is_published).length);
const draftClients = computed(() => clients.value.length - publishedClients.value);
const profileDisplayName = computed(() => user.value?.name || 'Loading...');
const userCompanyName = computed(() => user.value?.selected_company_name ?? user.value?.company_name ?? 'No company assigned');
const canManageUsers = computed(() => user.value?.roles?.includes('super_admin') ?? false);
const activeClient = computed(() => clients.value.find((client) => client.is_active) ?? null);
const activeClientName = computed(() => activeClient.value?.name ?? 'No active client');
const currentReportSummary = computed(() => currentReport?.value?.summary ?? {});
const currentReportHomepage = computed(() => currentReport?.value?.homepage ?? {});
const currentReportHeaderMenu = computed(() => currentReport?.value?.header_menu ?? {});
const currentReportFooterInformation = computed(() => currentReport?.value?.footer_information ?? {});
const currentReportSiteAssets = computed(() => currentReport?.value?.site_assets ?? {});
const currentReportColors = computed(() => currentReport?.value?.colors ?? []);
const currentReportFonts = computed(() => currentReport?.value?.fonts ?? []);
const currentReportPages = computed(() => currentReport?.value?.pages ?? []);
const currentReportErrors = computed(() => currentReport?.value?.errors ?? []);
const currentReportTopColors = computed(() => groupedReportColors(currentReportColors.value).slice(0, 16));
const currentReportTopFonts = computed(() => groupedReportFonts(currentReportFonts.value).slice(0, 10));
const requiredFormatColorCount = computed(() => requiredFormatColors.value.length);
const requiredFormatColorSamples = computed(() => requiredFormatColors.value
    .map((color) => normalizedReportColor(color)?.value)
    .filter(Boolean)
    .slice(0, 8));
const selectedFormatColorSamples = computed(() => {
    selectedAnalysisColorsVersion.value;

    const selectedColors = [];
    const selectedColorValues = new Set();

    analyses.value.forEach((analysis) => {
        storedSelectedAnalysisColors(analysis.id).forEach((color) => {
            const normalizedColor = normalizedReportColor(color);

            if (!normalizedColor || selectedColorValues.has(normalizedColor.value)) {
                return;
            }

            selectedColorValues.add(normalizedColor.value);
            selectedColors.push({
                value: normalizedColor.value,
                analysisUrl: analysis.url,
            });
        });
    });

    return selectedColors.slice(0, 12);
});
const currentReportImages = computed(() => currentReportSiteAssets.value.images ?? []);
const currentReportOtherFiles = computed(() => currentReportSiteAssets.value.other_files ?? []);
const liveAnalysisStatuses = ['queued', 'running'];
const formatColorSchemeModeOptions = [
    {
        key: 'assistant',
        label: 'Assistent',
        icon: 'mdi-auto-fix',
    },
    {
        key: 'custom-color',
        label: 'Individuelle Farbe als Basis',
        icon: 'mdi-eyedropper',
    },
    {
        key: 'selected-colors',
        label: 'Gewählte Farben als Basis',
        icon: 'mdi-palette-outline',
    },
];
const hasSelectedFormatColorSchemeMode = computed(() => {
    return formatColorSchemeModeOptions.some((option) => option.key === formatColorSchemeMode.value);
});
const isFormatSchemeSelectionVisible = computed(() => {
    return isFormatSchemeCreationOpen.value
        && !isFormatSchemeColorSelectionOpen.value
        && !generatedFormatColorScheme.value;
});
const isFormatSchemeColorSelectionVisible = computed(() => {
    return isFormatSchemeCreationOpen.value
        && isFormatSchemeColorSelectionOpen.value
        && formatColorSchemeMode.value === 'selected-colors'
        && !generatedFormatColorScheme.value;
});
const hasLiveAnalyses = computed(() => analyses.value.some((analysis) => isLiveAnalysis(analysis)));
const hasLiveCurrentAnalysis = computed(() => currentAnalysis?.value ? isLiveAnalysis(currentAnalysis.value) : false);
const isCurrentAnalysisReachabilityRunning = computed(() => {
    return hasLiveCurrentAnalysis.value && currentAnalysis?.value?.analysis_step === 'reachability';
});
const shouldPollAnalyses = computed(() => {
    if (activeSection.value !== 'helpers' || activeHelperSection.value !== 'analyse') {
        return false;
    }

    if (analysisDetailId.value) {
        return currentAnalysis?.value ? hasLiveCurrentAnalysis.value : true;
    }

    return hasLiveAnalyses.value;
});

const menuItems = [
    {
        key: 'dashboard',
        label: 'Dashboard',
        icon: 'mdi-view-dashboard-outline',
    },
    {
        key: 'admin',
        label: 'Admin',
        icon: 'mdi-account-cog-outline',
    },
    {
        key: 'formate',
        label: 'Formate',
        icon: 'mdi-format-list-bulleted',
    },
    {
        key: 'helpers',
        label: 'Helpers',
        icon: 'mdi-tools',
    },
    {
        key: 'profile',
        label: 'Profil',
        icon: 'mdi-account-circle-outline',
    },
];

const adminMenuItems = [
    {
        key: 'companies',
        label: 'Unternehmen',
        icon: 'mdi-domain',
    },
    {
        key: 'clients-overview',
        label: 'Clients',
        icon: 'mdi-account-box-multiple-outline',
    },
    {
        key: 'users',
        label: 'Benutzer',
        icon: 'mdi-account-group-outline',
    },
    {
        key: 'roles',
        label: 'Rollen',
        icon: 'mdi-shield-account-outline',
    },
];
const visibleAdminMenuItems = computed(() => adminMenuItems.filter((item) => {
    return !['clients-overview', 'companies', 'users', 'roles'].includes(item.key) || canManageUsers.value;
}));
const helperMenuItems = [
    {
        key: 'analyse',
        label: 'Analyse',
        icon: 'mdi-chart-line',
    },
];
const formatMenuItems = [
    {
        key: 'format-one',
        label: 'Farben',
        icon: 'mdi-format-paint',
    },
    {
        key: 'format-two',
        label: 'Dummy 2',
        icon: 'mdi-format-text',
    },
];
const homepagePreviewNavItems = ['Leistungen', 'Projekte', 'Kontakt'];
const homepagePreviewMetrics = [
    { value: '4.9', label: 'Bewertung' },
    { value: '18', label: 'Jahre Erfahrung' },
    { value: '240+', label: 'Projekte' },
];
const homepagePreviewServices = [
    {
        key: 'concept',
        number: '01',
        title: 'Beratung',
        text: 'Ein klares Erstgespräch macht Budget, Stil und nächste Schritte sichtbar.',
        token: 'support',
    },
    {
        key: 'design',
        number: '02',
        title: 'Planung',
        text: 'Materialien, Raumgefühl und Details werden zu einem stimmigen Konzept verbunden.',
        token: 'accent',
    },
    {
        key: 'delivery',
        number: '03',
        title: 'Umsetzung',
        text: 'Ein eingespieltes Team führt das Projekt sauber bis zur Übergabe.',
        token: 'decorative',
    },
];
const homepagePreviewSteps = [
    'Kostenlosen Termin buchen',
    'Konzept und Angebot erhalten',
    'Projekt mit klarer Timeline starten',
];
const menuItemKeys = computed(() => menuItems.map((item) => item.key));
const visibleAdminMenuItemKeys = computed(() => visibleAdminMenuItems.value.map((item) => item.key));
const helperMenuItemKeys = computed(() => helperMenuItems.map((item) => item.key));
const formatMenuItemKeys = computed(() => formatMenuItems.map((item) => item.key));

watch(
    user,
    (currentUser) => {
        profileLastName.value = currentUser?.last_name ?? '';
        profileFirstName.value = currentUser?.first_name ?? '';
    },
    { immediate: true },
);

watch(clientCompanySearch, (search) => {
    if (!isClientCompanyDialogOpen.value) {
        return;
    }

    const searchTerm = search.trim();

    if (clientCompanySearchTimer.value) {
        clearTimeout(clientCompanySearchTimer.value);
    }

    if (selectedClientCompany.value?.company_name_1 !== searchTerm) {
        selectedClientCompany.value = null;
    }

    if (searchTerm.length < 3) {
        clientCompanySearchResults.value = [];

        return;
    }

    clientCompanySearchTimer.value = setTimeout(async () => {
        clientCompanySearchLoading.value = true;
        clientCompanyError.value = '';

        try {
            const data = await clientsStore.searchCompanies(searchTerm);
            clientCompanySearchResults.value = data.companies;
        } catch (err) {
            clientCompanyError.value = err.message;
        } finally {
            clientCompanySearchLoading.value = false;
        }
    }, 250);
});

watch(analysisUrl, () => {
    analysisCheckedUrl.value = null;
    analysisMessage.value = '';
    analysisError.value = '';
});

watch(shouldPollAnalyses, (shouldPoll) => {
    if (shouldPoll) {
        startAnalysisPolling();

        return;
    }

    stopAnalysisPolling();
});

onMounted(async () => {
    if (!isLoginPage.value) {
        await auth.loadUser();
        applyLoggedInCompanyFilter();
        await clientsStore.loadClients(clientCompanyFilterId.value);
        if (canManageUsers.value) {
            await companiesStore.loadCompanies();
            await usersStore.loadUsers();
            await rolesStore.loadRoles();
        }
        await analysesStore.loadAnalyses();
        applyRouteFromPath();
        window.addEventListener('popstate', applyRouteFromPath);
    }
});

onUnmounted(() => {
    window.removeEventListener('popstate', applyRouteFromPath);
    if (clientCompanySearchTimer.value) {
        clearTimeout(clientCompanySearchTimer.value);
    }
    stopAnalysisPolling();
});

function navigateSection(section) {
    activeSection.value = section;
    analysisDetailId.value = null;
    clearCurrentAnalysis();

    if (section === 'admin' && !visibleAdminMenuItemKeys.value.includes(activeAdminSection.value)) {
        activeAdminSection.value = visibleAdminMenuItemKeys.value[0] ?? 'clients-overview';
    }

    if (section === 'helpers' && !helperMenuItemKeys.value.includes(activeHelperSection.value)) {
        activeHelperSection.value = helperMenuItemKeys.value[0] ?? 'analyse';
    }

    if (section === 'formate' && !formatMenuItemKeys.value.includes(activeFormatSection.value)) {
        activeFormatSection.value = formatMenuItemKeys.value[0] ?? 'format-one';
    }

    updateUrlPath();
}

async function navigateAdminSection(adminSection) {
    activeSection.value = 'admin';
    activeAdminSection.value = adminSection;
    clearAdminSectionMessages();
    updateUrlPath();

    if (adminSection === 'roles') {
        await rolesStore.loadRoles(rolePagination.value.current_page);
    }
}

function navigateHelperSection(helperSection) {
    activeSection.value = 'helpers';
    activeHelperSection.value = helperSection;
    analysisDetailId.value = null;
    clearCurrentAnalysis();
    updateUrlPath();
}

function navigateFormatSection(formatSection) {
    activeSection.value = 'formate';
    activeFormatSection.value = formatSection;
    analysisDetailId.value = null;
    clearCurrentAnalysis();
    updateUrlPath();
}

function applyRouteFromPath() {
    const path = window.location.pathname.replace(/\/+$/, '') || '/admin';

    if (path.startsWith('/admin/helpers/analyse/')) {
        const detailId = decodeURIComponent(path.replace('/admin/helpers/analyse/', ''));
        activeSection.value = 'helpers';
        activeHelperSection.value = 'analyse';
        resetAnalysisForm();
        analysisDetailId.value = detailId;
        loadAnalysisDetail(detailId);
        updateUrlPath(true);

        return;
    }

    if (path.startsWith('/admin/helpers/')) {
        const helperSection = decodeURIComponent(path.replace('/admin/helpers/', ''));
        activeSection.value = 'helpers';
        activeHelperSection.value = helperMenuItemKeys.value.includes(helperSection)
            ? helperSection
            : helperMenuItemKeys.value[0] ?? 'analyse';
        analysisDetailId.value = null;
        resetAnalysisForm();
        clearCurrentAnalysis();
        updateUrlPath(true);

        return;
    }

    if (path === '/admin/formate' || path.startsWith('/admin/formate/')) {
        const formatSection = path === '/admin/formate'
            ? formatMenuItemKeys.value[0] ?? 'format-one'
            : decodeURIComponent(path.replace('/admin/formate/', ''));
        activeSection.value = 'formate';
        activeFormatSection.value = formatMenuItemKeys.value.includes(formatSection)
            ? formatSection
            : formatMenuItemKeys.value[0] ?? 'format-one';
        analysisDetailId.value = null;
        resetAnalysisForm();
        clearCurrentAnalysis();
        updateUrlPath(true);

        return;
    }

    if (path.startsWith('/admin/menu/')) {
        const adminSection = decodeURIComponent(path.replace('/admin/menu/', ''));
        activeSection.value = 'admin';
        activeAdminSection.value = visibleAdminMenuItemKeys.value.includes(adminSection)
            ? adminSection
            : visibleAdminMenuItemKeys.value[0] ?? 'clients-overview';
        analysisDetailId.value = null;
        resetAnalysisForm();
        clearCurrentAnalysis();
        updateUrlPath(true);

        return;
    }

    if (path === '/admin/profile') {
        activeSection.value = 'profile';
        analysisDetailId.value = null;
        resetAnalysisForm();
        clearCurrentAnalysis();
        updateUrlPath(true);

        return;
    }

    activeSection.value = 'dashboard';
    analysisDetailId.value = null;
    resetAnalysisForm();
    clearCurrentAnalysis();
    updateUrlPath(true);
}

function updateUrlPath(replace = false) {
    const path = activeSection.value === 'admin'
        ? `/admin/menu/${activeAdminSection.value}`
        : activeSection.value === 'formate'
            ? `/admin/formate/${activeFormatSection.value}`
        : activeSection.value === 'helpers'
            ? analysisDetailId.value
                ? `/admin/helpers/${activeHelperSection.value}/${analysisDetailId.value}`
                : `/admin/helpers/${activeHelperSection.value}`
            : `/admin/${activeSection.value}`;
    const nextUrl = `${path}${window.location.search}`;

    if (`${window.location.pathname}${window.location.search}` === nextUrl) {
        return;
    }

    if (replace) {
        window.history.replaceState(null, '', nextUrl);

        return;
    }

    window.history.pushState(null, '', nextUrl);
}

function emptyUserForm() {
    return {
        last_name: '',
        first_name: '',
        company_id: null,
        email: '',
        roles: ['admin'],
    };
}

function emptyCompanyForm() {
    return {
        company_name_1: '',
        company_name_2: '',
        street: '',
        postal_code: '',
        city: '',
        country: '',
    };
}

function emptyRoleForm() {
    return {
        name: '',
    };
}

function clearAdminSectionMessages() {
    clientMessage.value = '';
    clientError.value = '';
    clientCompanyMessage.value = '';
    clientCompanyError.value = '';
    companyMessage.value = '';
    companyError.value = '';
    roleMessage.value = '';
    roleError.value = '';
    userMessage.value = '';
    userError.value = '';
}

function emptyClientForm() {
    return {
        name: '',
        signature: '',
    };
}

function applyLoggedInCompanyFilter() {
    clientCompanyFilterId.value = user.value?.selected_company_id ?? user.value?.company_id ?? null;
    clientCompanyFilterName.value = user.value?.selected_company_name ?? user.value?.company_name ?? 'No company assigned';
}

async function sendCode() {
    localError.value = '';

    try {
        await auth.sendCode(email.value);
        step.value = 'code';
    } catch (err) {
        localError.value = err.message;
    }
}

async function verifyCode() {
    localError.value = '';

    try {
        await auth.verifyCode(code.value);
    } catch (err) {
        localError.value = err.message;
    }
}

async function passwordLogin() {
    localError.value = '';

    try {
        await auth.passwordLogin(email.value, loginPassword.value);
    } catch (err) {
        localError.value = err.message;
    }
}

async function saveProfileName() {
    profileMessage.value = '';
    profileError.value = '';

    try {
        await auth.updateName(profileLastName.value, profileFirstName.value);
        profileMessage.value = auth.notice;
        isNameDialogOpen.value = false;
    } catch (err) {
        profileError.value = err.message;
    }
}

function openNameDialog() {
    profileLastName.value = user.value?.last_name ?? '';
    profileFirstName.value = user.value?.first_name ?? '';
    profileMessage.value = '';
    profileError.value = '';
    isNameDialogOpen.value = true;
}

function abortNameEdit() {
    profileLastName.value = user.value?.last_name ?? '';
    profileFirstName.value = user.value?.first_name ?? '';
    profileError.value = '';
    isNameDialogOpen.value = false;
}

async function savePassword() {
    profileMessage.value = '';
    profileError.value = '';

    try {
        await auth.updatePassword(newPassword.value);
        newPassword.value = '';
        profileMessage.value = auth.notice;
        isPasswordDialogOpen.value = false;
    } catch (err) {
        profileError.value = err.message;
    }
}

function openPasswordDialog() {
    newPassword.value = '';
    profileMessage.value = '';
    profileError.value = '';
    isPasswordDialogOpen.value = true;
}

function abortPasswordEdit() {
    newPassword.value = '';
    profileError.value = '';
    isPasswordDialogOpen.value = false;
}

function openCreateUserDialog() {
    userDialogMode.value = 'create';
    selectedUser.value = null;
    userForm.value = {
        ...emptyUserForm(),
        company_id: clientCompanyFilterId.value,
    };
    userMessage.value = '';
    userError.value = '';
    isUserDialogOpen.value = true;
}

function openEditUserDialog(adminUser) {
    userDialogMode.value = 'edit';
    selectedUser.value = adminUser;
    userForm.value = {
        last_name: adminUser.last_name,
        first_name: adminUser.first_name,
        company_id: adminUser.company_id,
        email: adminUser.email,
        roles: [...adminUser.roles],
    };
    userMessage.value = '';
    userError.value = '';
    isUserDialogOpen.value = true;
}

function abortUserDialog() {
    userForm.value = emptyUserForm();
    selectedUser.value = null;
    userError.value = '';
    isUserDialogOpen.value = false;
}

async function saveUser() {
    userMessage.value = '';
    userError.value = '';

    try {
        const data = userDialogMode.value === 'create'
            ? await usersStore.createUser(userForm.value)
            : await usersStore.updateUser(selectedUser.value.id, userForm.value);

        userMessage.value = data.message;
        isUserDialogOpen.value = false;
        await usersStore.loadUsers(userDialogMode.value === 'create' ? 1 : userPagination.value.current_page);
        await rolesStore.loadRoles(rolePagination.value.current_page);
    } catch (err) {
        userError.value = err.message;
    }
}

function openDeleteUserDialog(adminUser) {
    selectedUser.value = adminUser;
    userMessage.value = '';
    userError.value = '';
    isDeleteUserDialogOpen.value = true;
}

function abortDeleteUserDialog() {
    selectedUser.value = null;
    userError.value = '';
    isDeleteUserDialogOpen.value = false;
}

async function deleteUser() {
    userMessage.value = '';
    userError.value = '';

    try {
        const data = await usersStore.deleteUser(selectedUser.value.id);
        userMessage.value = data.message;
        isDeleteUserDialogOpen.value = false;
        selectedUser.value = null;
        await usersStore.loadUsers(userPagination.value.current_page);
        await rolesStore.loadRoles(rolePagination.value.current_page);
    } catch (err) {
        userError.value = err.message;
    }
}

function openCreateCompanyDialog() {
    companyDialogMode.value = 'create';
    selectedCompany.value = null;
    companyForm.value = emptyCompanyForm();
    companyMessage.value = '';
    companyError.value = '';
    isCompanyDialogOpen.value = true;
}

function openEditCompanyDialog(company) {
    companyDialogMode.value = 'edit';
    selectedCompany.value = company;
    companyForm.value = {
        company_name_1: company.company_name_1,
        company_name_2: company.company_name_2 ?? '',
        street: company.street,
        postal_code: company.postal_code,
        city: company.city,
        country: company.country,
    };
    companyMessage.value = '';
    companyError.value = '';
    isCompanyDialogOpen.value = true;
}

function abortCompanyDialog() {
    companyForm.value = emptyCompanyForm();
    selectedCompany.value = null;
    companyError.value = '';
    isCompanyDialogOpen.value = false;
}

async function saveCompany() {
    companyMessage.value = '';
    companyError.value = '';

    try {
        const data = companyDialogMode.value === 'create'
            ? await companiesStore.createCompany(companyForm.value)
            : await companiesStore.updateCompany(selectedCompany.value.id, companyForm.value);

        companyMessage.value = data.message;
        isCompanyDialogOpen.value = false;
        await companiesStore.loadCompanies(companyDialogMode.value === 'create' ? 1 : companyPagination.value.current_page);
    } catch (err) {
        companyError.value = err.message;
    }
}

function openDeleteCompanyDialog(company) {
    selectedCompany.value = company;
    companyMessage.value = '';
    companyError.value = '';
    isDeleteCompanyDialogOpen.value = true;
}

function abortDeleteCompanyDialog() {
    selectedCompany.value = null;
    companyError.value = '';
    isDeleteCompanyDialogOpen.value = false;
}

async function deleteCompany() {
    companyMessage.value = '';
    companyError.value = '';

    try {
        const data = await companiesStore.deleteCompany(selectedCompany.value.id);
        companyMessage.value = data.message;
        isDeleteCompanyDialogOpen.value = false;
        selectedCompany.value = null;
        await companiesStore.loadCompanies(companyPagination.value.current_page);
    } catch (err) {
        companyError.value = err.message;
    }
}

async function activateCompany(company) {
    companyMessage.value = '';
    companyError.value = '';

    try {
        const data = await companiesStore.activateCompany(company.id);
        companyMessage.value = data.message;
        auth.selectCompany(data.company);
        clientCompanyFilterId.value = data.company.id;
        clientCompanyFilterName.value = data.company.company_name_1;
        await clientsStore.loadClients(data.company.id);
        await analysesStore.loadAnalyses();
        closeAnalysisDetail();
        await companiesStore.loadCompanies(companyPagination.value.current_page);
    } catch (err) {
        companyError.value = err.message;
    }
}

function openCreateRoleDialog() {
    roleDialogMode.value = 'create';
    selectedRole.value = null;
    roleForm.value = emptyRoleForm();
    roleMessage.value = '';
    roleError.value = '';
    isRoleDialogOpen.value = true;
}

function openEditRoleDialog(role) {
    if (!role.can_edit) {
        return;
    }

    roleDialogMode.value = 'edit';
    selectedRole.value = role;
    roleForm.value = {
        name: role.name,
    };
    roleMessage.value = '';
    roleError.value = '';
    isRoleDialogOpen.value = true;
}

function abortRoleDialog() {
    roleForm.value = emptyRoleForm();
    selectedRole.value = null;
    roleError.value = '';
    isRoleDialogOpen.value = false;
}

async function saveRole() {
    roleMessage.value = '';
    roleError.value = '';

    try {
        const data = roleDialogMode.value === 'create'
            ? await rolesStore.createRole(roleForm.value)
            : await rolesStore.updateRole(selectedRole.value.id, roleForm.value);

        roleMessage.value = data.message;
        isRoleDialogOpen.value = false;
        await rolesStore.loadRoles(roleDialogMode.value === 'create' ? 1 : rolePagination.value.current_page);
        await usersStore.loadUsers(userPagination.value.current_page);
    } catch (err) {
        roleError.value = err.message;
    }
}

function openDeleteRoleDialog(role) {
    if (!role.can_delete) {
        return;
    }

    selectedRole.value = role;
    roleMessage.value = '';
    roleError.value = '';
    isDeleteRoleDialogOpen.value = true;
}

function abortDeleteRoleDialog() {
    selectedRole.value = null;
    roleError.value = '';
    isDeleteRoleDialogOpen.value = false;
}

async function deleteRole() {
    roleMessage.value = '';
    roleError.value = '';

    try {
        const data = await rolesStore.deleteRole(selectedRole.value.id);
        roleMessage.value = data.message;
        isDeleteRoleDialogOpen.value = false;
        selectedRole.value = null;
        await rolesStore.loadRoles(rolePagination.value.current_page);
        await usersStore.loadUsers(userPagination.value.current_page);
    } catch (err) {
        roleError.value = err.message;
    }
}

function openClientCompanyDialog() {
    selectedClientCompany.value = null;
    clientCompanySearch.value = '';
    clientCompanySearchResults.value = [];
    clientCompanyMessage.value = '';
    clientMessage.value = '';
    clientCompanyError.value = '';
    clientError.value = '';
    isClientCompanyDialogOpen.value = true;
}

function selectClientCompany(company) {
    selectedClientCompany.value = company;
    clientCompanySearch.value = company.company_name_1;
    clientCompanySearchResults.value = [];
}

function abortClientCompanyDialog() {
    selectedClientCompany.value = null;
    clientCompanySearch.value = '';
    clientCompanySearchResults.value = [];
    clientCompanyError.value = '';
    isClientCompanyDialogOpen.value = false;
}

async function saveClientCompany() {
    clientCompanyMessage.value = '';
    clientCompanyError.value = '';

    try {
        const data = await companiesStore.activateCompany(selectedClientCompany.value.id);
        auth.selectCompany(data.company);
        await companiesStore.loadCompanies(companyPagination.value.current_page);
        await clientsStore.loadClients(data.company.id);
        clientCompanyFilterId.value = selectedClientCompany.value.id;
        clientCompanyFilterName.value = data.company.company_name_1;
        clientCompanyMessage.value = `Activated ${data.company.company_name_1}.`;
        await analysesStore.loadAnalyses();
        closeAnalysisDetail();
        isClientCompanyDialogOpen.value = false;
        selectedClientCompany.value = null;
        clientCompanySearch.value = '';
        clientCompanySearchResults.value = [];
    } catch (err) {
        clientCompanyError.value = err.message;
    }
}

function openCreateClientDialog() {
    clientDialogMode.value = 'create';
    selectedClient.value = null;
    clientForm.value = emptyClientForm();
    clientMessage.value = '';
    clientError.value = '';
    isClientDialogOpen.value = true;
}

function openEditClientDialog(client) {
    clientDialogMode.value = 'edit';
    selectedClient.value = client;
    clientForm.value = {
        name: client.name,
        signature: client.signature,
    };
    clientMessage.value = '';
    clientError.value = '';
    isClientDialogOpen.value = true;
}

function abortClientDialog() {
    clientForm.value = emptyClientForm();
    selectedClient.value = null;
    clientError.value = '';
    isClientDialogOpen.value = false;
}

function openDeleteClientDialog(client) {
    selectedClient.value = client;
    clientDeleteConfirmation.value = '';
    clientMessage.value = '';
    clientError.value = '';
    isDeleteClientDialogOpen.value = true;
}

function abortDeleteClientDialog() {
    selectedClient.value = null;
    clientDeleteConfirmation.value = '';
    clientError.value = '';
    isDeleteClientDialogOpen.value = false;
}

async function saveClient() {
    clientMessage.value = '';
    clientError.value = '';

    try {
        const data = clientDialogMode.value === 'create'
            ? await clientsStore.createClient({
                ...clientForm.value,
                company_id: clientCompanyFilterId.value,
            })
            : await clientsStore.updateClient(selectedClient.value.id, clientForm.value);

        clientMessage.value = data.message;
        isClientDialogOpen.value = false;
        clientForm.value = emptyClientForm();
        selectedClient.value = null;
        await clientsStore.loadClients(clientCompanyFilterId.value);
    } catch (err) {
        clientError.value = err.message;
    }
}

async function deleteClient() {
    clientMessage.value = '';
    clientError.value = '';

    try {
        const data = await clientsStore.deleteClient(selectedClient.value.id, clientDeleteConfirmation.value);
        clientMessage.value = data.message;
        isDeleteClientDialogOpen.value = false;
        clientDeleteConfirmation.value = '';
        selectedClient.value = null;
        await clientsStore.loadClients(clientCompanyFilterId.value);
        if (activeClient.value) {
            await analysesStore.loadAnalyses();
        }
        closeAnalysisDetail();
    } catch (err) {
        clientError.value = err.message;
    }
}

async function activateClient(client) {
    clientMessage.value = '';
    clientError.value = '';

    try {
        const data = await clientsStore.activateClient(client.id);
        clientMessage.value = data.message;
        await clientsStore.loadClients(clientCompanyFilterId.value);
        await analysesStore.loadAnalyses();
        closeAnalysisDetail();
    } catch (err) {
        clientError.value = err.message;
    }
}

async function startAnalysis() {
    analysisMessage.value = '';
    analysisError.value = '';

    try {
        const data = await analysesStore.createAnalysis(analysisCheckedUrl.value?.url ?? analysisUrl.value);
        resetAnalysisForm();
        analysisMessage.value = data.message;
        await analysesStore.loadAnalyses();
        startAnalysisPolling();
    } catch (err) {
        analysisError.value = err.message;
    }
}

async function checkAnalysisUrl() {
    analysisMessage.value = '';
    analysisError.value = '';
    analysisCheckedUrl.value = null;

    try {
        const data = await analysesStore.checkUrl(analysisUrl.value);
        analysisCheckedUrl.value = data.url;
        analysisMessage.value = data.message;
    } catch (err) {
        analysisError.value = err.message;
    }
}

async function loadAnalysisDetail(id) {
    if (!hasLiveCurrentAnalysis.value) {
        analysisMessage.value = '';
    }

    analysisError.value = '';

    try {
        await analysesStore.loadAnalysis(id);
        selectedAnalysisColors.value = storedSelectedAnalysisColors(id);

        if (currentAnalysis.value?.status !== 'queued') {
            analysisMessage.value = '';
        }
    } catch (err) {
        analysisError.value = err.message;
    }
}

function openAnalysis(analysis) {
    activeSection.value = 'helpers';
    activeHelperSection.value = 'analyse';
    analysisDetailId.value = analysis.id;
    loadAnalysisDetail(analysis.id);
    updateUrlPath();
}

function closeAnalysisDetail() {
    analysisDetailId.value = null;
    selectedAnalysisColors.value = [];
    clearCurrentAnalysis();
}

function backToAnalyses() {
    analysisMessage.value = '';
    analysisError.value = '';
    closeAnalysisDetail();
    updateUrlPath();
}

function openNewAnalysisForm() {
    analysisMessage.value = '';
    analysisError.value = '';
    isAnalysisFormOpen.value = true;
}

function closeNewAnalysisForm() {
    resetAnalysisForm();
}

function resetAnalysisForm() {
    analysisUrl.value = '';
    analysisCheckedUrl.value = null;
    isAnalysisFormOpen.value = false;
}

function selectedAnalysisColorsStorageKey(analysisId = analysisDetailId.value) {
    return `${selectedAnalysisColorsStoragePrefix}.${analysisId}`;
}

function storedSelectedAnalysisColors(analysisId) {
    try {
        const storedColors = JSON.parse(localStorage.getItem(selectedAnalysisColorsStorageKey(analysisId)) ?? '[]');

        return Array.isArray(storedColors) ? storedColors.filter((color) => typeof color === 'string') : [];
    } catch {
        return [];
    }
}

function persistSelectedAnalysisColors() {
    if (!analysisDetailId.value) {
        return;
    }

    localStorage.setItem(
        selectedAnalysisColorsStorageKey(),
        JSON.stringify(selectedAnalysisColors.value),
    );
    selectedAnalysisColorsVersion.value += 1;
}

function isAnalysisColorSelected(color) {
    return selectedAnalysisColors.value.includes(color.value);
}

function toggleAnalysisColorSelection(color) {
    selectedAnalysisColors.value = isAnalysisColorSelected(color)
        ? selectedAnalysisColors.value.filter((selectedColor) => selectedColor !== color.value)
        : [...selectedAnalysisColors.value, color.value];

    persistSelectedAnalysisColors();
}

function storedRequiredFormatColors() {
    try {
        const storedColors = JSON.parse(localStorage.getItem(requiredFormatColorsStorageKey) ?? '[]');

        return Array.isArray(storedColors) ? storedColors.filter((color) => typeof color === 'string') : [];
    } catch {
        return [];
    }
}

function persistRequiredFormatColors() {
    localStorage.setItem(requiredFormatColorsStorageKey, JSON.stringify(requiredFormatColors.value));
}

function storedSavedFormatColorSchemes() {
    try {
        const storedSchemes = JSON.parse(localStorage.getItem(savedFormatColorSchemesStorageKey) ?? '[]');

        return Array.isArray(storedSchemes)
            ? storedSchemes.filter((scheme) => scheme && typeof scheme.name === 'string' && scheme.tokens)
            : [];
    } catch {
        return [];
    }
}

function persistSavedFormatColorSchemes() {
    localStorage.setItem(savedFormatColorSchemesStorageKey, JSON.stringify(savedFormatColorSchemes.value));
}

function isRequiredFormatColor(color) {
    return requiredFormatColors.value.includes(color.value);
}

function isRequiredFormatColorDisabled(color) {
    return !isRequiredFormatColor(color) && requiredFormatColorCount.value >= maximumRequiredFormatColors;
}

function toggleRequiredFormatColor(color) {
    if (isRequiredFormatColorDisabled(color)) {
        return;
    }

    requiredFormatColors.value = isRequiredFormatColor(color)
        ? requiredFormatColors.value.filter((requiredColor) => requiredColor !== color.value)
        : [...requiredFormatColors.value, color.value];

    persistRequiredFormatColors();
}

function storedFormatColorSchemeMode() {
    const storedMode = localStorage.getItem(formatColorSchemeModeStorageKey);

    return ['assistant', 'custom-color', 'selected-colors'].includes(storedMode) ? storedMode : 'assistant';
}

function selectFormatColorSchemeMode(mode) {
    formatColorSchemeMode.value = mode;
    localStorage.setItem(formatColorSchemeModeStorageKey, mode);
    isFormatSchemeColorSelectionOpen.value = mode === 'selected-colors';
    generatedFormatColorScheme.value = null;
    formatColorSchemeError.value = '';
}

function openFormatSchemeCreation() {
    isFormatSchemeCreationOpen.value = true;
    isFormatSchemeColorSelectionOpen.value = false;
    generatedFormatColorScheme.value = null;
    formatColorSchemeError.value = '';
}

function abortFormatSchemeCreation() {
    isFormatSchemeCreationOpen.value = false;
    isFormatSchemeColorSelectionOpen.value = false;
    generatedFormatColorScheme.value = null;
    isGeneratedFormatColorSchemeOpen.value = true;
    isSaveFormatColorSchemeDialogOpen.value = false;
    formatColorSchemeName.value = '';
    formatColorSchemeSaveError.value = '';
    formatColorSchemeError.value = '';
}

function returnToFormatSchemeModeSelection() {
    isFormatSchemeColorSelectionOpen.value = false;
    formatColorSchemeError.value = '';
}

function continueFormatSchemeCreation() {
    formatColorSchemeError.value = '';

    if (!hasSelectedFormatColorSchemeMode.value) {
        return;
    }

    if (formatColorSchemeMode.value === 'selected-colors' && !isFormatSchemeColorSelectionOpen.value) {
        isFormatSchemeColorSelectionOpen.value = true;

        return;
    }

    const sourceColors = formatColorSchemeSourceColors();

    if (formatColorSchemeMode.value === 'selected-colors' && sourceColors.length === 0) {
        formatColorSchemeError.value = 'Bitte wähle zuerst mindestens eine Farbe aus.';

        return;
    }

    generatedFormatColorScheme.value = generateFormatColorSchemePreview(sourceColors);
    isFormatSchemeColorSelectionOpen.value = false;
    isGeneratedFormatColorSchemeOpen.value = true;
    openSaveFormatColorSchemeDialog();
}

function openSaveFormatColorSchemeDialog() {
    formatColorSchemeName.value = nextFormatColorSchemeName();
    formatColorSchemeSaveError.value = '';
    isSaveFormatColorSchemeDialogOpen.value = true;
}

function nextFormatColorSchemeName() {
    return `Farbschema ${savedFormatColorSchemes.value.length + 1}`;
}

function saveGeneratedFormatColorScheme() {
    const name = formatColorSchemeName.value.trim();

    if (!name) {
        formatColorSchemeSaveError.value = 'Bitte gib einen Namen für das Farbschema ein.';

        return;
    }

    if (!generatedFormatColorScheme.value) {
        formatColorSchemeSaveError.value = 'Es wurde noch kein Farbschema erstellt.';

        return;
    }

    savedFormatColorSchemes.value = [
        {
            ...JSON.parse(JSON.stringify(generatedFormatColorScheme.value)),
            id: `scheme-${Date.now()}`,
            name,
            createdAt: new Date().toISOString(),
        },
        ...savedFormatColorSchemes.value,
    ];

    persistSavedFormatColorSchemes();
    generatedFormatColorScheme.value.name = name;
    isSaveFormatColorSchemeDialogOpen.value = false;
    formatColorSchemeName.value = '';
    formatColorSchemeSaveError.value = '';
}

function formatColorSchemeSourceColors() {
    const selectedColors = selectedFormatColorSamples.value.map((color) => color.value);
    const requiredColors = requiredFormatColorSamples.value;

    if (formatColorSchemeMode.value === 'selected-colors') {
        return uniqueFormatSchemeColors(requiredColors);
    }

    if (formatColorSchemeMode.value === 'custom-color') {
        return uniqueFormatSchemeColors([
            requiredColors[0] ?? selectedColors[0] ?? '#007BFF',
        ]);
    }

    return uniqueFormatSchemeColors(requiredColors.length > 0 ? requiredColors : ['#007BFF', '#28A745', '#DC3545']);
}

function uniqueFormatSchemeColors(colors) {
    return [...new Set(colors
        .map((color) => normalizedReportColor(color)?.value.toUpperCase())
        .filter(Boolean))]
        .slice(0, maximumRequiredFormatColors);
}

function generateFormatColorSchemePreview(sourceColors) {
    const colors = sourceColors.length > 0 ? sourceColors : ['#007BFF', '#28A745', '#DC3545'];
    const roles = assignFormatSchemePreviewRoles(colors);
    const tokens = {
        color_page_bg: '#F8FAFC',
        color_card_bg: '#FFFFFF',
        color_card_border: '#D9E2EC',
        color_heading: '#111827',
        color_text: '#374151',
        color_button_primary_bg: roles.primary,
        color_button_primary_bg_hover: darkenPreviewColor(roles.primary, 0.14),
        color_button_primary_text: contrastPreviewText(roles.primary),
        color_button_primary_hover_text: contrastPreviewText(darkenPreviewColor(roles.primary, 0.14)),
        color_accent: roles.accent,
        color_accent_text: contrastPreviewText(roles.accent),
        color_accent_soft: softPreviewColor(roles.accent),
        color_support: roles.support,
        color_support_text: contrastPreviewText(roles.support),
        color_support_soft: softPreviewColor(roles.support),
        color_decorative: roles.decorative,
        color_decorative_text: contrastPreviewText(roles.decorative),
        color_decorative_soft: softPreviewColor(roles.decorative),
    };
    const selectedMode = formatColorSchemeModeOptions.find((option) => option.key === formatColorSchemeMode.value);

    return {
        name: 'Farbschema 1',
        modeLabel: selectedMode?.label ?? 'Farbschema',
        sourceColors: colors,
        roles: [
            { key: 'role_primary', label: 'Primary', hex: roles.primary },
            { key: 'role_accent', label: 'Accent', hex: roles.accent },
            { key: 'role_support', label: 'Support', hex: roles.support },
            { key: 'role_decorative', label: 'Decorative', hex: roles.decorative },
        ],
        usageTokens: Object.entries(tokens).map(([key, hex]) => ({
            key,
            label: key.replaceAll('_', ' '),
            hex,
        })),
        tokens,
    };
}

function assignFormatSchemePreviewRoles(colors) {
    const analyzedColors = colors.map((color) => ({
        hex: color,
        hsl: rgbToPreviewHsl(hexToRgb(color.replace('#', ''))),
    }));
    const primary = analyzedColors.find((color) => !isPreviewRed(color.hsl.h) && color.hsl.l < 0.72)?.hex ?? analyzedColors[0].hex;
    const accent = mostDistinctPreviewColor(analyzedColors, primary)?.hex ?? generatedPreviewColor(primary, 140);
    const support = analyzedColors.find((color) => ![primary, accent].includes(color.hex) && !isPreviewRed(color.hsl.h))?.hex
        ?? generatedPreviewColor(primary, 70);
    const decorative = analyzedColors.find((color) => ![primary, accent, support].includes(color.hex) && isPreviewRed(color.hsl.h))?.hex
        ?? analyzedColors.find((color) => ![primary, accent, support].includes(color.hex))?.hex
        ?? generatedPreviewColor(primary, -45);

    return {
        primary,
        accent,
        support,
        decorative,
    };
}

function mostDistinctPreviewColor(colors, referenceHex) {
    const referenceColor = colors.find((color) => color.hex === referenceHex);

    if (!referenceColor) {
        return null;
    }

    return colors
        .filter((color) => color.hex !== referenceHex)
        .sort((firstColor, secondColor) => {
            return previewHueDistance(secondColor.hsl.h, referenceColor.hsl.h)
                - previewHueDistance(firstColor.hsl.h, referenceColor.hsl.h);
        })[0] ?? null;
}

function generatedPreviewColor(hex, hueOffset) {
    const hsl = rgbToPreviewHsl(hexToRgb(hex.replace('#', '')));

    return rgbToHex(hslToRgb(hsl.h + hueOffset, Math.max(0.42, hsl.s), 0.42)).toUpperCase();
}

function rgbToPreviewHsl(rgb) {
    const [red, green, blue] = rgb.map((channel) => channel / 255);
    const max = Math.max(red, green, blue);
    const min = Math.min(red, green, blue);
    const lightness = (max + min) / 2;

    if (max === min) {
        return { h: 0, s: 0, l: lightness };
    }

    const delta = max - min;
    const saturation = lightness > 0.5
        ? delta / (2 - max - min)
        : delta / (max + min);
    let hue = 0;

    if (max === red) {
        hue = ((green - blue) / delta) + (green < blue ? 6 : 0);
    } else if (max === green) {
        hue = ((blue - red) / delta) + 2;
    } else {
        hue = ((red - green) / delta) + 4;
    }

    return {
        h: hue * 60,
        s: saturation,
        l: lightness,
    };
}

function previewHueDistance(firstHue, secondHue) {
    const distance = Math.abs(firstHue - secondHue);

    return Math.min(distance, 360 - distance);
}

function isPreviewRed(hue) {
    return hue <= 18 || hue >= 340;
}

function darkenPreviewColor(hex, amount) {
    const hsl = rgbToPreviewHsl(hexToRgb(hex.replace('#', '')));

    return rgbToHex(hslToRgb(hsl.h, hsl.s, Math.max(0, hsl.l - amount))).toUpperCase();
}

function softPreviewColor(hex) {
    const hsl = rgbToPreviewHsl(hexToRgb(hex.replace('#', '')));

    return rgbToHex(hslToRgb(hsl.h, Math.min(hsl.s, 0.42), Math.max(0.9, Math.min(0.96, hsl.l + 0.42)))).toUpperCase();
}

function contrastPreviewText(hex) {
    return previewContrastRatio(hex, '#FFFFFF') >= previewContrastRatio(hex, '#111111')
        ? '#FFFFFF'
        : '#111111';
}

function previewContrastRatio(firstHex, secondHex) {
    const firstLuminance = previewRelativeLuminance(hexToRgb(firstHex.replace('#', '')));
    const secondLuminance = previewRelativeLuminance(hexToRgb(secondHex.replace('#', '')));
    const lighter = Math.max(firstLuminance, secondLuminance);
    const darker = Math.min(firstLuminance, secondLuminance);

    return (lighter + 0.05) / (darker + 0.05);
}

function previewRelativeLuminance(rgb) {
    const [red, green, blue] = rgb.map((channel) => {
        const value = channel / 255;

        return value <= 0.03928
            ? value / 12.92
            : ((value + 0.055) / 1.055) ** 2.4;
    });

    return (0.2126 * red) + (0.7152 * green) + (0.0722 * blue);
}

function openDeleteAnalysisDialog(analysis) {
    if (isLiveAnalysis(analysis)) {
        return;
    }

    selectedAnalysis.value = analysis;
    analysisMessage.value = '';
    analysisError.value = '';
    isDeleteAnalysisDialogOpen.value = true;
}

function abortDeleteAnalysisDialog() {
    selectedAnalysis.value = null;
    analysisError.value = '';
    isDeleteAnalysisDialogOpen.value = false;
}

async function deleteAnalysis() {
    analysisMessage.value = '';
    analysisError.value = '';

    if (!selectedAnalysis.value || isLiveAnalysis(selectedAnalysis.value)) {
        return;
    }

    try {
        const data = await analysesStore.deleteAnalysis(selectedAnalysis.value.id);
        analysisMessage.value = data.message;
        isDeleteAnalysisDialogOpen.value = false;

        if (String(analysisDetailId.value) === String(selectedAnalysis.value.id)) {
            closeAnalysisDetail();
            updateUrlPath();
        }

        selectedAnalysis.value = null;
        await analysesStore.loadAnalyses();
    } catch (err) {
        analysisError.value = err.message;
    }
}

async function rerunAnalysis() {
    analysisMessage.value = '';
    analysisError.value = '';

    if (!currentAnalysis.value || isLiveAnalysis(currentAnalysis.value)) {
        return;
    }

    try {
        const data = await analysesStore.rerunAnalysis(currentAnalysis.value.id);
        analysisMessage.value = data.message;
        await analysesStore.loadAnalyses();
        startAnalysisPolling();
    } catch (err) {
        analysisError.value = err.message;
    }
}

async function cancelAnalysis(analysis) {
    analysisMessage.value = '';
    analysisError.value = '';

    if (!analysis || !isLiveAnalysis(analysis)) {
        return;
    }

    try {
        const data = await analysesStore.cancelAnalysis(analysis.id);
        analysisMessage.value = data.message;
        await analysesStore.loadAnalyses();

        if (analysisDetailId.value) {
            await analysesStore.loadAnalysis(analysisDetailId.value, { silent: true });
        }
    } catch (err) {
        analysisError.value = err.message;
    }
}

function clearCurrentAnalysis() {
    expandedReportPageUrl.value = null;
    expandedNavigationMenus.value = {};
    reportPageListModes.value = {};

    if (typeof analysesStore.clearCurrentAnalysis === 'function') {
        analysesStore.clearCurrentAnalysis();

        return;
    }

    if (currentAnalysis) {
        currentAnalysis.value = null;
    }

    if (currentReport) {
        currentReport.value = null;
    }
}

function groupedReportColors(colors) {
    const groupedColors = [];

    colors.forEach((color) => {
        const normalizedColor = normalizedReportColor(color.value);

        if (!normalizedColor) {
            return;
        }

        const similarColor = groupedColors.find((groupedColor) => colorDistance(groupedColor.rgb, normalizedColor.rgb) <= 28);

        if (similarColor) {
            similarColor.count += color.count;

            return;
        }

        groupedColors.push({
            value: normalizedColor.value,
            rgb: normalizedColor.rgb,
            count: color.count,
        });
    });

    return groupedColors
        .sort((firstColor, secondColor) => secondColor.count - firstColor.count)
        .map(({ value, count }) => ({ value, count }));
}

function groupedReportFonts(fonts) {
    const fontCounts = {};

    fonts.forEach((font) => {
        fontNamesFromStack(font.value).forEach((fontName) => {
            fontCounts[fontName] = (fontCounts[fontName] ?? 0) + font.count;
        });
    });

    return Object.entries(fontCounts)
        .map(([value, count]) => ({ value, count }))
        .sort((firstFont, secondFont) => secondFont.count - firstFont.count);
}

function fontNamesFromStack(fontStack) {
    return fontStack
        .split(',')
        .map((font) => cleanFontName(font))
        .filter((font) => font !== '' && !genericFontFamilies().includes(font.toLowerCase()));
}

function cleanFontName(font) {
    try {
        const url = new URL(font);
        const fontFamily = url.searchParams.get('family');

        if (fontFamily) {
            return cleanFontName(fontFamily.split(':')[0]);
        }
    } catch {
        //
    }

    return font
        .replaceAll('+', ' ')
        .trim()
        .replace(/^["']+|["']+$/g, '');
}

function genericFontFamilies() {
    return [
        'serif',
        'sans-serif',
        'monospace',
        'cursive',
        'fantasy',
        'system-ui',
        'ui-serif',
        'ui-sans-serif',
        'ui-monospace',
        'ui-rounded',
        'emoji',
        'math',
        'fangsong',
    ];
}

function formatPageTitle(title) {
    if (!title) {
        return '-';
    }

    const homepageTitle = currentReportHomepage.value?.title;

    if (!homepageTitle || title === homepageTitle) {
        return title;
    }

    const titleParts = title.split(/\s+[–—\-|:]\s+/);

    if (titleParts.length < 2) {
        return title;
    }

    const siteTitle = titleParts[titleParts.length - 1]?.trim();

    if (siteTitle !== homepageTitle) {
        return title;
    }

    return titleParts.slice(0, -1).join(' - ').trim() || title;
}

function toggleReportPage(page) {
    if (expandedReportPageUrl.value === page.url) {
        expandedReportPageUrl.value = null;

        return;
    }

    expandedReportPageUrl.value = page.url;
    reportPageListModes.value = {
        ...reportPageListModes.value,
        [page.url]: reportPageListModes.value[page.url] ?? 'images',
    };
}

function navigationMenuLinks(menu) {
    return Array.isArray(menu?.links) ? menu.links : [];
}

function isNavigationMenuExpanded(menuKey) {
    return expandedNavigationMenus.value[menuKey] ?? false;
}

function toggleNavigationMenu(menuKey) {
    expandedNavigationMenus.value = {
        ...expandedNavigationMenus.value,
        [menuKey]: !isNavigationMenuExpanded(menuKey),
    };
}

function reportPageListMode(page) {
    return reportPageListModes.value[page.url] ?? 'images';
}

function setReportPageListMode(page, mode) {
    reportPageListModes.value = {
        ...reportPageListModes.value,
        [page.url]: mode,
    };
}

function reportPageImages(page) {
    return uniqueValues([
        ...(page.assets?.images ?? []),
        ...(page.assets?.responsive_images ?? []),
    ]);
}

function reportPageLinks(page) {
    const links = page.content_links ?? page.links ?? {};

    return uniqueValues([
        ...(links.internal ?? []),
        ...(links.external ?? []),
        ...(links.mailto ?? []),
    ]).filter((link) => !isReportFileUrl(link));
}

function reportPageFiles(page) {
    const links = page.content_links ?? page.links ?? {};

    return uniqueValues([
        ...(page.assets?.other_files ?? []),
        ...(links.internal ?? []),
        ...(links.external ?? []),
    ]).filter((link) => isReportFileUrl(link));
}

function uniqueValues(values) {
    return [...new Set(values.filter(Boolean))];
}

function isReportFileUrl(url) {
    try {
        return /\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|csv|json|xml)$/i.test(new URL(url).pathname);
    } catch {
        return /\.(pdf|docx?|xlsx?|pptx?|zip|rar|7z|csv|json|xml)$/i.test(url);
    }
}

function languageLabel(language) {
    if (!language) {
        return '-';
    }

    const normalizedLanguage = language.toLowerCase();
    const primaryLanguage = normalizedLanguage.split(/[-_]/)[0];
    const languageLabels = {
        de: 'Deutsch',
        en: 'Englisch',
        fr: 'Französisch',
        it: 'Italienisch',
        es: 'Spanisch',
    };

    return languageLabels[primaryLanguage] ?? language;
}

function normalizedReportColor(value) {
    const color = value.trim().toLowerCase();

    if (color.startsWith('#')) {
        return normalizedHexColor(color);
    }

    if (color.startsWith('rgb')) {
        return normalizedRgbColor(color);
    }

    if (color.startsWith('hsl')) {
        return normalizedHslColor(color);
    }

    return null;
}

function normalizedHexColor(color) {
    const hex = color.replace('#', '');

    if (![3, 4, 6, 8].includes(hex.length)) {
        return null;
    }

    const fullHex = hex.length <= 4
        ? hex.split('').map((character) => `${character}${character}`).join('')
        : hex;
    const opaqueHex = fullHex.slice(0, 6);
    const rgb = hexToRgb(opaqueHex);

    if (!rgb) {
        return null;
    }

    return {
        value: `#${opaqueHex}`,
        rgb,
    };
}

function normalizedRgbColor(color) {
    const matches = color.match(/rgba?\(([^)]+)\)/);

    if (!matches) {
        return null;
    }

    const channels = colorFunctionChannels(matches[1]).slice(0, 3).map((channel) => {
        if (channel.endsWith('%')) {
            return (Number.parseFloat(channel) / 100) * 255;
        }

        return Number.parseFloat(channel);
    });

    if (channels.length !== 3 || channels.some((channel) => Number.isNaN(channel))) {
        return null;
    }

    const rgb = channels.map((channel) => Math.max(0, Math.min(255, Math.round(channel))));

    return {
        value: rgbToHex(rgb),
        rgb,
    };
}

function normalizedHslColor(color) {
    const matches = color.match(/hsla?\(([^)]+)\)/);

    if (!matches) {
        return null;
    }

    const channels = colorFunctionChannels(matches[1]).slice(0, 3);

    if (channels.length !== 3) {
        return null;
    }

    const hue = Number.parseFloat(channels[0]);
    const saturation = Number.parseFloat(channels[1]) / 100;
    const lightness = Number.parseFloat(channels[2]) / 100;

    if ([hue, saturation, lightness].some((channel) => Number.isNaN(channel))) {
        return null;
    }

    const rgb = hslToRgb(hue, saturation, lightness);

    return {
        value: rgbToHex(rgb),
        rgb,
    };
}

function colorFunctionChannels(value) {
    return value
        .replace(/\s*\/\s*.+$/, '')
        .split(/[,\s]+/)
        .filter(Boolean);
}

function hexToRgb(hex) {
    const numericColor = Number.parseInt(hex, 16);

    if (Number.isNaN(numericColor)) {
        return null;
    }

    return [
        (numericColor >> 16) & 255,
        (numericColor >> 8) & 255,
        numericColor & 255,
    ];
}

function rgbToHex(rgb) {
    return `#${rgb.map((channel) => channel.toString(16).padStart(2, '0')).join('')}`;
}

function hslToRgb(hue, saturation, lightness) {
    const chroma = (1 - Math.abs((2 * lightness) - 1)) * saturation;
    const hueSegment = (((hue % 360) + 360) % 360) / 60;
    const secondLargestComponent = chroma * (1 - Math.abs((hueSegment % 2) - 1));
    const matchValue = lightness - (chroma / 2);
    const [red, green, blue] = hslSegmentToRgb(hueSegment, chroma, secondLargestComponent);

    return [red, green, blue].map((channel) => Math.round((channel + matchValue) * 255));
}

function hslSegmentToRgb(hueSegment, chroma, secondLargestComponent) {
    if (hueSegment < 1) {
        return [chroma, secondLargestComponent, 0];
    }

    if (hueSegment < 2) {
        return [secondLargestComponent, chroma, 0];
    }

    if (hueSegment < 3) {
        return [0, chroma, secondLargestComponent];
    }

    if (hueSegment < 4) {
        return [0, secondLargestComponent, chroma];
    }

    if (hueSegment < 5) {
        return [secondLargestComponent, 0, chroma];
    }

    return [chroma, 0, secondLargestComponent];
}

function colorDistance(firstRgb, secondRgb) {
    return Math.sqrt(firstRgb.reduce((distance, channel, index) => {
        return distance + ((channel - secondRgb[index]) ** 2);
    }, 0));
}

function isLiveAnalysis(analysis) {
    return liveAnalysisStatuses.includes(analysis?.status);
}

function analysisStatusColor(analysis) {
    if (analysis?.status === 'completed') {
        return 'success';
    }

    if (analysis?.status === 'failed') {
        return 'error';
    }

    if (analysis?.status === 'canceled') {
        return 'warning';
    }

    if (isLiveAnalysis(analysis)) {
        return 'primary';
    }

    return 'default';
}

function analysisStepLabel(analysis) {
    if (!analysis) {
        return '-';
    }

    if (analysis.analysis_step === 'reachability') {
        return 'Step 2: Erreichbarkeit prüfen';
    }

    if (analysis.analysis_step === 'analysis') {
        return 'Step 1: Website analysieren';
    }

    if (analysis.status === 'completed') {
        return 'Abgeschlossen';
    }

    if (analysis.status === 'queued') {
        return 'Wartet';
    }

    if (analysis.status === 'canceled') {
        return 'Abgebrochen';
    }

    if (analysis.status === 'failed') {
        return 'Fehlgeschlagen';
    }

    return analysis.analysis_step ?? analysis.status ?? '-';
}

function reachabilityProgressLabel(analysis) {
    const checkedCount = analysis?.reachability_checked_count ?? 0;
    const totalCount = analysis?.reachability_total_count ?? 0;

    if (totalCount === 0) {
        return '0/0';
    }

    return `${checkedCount}/${totalCount}`;
}

function shouldShowReachabilityProgress(analysis) {
    return analysis?.analysis_step === 'reachability' || (analysis?.reachability_total_count ?? 0) > 0;
}

function startAnalysisPolling() {
    if (analysisPollingTimer.value) {
        return;
    }

    analysisPollingTimer.value = window.setInterval(pollAnalysisProgress, 2500);
    pollAnalysisProgress();
}

function stopAnalysisPolling() {
    if (!analysisPollingTimer.value) {
        return;
    }

    window.clearInterval(analysisPollingTimer.value);
    analysisPollingTimer.value = null;
}

async function pollAnalysisProgress() {
    if (isAnalysisPollingRequestActive.value || !shouldPollAnalyses.value) {
        return;
    }

    isAnalysisPollingRequestActive.value = true;

    try {
        if (analysisDetailId.value) {
            await analysesStore.loadAnalysis(analysisDetailId.value, { silent: true });

            return;
        }

        await analysesStore.loadAnalyses({ silent: true });
    } catch (err) {
        analysisError.value = err.message;
        stopAnalysisPolling();
    } finally {
        isAnalysisPollingRequestActive.value = false;
    }
}

function formatDateTime(value) {
    if (!value) {
        return '-';
    }

    return new Intl.DateTimeFormat('de-AT', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
</script>

<template>
    <v-app>
        <main v-if="isLoginPage" class="login-screen">
            <section class="login-panel">
                <div class="brand-mark">STK</div>
                <h1>Stocks</h1>
                <p class="muted">Admin access uses a 6-digit email code.</p>

                <v-alert v-if="notice" type="success" variant="tonal" density="compact" class="mb-4">
                    {{ notice }}
                </v-alert>
                <v-alert v-if="localError || error" type="error" variant="tonal" density="compact" class="mb-4">
                    {{ localError || error }}
                </v-alert>

                <v-btn-toggle v-model="loginMode" mandatory class="login-mode-toggle">
                    <v-btn value="code">
                        <v-icon start icon="mdi-shield-key-outline" />
                        Code
                    </v-btn>
                    <v-btn value="password">
                        <v-icon start icon="mdi-lock-outline" />
                        Password
                    </v-btn>
                </v-btn-toggle>

                <form v-if="loginMode === 'code' && step === 'email'" @submit.prevent="sendCode">
                    <v-text-field
                        v-model="email"
                        type="email"
                        label="Email"
                        prepend-inner-icon="mdi-email-outline"
                        autocomplete="email"
                        required
                    />
                    <v-btn type="submit" color="primary" size="large" block :loading="loading">
                        <v-icon start icon="mdi-send-outline" />
                        Send login code
                    </v-btn>
                </form>

                <form v-if="loginMode === 'code' && step === 'code'" @submit.prevent="verifyCode">
                    <v-text-field
                        v-model="code"
                        label="6-digit code"
                        prepend-inner-icon="mdi-shield-key-outline"
                        inputmode="numeric"
                        maxlength="6"
                        required
                    />
                    <v-btn type="submit" color="primary" size="large" block :loading="loading">
                        <v-icon start icon="mdi-login" />
                        Sign in
                    </v-btn>
                    <v-btn variant="text" class="mt-2" block @click="step = 'email'">
                        Use another email
                    </v-btn>
                </form>

                <form v-if="loginMode === 'password'" @submit.prevent="passwordLogin">
                    <v-text-field
                        v-model="email"
                        type="email"
                        label="Email"
                        prepend-inner-icon="mdi-email-outline"
                        autocomplete="email"
                        required
                    />
                    <v-text-field
                        v-model="loginPassword"
                        type="password"
                        label="Password"
                        prepend-inner-icon="mdi-lock-outline"
                        autocomplete="current-password"
                        required
                    />
                    <v-btn type="submit" color="primary" size="large" block :loading="loading">
                        <v-icon start icon="mdi-login" />
                        Sign in
                    </v-btn>
                </form>
            </section>
        </main>

        <template v-else>
            <div class="admin-layout">
                <aside class="dashboard-menu">
                    <div class="menu-brand">
                        <div class="brand-mark">STK</div>
                        <div>
                            <strong>Stocks</strong>
                            <span>{{ user?.email }}</span>
                            <span class="menu-company">{{ userCompanyName }}</span>
                        </div>
                    </div>

                    <nav class="menu-nav" aria-label="Dashboard menu">
                        <button
                            v-for="item in menuItems"
                            :key="item.key"
                            type="button"
                            class="menu-item"
                            :class="{ 'is-active': activeSection === item.key }"
                            @click="navigateSection(item.key)"
                        >
                            <v-icon :icon="item.icon" size="20" />
                            <span>{{ item.label }}</span>
                        </button>
                    </nav>

                    <v-btn href="/admin/logout" variant="tonal" color="primary" class="logout-button">
                        <v-icon start icon="mdi-logout" />
                        Logout
                    </v-btn>
                </aside>

                <v-main class="dashboard-main">
                    <section class="dashboard-shell">
                        <div class="page-context-bar">
                            <div class="page-context-item">
                                <span>Selected company</span>
                                <strong>{{ clientCompanyFilterName }}</strong>
                            </div>
                            <div class="page-context-item">
                                <span>Selected client</span>
                                <strong>{{ activeClientName }}</strong>
                            </div>
                        </div>

                        <section v-if="activeSection === 'dashboard'">
                            <div class="dashboard-header">
                                <div>
                                    <p class="eyebrow">Dashboard</p>
                                    <h1>Dashboard</h1>
                                    <p class="muted">Clients for {{ clientCompanyFilterName }}</p>
                                </div>
                            </div>

                            <section class="clients-section">
                                <v-alert v-if="clientMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ clientMessage }}
                                </v-alert>
                                <v-alert v-if="clientError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ clientError }}
                                </v-alert>

                                <div class="section-title-row">
                                    <div>
                                        <h2>Clients</h2>
                                        <p class="muted">Company: {{ clientCompanyFilterName }}</p>
                                    </div>
                                    <v-progress-circular
                                        v-if="clientsLoading"
                                        indeterminate
                                        size="22"
                                        width="2"
                                        color="primary"
                                    />
                                </div>

                                <v-alert v-if="clientsError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ clientsError }}
                                </v-alert>

                                <div class="client-grid">
                                    <v-card
                                        v-for="client in clients"
                                        :key="client.id"
                                        flat
                                        border
                                        rounded="lg"
                                        class="client-card"
                                        :class="{ 'is-active-client': client.is_active }"
                                    >
                                        <div class="client-card-header">
                                            <div>
                                                <h3>{{ client.name }}</h3>
                                                <p>/{{ client.signature }}</p>
                                            </div>
                                            <v-chip
                                                :color="client.is_published ? 'success' : 'warning'"
                                                variant="tonal"
                                                size="small"
                                            >
                                                {{ client.is_published ? 'Published' : 'Draft' }}
                                            </v-chip>
                                            <v-chip
                                                :color="client.is_active ? 'primary' : 'default'"
                                                variant="tonal"
                                                size="small"
                                            >
                                                {{ client.is_active ? 'Active' : 'Inactive' }}
                                            </v-chip>
                                        </div>
                                        <p class="client-headline">{{ client.headline }}</p>
                                        <div class="client-actions">
                                            <v-btn
                                                type="button"
                                                :disabled="client.is_active"
                                                :loading="clientsLoading"
                                                variant="flat"
                                                color="primary"
                                                @click="activateClient(client)"
                                            >
                                                <v-icon start icon="mdi-check-circle-outline" />
                                                Activate
                                            </v-btn>
                                            <v-btn :href="client.public_url" target="_blank" rel="noopener" variant="tonal" color="primary">
                                                <v-icon start icon="mdi-open-in-new" />
                                                Open page
                                            </v-btn>
                                        </div>
                                    </v-card>
                                </div>
                            </section>
                        </section>

                        <section v-if="activeSection === 'admin'">
                            <div class="dashboard-header">
                                <div>
                                    <p class="eyebrow">Admin</p>
                                    <h1>Admin</h1>
                                    <p class="muted">Manage admin workflows from this page.</p>
                                </div>
                                <v-btn v-if="activeAdminSection === 'users' && canManageUsers" color="primary" variant="flat" @click="openCreateUserDialog">
                                    <v-icon start icon="mdi-account-plus-outline" />
                                    New user
                                </v-btn>
                                <v-btn v-if="activeAdminSection === 'roles' && canManageUsers" color="primary" variant="flat" @click="openCreateRoleDialog">
                                    <v-icon start icon="mdi-shield-plus-outline" />
                                    New role
                                </v-btn>
                                <v-btn v-if="activeAdminSection === 'companies' && canManageUsers" color="primary" variant="flat" @click="openCreateCompanyDialog">
                                    <v-icon start icon="mdi-domain-plus" />
                                    New company
                                </v-btn>
                            </div>

                            <nav class="admin-submenu" aria-label="Admin menu">
                                <button
                                    v-for="item in visibleAdminMenuItems"
                                    :key="item.key"
                                    type="button"
                                    class="admin-submenu-item"
                                    :class="{ 'is-active': activeAdminSection === item.key }"
                                    @click="navigateAdminSection(item.key)"
                                >
                                    <v-icon :icon="item.icon" size="18" />
                                    <span>{{ item.label }}</span>
                                </button>
                            </nav>

                            <section v-if="activeAdminSection === 'clients-overview'" class="users-section">
                                <v-alert v-if="clientMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ clientMessage }}
                                </v-alert>
                                <v-alert v-if="clientError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ clientError }}
                                </v-alert>
                                <v-alert v-if="clientCompanyMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ clientCompanyMessage }}
                                </v-alert>
                                <v-alert v-if="clientCompanyError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ clientCompanyError }}
                                </v-alert>
                                <v-alert v-if="clientsError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ clientsError }}
                                </v-alert>

                                <v-card flat border rounded="lg" class="users-card">
                                    <div class="section-title-row">
                                        <div>
                                            <h2>Clients</h2>
                                            <p class="muted">Company: {{ clientCompanyFilterName }}</p>
                                        </div>
                                        <v-progress-circular v-if="clientsLoading" indeterminate size="22" width="2" color="primary" />
                                    </div>

                                    <div class="section-action-row">
                                        <v-btn
                                            v-if="canManageUsers"
                                            type="button"
                                            color="primary"
                                            variant="flat"
                                            :disabled="!clientCompanyFilterId"
                                            @click="openCreateClientDialog"
                                        >
                                            <v-icon start icon="mdi-plus" />
                                            New client
                                        </v-btn>
                                    </div>

                                    <v-table class="users-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>URL</th>
                                                <th class="actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="client in clients" :key="client.id">
                                                <td>{{ client.name }}</td>
                                                <td>{{ client.public_url }}</td>
                                                <td class="actions-column">
                                                    <v-btn
                                                        :href="client.public_url"
                                                        target="_blank"
                                                        rel="noopener"
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="primary"
                                                        aria-label="Open client page"
                                                    >
                                                        <v-icon icon="mdi-open-in-new" />
                                                    </v-btn>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="primary"
                                                        aria-label="Edit client"
                                                        @click="openEditClientDialog(client)"
                                                    >
                                                        <v-icon icon="mdi-pencil-outline" />
                                                    </v-btn>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="error"
                                                        aria-label="Delete client"
                                                        @click="openDeleteClientDialog(client)"
                                                    >
                                                        <v-icon icon="mdi-delete-outline" />
                                                    </v-btn>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>
                                </v-card>
                            </section>

                            <v-dialog v-model="isClientDialogOpen" persistent max-width="700">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>
                                        {{ clientDialogMode === 'create' ? `New client for ${clientCompanyFilterName}` : 'Edit client' }}
                                    </v-card-title>
                                    <v-card-text>
                                        <form id="client-form" @submit.prevent="saveClient">
                                            <v-text-field
                                                v-model="clientForm.name"
                                                label="Name"
                                                prepend-inner-icon="mdi-account-box-outline"
                                                required
                                            />
                                            <v-text-field
                                                v-model="clientForm.signature"
                                                label="URL"
                                                prefix="/"
                                                prepend-inner-icon="mdi-link-variant"
                                                hint="lowercase letters, numbers, and hyphens"
                                                persistent-hint
                                                required
                                            />
                                        </form>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="clientsLoading" @click="abortClientDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="submit" form="client-form" color="primary" variant="flat" :loading="clientsLoading">
                                            <v-icon start icon="mdi-content-save-outline" />
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isDeleteClientDialogOpen" persistent max-width="520">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Delete client</v-card-title>
                                    <v-card-text>
                                        <p>
                                            Delete <strong>{{ selectedClient?.name }}</strong> and all connected data?
                                        </p>
                                        <div class="delete-impact-list">
                                            <div>
                                                <span>Client</span>
                                                <strong>{{ selectedClient?.name }}</strong>
                                            </div>
                                            <div>
                                                <span>URL</span>
                                                <strong>{{ selectedClient?.public_url }}</strong>
                                            </div>
                                            <div>
                                                <span>Analyses</span>
                                                <strong>{{ selectedClient?.analyses_count ?? 0 }}</strong>
                                            </div>
                                            <div>
                                                <span>Files</span>
                                                <strong>Stored JSON analysis files</strong>
                                            </div>
                                        </div>
                                        <v-text-field
                                            v-model="clientDeleteConfirmation"
                                            label="Type DELETE to confirm"
                                            prepend-inner-icon="mdi-alert-outline"
                                            autocomplete="off"
                                        />
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="clientsLoading" @click="abortDeleteClientDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn
                                            type="button"
                                            color="error"
                                            variant="flat"
                                            :disabled="clientDeleteConfirmation !== 'DELETE'"
                                            :loading="clientsLoading"
                                            @click="deleteClient"
                                        >
                                            <v-icon start icon="mdi-delete-outline" />
                                            Delete
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isClientCompanyDialogOpen" persistent max-width="560">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Show company clients</v-card-title>
                                    <v-card-text>
                                        <v-text-field
                                            v-model="clientCompanySearch"
                                            label="Company"
                                            prepend-inner-icon="mdi-domain"
                                            :loading="clientCompanySearchLoading"
                                            autofocus
                                        />
                                        <v-list v-if="clientCompanySearchResults.length" density="compact" class="company-search-list">
                                            <v-list-item
                                                v-for="company in clientCompanySearchResults"
                                                :key="company.id"
                                                :active="selectedClientCompany?.id === company.id"
                                                @click="selectClientCompany(company)"
                                            >
                                                <v-list-item-title>{{ company.company_name_1 }}</v-list-item-title>
                                                <v-list-item-subtitle v-if="company.company_name_2">
                                                    {{ company.company_name_2 }}
                                                </v-list-item-subtitle>
                                            </v-list-item>
                                        </v-list>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="clientsLoading" @click="abortClientCompanyDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn
                                            type="button"
                                            color="primary"
                                            variant="flat"
                                            :disabled="!selectedClientCompany"
                                            :loading="clientsLoading"
                                            @click="saveClientCompany"
                                        >
                                            <v-icon start icon="mdi-filter-outline" />
                                            Show
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <section v-if="activeAdminSection === 'companies' && canManageUsers" class="users-section">
                                <v-alert v-if="companyMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ companyMessage }}
                                </v-alert>
                                <v-alert v-if="companyError || companiesError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ companyError || companiesError }}
                                </v-alert>

                                <v-card flat border rounded="lg" class="users-card">
                                    <div class="section-title-row">
                                        <h2>Unternehmen</h2>
                                        <v-progress-circular v-if="companiesLoading" indeterminate size="22" width="2" color="primary" />
                                    </div>

                                    <v-table class="users-table companies-table">
                                        <thead>
                                            <tr>
                                                <th>Company name</th>
                                                <th>Straße</th>
                                                <th>PLZ</th>
                                                <th>ORT</th>
                                                <th>Land</th>
                                                <th class="actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr
                                                v-for="company in companies"
                                                :key="company.id"
                                                :class="{ 'is-active-company-row': company.is_active }"
                                            >
                                                <td>
                                                    <div class="company-name-row">
                                                        <strong>{{ company.company_name_1 }}</strong>
                                                        <v-chip
                                                            v-if="company.is_active"
                                                            class="company-active-chip"
                                                            color="primary"
                                                            variant="tonal"
                                                            size="small"
                                                        >
                                                            Active
                                                        </v-chip>
                                                    </div>
                                                    <span v-if="company.company_name_2" class="company-name-secondary">
                                                        {{ company.company_name_2 }}
                                                    </span>
                                                </td>
                                                <td>{{ company.street }}</td>
                                                <td>{{ company.postal_code }}</td>
                                                <td>{{ company.city }}</td>
                                                <td>{{ company.country }}</td>
                                                <td class="actions-column">
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="primary"
                                                        aria-label="Activate company"
                                                        :disabled="company.is_active"
                                                        :loading="companiesLoading"
                                                        @click="activateCompany(company)"
                                                    >
                                                        <v-icon icon="mdi-check-circle-outline" />
                                                    </v-btn>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="primary"
                                                        aria-label="Edit company"
                                                        @click="openEditCompanyDialog(company)"
                                                    >
                                                        <v-icon icon="mdi-pencil-outline" />
                                                    </v-btn>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="error"
                                                        aria-label="Delete company"
                                                        :disabled="!company.can_delete"
                                                        @click="openDeleteCompanyDialog(company)"
                                                    >
                                                        <v-icon icon="mdi-delete-outline" />
                                                    </v-btn>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>

                                    <div class="pagination-row">
                                        <p class="muted">
                                            {{ companyPagination.from ?? 0 }}-{{ companyPagination.to ?? 0 }} / {{ companyPagination.total }}
                                        </p>
                                        <v-pagination
                                            :model-value="companyPagination.current_page"
                                            :length="companyPagination.last_page"
                                            density="comfortable"
                                            @update:model-value="companiesStore.loadCompanies"
                                        />
                                    </div>
                                </v-card>
                            </section>

                            <section v-if="activeAdminSection === 'users' && canManageUsers" class="users-section">
                                <v-alert v-if="userMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ userMessage }}
                                </v-alert>
                                <v-alert v-if="userError || usersError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ userError || usersError }}
                                </v-alert>

                                <v-card flat border rounded="lg" class="users-card">
                                    <div class="section-title-row">
                                        <div>
                                            <h2>Benutzer</h2>
                                            <p class="muted">Company: {{ clientCompanyFilterName }}</p>
                                        </div>
                                        <v-progress-circular v-if="usersLoading" indeterminate size="22" width="2" color="primary" />
                                    </div>

                                    <v-table class="users-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Email</th>
                                                <th>Roles</th>
                                                <th class="actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="adminUser in users" :key="adminUser.id">
                                                <td>{{ adminUser.name }}</td>
                                                <td>{{ adminUser.email }}</td>
                                                <td>
                                                    <v-chip
                                                        v-for="role in adminUser.roles"
                                                        :key="role"
                                                        size="small"
                                                        color="primary"
                                                        variant="tonal"
                                                        class="mr-1"
                                                    >
                                                        {{ role }}
                                                    </v-chip>
                                                </td>
                                                <td class="actions-column">
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="primary"
                                                        aria-label="Edit user"
                                                        @click="openEditUserDialog(adminUser)"
                                                    >
                                                        <v-icon icon="mdi-pencil-outline" />
                                                    </v-btn>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="error"
                                                        aria-label="Delete user"
                                                        :disabled="!adminUser.can_delete"
                                                        @click="openDeleteUserDialog(adminUser)"
                                                    >
                                                        <v-icon icon="mdi-delete-outline" />
                                                    </v-btn>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </v-table>

                                    <div class="pagination-row">
                                        <p class="muted">
                                            {{ userPagination.from ?? 0 }}-{{ userPagination.to ?? 0 }} / {{ userPagination.total }}
                                        </p>
                                        <v-pagination
                                            :model-value="userPagination.current_page"
                                            :length="userPagination.last_page"
                                            density="comfortable"
                                            @update:model-value="usersStore.loadUsers"
                                        />
                                    </div>
                                </v-card>
                            </section>

                            <section v-if="activeAdminSection === 'roles' && canManageUsers" class="users-section">
                                <v-alert v-if="roleMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ roleMessage }}
                                </v-alert>
                                <v-alert v-if="roleError || rolesError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ roleError || rolesError }}
                                </v-alert>

                                <v-card flat border rounded="lg" class="users-card">
                                    <div class="section-title-row">
                                        <h2>Rollen</h2>
                                        <v-progress-circular v-if="rolesLoading" indeterminate size="22" width="2" color="primary" />
                                    </div>

                                    <v-table class="users-table">
                                        <thead>
                                            <tr>
                                                <th>Name</th>
                                                <th>Guard</th>
                                                <th>Users</th>
                                                <th class="actions-column">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr v-for="role in roles" :key="role.id">
                                                <td>
                                                    <strong>{{ role.name }}</strong>
                                                </td>
                                                <td>{{ role.guard_name }}</td>
                                                <td>{{ role.users_count }}</td>
                                                <td class="actions-column">
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        variant="text"
                                                        color="primary"
                                                        aria-label="Edit role"
                                                        :disabled="!role.can_edit"
                                                        @click="openEditRoleDialog(role)"
                                                    >
                                                        <v-icon icon="mdi-pencil-outline" />
                                                    </v-btn>
                                                    <v-btn
                                                        type="button"
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

                                    <div class="pagination-row">
                                        <p class="muted">
                                            {{ rolePagination.from ?? 0 }}-{{ rolePagination.to ?? 0 }} / {{ rolePagination.total }}
                                        </p>
                                        <v-pagination
                                            :model-value="rolePagination.current_page"
                                            :length="rolePagination.last_page"
                                            density="comfortable"
                                            @update:model-value="rolesStore.loadRoles"
                                        />
                                    </div>
                                </v-card>
                            </section>

                            <v-dialog v-model="isCompanyDialogOpen" persistent max-width="700">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>{{ companyDialogMode === 'create' ? 'Create company' : 'Edit company' }}</v-card-title>
                                    <v-card-text>
                                        <form id="admin-company-form" @submit.prevent="saveCompany">
                                            <div class="user-form-grid">
                                                <v-text-field
                                                    v-model="companyForm.company_name_1"
                                                    label="Company name 1"
                                                    required
                                                />
                                                <v-text-field
                                                    v-model="companyForm.company_name_2"
                                                    label="Company name 2"
                                                />
                                            </div>
                                            <v-text-field
                                                v-model="companyForm.street"
                                                label="Straße"
                                                required
                                            />
                                            <div class="user-form-grid">
                                                <v-text-field
                                                    v-model="companyForm.postal_code"
                                                    label="PLZ"
                                                    required
                                                />
                                                <v-text-field
                                                    v-model="companyForm.city"
                                                    label="ORT"
                                                    required
                                                />
                                            </div>
                                            <v-text-field
                                                v-model="companyForm.country"
                                                label="Land"
                                                required
                                            />
                                        </form>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="companiesLoading" @click="abortCompanyDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="submit" form="admin-company-form" color="primary" variant="flat" :loading="companiesLoading">
                                            <v-icon start icon="mdi-content-save-outline" />
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isDeleteCompanyDialogOpen" persistent max-width="460">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Delete company</v-card-title>
                                    <v-card-text>
                                        <p>Delete {{ selectedCompany?.company_name_1 }}?</p>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="companiesLoading" @click="abortDeleteCompanyDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="button" color="error" variant="flat" :loading="companiesLoading" @click="deleteCompany">
                                            <v-icon start icon="mdi-delete-outline" />
                                            Delete
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isUserDialogOpen" persistent max-width="620">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>{{ userDialogMode === 'create' ? 'Create user' : 'Edit user' }}</v-card-title>
                                    <v-card-text>
                                        <form id="admin-user-form" @submit.prevent="saveUser">
                                            <div class="user-form-grid">
                                                <v-text-field
                                                    v-model="userForm.last_name"
                                                    label="Last name"
                                                    autocomplete="family-name"
                                                    required
                                                />
                                                <v-text-field
                                                    v-model="userForm.first_name"
                                                    label="First name"
                                                    autocomplete="given-name"
                                                    required
                                                />
                                            </div>
                                            <v-text-field
                                                v-model="userForm.email"
                                                type="email"
                                                label="Email"
                                                autocomplete="email"
                                                required
                                            />
                                            <v-select
                                                v-model="userForm.roles"
                                                :items="userRoles"
                                                label="Roles"
                                                multiple
                                                chips
                                                required
                                                :disabled="selectedUser?.roles_locked"
                                            />
                                        </form>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="usersLoading" @click="abortUserDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="submit" form="admin-user-form" color="primary" variant="flat" :loading="usersLoading">
                                            <v-icon start icon="mdi-content-save-outline" />
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isDeleteUserDialogOpen" persistent max-width="460">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Delete user</v-card-title>
                                    <v-card-text>
                                        <p>Delete {{ selectedUser?.name }}?</p>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="usersLoading" @click="abortDeleteUserDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="button" color="error" variant="flat" :loading="usersLoading" @click="deleteUser">
                                            <v-icon start icon="mdi-delete-outline" />
                                            Delete
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isRoleDialogOpen" persistent max-width="520">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>{{ roleDialogMode === 'create' ? 'Create role' : 'Edit role' }}</v-card-title>
                                    <v-card-text>
                                        <form id="admin-role-form" @submit.prevent="saveRole">
                                            <v-text-field
                                                v-model="roleForm.name"
                                                label="Name"
                                                prepend-inner-icon="mdi-shield-account-outline"
                                                required
                                            />
                                        </form>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="rolesLoading" @click="abortRoleDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="submit" form="admin-role-form" color="primary" variant="flat" :loading="rolesLoading">
                                            <v-icon start icon="mdi-content-save-outline" />
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isDeleteRoleDialogOpen" persistent max-width="460">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Delete role</v-card-title>
                                    <v-card-text>
                                        <p>Delete {{ selectedRole?.name }}?</p>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="rolesLoading" @click="abortDeleteRoleDialog">
                                            Abort
                                        </v-btn>
                                        <v-btn type="button" color="error" variant="flat" :loading="rolesLoading" @click="deleteRole">
                                            <v-icon start icon="mdi-delete-outline" />
                                            Delete
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>
                        </section>

                        <section v-if="activeSection === 'formate'">
                            <div class="dashboard-header">
                                <div>
                                    <p class="eyebrow">Formate</p>
                                    <h1>Formate</h1>
                                    <p class="muted">Manage format settings like colors and font-types for this page.</p>
                                </div>
                            </div>

                            <nav class="admin-submenu" aria-label="Formate menu">
                                <button
                                    v-for="item in formatMenuItems"
                                    :key="item.key"
                                    type="button"
                                    class="admin-submenu-item"
                                    :class="{ 'is-active': activeFormatSection === item.key }"
                                    @click="navigateFormatSection(item.key)"
                                >
                                    <v-icon :icon="item.icon" size="18" />
                                    <span>{{ item.label }}</span>
                                </button>
                            </nav>

                            <section class="users-section">
                                <v-card
                                    v-if="isFormatSchemeColorSelectionVisible"
                                    flat
                                    border
                                    rounded="lg"
                                    class="users-card"
                                >
                                    <div class="section-title-row">
                                        <div>
                                            <h2>
                                                {{ formatMenuItems.find((item) => item.key === activeFormatSection)?.label }}
                                            </h2>
                                            <p v-if="activeFormatSection === 'format-one'" class="muted">
                                                Ausgewählte Farben aus analysierten Websites.
                                            </p>
                                            <p v-else class="muted">Dummy submenu item.</p>
                                        </div>
                                        <v-chip
                                            v-if="activeFormatSection === 'format-one' && requiredFormatColorCount > 0"
                                            size="small"
                                            color="primary"
                                            variant="tonal"
                                        >
                                            {{ requiredFormatColorCount }} / {{ maximumRequiredFormatColors }} müssen verwendet werden
                                        </v-chip>
                                    </div>

                                    <div v-if="activeFormatSection === 'format-one'">
                                        <div v-if="selectedFormatColorSamples.length > 0" class="format-color-sample-list">
                                            <label
                                                v-for="color in selectedFormatColorSamples"
                                                :key="color.value"
                                                class="format-color-sample"
                                                :class="{ 'is-required': isRequiredFormatColor(color) }"
                                            >
                                                <span class="format-color-swatch" :style="{ backgroundColor: color.value }" />
                                                <span class="format-color-meta">
                                                    <strong>{{ color.value }}</strong>
                                                    <small :title="color.analysisUrl">{{ color.analysisUrl }}</small>
                                                </span>
                                                <span class="format-color-required">
                                                    <input
                                                        type="checkbox"
                                                        class="format-color-checkbox"
                                                        :checked="isRequiredFormatColor(color)"
                                                        :disabled="isRequiredFormatColorDisabled(color)"
                                                        @change="toggleRequiredFormatColor(color)"
                                                    >
                                                    <em>Muss verwendet werden</em>
                                                </span>
                                            </label>
                                        </div>
                                        <p v-else class="muted format-empty-state">
                                            Noch keine Farben ausgewählt.
                                        </p>
                                    </div>
                                </v-card>

                                <v-card
                                    v-if="activeFormatSection === 'format-one'"
                                    flat
                                    border
                                    rounded="lg"
                                    class="users-card format-schema-card"
                                >
                                    <div class="section-title-row">
                                        <div>
                                            <h2>Farben-Schema</h2>
                                            <p class="muted">Gespeicherte Farbschemas und neue Entwürfe.</p>
                                        </div>
                                        <v-btn
                                            v-if="!isFormatSchemeCreationOpen"
                                            type="button"
                                            color="primary"
                                            variant="flat"
                                            @click="openFormatSchemeCreation"
                                        >
                                            <v-icon start icon="mdi-plus" />
                                            Neu erstellen
                                        </v-btn>
                                        <v-btn
                                            v-else
                                            type="button"
                                            color="error"
                                            variant="text"
                                            @click="abortFormatSchemeCreation"
                                        >
                                            <v-icon start icon="mdi-close" />
                                            Abbrechen
                                        </v-btn>
                                    </div>
                                    <div class="format-saved-schemes">
                                        <div class="format-saved-schemes-header">
                                            <h3>Gespeicherte Farbschemas</h3>
                                            <span>{{ savedFormatColorSchemes.length }} gespeichert</span>
                                        </div>
                                        <div
                                            v-if="savedFormatColorSchemes.length > 0"
                                            class="format-saved-scheme-list"
                                        >
                                            <article
                                                v-for="scheme in savedFormatColorSchemes"
                                                :key="scheme.id"
                                                class="format-saved-scheme"
                                            >
                                                <div>
                                                    <strong>{{ scheme.name }}</strong>
                                                    <small>{{ scheme.modeLabel }}</small>
                                                </div>
                                                <div class="format-saved-scheme-palette" aria-label="Farben im gespeicherten Schema">
                                                    <span
                                                        v-for="color in scheme.sourceColors"
                                                        :key="`${scheme.id}-${color}`"
                                                        :style="{ backgroundColor: color }"
                                                        :title="color"
                                                    />
                                                </div>
                                            </article>
                                        </div>
                                        <p v-else class="muted format-empty-state">
                                            Noch keine Farbschemas gespeichert.
                                        </p>
                                    </div>
                                    <div
                                        v-if="isFormatSchemeSelectionVisible"
                                        class="format-scheme-options"
                                        role="radiogroup"
                                        aria-label="Farben-Schema"
                                    >
                                        <button
                                            v-for="option in formatColorSchemeModeOptions"
                                            :key="option.key"
                                            type="button"
                                            class="format-scheme-option"
                                            :class="{ 'is-active': formatColorSchemeMode === option.key }"
                                            :aria-checked="formatColorSchemeMode === option.key"
                                            role="radio"
                                            @click="selectFormatColorSchemeMode(option.key)"
                                        >
                                            <v-icon :icon="option.icon" size="20" />
                                            <span class="format-scheme-option-body">
                                                <span>{{ option.label }}</span>
                                                <span
                                                    v-if="option.key === 'selected-colors' && requiredFormatColorSamples.length > 0"
                                                    class="format-scheme-color-preview"
                                                    aria-label="Gewählte Farben"
                                                >
                                                    <span
                                                        v-for="color in requiredFormatColorSamples"
                                                        :key="color"
                                                        :style="{ backgroundColor: color }"
                                                        :title="color"
                                                    />
                                                </span>
                                            </span>
                                        </button>
                                    </div>
                                    <div
                                        v-if="(isFormatSchemeSelectionVisible || isFormatSchemeColorSelectionVisible) && hasSelectedFormatColorSchemeMode"
                                        class="format-scheme-actions"
                                    >
                                        <v-btn
                                            v-if="isFormatSchemeColorSelectionVisible"
                                            type="button"
                                            color="primary"
                                            variant="tonal"
                                            @click="returnToFormatSchemeModeSelection"
                                        >
                                            <v-icon start icon="mdi-arrow-left" />
                                            Zurück
                                        </v-btn>
                                        <v-btn
                                            type="button"
                                            color="primary"
                                            variant="flat"
                                            @click="continueFormatSchemeCreation"
                                        >
                                            Weiter
                                            <v-icon end icon="mdi-arrow-right" />
                                        </v-btn>
                                    </div>
                                    <v-alert
                                        v-if="formatColorSchemeError"
                                        type="warning"
                                        variant="tonal"
                                        density="compact"
                                        class="mt-4"
                                    >
                                        {{ formatColorSchemeError }}
                                    </v-alert>
                                    <div
                                        v-if="generatedFormatColorScheme"
                                        class="format-generated-scheme"
                                    >
                                        <div class="format-generated-scheme-header">
                                            <div>
                                                <h3>{{ generatedFormatColorScheme.name }}</h3>
                                                <p class="muted">{{ generatedFormatColorScheme.modeLabel }}</p>
                                            </div>
                                            <div class="format-generated-scheme-header-actions">
                                                <div class="format-generated-source-strip" aria-label="Quellfarben">
                                                    <span
                                                        v-for="color in generatedFormatColorScheme.sourceColors"
                                                        :key="color"
                                                        :style="{ backgroundColor: color }"
                                                        :title="color"
                                                    />
                                                </div>
                                                <v-btn
                                                    type="button"
                                                    color="primary"
                                                    variant="tonal"
                                                    size="small"
                                                    :aria-expanded="isGeneratedFormatColorSchemeOpen"
                                                    aria-controls="generated-format-scheme-details"
                                                    @click="isGeneratedFormatColorSchemeOpen = !isGeneratedFormatColorSchemeOpen"
                                                >
                                                    <v-icon
                                                        start
                                                        :icon="isGeneratedFormatColorSchemeOpen ? 'mdi-chevron-up' : 'mdi-chevron-down'"
                                                    />
                                                    {{ isGeneratedFormatColorSchemeOpen ? 'Einklappen' : 'Ausklappen' }}
                                                </v-btn>
                                            </div>
                                        </div>

                                        <div class="format-generated-preview-grid">
                                            <div
                                                v-show="isGeneratedFormatColorSchemeOpen"
                                                id="generated-format-scheme-details"
                                                class="format-generated-color-details"
                                            >
                                                <div class="format-generated-preview-panel">
                                                    <h4>Rollen</h4>
                                                    <div class="format-generated-role-list">
                                                        <div
                                                            v-for="role in generatedFormatColorScheme.roles"
                                                            :key="role.key"
                                                            class="format-generated-role"
                                                        >
                                                            <span :style="{ backgroundColor: role.hex }" />
                                                            <strong>{{ role.label }}</strong>
                                                            <small>{{ role.hex }}</small>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="format-generated-preview-panel">
                                                    <h4>Usage Tokens</h4>
                                                    <div class="format-generated-token-list">
                                                        <div
                                                            v-for="token in generatedFormatColorScheme.usageTokens"
                                                            :key="token.key"
                                                            class="format-generated-token"
                                                        >
                                                            <span :style="{ backgroundColor: token.hex }" />
                                                            <small>{{ token.label }}</small>
                                                            <strong>{{ token.hex }}</strong>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div
                                                class="format-generated-mini-preview format-homepage-example-card"
                                                :style="{
                                                    backgroundColor: generatedFormatColorScheme.tokens.color_page_bg,
                                                    color: generatedFormatColorScheme.tokens.color_text,
                                                    borderColor: generatedFormatColorScheme.tokens.color_card_border,
                                                }"
                                            >
                                                <div
                                                    class="format-homepage-example-browser"
                                                    :style="{
                                                        backgroundColor: generatedFormatColorScheme.tokens.color_card_bg,
                                                        borderColor: generatedFormatColorScheme.tokens.color_card_border,
                                                    }"
                                                >
                                                    <div
                                                        class="format-homepage-example-toolbar"
                                                        :style="{ borderColor: generatedFormatColorScheme.tokens.color_card_border }"
                                                    >
                                                        <div class="format-homepage-example-dots" aria-hidden="true">
                                                            <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_decorative }" />
                                                            <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_accent }" />
                                                            <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_support }" />
                                                        </div>
                                                        <div
                                                            class="format-homepage-example-url"
                                                            :style="{
                                                                backgroundColor: generatedFormatColorScheme.tokens.color_support_soft,
                                                                color: generatedFormatColorScheme.tokens.color_support,
                                                            }"
                                                        >
                                                            naturatelier.at
                                                        </div>
                                                    </div>

                                                    <header
                                                        class="format-homepage-example-nav"
                                                        :style="{ borderColor: generatedFormatColorScheme.tokens.color_card_border }"
                                                    >
                                                        <div class="format-homepage-example-brand">
                                                            <span
                                                                :style="{
                                                                    backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg,
                                                                    color: generatedFormatColorScheme.tokens.color_button_primary_text,
                                                                }"
                                                            >
                                                                N
                                                            </span>
                                                            <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                Naturatelier
                                                            </strong>
                                                        </div>
                                                        <nav class="format-homepage-example-links" aria-label="Homepage example navigation">
                                                            <span
                                                                v-for="item in homepagePreviewNavItems"
                                                                :key="item"
                                                            >
                                                                {{ item }}
                                                            </span>
                                                        </nav>
                                                    </header>

                                                    <section
                                                        class="format-homepage-example-hero"
                                                        :style="{
                                                            background: `linear-gradient(135deg, ${generatedFormatColorScheme.tokens.color_button_primary_bg}, ${generatedFormatColorScheme.tokens.color_support})`,
                                                            color: generatedFormatColorScheme.tokens.color_button_primary_text,
                                                        }"
                                                    >
                                                        <div class="format-homepage-example-hero-copy">
                                                            <span
                                                                class="format-homepage-example-pill"
                                                                :style="{
                                                                    backgroundColor: generatedFormatColorScheme.tokens.color_accent_soft,
                                                                    color: generatedFormatColorScheme.tokens.color_accent,
                                                                }"
                                                            >
                                                                Premium Gartenplanung
                                                            </span>
                                                            <h4>Ruhige Außenräume, präzise geplant.</h4>
                                                            <p>
                                                                Ein eleganter Homepage-Aufbau mit starker Headline, klarer CTA,
                                                                echten Leistungsbereichen und glaubwürdiger Projektführung.
                                                            </p>
                                                            <div class="format-homepage-example-actions">
                                                                <span
                                                                    :style="{
                                                                        backgroundColor: generatedFormatColorScheme.tokens.color_accent,
                                                                        color: generatedFormatColorScheme.tokens.color_accent_text,
                                                                    }"
                                                                >
                                                                    Beratung buchen
                                                                </span>
                                                                <span
                                                                :style="{
                                                                    backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg_hover,
                                                                    color: generatedFormatColorScheme.tokens.color_button_primary_hover_text,
                                                                }"
                                                            >
                                                                    Projekte ansehen
                                                                </span>
                                                            </div>
                                                        </div>

                                                        <div
                                                            class="format-homepage-example-showcase"
                                                            :style="{
                                                                backgroundColor: generatedFormatColorScheme.tokens.color_card_bg,
                                                                color: generatedFormatColorScheme.tokens.color_text,
                                                            }"
                                                        >
                                                            <div
                                                                class="format-homepage-example-image"
                                                                :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_decorative_soft }"
                                                            >
                                                                <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_decorative }" />
                                                                <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_support }" />
                                                                <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_accent }" />
                                                            </div>
                                                            <div class="format-homepage-example-booking">
                                                                <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                    Projektstart
                                                                </strong>
                                                                <small>Mai bis Juni</small>
                                                                <div
                                                                    :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_accent }"
                                                                />
                                                            </div>
                                                        </div>
                                                    </section>

                                                    <section class="format-homepage-example-metrics">
                                                        <div
                                                            v-for="metric in homepagePreviewMetrics"
                                                            :key="metric.label"
                                                            :style="{
                                                                backgroundColor: generatedFormatColorScheme.tokens.color_card_bg,
                                                                borderColor: generatedFormatColorScheme.tokens.color_card_border,
                                                            }"
                                                        >
                                                            <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                {{ metric.value }}
                                                            </strong>
                                                            <span>{{ metric.label }}</span>
                                                        </div>
                                                    </section>

                                                    <section class="format-homepage-example-services">
                                                        <article
                                                            v-for="service in homepagePreviewServices"
                                                            :key="service.key"
                                                            :style="{
                                                                backgroundColor: service.token === 'accent'
                                                                    ? generatedFormatColorScheme.tokens.color_accent_soft
                                                                    : service.token === 'decorative'
                                                                        ? generatedFormatColorScheme.tokens.color_decorative_soft
                                                                        : generatedFormatColorScheme.tokens.color_card_bg,
                                                                borderColor: service.token === 'accent'
                                                                    ? generatedFormatColorScheme.tokens.color_accent
                                                                    : service.token === 'decorative'
                                                                        ? generatedFormatColorScheme.tokens.color_decorative
                                                                        : generatedFormatColorScheme.tokens.color_card_border,
                                                            }"
                                                        >
                                                            <span
                                                                :style="{
                                                                    backgroundColor: service.token === 'accent'
                                                                        ? generatedFormatColorScheme.tokens.color_accent
                                                                        : service.token === 'decorative'
                                                                            ? generatedFormatColorScheme.tokens.color_decorative
                                                                            : generatedFormatColorScheme.tokens.color_support,
                                                                    color: service.token === 'accent'
                                                                        ? generatedFormatColorScheme.tokens.color_accent_text
                                                                        : service.token === 'decorative'
                                                                            ? generatedFormatColorScheme.tokens.color_decorative_text
                                                                            : generatedFormatColorScheme.tokens.color_support_text,
                                                                }"
                                                            >
                                                                {{ service.number }}
                                                            </span>
                                                            <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                {{ service.title }}
                                                            </strong>
                                                            <small>{{ service.text }}</small>
                                                        </article>
                                                    </section>

                                                    <section class="format-homepage-example-content-grid">
                                                        <div
                                                            class="format-homepage-example-process"
                                                            :style="{
                                                                backgroundColor: generatedFormatColorScheme.tokens.color_card_bg,
                                                                borderColor: generatedFormatColorScheme.tokens.color_card_border,
                                                            }"
                                                        >
                                                            <span
                                                                class="format-homepage-example-pill"
                                                                :style="{
                                                                    backgroundColor: generatedFormatColorScheme.tokens.color_support_soft,
                                                                    color: generatedFormatColorScheme.tokens.color_support,
                                                                }"
                                                            >
                                                                Ablauf
                                                            </span>
                                                            <h5 :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                Von Idee zu Gartenraum
                                                            </h5>
                                                            <ol>
                                                                <li
                                                                    v-for="step in homepagePreviewSteps"
                                                                    :key="step"
                                                                >
                                                                    <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg }" />
                                                                    {{ step }}
                                                                </li>
                                                            </ol>
                                                        </div>

                                                        <aside
                                                            class="format-homepage-example-testimonial"
                                                            :style="{
                                                                backgroundColor: generatedFormatColorScheme.tokens.color_support_soft,
                                                                borderColor: generatedFormatColorScheme.tokens.color_support,
                                                            }"
                                                        >
                                                            <span :style="{ color: generatedFormatColorScheme.tokens.color_support }">
                                                                "Sehr klar, sehr hochwertig, sehr einfach zu entscheiden."
                                                            </span>
                                                            <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                Familie Berger
                                                            </strong>
                                                            <small>Umbau eines Stadtgartens</small>
                                                        </aside>
                                                    </section>

                                                    <section
                                                        class="format-homepage-example-cta"
                                                        :style="{
                                                            backgroundColor: generatedFormatColorScheme.tokens.color_decorative_soft,
                                                            borderColor: generatedFormatColorScheme.tokens.color_decorative,
                                                        }"
                                                    >
                                                        <div>
                                                            <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                                Bereit für den ersten Entwurf?
                                                            </strong>
                                                            <span>Die CTA-Zone nutzt warme Akzente ohne die Seite zu überladen.</span>
                                                        </div>
                                                        <button
                                                            type="button"
                                                            :style="{
                                                                backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg,
                                                                color: generatedFormatColorScheme.tokens.color_button_primary_text,
                                                            }"
                                                        >
                                                            Anfrage senden
                                                        </button>
                                                    </section>

                                                    <footer
                                                        class="format-homepage-example-footer"
                                                        :style="{
                                                            borderColor: generatedFormatColorScheme.tokens.color_card_border,
                                                        }"
                                                    >
                                                        <strong :style="{ color: generatedFormatColorScheme.tokens.color_heading }">
                                                            Naturatelier
                                                        </strong>
                                                        <div class="format-homepage-example-palette" aria-label="Generated source colors">
                                                            <span
                                                                :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg }"
                                                                title="Primary"
                                                            />
                                                            <span
                                                                :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_accent }"
                                                                title="Accent"
                                                            />
                                                            <span
                                                                :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_support }"
                                                                title="Support"
                                                            />
                                                            <span
                                                                :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_decorative }"
                                                                title="Decorative"
                                                            />
                                                        </div>
                                                        <div class="format-homepage-example-palette" aria-label="Source colors">
                                                            <span
                                                                v-for="color in generatedFormatColorScheme.sourceColors"
                                                                :key="`homepage-preview-${color}`"
                                                                :style="{ backgroundColor: color }"
                                                                :title="color"
                                                            />
                                                        </div>
                                                    </footer>
                                                </div>
                                            </div>
                                            <div
                                                class="format-homepage-example-mobile"
                                                :style="{
                                                    backgroundColor: generatedFormatColorScheme.tokens.color_card_bg,
                                                    borderColor: generatedFormatColorScheme.tokens.color_card_border,
                                                }"
                                            >
                                                <div
                                                    class="format-homepage-example-phone"
                                                    :style="{
                                                        backgroundColor: generatedFormatColorScheme.tokens.color_page_bg,
                                                        borderColor: generatedFormatColorScheme.tokens.color_button_primary_bg,
                                                    }"
                                                >
                                                    <div
                                                        class="format-homepage-example-phone-bar"
                                                        :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_card_bg }"
                                                    >
                                                        <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg }" />
                                                        <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_card_border }" />
                                                    </div>
                                                    <div
                                                        class="format-homepage-example-phone-hero"
                                                        :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_button_primary_bg }"
                                                    >
                                                        <span :style="{ backgroundColor: generatedFormatColorScheme.tokens.color_accent }" />
                                                        <strong :style="{ color: generatedFormatColorScheme.tokens.color_button_primary_text }">
                                                            Mobile CTA
                                                        </strong>
                                                    </div>
                                                    <div class="format-homepage-example-phone-cards">
                                                        <span
                                                            v-for="color in [
                                                                generatedFormatColorScheme.tokens.color_support_soft,
                                                                generatedFormatColorScheme.tokens.color_accent_soft,
                                                                generatedFormatColorScheme.tokens.color_decorative_soft,
                                                            ]"
                                                            :key="`phone-${color}`"
                                                            :style="{ backgroundColor: color }"
                                                        />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </v-card>

                                <v-dialog v-model="isSaveFormatColorSchemeDialogOpen" persistent max-width="520">
                                    <v-card class="profile-dialog-card">
                                        <v-card-title>Farbschema speichern</v-card-title>
                                        <v-card-text>
                                            <form id="format-color-scheme-save-form" @submit.prevent="saveGeneratedFormatColorScheme">
                                                <v-text-field
                                                    v-model="formatColorSchemeName"
                                                    label="Name des Farbschemas"
                                                    prepend-inner-icon="mdi-palette-outline"
                                                    autocomplete="off"
                                                    required
                                                    autofocus
                                                />
                                                <v-alert
                                                    v-if="formatColorSchemeSaveError"
                                                    type="warning"
                                                    variant="tonal"
                                                    density="compact"
                                                >
                                                    {{ formatColorSchemeSaveError }}
                                                </v-alert>
                                            </form>
                                        </v-card-text>
                                        <v-card-actions>
                                            <v-spacer />
                                            <v-btn
                                                type="submit"
                                                form="format-color-scheme-save-form"
                                                color="primary"
                                                variant="flat"
                                            >
                                                <v-icon start icon="mdi-content-save-outline" />
                                                Speichern
                                            </v-btn>
                                        </v-card-actions>
                                    </v-card>
                                </v-dialog>
                            </section>
                        </section>

                        <section v-if="activeSection === 'helpers'">
                            <div class="dashboard-header">
                                <div>
                                    <p class="eyebrow">Helpers</p>
                                    <h1>Helpers</h1>
                                    <p class="muted">Tools and helper workflows for the selected company and client.</p>
                                </div>
                            </div>

                            <nav class="admin-submenu" aria-label="Helpers menu">
                                <button
                                    v-for="item in helperMenuItems"
                                    :key="item.key"
                                    type="button"
                                    class="admin-submenu-item"
                                    :class="{ 'is-active': activeHelperSection === item.key }"
                                    @click="navigateHelperSection(item.key)"
                                >
                                    <v-icon :icon="item.icon" size="18" />
                                    <span>{{ item.label }}</span>
                                </button>
                            </nav>

                            <section v-if="activeHelperSection === 'analyse'" class="users-section">
                                <v-alert v-if="analysisMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                    {{ analysisMessage }}
                                </v-alert>
                                <v-alert v-if="analysisError || analysesError" type="error" variant="tonal" density="compact" class="mt-6">
                                    {{ analysisError || analysesError }}
                                </v-alert>

                                <v-card flat border rounded="lg" class="users-card">
                                    <div class="section-title-row">
                                        <div>
                                            <h2>{{ analysisDetailId ? 'Analyse anzeigen' : 'Analyse' }}</h2>
                                            <p class="muted">
                                                {{ clientCompanyFilterName }} / {{ activeClientName }}
                                            </p>
                                        </div>
                                        <div class="analysis-header-actions">
                                            <v-btn
                                                v-if="analysisDetailId"
                                                type="button"
                                                variant="tonal"
                                                color="primary"
                                                @click="backToAnalyses"
                                            >
                                                <v-icon start icon="mdi-arrow-left" />
                                                Zurück
                                            </v-btn>
                                            <v-btn
                                                v-else-if="!isAnalysisFormOpen"
                                                type="button"
                                                color="primary"
                                                variant="flat"
                                                @click="openNewAnalysisForm"
                                            >
                                                <v-icon start icon="mdi-plus" />
                                                Neue Analyse
                                            </v-btn>
                                            <v-btn
                                                v-else
                                                type="button"
                                                variant="tonal"
                                                color="primary"
                                                :disabled="analysesLoading"
                                                @click="closeNewAnalysisForm"
                                            >
                                                Abbrechen
                                            </v-btn>
                                            <v-progress-circular
                                                v-if="analysesLoading"
                                                indeterminate
                                                size="22"
                                                width="2"
                                                color="primary"
                                            />
                                        </div>
                                    </div>

                                    <template v-if="!analysisDetailId">
                                        <div v-if="isAnalysisFormOpen" class="analysis-step-grid">
                                            <section class="analysis-step">
                                                <span>Step 1</span>
                                                <h3>Enter URL</h3>
                                                <form class="analysis-form" @submit.prevent="checkAnalysisUrl">
                                                    <v-text-field
                                                        v-model="analysisUrl"
                                                        type="url"
                                                        label="URL"
                                                        prepend-inner-icon="mdi-web"
                                                        required
                                                    />
                                                    <v-btn type="submit" color="primary" variant="tonal" :loading="analysesLoading">
                                                        <v-icon start icon="mdi-magnify" />
                                                        Check URL
                                                    </v-btn>
                                                </form>
                                            </section>

                                            <section class="analysis-step">
                                                <span>Step 2</span>
                                                <h3>Check URL</h3>
                                                <p v-if="analysisCheckedUrl" class="muted">
                                                    {{ analysisCheckedUrl.url }} responded with status {{ analysisCheckedUrl.status }}.
                                                </p>
                                                <p v-else class="muted">
                                                    Enter a URL and check it before starting the analysis.
                                                </p>
                                            </section>

                                            <section class="analysis-step">
                                                <span>Step 3</span>
                                                <h3>Analyze website</h3>
                                                <p class="muted">The analysis is queued for the selected company and active client.</p>
                                                <v-btn
                                                    type="button"
                                                    variant="flat"
                                                    class="analysis-start-button"
                                                    :disabled="!analysisCheckedUrl"
                                                    :loading="analysesLoading"
                                                    @click="startAnalysis"
                                                >
                                                    <v-icon start icon="mdi-play-circle-outline" />
                                                    Analyse starten
                                                </v-btn>
                                            </section>
                                        </div>

                                        <section v-if="!isAnalysisFormOpen" class="analysis-results">
                                            <div class="section-title-row">
                                                <div>
                                                    <span class="analysis-step-label">Analysen</span>
                                                    <h3>Analysierte Seiten</h3>
                                                </div>
                                                <v-chip
                                                    v-if="hasLiveAnalyses"
                                                    color="primary"
                                                    variant="tonal"
                                                    size="small"
                                                >
                                                    <v-progress-circular indeterminate size="14" width="2" class="mr-2" />
                                                    Live
                                                </v-chip>
                                            </div>

                                            <p v-if="analyses.length === 0" class="muted analysis-empty-state">
                                                Bisher keine Analyse.
                                            </p>

                                            <v-table v-else class="users-table">
                                                <thead>
                                                    <tr>
                                                        <th>URL</th>
                                                        <th>Status</th>
                                                        <th>Seiten</th>
                                                        <th>Dateien</th>
                                                        <th class="analysis-json-column">JSON</th>
                                                        <th class="analysis-actions-column">Aktionen</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <tr v-for="analysis in analyses" :key="analysis.id">
                                                        <td>{{ analysis.url }}</td>
                                                        <td>
                                                            <v-chip
                                                                :color="analysisStatusColor(analysis)"
                                                                size="small"
                                                                variant="tonal"
                                                            >
                                                                <v-progress-circular
                                                                    v-if="isLiveAnalysis(analysis)"
                                                                    indeterminate
                                                                    size="12"
                                                                    width="2"
                                                                    class="mr-2"
                                                                />
                                                                {{ analysis.status }}
                                                            </v-chip>
                                                            <small v-if="isLiveAnalysis(analysis)" class="analysis-table-progress">
                                                                {{ analysisStepLabel(analysis) }}
                                                                <template v-if="shouldShowReachabilityProgress(analysis)">
                                                                    {{ reachabilityProgressLabel(analysis) }}
                                                                </template>
                                                            </small>
                                                        </td>
                                                        <td>{{ analysis.pages_count }}</td>
                                                        <td>{{ analysis.assets_count }}</td>
                                                        <td class="analysis-json-column">
                                                            <span :title="analysis.result_path ?? '-'">
                                                                {{ analysis.result_path ?? '-' }}
                                                            </span>
                                                        </td>
                                                        <td class="analysis-actions-column">
                                                            <v-btn
                                                                type="button"
                                                                size="small"
                                                                color="primary"
                                                                variant="tonal"
                                                                @click="openAnalysis(analysis)"
                                                            >
                                                                <v-icon start icon="mdi-eye-outline" />
                                                                Anzeigen
                                                            </v-btn>
                                                            <v-btn
                                                                v-if="isLiveAnalysis(analysis)"
                                                                type="button"
                                                                size="small"
                                                                color="warning"
                                                                variant="tonal"
                                                                :loading="analysesLoading"
                                                                @click="cancelAnalysis(analysis)"
                                                            >
                                                                <v-icon start icon="mdi-stop-circle-outline" />
                                                                Abbrechen
                                                            </v-btn>
                                                            <v-btn
                                                                type="button"
                                                                icon
                                                                size="small"
                                                                color="error"
                                                                variant="tonal"
                                                                aria-label="Delete analysis"
                                                                :disabled="isLiveAnalysis(analysis)"
                                                                @click="openDeleteAnalysisDialog(analysis)"
                                                            >
                                                                <v-icon icon="mdi-delete-outline" />
                                                            </v-btn>
                                                        </td>
                                                    </tr>
                                                </tbody>
                                            </v-table>
                                        </section>
                                    </template>

                                    <section v-else class="analysis-detail">
                                        <div class="analysis-detail-grid">
                                            <div class="analysis-detail-panel">
                                                <div class="analysis-panel-heading">
                                                    <div>
                                                        <span class="analysis-step-label">Übersicht</span>
                                                        <h3>{{ currentAnalysis?.url ?? 'Analyse' }}</h3>
                                                    </div>
                                                    <div class="analysis-heading-actions">
                                                        <v-btn
                                                            v-if="isLiveAnalysis(currentAnalysis)"
                                                            type="button"
                                                            size="small"
                                                            color="warning"
                                                            variant="tonal"
                                                            :loading="analysesLoading"
                                                            @click="cancelAnalysis(currentAnalysis)"
                                                        >
                                                            <v-icon start icon="mdi-stop-circle-outline" />
                                                            Abbrechen
                                                        </v-btn>
                                                        <v-btn
                                                            type="button"
                                                            size="small"
                                                            color="primary"
                                                            variant="tonal"
                                                            :disabled="isLiveAnalysis(currentAnalysis)"
                                                            :loading="analysesLoading"
                                                            @click="rerunAnalysis"
                                                        >
                                                            <v-icon start icon="mdi-refresh" />
                                                            Erneute Analyse
                                                        </v-btn>
                                                    </div>
                                                </div>
                                                <div v-if="isLiveAnalysis(currentAnalysis)" class="analysis-step-status-list">
                                                    <div
                                                        :class="{ 'is-active': currentAnalysis?.analysis_step === 'analysis' }"
                                                        class="analysis-step-status"
                                                    >
                                                        <span>Step 1</span>
                                                        <strong>Website analysieren</strong>
                                                        <small>{{ currentAnalysis?.pages_count ?? 0 }} Seiten gefunden</small>
                                                    </div>
                                                    <div
                                                        :class="{ 'is-active': currentAnalysis?.analysis_step === 'reachability' }"
                                                        class="analysis-step-status"
                                                    >
                                                        <span>Step 2</span>
                                                        <strong>Erreichbarkeit prüfen</strong>
                                                        <small>{{ reachabilityProgressLabel(currentAnalysis) }}</small>
                                                    </div>
                                                </div>
                                                <dl class="analysis-definition-grid">
                                                    <div>
                                                        <dt>Status</dt>
                                                        <dd>
                                                            <v-chip
                                                                :color="analysisStatusColor(currentAnalysis)"
                                                                size="small"
                                                                variant="tonal"
                                                            >
                                                                <v-progress-circular
                                                                    v-if="isLiveAnalysis(currentAnalysis)"
                                                                    indeterminate
                                                                    size="12"
                                                                    width="2"
                                                                    class="mr-2"
                                                                />
                                                                {{ currentAnalysis?.status ?? '-' }}
                                                            </v-chip>
                                                        </dd>
                                                    </div>
                                                    <div>
                                                        <dt>Unternehmen</dt>
                                                        <dd>{{ currentAnalysis?.company_name ?? '-' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Client</dt>
                                                        <dd>{{ currentAnalysis?.client_name ?? '-' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Host</dt>
                                                        <dd>{{ currentAnalysis?.host ?? '-' }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Seiten</dt>
                                                        <dd>{{ currentAnalysis?.pages_count ?? 0 }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Dateien</dt>
                                                        <dd>{{ currentAnalysis?.assets_count ?? 0 }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Gestartet</dt>
                                                        <dd>{{ formatDateTime(currentAnalysis?.started_at) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Fertig</dt>
                                                        <dd>{{ formatDateTime(currentAnalysis?.completed_at) }}</dd>
                                                    </div>
                                                    <div class="analysis-definition-wide">
                                                        <dt>JSON</dt>
                                                        <dd>{{ currentAnalysis?.result_path ?? '-' }}</dd>
                                                    </div>
                                                </dl>
                                            </div>

                                            <div class="analysis-detail-panel">
                                                <span class="analysis-step-label">Homepage</span>
                                                <h3>{{ currentReportHomepage.title ?? '-' }}</h3>
                                                <dl class="analysis-definition-grid">
                                                    <div>
                                                        <dt>Sprache</dt>
                                                        <dd>{{ languageLabel(currentReportHomepage.meta?.language) }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Wörter</dt>
                                                        <dd>{{ currentReportHomepage.content?.word_count ?? 0 }}</dd>
                                                    </div>
                                                    <div class="analysis-definition-wide">
                                                        <dt>Description</dt>
                                                        <dd>{{ currentReportHomepage.meta?.landing_description ?? currentReportHomepage.meta?.description ?? '-' }}</dd>
                                                    </div>
                                                </dl>
                                            </div>
                                        </div>

                                        <v-alert v-if="!currentReport && !analysesLoading" type="info" variant="tonal" density="compact" class="mt-6">
                                            Für diese Analyse ist noch kein JSON-Bericht vorhanden.
                                        </v-alert>

                                        <template v-if="currentReport">
                                            <div class="analysis-metric-grid">
                                                <div>
                                                    <span>Seiten gefunden</span>
                                                    <strong>{{ currentReportSummary.pages_discovered ?? 0 }}</strong>
                                                </div>
                                                <div>
                                                    <span>Website-Dateien</span>
                                                    <strong>{{ currentReportSummary.site_assets ?? 0 }}</strong>
                                                </div>
                                                <div>
                                                    <span>Bilder gesamt</span>
                                                    <strong>{{ currentReportSummary.site_images ?? 0 }}</strong>
                                                </div>
                                            </div>

                                            <div v-if="currentReportHeaderMenu.found" class="analysis-detail-panel">
                                                <div class="analysis-panel-heading">
                                                    <div>
                                                        <span class="analysis-step-label">Navigation</span>
                                                        <h3>Header-Menü gefunden</h3>
                                                    </div>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        size="x-small"
                                                        variant="text"
                                                        color="primary"
                                                        :aria-label="isNavigationMenuExpanded('header') ? 'Header-Menü einklappen' : 'Header-Menü aufklappen'"
                                                        @click="toggleNavigationMenu('header')"
                                                    >
                                                        <v-icon :icon="isNavigationMenuExpanded('header') ? 'mdi-chevron-up' : 'mdi-chevron-down'" />
                                                    </v-btn>
                                                </div>

                                                <dl class="analysis-definition-grid">
                                                    <div>
                                                        <dt>Links im Header-Menü</dt>
                                                        <dd>{{ currentReportHeaderMenu.link_count ?? 0 }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Gefunden auf Seiten</dt>
                                                        <dd>{{ currentReportHeaderMenu.page_count ?? 0 }}</dd>
                                                    </div>
                                                </dl>

                                                <div v-if="isNavigationMenuExpanded('header')" class="analysis-collapsible-body">
                                                    <div v-if="navigationMenuLinks(currentReportHeaderMenu).length > 0" class="analysis-url-list analysis-navigation-link-list">
                                                        <a
                                                            v-for="link in navigationMenuLinks(currentReportHeaderMenu)"
                                                            :key="`header-${link}`"
                                                            :href="link"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            {{ link }}
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="analysis-detail-panel">
                                                <div class="analysis-panel-heading">
                                                    <div>
                                                        <span class="analysis-step-label">Navigation</span>
                                                        <h3>{{ currentReportFooterInformation.found ? 'Footer-Menü gefunden' : 'Footer-Menü nicht gefunden' }}</h3>
                                                    </div>
                                                    <v-btn
                                                        type="button"
                                                        icon
                                                        size="x-small"
                                                        variant="text"
                                                        color="primary"
                                                        :aria-label="isNavigationMenuExpanded('footer') ? 'Footer-Menü einklappen' : 'Footer-Menü aufklappen'"
                                                        @click="toggleNavigationMenu('footer')"
                                                    >
                                                        <v-icon :icon="isNavigationMenuExpanded('footer') ? 'mdi-chevron-up' : 'mdi-chevron-down'" />
                                                    </v-btn>
                                                </div>

                                                <dl class="analysis-definition-grid">
                                                    <div>
                                                        <dt>Links im Footer-Menü</dt>
                                                        <dd>{{ currentReportFooterInformation.link_count ?? 0 }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Gefunden auf Seiten</dt>
                                                        <dd>{{ currentReportFooterInformation.page_count ?? 0 }}</dd>
                                                    </div>
                                                </dl>

                                                <div v-if="isNavigationMenuExpanded('footer')" class="analysis-collapsible-body">
                                                    <div v-if="navigationMenuLinks(currentReportFooterInformation).length > 0" class="analysis-url-list analysis-navigation-link-list">
                                                        <a
                                                            v-for="link in navigationMenuLinks(currentReportFooterInformation)"
                                                            :key="`footer-${link}`"
                                                            :href="link"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            {{ link }}
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="analysis-detail-grid">
                                                <div class="analysis-detail-panel">
                                                    <span class="analysis-step-label">Farben</span>
                                                    <div class="analysis-color-title-row">
                                                        <h3>Verwendete Farben</h3>
                                                        <v-chip
                                                            v-if="selectedAnalysisColors.length > 0"
                                                            size="small"
                                                            color="primary"
                                                            variant="tonal"
                                                        >
                                                            {{ selectedAnalysisColors.length }} ausgewählt
                                                        </v-chip>
                                                    </div>
                                                    <div class="analysis-color-list">
                                                        <button
                                                            v-for="color in currentReportTopColors"
                                                            :key="color.value"
                                                            type="button"
                                                            class="analysis-color-item"
                                                            :class="{ 'is-selected': isAnalysisColorSelected(color) }"
                                                            :aria-pressed="isAnalysisColorSelected(color)"
                                                            :aria-label="`${color.value} auswählen`"
                                                            @click="toggleAnalysisColorSelection(color)"
                                                        >
                                                            <span :style="{ backgroundColor: color.value }" />
                                                            <strong>{{ color.value }}</strong>
                                                            <small>{{ color.count }}x</small>
                                                            <v-icon
                                                                class="analysis-color-check"
                                                                :icon="isAnalysisColorSelected(color) ? 'mdi-check-circle' : 'mdi-checkbox-blank-circle-outline'"
                                                                size="18"
                                                            />
                                                        </button>
                                                    </div>
                                                </div>

                                                <div class="analysis-detail-panel">
                                                    <span class="analysis-step-label">Fonts</span>
                                                    <h3>Verwendete Schriften</h3>
                                                    <div class="analysis-chip-list">
                                                        <v-chip
                                                            v-for="font in currentReportTopFonts"
                                                            :key="font.value"
                                                            size="small"
                                                            variant="tonal"
                                                        >
                                                            {{ font.value }} ({{ font.count }})
                                                        </v-chip>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="analysis-detail-panel">
                                                <span class="analysis-step-label">Dateien</span>
                                                <h3>Assets</h3>
                                                <dl class="analysis-definition-grid">
                                                    <div>
                                                        <dt>Bilder</dt>
                                                        <dd>{{ currentReportImages.length }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Andere Dateien</dt>
                                                        <dd>{{ currentReportOtherFiles.length }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>CSS</dt>
                                                        <dd>{{ currentReportSiteAssets.stylesheets?.length ?? 0 }}</dd>
                                                    </div>
                                                    <div>
                                                        <dt>Scripts</dt>
                                                        <dd>{{ currentReportSiteAssets.scripts?.length ?? 0 }}</dd>
                                                    </div>
                                                </dl>
                                            </div>

                                            <div class="analysis-detail-panel">
                                                <span class="analysis-step-label">Seiten</span>
                                                <h3>Gefundene Seiten</h3>
                                                <v-table class="users-table">
                                                    <thead>
                                                        <tr>
                                                            <th>URL</th>
                                                            <th>Titel</th>
                                                            <th>Wörter</th>
                                                            <th>Bilder</th>
                                                            <th>Dateien</th>
                                                            <th>Links</th>
                                                            <th class="analysis-page-toggle-column"></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <template v-for="page in currentReportPages" :key="page.url">
                                                            <tr>
                                                                <td>
                                                                    <a
                                                                        class="analysis-table-link"
                                                                        :href="page.url"
                                                                        target="_blank"
                                                                        rel="noreferrer"
                                                                    >
                                                                        {{ page.url }}
                                                                    </a>
                                                                </td>
                                                                <td>{{ formatPageTitle(page.title) }}</td>
                                                                <td>{{ page.content?.word_count ?? 0 }}</td>
                                                                <td>{{ reportPageImages(page).length }}</td>
                                                                <td>{{ reportPageFiles(page).length }}</td>
                                                                <td>{{ reportPageLinks(page).length }}</td>
                                                                <td class="analysis-page-toggle-column">
                                                                    <v-btn
                                                                        type="button"
                                                                        icon
                                                                        size="x-small"
                                                                        variant="text"
                                                                        color="primary"
                                                                        :aria-label="expandedReportPageUrl === page.url ? 'Seite einklappen' : 'Seite aufklappen'"
                                                                        @click="toggleReportPage(page)"
                                                                    >
                                                                        <v-icon :icon="expandedReportPageUrl === page.url ? 'mdi-chevron-up' : 'mdi-chevron-down'" />
                                                                    </v-btn>
                                                                </td>
                                                            </tr>
                                                            <tr v-if="expandedReportPageUrl === page.url" class="analysis-page-detail-row">
                                                                <td colspan="7">
                                                                    <div class="analysis-page-detail">
                                                                        <div class="analysis-page-detail-actions">
                                                                            <v-btn
                                                                                type="button"
                                                                                size="small"
                                                                                :variant="reportPageListMode(page) === 'images' ? 'flat' : 'tonal'"
                                                                                color="primary"
                                                                                @click="setReportPageListMode(page, 'images')"
                                                                            >
                                                                                Bilder
                                                                            </v-btn>
                                                                            <v-btn
                                                                                type="button"
                                                                                size="small"
                                                                                :variant="reportPageListMode(page) === 'files' ? 'flat' : 'tonal'"
                                                                                color="primary"
                                                                                @click="setReportPageListMode(page, 'files')"
                                                                            >
                                                                                Dateien
                                                                            </v-btn>
                                                                            <v-btn
                                                                                type="button"
                                                                                size="small"
                                                                                :variant="reportPageListMode(page) === 'links' ? 'flat' : 'tonal'"
                                                                                color="primary"
                                                                                @click="setReportPageListMode(page, 'links')"
                                                                            >
                                                                                Links
                                                                            </v-btn>
                                                                        </div>

                                                                        <div v-if="reportPageListMode(page) === 'images'" class="analysis-url-list analysis-page-detail-list">
                                                                            <span v-if="reportPageImages(page).length === 0">Keine Bilder gefunden.</span>
                                                                            <a
                                                                                v-for="(image, imageIndex) in reportPageImages(page)"
                                                                                :key="image"
                                                                                :href="image"
                                                                                target="_blank"
                                                                                rel="noreferrer"
                                                                            >
                                                                                {{ imageIndex + 1 }}. {{ image }}
                                                                            </a>
                                                                        </div>

                                                                        <div v-else-if="reportPageListMode(page) === 'files'" class="analysis-url-list analysis-page-detail-list">
                                                                            <span v-if="reportPageFiles(page).length === 0">Keine Dateien gefunden.</span>
                                                                            <a
                                                                                v-for="(file, fileIndex) in reportPageFiles(page)"
                                                                                :key="file"
                                                                                :href="file"
                                                                                target="_blank"
                                                                                rel="noreferrer"
                                                                            >
                                                                                {{ fileIndex + 1 }}. {{ file }}
                                                                            </a>
                                                                        </div>

                                                                        <div v-else class="analysis-url-list analysis-page-detail-list">
                                                                            <span v-if="reportPageLinks(page).length === 0">Keine Links gefunden.</span>
                                                                            <a
                                                                                v-for="(link, linkIndex) in reportPageLinks(page)"
                                                                                :key="link"
                                                                                :href="link"
                                                                                target="_blank"
                                                                                rel="noreferrer"
                                                                            >
                                                                                {{ linkIndex + 1 }}. {{ link }}
                                                                            </a>
                                                                        </div>
                                                                    </div>
                                                                </td>
                                                            </tr>
                                                        </template>
                                                    </tbody>
                                                </v-table>
                                            </div>

                                            <div v-if="isCurrentAnalysisReachabilityRunning" class="analysis-detail-panel">
                                                <span class="analysis-step-label">Step 2</span>
                                                <h3>Erreichbarkeit wird geprüft</h3>
                                                <p class="analysis-panel-muted">
                                                    {{ reachabilityProgressLabel(currentAnalysis) }} Links, Bilder und Dateien geprüft.
                                                </p>
                                            </div>

                                            <div v-else-if="currentReportErrors.length > 0" class="analysis-detail-panel">
                                                <span class="analysis-step-label">Fehler</span>
                                                <h3>Analyse-Fehler</h3>
                                                <div class="analysis-error-list">
                                                    <div
                                                        v-for="reportError in currentReportErrors"
                                                        :key="`${reportError.url}-${reportError.message}`"
                                                        class="analysis-error-item"
                                                    >
                                                        <a
                                                            :href="reportError.url"
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                        >
                                                            {{ reportError.url }}
                                                        </a>
                                                        <span>
                                                            {{ reportError.type ?? 'resource' }}
                                                            <template v-if="reportError.status">- Status {{ reportError.status }}</template>
                                                            - {{ reportError.message }}
                                                        </span>
                                                        <div v-if="reportError.found_on?.length > 0" class="analysis-error-found-on">
                                                            <small>Gefunden auf:</small>
                                                            <a
                                                                v-for="foundOnUrl in reportError.found_on"
                                                                :key="`${reportError.url}-${foundOnUrl}`"
                                                                :href="foundOnUrl"
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                {{ foundOnUrl }}
                                                            </a>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </template>
                                    </section>

                                    <v-dialog v-model="isDeleteAnalysisDialogOpen" persistent max-width="460">
                                        <v-card class="profile-dialog-card">
                                            <v-card-title>Delete analysis</v-card-title>
                                            <v-card-text>
                                                <p>Delete {{ selectedAnalysis?.url }}?</p>
                                            </v-card-text>
                                            <v-card-actions>
                                                <v-spacer />
                                                <v-btn type="button" variant="text" :disabled="analysesLoading" @click="abortDeleteAnalysisDialog">
                                                    Abort
                                                </v-btn>
                                                <v-btn type="button" color="error" variant="flat" :loading="analysesLoading" @click="deleteAnalysis">
                                                    <v-icon start icon="mdi-delete-outline" />
                                                    Delete
                                                </v-btn>
                                            </v-card-actions>
                                        </v-card>
                                    </v-dialog>
                                </v-card>
                            </section>
                        </section>

                        <section v-if="activeSection === 'profile'">
                            <div class="dashboard-header">
                                <div>
                                    <p class="eyebrow">Profil</p>
                                    <h1>Account settings</h1>
                                    <p class="muted">Change your first name, last name, and password.</p>
                                </div>
                            </div>

                            <v-alert v-if="profileMessage" type="success" variant="tonal" density="compact" class="mt-6">
                                {{ profileMessage }}
                            </v-alert>
                            <v-alert v-if="profileError || error" type="error" variant="tonal" density="compact" class="mt-6">
                                {{ profileError || error }}
                            </v-alert>

                            <div class="profile-grid">
                                <v-card flat border rounded="lg" class="profile-card">
                                    <div class="profile-card-header">
                                        <h2>Name</h2>
                                        <v-btn
                                            type="button"
                                            icon
                                            variant="text"
                                            color="primary"
                                            aria-label="Edit name"
                                            @click="openNameDialog"
                                        >
                                            <v-icon icon="mdi-pencil-outline" />
                                        </v-btn>
                                    </div>

                                    <div class="profile-name-display">
                                        <v-icon icon="mdi-account-outline" size="28" color="primary" />
                                        <div>
                                            <p>{{ profileDisplayName }}</p>
                                            <span>{{ user?.email }}</span>
                                        </div>
                                    </div>
                                </v-card>

                                <v-card flat border rounded="lg" class="profile-card">
                                    <div class="profile-card-header">
                                        <h2>Password</h2>
                                        <v-btn
                                            type="button"
                                            icon
                                            variant="text"
                                            color="primary"
                                            aria-label="Edit password"
                                            @click="openPasswordDialog"
                                        >
                                            <v-icon icon="mdi-pencil-outline" />
                                        </v-btn>
                                    </div>

                                    <div class="profile-name-display">
                                        <v-icon icon="mdi-lock-outline" size="28" color="primary" />
                                        <div>
                                            <p>********</p>
                                            <span>Password is protected</span>
                                        </div>
                                    </div>
                                </v-card>
                            </div>

                            <v-dialog v-model="isNameDialogOpen" persistent max-width="520">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Edit name</v-card-title>
                                    <v-card-text>
                                        <form id="profile-name-form" @submit.prevent="saveProfileName">
                                            <v-text-field
                                                v-model="profileLastName"
                                                label="Last name"
                                                prepend-inner-icon="mdi-account-outline"
                                                autocomplete="family-name"
                                                required
                                            />
                                            <v-text-field
                                                v-model="profileFirstName"
                                                label="First name"
                                                prepend-inner-icon="mdi-account-outline"
                                                autocomplete="given-name"
                                                required
                                            />
                                        </form>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="loading" @click="abortNameEdit">
                                            Abort
                                        </v-btn>
                                        <v-btn type="submit" form="profile-name-form" color="primary" variant="flat" :loading="loading">
                                            <v-icon start icon="mdi-content-save-outline" />
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>

                            <v-dialog v-model="isPasswordDialogOpen" persistent max-width="520">
                                <v-card class="profile-dialog-card">
                                    <v-card-title>Edit password</v-card-title>
                                    <v-card-text>
                                        <form id="profile-password-form" @submit.prevent="savePassword">
                                            <v-text-field
                                                v-model="newPassword"
                                                type="password"
                                                label="Password"
                                                prepend-inner-icon="mdi-lock-reset"
                                                autocomplete="new-password"
                                                required
                                            />
                                        </form>
                                    </v-card-text>
                                    <v-card-actions>
                                        <v-spacer />
                                        <v-btn type="button" variant="text" :disabled="loading" @click="abortPasswordEdit">
                                            Abort
                                        </v-btn>
                                        <v-btn type="submit" form="profile-password-form" color="primary" variant="flat" :loading="loading">
                                            <v-icon start icon="mdi-content-save-outline" />
                                            Save
                                        </v-btn>
                                    </v-card-actions>
                                </v-card>
                            </v-dialog>
                        </section>
                    </section>
                </v-main>
            </div>
        </template>
    </v-app>
</template>
