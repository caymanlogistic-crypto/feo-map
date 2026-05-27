
let map, placemarks = [], groupsData = [], flightStatusFilters = {}, customLayerFilters = {};
let selectedOrder = [];
let weightById = {};
let svgCache = {};
let popupsDisabled = true;
let multiRoute = null;
let editingRouteId = null;
const UNLOAD_COORDS = [55.3228, 38.6969];
const UNLOAD_ADDRESS = '\u041c\u043e\u0441\u043a\u043e\u0432\u0441\u043a\u0430\u044f \u043e\u0431\u043b., \u0433 \u0412\u043e\u0441\u043a\u0440\u0435\u0441\u0435\u043d\u0441\u043a, \u0443\u043b \u041a\u0438\u0440\u043e\u0432\u0430, \u0434 3 \u0432';

// === TRACKERS AND COLLECTIONS ===
let trackerPlacemarks = [];
let trackerUpdateInterval = null;
let isUpdatingTrackers = false;
let showTransport = true;
let transportDisplayMode = 'active';
let requestsCollection, trackersCollection;
let warehousesCollection;
let warehousesData = [];
let warehousesVisible = true;
let warehouseCreateTargetFieldId = '';
let warehouseAddressGeocoded = false;
let driverCreateInFlight = false;
let driverMenuVisible = false;
let driverMenuItems = [];
const TXT = {
    notSpecified: '\u041d\u0435 \u0443\u043a\u0430\u0437\u0430\u043d',
    requests: '\u0417\u0430\u044f\u0432\u043a\u0438',
    request: '\u0417\u0430\u044f\u0432\u043a\u0430',
    totalWeight: '\u041e\u0431\u0449\u0438\u0439 \u0432\u0435\u0441',
    sender: '\u0413\u0440\u0443\u0437\u043e\u043e\u0442\u043f\u0440\u0430\u0432\u0438\u0442\u0435\u043b\u044c',
    address: '\u0410\u0434\u0440\u0435\u0441',
    baseStatus: '\u041e\u0441\u043d\u043e\u0432\u043d\u043e\u0439 \u0441\u0442\u0430\u0442\u0443\u0441',
    routeStatus: '\u0421\u0442\u0430\u0442\u0443\u0441 \u0440\u0435\u0439\u0441\u0430',
    routeId: 'ID \u0440\u0435\u0439\u0441\u0430',
    status: '\u0421\u0442\u0430\u0442\u0443\u0441',
    category: '\u041a\u0430\u0442\u0435\u0433\u043e\u0440\u0438\u044f',
    availableForPickup: '\u0414\u043e\u0441\u0442\u0443\u043f\u043d\u043e \u043a \u0432\u044b\u0432\u043e\u0437\u0443',
    loading: '\u0417\u0430\u0433\u0440\u0443\u0437\u043a\u0430...',
    noSavedRoutes: '\u041d\u0435\u0442 \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u043d\u044b\u0445 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u043e\u0432',
    loadError: '\u041e\u0448\u0438\u0431\u043a\u0430 \u0437\u0430\u0433\u0440\u0443\u0437\u043a\u0438',
    requestsCount: '\u0417\u0430\u044f\u0432\u043e\u043a',
    addressesCount: '\u0410\u0434\u0440\u0435\u0441\u043e\u0432'
};
const UI = {
    pin: '',
    truck: '',
    ruler: '\u0414\u0438\u0441\u0442\u0430\u043d\u0446\u0438\u044f:',
    clock: '\u0412\u0440\u0435\u043c\u044f:',
    bullet: ' \u2022 ',
    emDash: ' \u2014 ',
    kg: '\u043a\u0433',
    km: '\u043a\u043c',
    m: '\u043c',
    min: '\u043c\u0438\u043d',
    hourShort: '\u0447',
    totalWeightLabel: '\u041e\u0431\u0449\u0438\u0439 \u0432\u0435\u0441',
    routeCreate: '\u0421\u043e\u0437\u0434\u0430\u043d\u0438\u0435 \u043d\u043e\u0432\u043e\u0433\u043e \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430',
    routeSave: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u043c\u0430\u0440\u0448\u0440\u0443\u0442',
    routeUpdate: '\u041e\u0431\u043d\u043e\u0432\u0438\u0442\u044c \u043c\u0430\u0440\u0448\u0440\u0443\u0442',
    routeEditTitle: '\u0420\u0435\u0434\u0430\u043a\u0442\u0438\u0440\u043e\u0432\u0430\u043d\u0438\u0435 \u0440\u0435\u0439\u0441\u0430',
    modalPlanStart: '\u0412\u044b\u0432\u043e\u0437 \u0437\u0430\u043f\u043b\u0430\u043d\u0438\u0440\u043e\u0432\u0430\u043d \u043d\u0430 \u0434\u0430\u0442\u044b',
    modalPlanEnd: '\u041f\u043e',
    modalActualDates: '\u0424\u0430\u043a\u0442\u0438\u0447\u0435\u0441\u043a\u0438\u0435 \u0434\u0430\u0442\u044b \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u043a\u0438',
    modalDriver: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c / \u043c\u0430\u0448\u0438\u043d\u0430',
    modalCost: '\u0421\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c',
    modalSave: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c/\u041e\u0431\u043d\u043e\u0432\u0438\u0442\u044c',
    modalCancel: '\u041e\u0442\u043c\u0435\u043d\u0430',
    modalToFound: '\u0421\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441',
    modalToStarted: '\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c',
    modalStartTitle: '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u201c\u0412\u044b\u0432\u043e\u0437 \u043d\u0430\u0447\u0430\u043b\u0441\u044f\u201d',
    modalStartDate: '\u0414\u0430\u0442\u0430 \u043d\u0430\u0447\u0430\u043b\u0430 \u0432\u044b\u0432\u043e\u0437\u0430',
    driverMissing: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c \u043d\u0435 \u0443\u043a\u0430\u0437\u0430\u043d',
    driverPrefix: '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c #',
    weight: '\u0412\u0435\u0441',
    group: '\u0413\u0440\u0443\u043f\u043f\u0430',
    routePrefix: '\u0420\u0435\u0439\u0441 #'
};
UI.chooseDriver = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044f';
UI.msgFlightDataNotFound = '\u0414\u0430\u043d\u043d\u044b\u0435 \u0440\u0435\u0439\u0441\u0430 \u043d\u0435 \u043d\u0430\u0439\u0434\u0435\u043d\u044b';
UI.msgSetFlightAndDriver = '\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u0440\u0435\u0439\u0441 \u0438 \u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044f';
UI.msgSaveFailed = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0441\u043e\u0445\u0440\u0430\u043d\u0438\u0442\u044c \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f';
UI.msgNetworkUpdateFlight = '\u041e\u0448\u0438\u0431\u043a\u0430 \u0441\u0435\u0442\u0438 \u043f\u0440\u0438 \u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u0438 \u0440\u0435\u0439\u0441\u0430';
UI.msgConfirmToFound = '\u0421\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441?';
UI.msgTransferFailed = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u043f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0440\u0435\u0439\u0441';
UI.msgNetworkTransfer = '\u041e\u0448\u0438\u0431\u043a\u0430 \u0441\u0435\u0442\u0438 \u043f\u0440\u0438 \u043f\u0435\u0440\u0435\u0432\u043e\u0434\u0435 \u0440\u0435\u0439\u0441\u0430';
UI.msgInvalidFlightId = '\u041d\u0435\u043a\u043e\u0440\u0440\u0435\u043a\u0442\u043d\u044b\u0439 ID \u0440\u0435\u0439\u0441\u0430';
UI.msgPickAtLeastOneRequest = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u0445\u043e\u0442\u044f \u0431\u044b \u043e\u0434\u043d\u0443 \u0437\u0430\u044f\u0432\u043a\u0443';
UI.msgEnterRouteName = '\u0412\u0432\u0435\u0434\u0438\u0442\u0435 \u043d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430:';
UI.msgRouteNameEmpty = '\u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430 \u043d\u0435 \u043c\u043e\u0436\u0435\u0442 \u0431\u044b\u0442\u044c \u043f\u0443\u0441\u0442\u044b\u043c';
UI.msgSaving = '\u23F3 \u0421\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u0435...';
UI.msgErrorPrefix = '\u041e\u0448\u0438\u0431\u043a\u0430: ';
UI.msgNetworkError = '\u041e\u0448\u0438\u0431\u043a\u0430 \u0441\u0435\u0442\u0438';
UI.msgDeleteRouteConfirm = '\u0423\u0434\u0430\u043b\u0438\u0442\u044c \u043c\u0430\u0440\u0448\u0440\u0443\u0442?';
UI.msgMinTwoRequests = '\u041c\u0438\u043d\u0438\u043c\u0443\u043c 2 \u0437\u0430\u044f\u0432\u043a\u0438';
UI.msgCoordsNotFound = '\u041a\u043e\u043e\u0440\u0434\u0438\u043d\u0430\u0442\u044b \u043d\u0435 \u043d\u0430\u0439\u0434\u0435\u043d\u044b';
UI.labelEditRoute = '\u0420\u0435\u0434\u0430\u043a\u0442\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441';
UI.labelToFound = '\u0412 \u00ab\u0420\u0435\u0439\u0441 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d\u00bb';
UI.labelToStarted = '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u0412\u044b\u0432\u043e\u0437 \u043d\u0430\u0447\u0430\u043b\u0441\u044f';
UI.labelBackToPlanned = '\u0412\u0435\u0440\u043d\u0443\u0442\u044c \u0432 \u00ab\u041f\u043b\u0430\u043d\u0438\u0440\u0443\u0435\u043c\u044b\u0439\u00bb';
UI.labelBackToFound = '\u0412\u0435\u0440\u043d\u0443\u0442\u044c \u0432 \u00ab\u0420\u0435\u0439\u0441 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d\u00bb';
UI.labelDriver = '\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c';
UI.msgBackToPlanned = '\u0420\u0435\u0439\u0441 #{id} \u0431\u0443\u0434\u0435\u0442 \u0432\u043e\u0437\u0432\u0440\u0430\u0449\u0435\u043d \u0432 \u00ab\u041f\u043b\u0430\u043d\u0438\u0440\u0443\u0435\u043c\u044b\u0439\u00bb.\\n\\n\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c?';
UI.msgBackToFound = '\u0420\u0435\u0439\u0441 #{id} \u0431\u0443\u0434\u0435\u0442 \u0432\u043e\u0437\u0432\u0440\u0430\u0449\u0451\u043d \u0432 \u00ab\u0420\u0435\u0439\u0441 \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u043d\u00bb.\\n\\n\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c?';
UI.msgToCompleted = '\u0420\u0435\u0439\u0441 #{id} \u0431\u0443\u0434\u0435\u0442 \u043f\u0435\u0440\u0435\u0432\u0435\u0434\u0435\u043d \u0432 \u00ab\u0413\u0440\u0443\u0437 \u0441\u0434\u0430\u043d\u00bb.\\n\\n\u041f\u043e\u0434\u0442\u0432\u0435\u0440\u0434\u0438\u0442\u044c?';
UI.msgNeedEndDate = '\u0423\u043a\u0430\u0436\u0438\u0442\u0435 \u0434\u0430\u0442\u0443 \u0437\u0430\u0432\u0435\u0440\u0448\u0435\u043d\u0438\u044f \u043f\u0435\u0440\u0435\u0432\u043e\u0437\u043a\u0438.';
UI.labelToCompleted = '\u041f\u0435\u0440\u0435\u0432\u0435\u0441\u0442\u0438 \u0432 \u0413\u0440\u0443\u0437 \u0441\u0434\u0430\u043d';
UI.msgTransitionValidationHeader = '\u0427\u0442\u043e\u0431\u044b \u0441\u0444\u043e\u0440\u043c\u0438\u0440\u043e\u0432\u0430\u0442\u044c \u0440\u0435\u0439\u0441, \u0437\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u0435 \u043e\u0431\u044f\u0437\u0430\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u043f\u043e\u043b\u044f.';
UI.msgTransitionValidationTitle = '\u043a\u043e\u043c\u043c\u0435\u043d\u0442\u0430\u0440\u0438\u0439 / \u0437\u0430\u0433\u043e\u043b\u043e\u0432\u043e\u043a';
UI.msgTransitionValidationDriver = '\u0432\u043e\u0434\u0438\u0442\u0435\u043b\u044c';
UI.msgTransitionValidationDates = '\u0434\u0430\u0442\u044b';
UI.msgTransitionValidationCost = '\u0441\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c';
UI.msgTransitionValidationRequests = '\u0437\u0430\u044f\u0432\u043a\u0438';
UI.msgTransitionValidationRouteType = '\u0442\u0438\u043f \u0440\u0435\u0439\u0441\u0430';
UI.msgTransitionValidationWarehouseSource = '\u0441\u043a\u043b\u0430\u0434 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u0438\u044f';
UI.msgTransitionValidationWarehouseDestination = '\u0441\u043a\u043b\u0430\u0434 \u043d\u0430\u0437\u043d\u0430\u0447\u0435\u043d\u0438\u044f';
UI.msgSaveValidationTitle = '\u0414\u043b\u044f \u0441\u043e\u0445\u0440\u0430\u043d\u0435\u043d\u0438\u044f/\u043e\u0431\u043d\u043e\u0432\u043b\u0435\u043d\u0438\u044f \u0437\u0430\u043f\u043e\u043b\u043d\u0438\u0442\u0435 \u043e\u0431\u044f\u0437\u0430\u0442\u0435\u043b\u044c\u043d\u044b\u0435 \u043f\u043e\u043b\u044f.';
UI.msgChooseManagerForRoute = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430 \u0434\u043b\u044f \u043f\u043b\u0430\u043d\u0438\u0440\u0443\u0435\u043c\u043e\u0433\u043e \u0440\u0435\u0439\u0441\u0430.';
UI.msgChooseManagerOption = '\u0412\u044b\u0431\u0435\u0440\u0438\u0442\u0435 \u043c\u0435\u043d\u0435\u0434\u0436\u0435\u0440\u0430';
UI.msgCreateRouteTitleRequired = '\u041d\u0430\u0437\u0432\u0430\u043d\u0438\u0435 \u043c\u0430\u0440\u0448\u0440\u0443\u0442\u0430 \u043d\u0435 \u043c\u043e\u0436\u0435\u0442 \u0431\u044b\u0442\u044c \u043f\u0443\u0441\u0442\u044b\u043c';
UI.labelRouteType = '\u0422\u0438\u043f \u0440\u0435\u0439\u0441\u0430';
UI.msgRouteEditLoadFreshFailed = '\u041d\u0435 \u0443\u0434\u0430\u043b\u043e\u0441\u044c \u0437\u0430\u0433\u0440\u0443\u0437\u0438\u0442\u044c \u0430\u043a\u0442\u0443\u0430\u043b\u044c\u043d\u044b\u0435 \u0434\u0430\u043d\u043d\u044b\u0435 \u0440\u0435\u0439\u0441\u0430. \u041f\u043e\u0432\u0442\u043e\u0440\u0438\u0442\u0435 \u043f\u043e\u043f\u044b\u0442\u043a\u0443.';

// === TRACKER DATA FROM PHP BOOTSTRAP ===
const mapBootstrap = (typeof window !== 'undefined' && window.MAP_BOOTSTRAP && typeof window.MAP_BOOTSTRAP === 'object')
    ? window.MAP_BOOTSTRAP
    : {};
const uiTexts = (mapBootstrap.uiTexts && typeof mapBootstrap.uiTexts === 'object') ? mapBootstrap.uiTexts : {};
function uiText(key, fallback) {
    const value = uiTexts[key];
    if (typeof value !== 'string') return fallback;
    const trimmed = value.trim();
    return trimmed !== '' ? trimmed : fallback;
}
const initialTrackers = Array.isArray(mapBootstrap.initialTrackers) ? mapBootstrap.initialTrackers : [];
const allTrackers = Array.isArray(mapBootstrap.allTrackers) ? mapBootstrap.allTrackers : [];
const routeCardsMeta = (mapBootstrap.routeCardsMeta && typeof mapBootstrap.routeCardsMeta === 'object') ? mapBootstrap.routeCardsMeta : {};
const foundRoutesData = Array.isArray(mapBootstrap.foundRoutesData) ? mapBootstrap.foundRoutesData : [];
const startedRoutesData = Array.isArray(mapBootstrap.startedRoutesData) ? mapBootstrap.startedRoutesData : [];
const driversForSelect = Array.isArray(mapBootstrap.driversForSelect) ? mapBootstrap.driversForSelect : [];
let currentDriversCatalog = Array.isArray(driversForSelect) ? driversForSelect.slice() : [];
const statusNames = (mapBootstrap.statusNames && typeof mapBootstrap.statusNames === 'object') ? mapBootstrap.statusNames : {};
const bootstrapGroupsData = Array.isArray(mapBootstrap.groupsData) ? mapBootstrap.groupsData : [];
const bootstrapFlightStatusList = (mapBootstrap.flightStatusList && typeof mapBootstrap.flightStatusList === 'object') ? mapBootstrap.flightStatusList : {};
const bootstrapCustomLayers = (mapBootstrap.customLayers && typeof mapBootstrap.customLayers === 'object') ? mapBootstrap.customLayers : {};
let currentActiveTrackers = Array.isArray(initialTrackers) ? initialTrackers : [];
let currentAllTrackers = Array.isArray(allTrackers) ? allTrackers : [];
let currentEditingMeta = null;
let currentFoundRoutes = Array.isArray(foundRoutesData) ? foundRoutesData : [];
let currentStartedRoutes = Array.isArray(startedRoutesData) ? startedRoutesData : [];
let managerScopeId = '';
let managerScopeDriverIds = new Set();
let managerScopePlates = new Set();
let managerScopeInitialized = false;
let currentManagers = [];
UI.chooseDriver = uiText('driver.select.placeholder', UI.chooseDriver);
UI.msgPickAtLeastOneRequest = uiText('validation.pick_one_request', UI.msgPickAtLeastOneRequest);
UI.msgDeleteRouteConfirm = uiText('confirm.delete_route', UI.msgDeleteRouteConfirm);
UI.msgSelectRouteToEdit = uiText('route.edit.select_required', 'Выберите рейс для редактирования.');
UI.msgDriverMustPickFromList = uiText('driver.validation.must_select_from_list', 'Выберите водителя из списка.');
UI.msgDriverNameInvalid = uiText('driver.validation.full_name', 'Введите ФИО полностью: Фамилия Имя Отчество');
UI.msgDriverPlateInvalid = uiText('driver.validation.plate', 'Введите госномер в формате А123АА45 или А123АА456');
UI.msgDriverNotFound = uiText('driver.search.not_found', 'Ничего не найдено');
UI.msgWarehouseRequired = uiText('warehouse.validation.required', 'Заполните обязательные поля: название и полный адрес склада.');
UI.msgWarehouseLatInvalid = uiText('warehouse.validation.latitude', 'Некорректная широта.');
UI.msgWarehouseLonInvalid = uiText('warehouse.validation.longitude', 'Некорректная долгота.');
UI.msgWarehouseSaveFailed = uiText('warehouse.save.failed', 'Не удалось сохранить склад.');
UI.msgWarehouseSaveNetwork = uiText('warehouse.save.network_error', 'Ошибка сети при сохранении склада.');
UI.msgWarehouseGeocodeAddressRequired = uiText('warehouse.geocode.address_required', 'Введите полный адрес для определения координат.');
UI.msgWarehouseGeocodeLoading = uiText('warehouse.geocode.loading', 'Определение...');
UI.msgWarehouseGeocodeSuccess = uiText('warehouse.geocode.success', 'Координаты определены.');
UI.msgWarehouseGeocodeNetwork = uiText('warehouse.geocode.network_error', 'Ошибка сети при определении координат.');
UI.msgWarehouseGeocodeChanged = uiText('warehouse.geocode.changed_after_edit', 'Адрес изменён, координаты лучше определить заново.');
UI.labelWarehouseMarker = uiText('warehouse.marker.label', 'СКЛАД');
UI.labelWarehouseName = uiText('warehouse.popup.name', 'Название');
UI.labelWarehouseAddress = uiText('warehouse.popup.address', 'Адрес');
UI.labelWarehouseCoordinates = uiText('warehouse.popup.coordinates', 'Координаты');
UI.msgTransitionValidationRouteType = uiText('route.validation.required.route_type', UI.msgTransitionValidationRouteType);
UI.msgTransitionValidationWarehouseSource = uiText('route.validation.required.warehouse_source', UI.msgTransitionValidationWarehouseSource);
UI.msgTransitionValidationWarehouseDestination = uiText('route.validation.required.warehouse_destination', UI.msgTransitionValidationWarehouseDestination);
UI.msgRouteEditLoadFreshFailed = uiText('route.edit.load_fresh_failed', UI.msgRouteEditLoadFreshFailed);
UI.msgCopied = uiText('common.copied', 'Текст скопирован.');
UI.msgCopyFailed = uiText('common.copy_failed', 'Не удалось скопировать текст.');
UI.msgDriverGpsTypeRequired = uiText('driver.validation.gps_type', 'Выберите тип GPS подключения.');
UI.msgDriverCreateInProgress = uiText('driver.create.in_progress', 'Создание...');
UI.msgDriverCreateFailed = uiText('driver.create.failed', 'Не удалось создать водителя.');
UI.msgDriverCreateServerNoData = uiText('driver.create.server_no_driver', 'Сервер не вернул данные водителя.');
UI.msgDriverCreateExisting = uiText('driver.create.existing_selected', 'Такой водитель уже существует и выбран в форме.');
UI.msgDriverCreateNewTrackerDone = uiText('driver.create.new_tracker_done', 'Трекер настроен.');
UI.msgDriverCreateFreeLeft = uiText('driver.create.free_left', 'Свободных трекеров осталось');
UI.msgDriverCreateRetranslationDone = uiText('driver.create.retranslation_done', 'Водитель создан и выбран в форме. Настройки ретрансляции отправлены в MAX.');
UI.msgDriverCreateNetworkError = uiText('driver.create.network_error', 'Ошибка сети при создании водителя.');
UI.msgDriverGpsHelpRetranslation = uiText('driver.gps.helper.retranslation', 'Для варианта «Ретрансляция» используется тот же алгоритм регистрации трекера. Различается только текст MAX-уведомления.');
UI.msgDriverGpsHelpNewMobile = uiText('driver.gps.helper.new_mobile', 'Для варианта «Новый мобильный трекер» система подберет свободный трекер SLITEX и зарегистрирует его после сохранения водителя.');
const MANAGER_STORAGE_KEY = 'map_selected_manager_id';
let recentActivatedTrackersMap = {};
const foundRoutesById = {};
const startedRoutesById = {};
function rebuildFoundRoutesById() {
    Object.keys(foundRoutesById).forEach(key => delete foundRoutesById[key]);
    Object.keys(startedRoutesById).forEach(key => delete startedRoutesById[key]);
    if (Array.isArray(currentFoundRoutes)) {
        currentFoundRoutes.forEach(route => {
            if (route && route.id !== undefined && route.id !== null) {
                foundRoutesById[String(route.id)] = route;
            }
        });
    }
    if (Array.isArray(currentStartedRoutes)) {
        currentStartedRoutes.forEach(route => {
            if (route && route.id !== undefined && route.id !== null) {
                startedRoutesById[String(route.id)] = route;
            }
        });
    }
}
rebuildFoundRoutesById();

function getTrackersForCurrentMode() {
    if (transportDisplayMode === 'none') return [];
    const allDataset = Array.isArray(currentAllTrackers) && currentAllTrackers.length
        ? currentAllTrackers
        : (Array.isArray(currentActiveTrackers) ? currentActiveTrackers : []);

    if (transportDisplayMode === 'all') {
        return allDataset.filter(hasTrackerCoords);
    }

    const activeDataset = managerFilteredTrackers(Array.isArray(currentActiveTrackers) ? currentActiveTrackers : []);
    return mergeWithRecentActivatedTrackers(activeDataset, allDataset);
}

function renderTrackersByMode() {
    addTrackerMarkers(getTrackersForCurrentMode());
}

function hexToRgba(hex, alpha) {
    const safeHex = String(hex || '').trim();
    const match = safeHex.match(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/);
    if (!match) return `rgba(0,0,0,${alpha})`;
    let value = match[1];
    if (value.length === 3) {
        value = value.split('').map(ch => ch + ch).join('');
    }
    const num = parseInt(value, 16);
    const r = (num >> 16) & 255;
    const g = (num >> 8) & 255;
    const b = num & 255;
    return `rgba(${r},${g},${b},${alpha})`;
}

// === TRACKER FUNCTIONS ===
function getTrackerPreset(minutes) {
    if (minutes < 10) return 'islands#blueStretchyIcon';
    if (minutes < 60) return 'islands#orangeStretchyIcon';
    if (minutes < 180) return 'islands#orangeStretchyIcon';
    return 'islands#redStretchyIcon';
}

function getTrackerMinutes(tracker) {
    if (!tracker || typeof tracker !== 'object') return 9999;
    const rawMinutes = tracker.time_diff_minutes;
    if (rawMinutes === null || rawMinutes === undefined || rawMinutes === '') {
        return 9999;
    }
    const minutes = Number(rawMinutes);
    return Number.isFinite(minutes) ? minutes : 9999;
}

function hasTrackerCoords(tracker) {
    return !!(tracker &&
        Number.isFinite(Number(tracker.lat)) &&
        Number.isFinite(Number(tracker.lon)));
}

function getTrackerUniqueId(tracker) {
    if (!tracker || tracker.uniqueid === undefined || tracker.uniqueid === null) return '';
    return String(tracker.uniqueid).trim();
}

function isRecentActivatedTracker(tracker) {
    const uniqueid = getTrackerUniqueId(tracker);
    return uniqueid !== '' && !!recentActivatedTrackersMap[uniqueid];
}

function isTrackerAssignedToRoute(tracker) {
    if (!tracker || typeof tracker !== 'object') return false;
    const matchedDriverId = Number(tracker.matched_driver_id || 0);
    if (Number.isFinite(matchedDriverId) && matchedDriverId > 0) return true;

    const matchedFlightId = Number(tracker.matched_flight_id || tracker.flight_id || 0);
    if (Number.isFinite(matchedFlightId) && matchedFlightId > 0) return true;

    return false;
}

function mergeWithRecentActivatedTrackers(baseTrackers, allTrackersSource) {
    const result = [];
    const usedUniqueIds = new Set();

    (Array.isArray(baseTrackers) ? baseTrackers : []).forEach(tracker => {
        if (!hasTrackerCoords(tracker)) return;
        const uniqueid = getTrackerUniqueId(tracker);
        const merged = { ...tracker };
        if (uniqueid && recentActivatedTrackersMap[uniqueid] && !isTrackerAssignedToRoute(tracker)) {
            merged.is_new_tracker = true;
            merged.first_activation_at = String(recentActivatedTrackersMap[uniqueid].first_activation_at || '');
            usedUniqueIds.add(uniqueid);
        }
        result.push(merged);
    });

    (Array.isArray(allTrackersSource) ? allTrackersSource : []).forEach(tracker => {
        if (!hasTrackerCoords(tracker)) return;
        const uniqueid = getTrackerUniqueId(tracker);
        if (!uniqueid || !recentActivatedTrackersMap[uniqueid] || usedUniqueIds.has(uniqueid)) return;
        if (isTrackerAssignedToRoute(tracker)) return;

        result.push({
            ...tracker,
            is_new_tracker: true,
            first_activation_at: String(recentActivatedTrackersMap[uniqueid].first_activation_at || '')
        });
        usedUniqueIds.add(uniqueid);
    });

    return result;
}

async function loadRecentActivatedTrackers() {
    try {
        const response = await fetch('map_files/get_tracker_first_activation.php', {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });
        const data = await response.json();
        if (data && data.success && data.trackers && typeof data.trackers === 'object') {
            recentActivatedTrackersMap = data.trackers;
        } else {
            recentActivatedTrackersMap = {};
        }
    } catch (e) {
        console.warn('Activation data unavailable', e);
        recentActivatedTrackersMap = {};
    }
}

function isUserEditing() {
    const visibleModalIds = ['flightEditModal', 'driverCreateModal', 'warehouseCreateModal', 'startConfirmModal'];
    for (const id of visibleModalIds) {
        const el = document.getElementById(id);
        if (el && el.style && el.style.display !== 'none') {
            return true;
        }
    }
    const active = document.activeElement;
    if (active && active.closest) {
        if (active.closest('#flightEditModal, #driverCreateModal, #warehouseCreateModal, #startConfirmModal')) {
            return true;
        }
    }
    return false;
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>"']/g, function(m) {
        const map = {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'};
        return map[m] || m;
    });
}

function renderSafeStaticHtml(value) {
    const escaped = escapeHtml(String(value || ''));
    return escaped
        .replace(/&lt;br\s*\/?&gt;/gi, '<br>')
        .replace(/&lt;strong&gt;/gi, '<strong>')
        .replace(/&lt;\/strong&gt;/gi, '</strong>')
        .replace(/\r\n|\r|\n/g, '<br>');
}

function setSafeStaticHtml(element, value) {
    if (!element) return;
    element.classList.add('static-text-rendered');
    element.innerHTML = renderSafeStaticHtml(value);
}

function createTrackerBalloon(tracker) {
    const newTrackerBadge = tracker && tracker.is_new_tracker
        ? `<br><span style="display:inline-block; margin-top:6px; font-size:11px; font-weight:700; color:#0d47a1;">НОВЫЙ ТРЕКЕР</span>`
        : '';
    return `<div style="padding:12px; font-family:Arial,sans-serif; max-width:280px;">
        <b>${UI.truck} ${escapeHtml(tracker.name)}</b><br>
        <span style="color:#666; font-size:12px;">ID: ${tracker.uniqueid}</span><br>
        <span style="color:#888; font-size:11px;">${UI.clock} ${tracker.time_diff_text} \u043d\u0430\u0437\u0430\u0434</span><br>
        <span style="color:#555; font-size:11px;">${UI.pin} ${tracker.lat.toFixed(5)}, ${tracker.lon.toFixed(5)}</span>${newTrackerBadge}
    </div>`;
}

function extractPlateFromText(value) {
    const text = String(value || '').toUpperCase();
    const m = text.match(/[\u0410-\u042f\u0401A-Z]\d{3}[\u0410-\u042f\u0401A-Z]{2}\d{2,3}/u);
    return m ? m[0] : '';
}

function rebuildManagerScopeKeys(plannedRoutes, foundRoutes, startedRoutes) {
    managerScopeDriverIds = new Set();
    managerScopePlates = new Set();
    const allRoutes = []
        .concat(Array.isArray(plannedRoutes) ? plannedRoutes : [])
        .concat(Array.isArray(foundRoutes) ? foundRoutes : [])
        .concat(Array.isArray(startedRoutes) ? startedRoutes : []);
    allRoutes.forEach(route => {
        if (!route || typeof route !== 'object') return;
        const driverId = Number(route.driver_id || 0);
        if (driverId > 0) managerScopeDriverIds.add(String(driverId));
        const plate = extractPlateFromText(route.driver_label || route.name || '');
        if (plate) managerScopePlates.add(plate);
    });
}

function managerFilteredTrackers(trackers) {
    if (!managerScopeId) return trackers;
    if (!managerScopeDriverIds.size && !managerScopePlates.size) return [];

    return (Array.isArray(trackers) ? trackers : []).filter(tracker => {
        if (!tracker || typeof tracker !== 'object') return false;
        const matchedDriver = tracker.matched_driver_id ? String(tracker.matched_driver_id) : '';
        if (matchedDriver && managerScopeDriverIds.has(matchedDriver)) return true;
        const plate = extractPlateFromText(tracker.name || tracker.short_name || '');
        if (plate && managerScopePlates.has(plate)) return true;
        return false;
    });
}

function formatDriverCompactLabel(label) {
    const value = String(label || '').trim();
    if (!value || value === UI.driverMissing) return UI.driverMissing;
    const plateMatch = value.match(/[\u0410-\u042f\u0401A-Z]\d{3}[\u0410-\u042f\u0401A-Z]{2}\d{2,3}/u);
    let surname = '';

    const nameInBrackets = value.match(/\(([^)]+)\)/u);
    if (nameInBrackets && nameInBrackets[1]) {
        surname = String(nameInBrackets[1]).trim().split(/\s+/u)[0] || '';
    }
    if (!surname) {
        const beforeSlash = String(value.split('/')[0] || '').trim();
        const words = beforeSlash.match(/[\u0410-\u042F\u0401A-Z][\u0430-\u044F\u0451a-z]+/gu);
        if (words && words.length) {
            surname = words[0];
        }
    }

    if (plateMatch && surname) return `${plateMatch[0]} (${surname})`;
    if (plateMatch) return plateMatch[0];
    if (surname) return surname;
    return UI.driverMissing;
}

function formatRouteCost(costValue) {
    if (costValue === null || costValue === undefined || costValue === '') return '0';
    const num = Number(costValue);
    if (!Number.isFinite(num)) return String(costValue);
    return num.toLocaleString('ru-RU');
}

function normalizeUnloadType(value) {
    return String(value || '').toUpperCase() === 'SKLAD' ? 'SKLAD' : 'OO';
}

function normalizeRouteType(value, unloadTypeValue) {
    const v = String(value || '').trim().toLowerCase();
    const allowed = new Set([
        'generator_to_utilizer',
        'generator_to_warehouse',
        'warehouse_to_warehouse',
        'warehouse_to_utilizer'
    ]);
    if (allowed.has(v)) return v;
    return normalizeUnloadType(unloadTypeValue) === 'SKLAD'
        ? 'generator_to_warehouse'
        : 'generator_to_utilizer';
}

function resolveUnloadTypeByRouteType(routeType) {
    return normalizeRouteType(routeType, 'OO') === 'generator_to_utilizer' ? 'OO' : 'SKLAD';
}

function getRouteTypeLabel(routeTypeRaw, unloadTypeRaw = 'OO') {
    const routeType = normalizeRouteType(routeTypeRaw, unloadTypeRaw);
    if (routeType === 'generator_to_warehouse') return '\u0412\u044b\u0433\u0440\u0443\u0437\u043a\u0430 \u043d\u0430 \u0441\u043a\u043b\u0430\u0434';
    if (routeType === 'warehouse_to_warehouse') return '\u0421\u043a\u043b\u0430\u0434 \u2192 \u0421\u043a\u043b\u0430\u0434';
    if (routeType === 'warehouse_to_utilizer') return '\u0421\u043a\u043b\u0430\u0434 \u2192 \u0423\u0442\u0438\u043b\u0438\u0437\u0430\u0442\u043e\u0440';
    return '\u041e\u0431\u044b\u0447\u043d\u0430\u044f \u0432\u044b\u0433\u0440\u0443\u0437\u043a\u0430 / \u0423\u0442\u0438\u043b\u0438\u0437\u0430\u0442\u043e\u0440';
}

function getRouteTypeCompactSuffix(routeTypeRaw, unloadTypeRaw = 'OO') {
    const routeType = normalizeRouteType(routeTypeRaw, unloadTypeRaw);
    if (routeType === 'generator_to_warehouse') return `${UI.bullet}<strong>\u2192 \u0421\u043a\u043b\u0430\u0434</strong>`;
    if (routeType === 'warehouse_to_warehouse') return `${UI.bullet}<strong>\u0421\u043a\u043b\u0430\u0434 \u2192 \u0421\u043a\u043b\u0430\u0434</strong>`;
    if (routeType === 'warehouse_to_utilizer') return `${UI.bullet}<strong>\u0421\u043a\u043b\u0430\u0434 \u2192 \u0423\u0442\u0438\u043b.</strong>`;
    return '';
}

function updateFlightModalSummary() {
    const summary = document.getElementById('flightLiveSummary');
    if (!summary || !currentEditingMeta) return;

    const zayavkiRaw = document.getElementById('edit_zayavki_ids')?.value || '';
    const ids = zayavkiRaw
        .split(',')
        .map(v => v.trim())
        .filter(v => /^\d+$/.test(v));
    const uniqueIds = Array.from(new Set(ids));

    const isStarted = currentEditingMeta && String(currentEditingMeta.status) === 'started';
    const fromVal = (isStarted ? document.getElementById('edit_actual_start_date') : document.getElementById('edit_planned_start_date_from'))?.value || '';
    const toVal = (isStarted ? document.getElementById('edit_actual_end_date') : document.getElementById('edit_planned_start_date_to'))?.value || '';
    const costVal = document.getElementById('edit_cost')?.value || '';
    const driverSelect = document.getElementById('edit_driver_id');
    const driverText = driverSelect?.selectedOptions?.[0]?.textContent || UI.driverMissing;
    const routeTypeValue = document.getElementById('edit_route_type')?.value || currentEditingMeta.route_type || '';

    let totalTons = 0;
    if (Array.isArray(groupsData)) {
        groupsData.forEach(group => {
            if (!group || !Array.isArray(group.points)) return;
            group.points.forEach(point => {
                if (uniqueIds.includes(String(point.zayavka_id))) {
                    totalTons += Number(point.mass_netto || 0);
                }
            });
        });
    }
    const totalKg = Math.round(totalTons * 1000);
    const periodText = fromVal || toVal ? `${fromVal || TXT.notSpecified} ${UI.emDash} ${toVal || TXT.notSpecified}` : TXT.notSpecified;
    const statusLabel = statusNames[currentEditingMeta.status] || currentEditingMeta.status || TXT.notSpecified;
    const statusClass = currentEditingMeta.status === 'planned_route'
        ? 'flight-status-planned'
        : (currentEditingMeta.status === 'found'
            ? 'flight-status-found'
            : (currentEditingMeta.status === 'started' ? 'flight-status-started' : ''));

    summary.innerHTML = `
        <div><strong>${TXT.requestsCount}:</strong> ${uniqueIds.length}</div>
        <div><strong>${TXT.totalWeight}:</strong> ${totalKg.toLocaleString('ru-RU')} ${UI.kg}</div>
        <div><strong>${UI.modalCost}:</strong> ${formatRouteCost(costVal)} \u20BD</div>
        <div><strong>${UI.labelRouteType}:</strong> ${escapeHtml(getRouteTypeLabel(routeTypeValue, currentEditingMeta.unload_type || 'OO'))}</div>
        <div><strong>${UI.labelDriver}:</strong> ${escapeHtml(formatDriverCompactLabel(driverText))}</div>
        <div><strong>${TXT.routeStatus}:</strong> <span class="${statusClass}">${escapeHtml(statusLabel)}</span></div>
        <div><strong>\u041f\u0435\u0440\u0438\u043e\u0434:</strong> ${escapeHtml(periodText)}</div>
    `;
}

function buildFoundChangePreview(meta) {
    if (!meta || (meta.status !== 'found' && meta.status !== 'started')) return '';
    const isStarted = meta.status === 'started';
    const fromVal = (isStarted ? document.getElementById('edit_actual_start_date') : document.getElementById('edit_planned_start_date_from'))?.value || '';
    const toVal = (isStarted ? document.getElementById('edit_actual_end_date') : document.getElementById('edit_planned_start_date_to'))?.value || '';
    const costVal = document.getElementById('edit_cost')?.value || '';
    const zayavkiVal = document.getElementById('edit_zayavki_ids')?.value || '';
    const driverSelect = document.getElementById('edit_driver_id');
    const currentDriverText = driverSelect?.selectedOptions?.[0]?.textContent || UI.driverMissing;
    const currentRouteType = normalizeRouteType(document.getElementById('edit_route_type')?.value || '', meta.unload_type || 'OO');
    const previousDriver = meta.driver_label || UI.driverMissing;
    const previousDates = isStarted
        ? `${meta.actual_start_date || TXT.notSpecified} \u2014 ${meta.actual_end_date || TXT.notSpecified}`
        : `${meta.planned_start_date_from || TXT.notSpecified} \u2014 ${meta.planned_start_date_to || TXT.notSpecified}`;
    const currentDates = `${fromVal || TXT.notSpecified} \u2014 ${toVal || TXT.notSpecified}`;
    const previousCost = `${formatRouteCost(meta.cost)} \u20BD`;
    const currentCost = `${formatRouteCost(costVal)} \u20BD`;
    const previousIdsArr = String(meta.zayavki_ids || '').split(',').map(v => v.trim()).filter(Boolean);
    const currentIdsArr = zayavkiVal.split(',').map(v => v.trim()).filter(Boolean);
    const previousIds = previousIdsArr.join(',');
    const currentIds = currentIdsArr.join(',');
    const previousRouteType = normalizeRouteType(meta.route_type || '', meta.unload_type || 'OO');

    const blocks = [];
    if (formatDriverCompactLabel(previousDriver) !== formatDriverCompactLabel(currentDriverText)) {
        blocks.push(`\u0412\u043e\u0434\u0438\u0442\u0435\u043b\u044c:\n\u0411\u044b\u043b\u043e: ${formatDriverCompactLabel(previousDriver)}\n\u0421\u0442\u0430\u043b\u043e: ${formatDriverCompactLabel(currentDriverText)}`);
    }
    if (previousDates !== currentDates) {
        const periodLabel = isStarted ? '\u0424\u0430\u043a\u0442\u0438\u0447\u0435\u0441\u043a\u0438\u0439 \u043f\u0435\u0440\u0438\u043e\u0434' : '\u041f\u0435\u0440\u0438\u043e\u0434';
        blocks.push(`${periodLabel}:\n\u0411\u044b\u043b\u043e: ${previousDates}\n\u0421\u0442\u0430\u043b\u043e: ${currentDates}`);
    }
    if (previousCost !== currentCost) {
        blocks.push(`\u0421\u0442\u043e\u0438\u043c\u043e\u0441\u0442\u044c:\n\u0411\u044b\u043b\u043e: ${previousCost}\n\u0421\u0442\u0430\u043b\u043e: ${currentCost}`);
    }
    if (previousIds !== currentIds) {
        const prevSet = new Set(previousIdsArr);
        const currSet = new Set(currentIdsArr);
        const removed = previousIdsArr.filter(id => !currSet.has(id));
        const added = currentIdsArr.filter(id => !prevSet.has(id));
        if (removed.length > 0) {
            blocks.push(`\u0418\u0441\u043a\u043b\u044e\u0447\u0435\u043d\u043d\u044b\u0435 \u0437\u0430\u044f\u0432\u043a\u0438: ${removed.join(',')}`);
        }
        if (added.length > 0) {
            blocks.push(`\u0414\u043e\u0431\u0430\u0432\u043b\u0435\u043d\u043d\u044b\u0435 \u0437\u0430\u044f\u0432\u043a\u0438: ${added.join(',')}`);
        }
        if (previousIdsArr.length !== currentIdsArr.length) {
            blocks.push(`\u041a\u043e\u043b\u0438\u0447\u0435\u0441\u0442\u0432\u043e \u0437\u0430\u044f\u0432\u043e\u043a:\n\u0411\u044b\u043b\u043e: ${previousIdsArr.length}\n\u0421\u0442\u0430\u043b\u043e: ${currentIdsArr.length}`);
        }
    }
    if (previousRouteType !== currentRouteType) {
        blocks.push(`\u041c\u0430\u0440\u0448\u0440\u0443\u0442 \u0433\u0440\u0443\u0437\u0430:\n\u0411\u044b\u043b\u043e: ${getRouteTypeLabel(previousRouteType, meta.unload_type || 'OO')}\n\u0421\u0442\u0430\u043b\u043e: ${getRouteTypeLabel(currentRouteType, resolveUnloadTypeByRouteType(currentRouteType))}`);
    }
    if (blocks.length === 0) return '';
    const htmlBlocks = blocks.map(b => `<div style="white-space:pre-wrap;margin:4px 0">${escapeHtml(b)}</div>`);
    return `<div><strong>\u0411\u0443\u0434\u0443\u0442 \u043e\u0442\u043f\u0440\u0430\u0432\u043b\u0435\u043d\u044b \u0438\u0437\u043c\u0435\u043d\u0435\u043d\u0438\u044f \u0432 MAX:</strong></div>${htmlBlocks.join('')}`;
}
