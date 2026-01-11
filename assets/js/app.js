// API Base URL
var API_BASE = '/api';

// Cache for data
let alertsData = [];
let decisionsData = [];
let machinesData = [];
let whitelistData = [];
let allowListsData = [];
let alertSortState = { key: 'created_at', direction: 'desc' };
let decisionSortState = { key: 'created_at', direction: 'desc' };
let whitelistSortState = { key: 'created_at', direction: 'desc' };
let worldMap = null;
let worldMapMode = 'alerts';
let alertFilterOptions = null;
let alertFilterSessionTimer = null;
let worldMapData = {
    alerts: { values: {}, max: 0 },
    decisions: { values: {}, max: 0 }
};
let sourcesChart = null;
const dashboardState = {
    stats: null,
    alerts: [],
    filters: {
        scenarios: new Set(),
        countries: new Set(),
        hosts: new Set(),
        hours: new Set()
    }
};
const DATE_TIME_FORMATTER = new Intl.DateTimeFormat('cs-CZ', { dateStyle: 'medium', timeStyle: 'short' });
const TIME_FORMATTER = new Intl.DateTimeFormat('cs-CZ', { timeStyle: 'short' });

// Utility functions
function normalizeString(value) {
    return (value ?? '').toString().toLowerCase().trim();
}

function matchesFilter(value, filterValue) {
    if (!filterValue) return true;
    return normalizeString(value).includes(filterValue);
}

function uniqueValues(values) {
    return Array.from(new Set(values.filter(Boolean))).sort();
}

function compareValues(a, b, direction) {
    const multiplier = direction === 'desc' ? -1 : 1;
    if (a === b) return 0;
    if (a === null || a === undefined) return 1 * multiplier;
    if (b === null || b === undefined) return -1 * multiplier;

    const aNumber = typeof a === 'number' && !Number.isNaN(a);
    const bNumber = typeof b === 'number' && !Number.isNaN(b);

    if (aNumber && bNumber) {
        return (a - b) * multiplier;
    }

    return a.toString().localeCompare(b.toString(), 'cs-CZ', { numeric: true }) * multiplier;
}

function updateSortIndicators(tableId, sortState) {
    const table = document.getElementById(tableId);
    if (!table) return;
    table.querySelectorAll('th[data-sort-key]').forEach(th => {
        const indicator = th.querySelector('.sort-indicator');
        if (!indicator) return;
        if (th.dataset.sortKey === sortState.key) {
            indicator.textContent = sortState.direction === 'asc' ? '▲' : '▼';
            th.classList.add('sorted');
        } else {
            indicator.textContent = '';
            th.classList.remove('sorted');
        }
    });
}

function initializeSortableTable(tableId, sortState, renderFn) {
    const table = document.getElementById(tableId);
    if (!table) return;

    table.querySelectorAll('th[data-sort-key]').forEach(th => {
        th.classList.add('sortable');
        th.addEventListener('click', () => {
            const key = th.dataset.sortKey;
            if (!key) return;
            if (sortState.key === key) {
                sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
            } else {
                sortState.key = key;
                sortState.direction = 'asc';
            }
            renderFn();
        });
    });

    updateSortIndicators(tableId, sortState);
}

function setDatalistOptions(id, options) {
    const datalist = document.getElementById(id);
    if (!datalist) return;
    datalist.innerHTML = options.map(option => `<option value="${option}"></option>`).join('');
}

function formatDateTime(dateString) {
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return DATE_TIME_FORMATTER.format(date);
}

function formatTime(dateString) {
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) {
        return '-';
    }
    return TIME_FORMATTER.format(date);
}

function getAlertDurationMinutes(alert) {
    if (!alert?.started_at || !alert?.stopped_at) return null;
    const start = new Date(alert.started_at);
    const stop = new Date(alert.stopped_at);
    if (Number.isNaN(start.getTime()) || Number.isNaN(stop.getTime())) return null;
    const diffMs = Math.max(0, stop - start);
    return diffMs / 60000;
}

function formatAlertDuration(alert) {
    if (!alert?.started_at) return '-';
    const startLabel = formatTime(alert.started_at);
    if (startLabel === '-') return '-';
    const durationMinutes = getAlertDurationMinutes(alert);
    if (durationMinutes === null) {
        return `start: ${startLabel} trvání -`;
    }
    const roundedMinutes = Math.round(durationMinutes);
    const durationLabel = roundedMinutes >= 60
        ? `${Math.max(1, Math.round(roundedMinutes / 60))} hod`
        : `${Math.max(1, roundedMinutes)} min`;
    return `start: ${startLabel} trvání ${durationLabel}`;
}

function formatRelativeTime(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diffMs = now - date;
    const diffMins = Math.floor(diffMs / 60000);
    const diffHours = Math.floor(diffMs / 3600000);
    const diffDays = Math.floor(diffMs / 86400000);

    if (diffMins < 1) return 'právě teď';
    if (diffMins < 60) return `před ${diffMins} min`;
    if (diffHours < 24) return `před ${diffHours} h`;
    return `před ${diffDays} dny`;
}

function getCountryFlagHtml(countryCode) {
    if (!countryCode) return '';
    const normalized = countryCode.toLowerCase();
    if (!/^[a-z]{2}$/.test(normalized)) return '';
    return `<span class="fi fi-${normalized} country-flag" aria-label="${countryCode}"></span>`;
}

function getAlertHost(alert) {
    return alert.machine_id || 'Neznámý';
}

function getAlertCountry(alert) {
    return alert.source_country || 'Unknown';
}

function getAlertHourKey(alert) {
    const date = new Date(alert.created_at);
    if (Number.isNaN(date.getTime())) return null;
    date.setMinutes(0, 0, 0);
    return date.getTime();
}

function hasActiveDashboardFilters() {
    const filters = dashboardState.filters;
    return Object.values(filters).some(set => set.size > 0);
}

function applyDashboardFilters(alerts) {
    const { scenarios, countries, hosts, hours } = dashboardState.filters;
    return alerts.filter(alert => {
        const scenario = alert.scenario || '';
        const country = getAlertCountry(alert);
        const host = getAlertHost(alert);
        const hourKey = getAlertHourKey(alert);

        if (scenarios.size > 0 && !scenarios.has(scenario)) return false;
        if (countries.size > 0 && !countries.has(country)) return false;
        if (hosts.size > 0 && !hosts.has(host)) return false;
        if (hours.size > 0 && (hourKey === null || !hours.has(hourKey))) return false;
        return true;
    });
}

function toggleDashboardFilter(set, value) {
    if (value === null || value === undefined) return;
    if (set.has(value)) {
        set.delete(value);
    } else {
        set.add(value);
    }
}

function formatDashboardFilterValues(values, formatter) {
    const formatted = values.map(value => formatter(value)).filter(Boolean);
    if (formatted.length === 0) return '';
    const maxItems = 3;
    const visible = formatted.slice(0, maxItems);
    const remaining = formatted.length - visible.length;
    return remaining > 0 ? `${visible.join(', ')} +${remaining}` : visible.join(', ');
}

function formatDashboardHourFilter(hourKey) {
    const date = new Date(Number(hourKey));
    if (Number.isNaN(date.getTime())) return '';
    return date.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' });
}

function clearDashboardFilters() {
    Object.values(dashboardState.filters).forEach(set => set.clear());
    renderDashboard();
}

function updateDashboardFilterStatus() {
    const status = document.getElementById('dashboardFiltersStatus');
    const resetButton = document.getElementById('dashboardFiltersReset');
    const filterSection = document.querySelector('.dashboard-filters');
    if (!status) return;

    const parts = [];
    const { scenarios, countries, hosts, hours } = dashboardState.filters;
    if (scenarios.size) {
        parts.push(`Scénáře: ${formatDashboardFilterValues(Array.from(scenarios), scenario => scenario.split('/').pop())}`);
    }
    if (countries.size) {
        parts.push(`Země: ${formatDashboardFilterValues(Array.from(countries), country => country)}`);
    }
    if (hosts.size) {
        parts.push(`Hosté: ${formatDashboardFilterValues(Array.from(hosts), host => host)}`);
    }
    if (hours.size) {
        parts.push(`Hodiny: ${formatDashboardFilterValues(Array.from(hours), formatDashboardHourFilter)}`);
    }

    if (parts.length === 0) {
        status.textContent = 'Žádný filtr';
        if (resetButton) resetButton.disabled = true;
        if (filterSection) filterSection.style.display = 'none';
        return;
    }

    status.textContent = `Aktivní filtry: ${parts.join(', ')}`;
    if (resetButton) resetButton.disabled = false;
    if (filterSection) filterSection.style.display = '';
}

function setupDashboardFilterControls() {
    const resetButton = document.getElementById('dashboardFiltersReset');
    if (resetButton && !resetButton.dataset.bound) {
        resetButton.addEventListener('click', clearDashboardFilters);
        resetButton.dataset.bound = 'true';
    }
}

function buildTimelineData(alerts) {
    const buckets = new Map();
    const cutoff = Date.now() - 24 * 60 * 60 * 1000;
    alerts.forEach(alert => {
        const createdAt = new Date(alert.created_at);
        if (Number.isNaN(createdAt.getTime()) || createdAt.getTime() < cutoff) return;
        const hourKey = getAlertHourKey(alert);
        if (hourKey === null) return;
        buckets.set(hourKey, (buckets.get(hourKey) || 0) + 1);
    });

    return Array.from(buckets.entries())
        .sort((a, b) => a[0] - b[0])
        .map(([hourKey, count]) => {
            const date = new Date(hourKey);
            return {
                hour: hourKey,
                label: date.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }),
                count
            };
        });
}

function buildTopItems(alerts, getKey, limit) {
    const counts = new Map();
    alerts.forEach(alert => {
        const key = getKey(alert);
        if (!key) return;
        counts.set(key, (counts.get(key) || 0) + 1);
    });
    return Array.from(counts.entries())
        .map(([key, count]) => ({ key, count }))
        .sort((a, b) => b.count - a.count)
        .slice(0, limit);
}

function buildTopScenarios(alerts) {
    return buildTopItems(alerts, alert => alert.scenario, 10)
        .map(row => ({ scenario: row.key, count: row.count }));
}

function buildTopCountries(alerts) {
    return buildTopItems(alerts, alert => alert.source_country, 10)
        .map(row => ({ country: row.key, count: row.count }));
}

function buildTopIps(alerts) {
    return buildTopItems(alerts, alert => alert.source_ip, 10)
        .map(row => ({ ip: row.key, count: row.count }));
}

function buildAlertsByHost(alerts) {
    return buildTopItems(alerts, alert => getAlertHost(alert), 7)
        .map(row => ({ host: row.key, count: row.count }));
}

function buildWorldMapDatasetFromAlerts(alerts, mode) {
    const values = {};
    let max = 0;
    alerts.forEach(alert => {
        const country = alert.source_country;
        if (!country) return;
        const key = country.toUpperCase();
        const increment = mode === 'decisions' ? Number(alert.decisions_count || 0) : 1;
        if (mode === 'decisions' && increment === 0) return;
        values[key] = (values[key] || 0) + increment;
        if (values[key] > max) max = values[key];
    });
    return { values, max };
}

function getAlertHost(alert) {
    return alert.machine_id || 'Neznámý';
}

function getAlertCountry(alert) {
    return alert.source_country || 'Unknown';
}

function getAlertHourKey(alert) {
    const date = new Date(alert.created_at);
    if (Number.isNaN(date.getTime())) return null;
    date.setMinutes(0, 0, 0);
    return date.getTime();
}

function hasActiveDashboardFilters() {
    const filters = dashboardState.filters;
    return Object.values(filters).some(set => set.size > 0);
}

function applyDashboardFilters(alerts) {
    const { scenarios, countries, hosts, hours } = dashboardState.filters;
    return alerts.filter(alert => {
        const scenario = alert.scenario || '';
        const country = getAlertCountry(alert);
        const host = getAlertHost(alert);
        const hourKey = getAlertHourKey(alert);

        if (scenarios.size > 0 && !scenarios.has(scenario)) return false;
        if (countries.size > 0 && !countries.has(country)) return false;
        if (hosts.size > 0 && !hosts.has(host)) return false;
        if (hours.size > 0 && (hourKey === null || !hours.has(hourKey))) return false;
        return true;
    });
}

function toggleDashboardFilter(set, value) {
    if (value === null || value === undefined) return;
    if (set.has(value)) {
        set.delete(value);
    } else {
        set.add(value);
    }
}

function clearDashboardFilters() {
    Object.values(dashboardState.filters).forEach(set => set.clear());
    renderDashboard();
}

function updateDashboardFilterStatus() {
    const status = document.getElementById('dashboardFiltersStatus');
    const resetButton = document.getElementById('dashboardFiltersReset');
    const filterSection = document.querySelector('.dashboard-filters');
    if (!status) return;

    const parts = [];
    const { scenarios, countries, hosts, hours } = dashboardState.filters;
    if (scenarios.size) {
        parts.push(`Scénáře: ${formatDashboardFilterValues(Array.from(scenarios), scenario => scenario.split('/').pop())}`);
    }
    if (countries.size) {
        parts.push(`Země: ${formatDashboardFilterValues(Array.from(countries), country => country)}`);
    }
    if (hosts.size) {
        parts.push(`Hosté: ${formatDashboardFilterValues(Array.from(hosts), host => host)}`);
    }
    if (hours.size) {
        parts.push(`Hodiny: ${formatDashboardFilterValues(Array.from(hours), formatDashboardHourFilter)}`);
    }

    if (parts.length === 0) {
        status.textContent = 'Žádný filtr';
        if (resetButton) resetButton.disabled = true;
        if (filterSection) filterSection.style.display = 'none';
        return;
    }

    status.textContent = `Aktivní filtry: ${parts.join(', ')}`;
    if (resetButton) resetButton.disabled = false;
    if (filterSection) filterSection.style.display = '';
}

function setupDashboardFilterControls() {
    const resetButton = document.getElementById('dashboardFiltersReset');
    if (resetButton && !resetButton.dataset.bound) {
        resetButton.addEventListener('click', clearDashboardFilters);
        resetButton.dataset.bound = 'true';
    }
}

function buildTimelineData(alerts) {
    const buckets = new Map();
    const cutoff = Date.now() - 24 * 60 * 60 * 1000;
    alerts.forEach(alert => {
        const createdAt = new Date(alert.created_at);
        if (Number.isNaN(createdAt.getTime()) || createdAt.getTime() < cutoff) return;
        const hourKey = getAlertHourKey(alert);
        if (hourKey === null) return;
        buckets.set(hourKey, (buckets.get(hourKey) || 0) + 1);
    });

    return Array.from(buckets.entries())
        .sort((a, b) => a[0] - b[0])
        .map(([hourKey, count]) => {
            const date = new Date(hourKey);
            return {
                hour: hourKey,
                label: date.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }),
                count
            };
        });
}

function buildTimelineFromStats(rows) {
    return (rows || []).map(row => {
        const date = new Date(row.hour);
        const isValid = !Number.isNaN(date.getTime());
        const hourKey = isValid ? date.getTime() : row.hour;
        const label = isValid
            ? date.toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' })
            : row.hour;
        return {
            hour: hourKey,
            label,
            count: Number(row.count) || 0
        };
    });
}

function buildTopItems(alerts, getKey, limit) {
    const counts = new Map();
    alerts.forEach(alert => {
        const key = getKey(alert);
        if (!key) return;
        counts.set(key, (counts.get(key) || 0) + 1);
    });
    return Array.from(counts.entries())
        .map(([key, count]) => ({ key, count }))
        .sort((a, b) => b.count - a.count)
        .slice(0, limit);
}

function buildTopScenarios(alerts) {
    return buildTopItems(alerts, alert => alert.scenario, 10)
        .map(row => ({ scenario: row.key, count: row.count }));
}

function buildTopCountries(alerts) {
    return buildTopItems(alerts, alert => alert.source_country, 10)
        .map(row => ({ country: row.key, count: row.count }));
}

function buildTopIps(alerts) {
    return buildTopItems(alerts, alert => alert.source_ip, 10)
        .map(row => ({ ip: row.key, count: row.count }));
}

function buildAlertsByHost(alerts) {
    return buildTopItems(alerts, alert => getAlertHost(alert), 7)
        .map(row => ({ host: row.key, count: row.count }));
}

function buildWorldMapDatasetFromAlerts(alerts, mode) {
    const values = {};
    let max = 0;
    alerts.forEach(alert => {
        const country = alert.source_country;
        if (!country) return;
        const key = country.toUpperCase();
        const increment = mode === 'decisions' ? Number(alert.decisions_count || 0) : 1;
        if (mode === 'decisions' && increment === 0) return;
        values[key] = (values[key] || 0) + increment;
        if (values[key] > max) max = values[key];
    });
    return { values, max };
}

function buildMapDataset(rows) {
    const values = {};
    let max = 0;
    (rows || []).forEach(row => {
        const country = row.country;
        if (!country) return;
        const key = country.toUpperCase();
        const count = Number(row.count || 0);
        if (Number.isNaN(count) || count === 0) return;
        values[key] = count;
        if (count > max) max = count;
    });
    return { values, max };
}

function updateMapLegend(mode) {
    const legend = document.getElementById('mapLegend');
    if (!legend) return;
    const dataset = worldMapData[mode] || { max: 0 };
    const label = mode === 'decisions' ? 'banů' : 'alertů';
    const gradient = 'linear-gradient(90deg, #fef08a, #ef4444)';
    legend.innerHTML = dataset.max
        ? `<span>0</span><span class="legend-gradient" style="background:${gradient}"></span><span>${dataset.max}</span><span class="legend-label">Počet ${label}</span>`
        : `<span class="legend-empty">Žádná data pro mapu (${label}).</span>`;
}

function renderWorldMap() {
    const container = document.getElementById('worldMap');
    if (!container || typeof jsVectorMap === 'undefined') return;

    const dataset = worldMapData[worldMapMode] || { values: {}, max: 0 };
    const scale = ['#fef08a', '#ef4444'];
    const selectedRegions = Array.from(dashboardState.filters.countries).map(country => country.toUpperCase());

    if (worldMap) {
        worldMap.destroy();
    }
    container.innerHTML = '';

    const availableMaps = Object.keys(jsVectorMap.maps || {});
    const mapName = availableMaps.find(name => name.startsWith('world')) || availableMaps[0];
    if (!mapName) return;

    worldMap = new jsVectorMap({
        selector: '#worldMap',
        map: mapName,
        zoomButtons: false,
        backgroundColor: 'transparent',
        regionsSelectable: true,
        selectedRegions: selectedRegions,
        regionStyle: {
            initial: {
                fill: '#e5e7eb',
                stroke: '#ffffff',
                strokeWidth: 0.6
            },
            hover: {
                fill: '#93c5fd'
            },
            selected: {
                fill: '#1d4ed8'
            }
        },
        series: {
            regions: [{
                values: dataset.values,
                scale: scale,
                normalizeFunction: 'polynomial'
            }]
        },
        onRegionClick: (event, code) => {
            if (!code) return;
            toggleDashboardFilter(dashboardState.filters.countries, code.toUpperCase());
            renderDashboard();
        }
    });

    updateMapLegend(worldMapMode);
}

function setWorldMapMode(mode) {
    worldMapMode = mode;
    renderWorldMap();
}

function updateSourcesChart(data) {
    const ctx = document.getElementById('sourcesChart');
    if (!ctx) return;

    const labels = data.map(row => row.host || 'Neznámý');
    const hostKeys = data.map(row => row.host || 'Neznámý');
    const values = data.map(row => row.count);
    const baseColors = [
        '#ef4444',
        '#f97316',
        '#facc15',
        '#22c55e',
        '#38bdf8',
        '#a855f7',
        '#64748b'
    ];
    const mutedColor = '#e2e8f0';
    const hasFilters = dashboardState.filters.hosts.size > 0;
    const colors = labels.map((_, index) => {
        if (!hasFilters) return baseColors[index % baseColors.length];
        return dashboardState.filters.hosts.has(hostKeys[index])
            ? baseColors[index % baseColors.length]
            : mutedColor;
    });

    if (sourcesChart) {
        sourcesChart.destroy();
    }

    sourcesChart = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: values,
                backgroundColor: colors
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 12
                    }
                }
            },
            onClick: (event, elements) => {
                if (!elements.length) return;
                const index = elements[0].index;
                toggleDashboardFilter(dashboardState.filters.hosts, hostKeys[index]);
                renderDashboard();
            }
        }
    });
}

// API calls
async function apiGet(endpoint) {
    try {
        const response = await fetch(`${API_BASE}${endpoint}`);
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showNotification(`Chyba při načítání dat: ${error.message}`, 'error');
        throw error;
    }
}

async function apiPost(endpoint, data) {
    try {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showNotification(`Chyba při odesílání dat: ${error.message}`, 'error');
        throw error;
    }
}

async function apiPut(endpoint, data) {
    try {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showNotification(`Chyba při ukládání: ${error.message}`, 'error');
        throw error;
    }
}

async function apiDelete(endpoint) {
    try {
        const response = await fetch(`${API_BASE}${endpoint}`, {
            method: 'DELETE'
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return await response.json();
    } catch (error) {
        console.error('API Error:', error);
        showNotification(`Chyba při mazání: ${error.message}`, 'error');
        throw error;
    }
}

// Notifications
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;

    document.body.appendChild(notification);

    setTimeout(() => {
        notification.style.animation = 'slideOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
    }, 3000);
}

// Dashboard functions
async function loadDashboard() {
    try {
        const [stats, alerts] = await Promise.all([
            apiGet('/stats.php'),
            apiGet('/alerts.php?limit=0')
        ]);
        dashboardState.stats = stats;
        dashboardState.alerts = alerts || [];
        setupDashboardFilterControls();
        renderDashboard();

    } catch (error) {
        console.error('Failed to load dashboard:', error);
    }
}

let timelineChart = null;
function updateTimelineChart(data) {
    const ctx = document.getElementById('timelineChart');
    if (!ctx) return;

    const labels = data.map(d => d.label);
    const values = data.map(d => d.count);
    const hourKeys = data.map(d => d.hour);
    const selectedHours = dashboardState.filters.hours;
    const hasSelection = selectedHours.size > 0;
    const pointRadius = hourKeys.map(hourKey => (hasSelection && selectedHours.has(hourKey) ? 6 : 3));
    const pointBackgroundColor = hourKeys.map(hourKey => {
        if (!hasSelection) return '#2563eb';
        return selectedHours.has(hourKey) ? '#2563eb' : '#cbd5f5';
    });

    if (timelineChart) {
        timelineChart.destroy();
    }

    timelineChart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Počet alertů',
                data: values,
                borderColor: '#2563eb',
                backgroundColor: 'rgba(37, 99, 235, 0.1)',
                pointRadius: pointRadius,
                pointBackgroundColor: pointBackgroundColor,
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            },
            onClick: (event, elements) => {
                if (!elements.length) return;
                const index = elements[0].index;
                toggleDashboardFilter(dashboardState.filters.hours, hourKeys[index]);
                renderDashboard();
            }
        }
    });
}

let scenariosChart = null;
function updateScenariosChart(data) {
    const ctx = document.getElementById('scenariosChart');
    if (!ctx) return;

    const scenarioKeys = data.map(d => d.scenario);
    const labels = data.map(d => d.scenario.split('/').pop());
    const values = data.map(d => d.count);
    const hasSelection = dashboardState.filters.scenarios.size > 0;
    const baseColor = '#2563eb';
    const mutedColor = '#bfdbfe';
    const colors = scenarioKeys.map(scenario => {
        if (!hasSelection) return baseColor;
        return dashboardState.filters.scenarios.has(scenario) ? baseColor : mutedColor;
    });

    if (scenariosChart) {
        scenariosChart.destroy();
    }

    scenariosChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Počet',
                data: values,
                backgroundColor: colors
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: { display: false }
            },
            scales: {
                y: { beginAtZero: true }
            },
            onClick: (event, elements) => {
                if (!elements.length) return;
                const index = elements[0].index;
                toggleDashboardFilter(dashboardState.filters.scenarios, scenarioKeys[index]);
                renderDashboard();
            }
        }
    });
}

function updateCountriesTable(data) {
    const tbody = document.querySelector('#countriesTable tbody');
    if (!tbody) return;

    tbody.innerHTML = data.map(row => `
        <tr>
            <td>
                ${getCountryFlagHtml(row.country)}
                ${row.country || 'Unknown'}
            </td>
            <td>
                ${getCountryFlagHtml(row.country)}
            </td>
            <td>${row.count}</td>
        </tr>
    `).join('');
}

function updateIpsTable(data) {
    const tbody = document.querySelector('#ipsTable tbody');
    if (!tbody) return;

    tbody.innerHTML = data.map(row => `
        <tr>
            <td>
                ${row.ip
        ? `<span class="ip-action" data-ip="${row.ip}" data-tooltip="Kliknutím přidáte dlouhodobý ban">${row.ip}</span>`
        : '<span class="muted">Unknown</span>'}
            </td>
            <td>${row.count}</td>
        </tr>
    `).join('');
}

function updateSummaryCards(stats, filteredAlerts, topScenarios, topCountries) {
    const totalAlerts = document.getElementById('totalAlerts');
    const activeDecisions = document.getElementById('activeDecisions');
    const topScenario = document.getElementById('topScenario');
    const topCountry = document.getElementById('topCountry');
    const hasFilters = hasActiveDashboardFilters();

    if (totalAlerts) {
        totalAlerts.textContent = hasFilters
            ? filteredAlerts.length
            : stats?.total_alerts || filteredAlerts.length;
    }

    if (activeDecisions) {
        activeDecisions.textContent = stats?.active_decisions || 0;
    }

    if (topScenario) {
        const scenarioRow = hasFilters ? topScenarios[0] : stats?.top_scenarios?.[0];
        topScenario.textContent = scenarioRow
            ? `${scenarioRow.scenario} (${scenarioRow.count})`
            : '-';
    }

    if (topCountry) {
        const countryRow = hasFilters ? topCountries[0] : stats?.top_countries?.[0];
        if (countryRow) {
            const flag = getCountryFlagHtml(countryRow.country);
            topCountry.innerHTML = `${flag} ${countryRow.country} (${countryRow.count})`;
        } else {
            topCountry.textContent = '-';
        }
    }
}

function renderDashboard() {
    const stats = dashboardState.stats || {};
    const hasFilters = hasActiveDashboardFilters();
    const filteredAlerts = applyDashboardFilters(dashboardState.alerts);
    if (hasFilters) {
        const topScenarios = buildTopScenarios(filteredAlerts);
        const topCountries = buildTopCountries(filteredAlerts);
        const topIps = buildTopIps(filteredAlerts);
        const alertsByHost = buildAlertsByHost(filteredAlerts);
        const timelineData = buildTimelineData(filteredAlerts);

        updateSummaryCards(stats, filteredAlerts, topScenarios, topCountries);
        updateTimelineChart(timelineData);
        updateScenariosChart(topScenarios);
        updateCountriesTable(topCountries);
        updateIpsTable(topIps);
        updateSourcesChart(alertsByHost);

        worldMapData.alerts = buildWorldMapDatasetFromAlerts(filteredAlerts, 'alerts');
        worldMapData.decisions = buildWorldMapDatasetFromAlerts(filteredAlerts, 'decisions');
        renderWorldMap();
    } else {
        updateSummaryCards(stats, filteredAlerts, stats.top_scenarios || [], stats.top_countries || []);
        updateTimelineChart(buildTimelineFromStats(stats.timeline_24h || []));
        updateScenariosChart(stats.top_scenarios || []);
        updateCountriesTable(stats.top_countries || []);
        updateIpsTable(stats.top_ips || []);
        updateSourcesChart(stats.alerts_by_host || []);

        worldMapData.alerts = buildMapDataset(stats.top_countries || []);
        worldMapData.decisions = buildMapDataset(stats.top_decision_countries || []);
        renderWorldMap();
    }

    updateDashboardFilterStatus();
}

// Alerts functions
let alertFilterRefreshTimer = null;

function queueAlertFilterRefresh() {
    if (alertFilterRefreshTimer) {
        clearTimeout(alertFilterRefreshTimer);
    }
    alertFilterRefreshTimer = setTimeout(() => {
        loadAlerts();
    }, 300);
}

function queueAlertFilterSessionSave() {
    if (alertFilterSessionTimer) {
        clearTimeout(alertFilterSessionTimer);
    }
    alertFilterSessionTimer = setTimeout(() => {
        saveAlertFiltersToSession();
    }, 300);
}

function getAlertFilterSessionPayload() {
    return {
        scenario: document.getElementById('alertFilterScenario')?.value?.trim() || '',
        ip: document.getElementById('alertFilterIp')?.value?.trim() || '',
        machine: document.getElementById('alertFilterMachine')?.value?.trim() || '',
        country: document.getElementById('alertFilterCountry')?.value?.trim() || '',
        repeatedOnly: document.getElementById('alertFilterRepeated')?.checked || false
    };
}

async function saveAlertFiltersToSession() {
    try {
        await apiPost('/alerts.php?filters=1', getAlertFilterSessionPayload());
    } catch (error) {
        console.error('Failed to save alert filters:', error);
    }
}

function applyAlertFilterDefaults(defaults) {
    if (!defaults) return;
    const scenarioInput = document.getElementById('alertFilterScenario');
    if (scenarioInput && defaults.scenario !== undefined) scenarioInput.value = defaults.scenario;
    const ipInput = document.getElementById('alertFilterIp');
    if (ipInput && defaults.ip !== undefined) ipInput.value = defaults.ip;
    const machineSelect = document.getElementById('alertFilterMachine');
    if (machineSelect && defaults.machine !== undefined) machineSelect.value = defaults.machine;
    const countryInput = document.getElementById('alertFilterCountry');
    if (countryInput && defaults.country !== undefined) countryInput.value = defaults.country;
    const repeatedCheckbox = document.getElementById('alertFilterRepeated');
    if (repeatedCheckbox && defaults.repeatedOnly !== undefined) repeatedCheckbox.checked = Boolean(defaults.repeatedOnly);
}

async function loadAlerts() {
    try {
        const filters = getAlertFilterParams();
        const params = buildAlertQueryParams(filters);
        const query = params.toString();
        const [alerts, summary, filterOptions] = await Promise.all([
            apiGet(`/alerts.php${query ? `?${query}` : ''}`),
            apiGet(`/alerts.php?summary=1${query ? `&${query}` : ''}`),
            apiGet('/alerts.php?filters=1')
        ]);
        alertsData = (alerts || []).map(alert => ({
            ...alert,
            is_repeated: Boolean(Number(alert.is_repeated))
        }));
        alertFilterOptions = filterOptions || null;
        const totalCount = document.getElementById('alertsTotalCount');
        if (totalCount) {
            totalCount.textContent = summary?.total_alerts ?? alertsData.length;
        }
        updateAlertFilterOptions();
        renderAlerts();
    } catch (error) {
        console.error('Failed to load alerts:', error);
    }
}

function buildAlertQueryParams(filters) {
    const params = new URLSearchParams();
    if (filters.scenario) params.set('scenario', filters.scenario);
    if (filters.ip) params.set('ip', filters.ip);
    if (filters.machine) params.set('machine', filters.machine);
    if (filters.country) params.set('country', filters.country);
    return params;
}

function getAlertFilterParams() {
    return {
        scenario: normalizeString(document.getElementById('alertFilterScenario')?.value),
        ip: normalizeString(document.getElementById('alertFilterIp')?.value),
        machine: document.getElementById('alertFilterMachine')?.value?.trim() || '',
        country: document.getElementById('alertFilterCountry')?.value?.trim() || ''
    };
}

function renderAlerts() {
    const tbody = document.querySelector('#alertsTable tbody');
    if (!tbody) return;

    const filters = getAlertFilters();

    const filtered = alertsData.filter(alert => {
        const scenarioValue = normalizeString(alert.scenario);
        const ipValue = normalizeString(alert.source_ip);
        const machineValue = normalizeString(alert.machine_id);
        const countryValue = normalizeString(alert.source_country);
        const isRepeated = alert.is_repeated === true;

        return (
            matchesFilter(scenarioValue, filters.scenario) &&
            matchesFilter(ipValue, filters.ip) &&
            matchesFilter(machineValue, filters.machine) &&
            matchesFilter(countryValue, filters.country) &&
            (!filters.repeatedOnly || isRepeated)
        );
    });

    const sorted = filtered.sort((a, b) => {
        const key = alertSortState.key;
        let aValue = '';
        let bValue = '';
        switch (key) {
            case 'created_at':
                aValue = new Date(a.created_at).getTime() || 0;
                bValue = new Date(b.created_at).getTime() || 0;
                break;
            case 'scenario':
                aValue = a.scenario || '';
                bValue = b.scenario || '';
                break;
            case 'machine':
                aValue = a.machine_id || '';
                bValue = b.machine_id || '';
                break;
            case 'duration':
                aValue = getAlertDurationMinutes(a) ?? -1;
                bValue = getAlertDurationMinutes(b) ?? -1;
                break;
            case 'source_ip':
                aValue = a.source_ip || '';
                bValue = b.source_ip || '';
                break;
            case 'source_country':
                aValue = a.source_country || '';
                bValue = b.source_country || '';
                break;
            case 'events_count':
                aValue = Number(a.events_count || 0);
                bValue = Number(b.events_count || 0);
                break;
            default:
                aValue = a.scenario || '';
                bValue = b.scenario || '';
        }
        return compareValues(aValue, bValue, alertSortState.direction);
    });

    tbody.innerHTML = sorted.map(alert => {
        const flag = getCountryFlagHtml(alert.source_country);
        const scenarioLabel = alert.scenario?.split('/').pop() || '';
        const machineLabel = alert.machine_id || '-';
        const isRepeated = alert.is_repeated === true;
        const repeatedBadge = isRepeated ? '<span class="badge badge-repeated">Opakovaný</span>' : '';
        const activeDecisions = (alert.decisions || []).filter(decision => !decision.expired);
        const hasActiveDecision = activeDecisions.length > 0;
        const banLabel = hasActiveDecision ? 'Unban' : 'Ban';
        const banIcon = hasActiveDecision ? 'fa-unlock' : 'fa-ban';
        const banClass = hasActiveDecision ? 'icon-btn-warning' : 'icon-btn-danger';
        const ipDisabled = !alert.source_ip;
        const extendDisabled = ipDisabled || !hasActiveDecision;

        return `
            <tr class="${isRepeated ? 'alert-repeated' : ''}">
                <td>${formatDateTime(alert.created_at)}</td>
                <td>${formatAlertDuration(alert)}</td>
                <td class="table-filter-link" title="${alert.scenario}" data-tooltip="Kliknutím přefiltrujete" data-filter-target="alertFilterScenario" data-filter-value="${alert.scenario}">${scenarioLabel} ${repeatedBadge}</td>
                <td class="table-filter-link" data-tooltip="Kliknutím přefiltrujete" data-filter-target="alertFilterMachine" data-filter-value="${alert.machine_id || ''}">${machineLabel}</td>
                <td class="table-filter-link" data-tooltip="Kliknutím přefiltrujete" data-filter-target="alertFilterIp" data-filter-value="${alert.source_ip || ''}">${alert.source_ip || '-'}</td>
                <td class="table-filter-link" data-tooltip="Kliknutím přefiltrujete" data-filter-target="alertFilterCountry" data-filter-value="${alert.source_country || ''}">${flag} ${alert.source_country || '-'}</td>
                <td>${alert.events_count || 0}</td>
                <td>
                    <div class="table-actions">
                        <button class="icon-btn icon-btn-primary" onclick="viewAlert(${alert.id})" aria-label="Detail" title="Detail">
                            <i class="fa-solid fa-eye"></i>
                        </button>
                        <button class="icon-btn ${banClass}" onclick="toggleAlertDecision(${alert.id})" ${ipDisabled ? 'disabled' : ''} aria-label="${banLabel}" title="${banLabel}">
                            <i class="fa-solid ${banIcon}"></i>
                        </button>
                        <button class="icon-btn icon-btn-primary" onclick="extendAlertDecision(${alert.id})" ${extendDisabled ? 'disabled' : ''} aria-label="Prodloužit ban" title="Prodloužit ban">
                            <i class="fa-solid fa-clock"></i>
                        </button>
                        <button class="icon-btn icon-btn-success" onclick="addAlertIpToWhitelist('${alert.source_ip || ''}')" ${ipDisabled ? 'disabled' : ''} aria-label="Whitelist" title="Whitelist">
                            <i class="fa-solid fa-shield"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');

    updateSortIndicators('alertsTable', alertSortState);
}

async function viewAlert(id) {
    try {
        const alert = await apiGet(`/alerts.php?id=${id}`);
        showAlertModal(alert);
    } catch (error) {
        console.error('Failed to load alert details:', error);
    }
}

function showAlertModal(alert) {
    const modal = document.getElementById('alertModal');
    const detail = document.getElementById('alertDetail');
    if (!modal || !detail) return;

    const source = alert.source || {};
    const flag = getCountryFlagHtml(source.cn);
    const latitude = alert.source_latitude;
    const longitude = alert.source_longitude;
    let mapLink = '-';
    if (latitude !== null && latitude !== undefined && longitude !== null && longitude !== undefined && latitude !== '' && longitude !== '') {
        const lat = Number(latitude);
        const lon = Number(longitude);
        if (!Number.isNaN(lat) && !Number.isNaN(lon)) {
            const mapUrl = `https://www.openstreetmap.org/?mlat=${lat}&mlon=${lon}#map=12/${lat}/${lon}`;
            mapLink = `<a href="${mapUrl}" target="_blank" rel="noopener">Zobrazit mapu</a>`;
        }
    }
    const events = Array.isArray(alert.events_detail) && alert.events_detail.length > 0
        ? alert.events_detail
        : Array.isArray(alert.events)
            ? alert.events
            : [];

    const hiddenEventKeys = new Set([
        'timestamp',
        'isineu',
        'isocode',
        'country',
        'sourcecountry',
        'stat',
        'cn',
        'sourceip',
        'sourcerange',
        'asnnumber',
        'asnorg'
    ]);

    const normalizeEventKey = (key) => (key ?? '')
        .toString()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '');

    const eventsHtml = events.length > 0
        ? `
            <div class="alert-events">
                ${events.map(event => {
                    const eventTime = event.time ? formatDateTime(event.time) : '-';
                    const metaItems = Array.isArray(event.meta) ? event.meta : [];
                    const filteredMeta = metaItems.filter(meta => {
                        const key = normalizeEventKey(meta?.key);
                        return key && !hiddenEventKeys.has(key);
                    });
                    const metaHtml = filteredMeta.length > 0
                        ? `
                            <div class="alert-event-meta">
                                ${filteredMeta.map(meta => `
                                    <div class="alert-event-meta-row">
                                        <span class="alert-event-meta-key">${meta.key ?? '-'}</span>
                                        <span class="alert-event-meta-value ${normalizeEventKey(meta?.key) === 'logtype' ? 'alert-event-pill' : ''}">${meta.value ?? '-'}</span>
                                    </div>
                                `).join('')}
                            </div>
                        `
                        : '<div class="muted alert-event-empty">Bez detailů</div>';

                    return `
                        <div class="alert-event-card">
                            <div class="alert-event-header">
                                <span class="alert-event-time">${eventTime}</span>
                                <span class="alert-event-id">#${event.id ?? '-'}</span>
                            </div>
                            ${metaHtml}
                        </div>
                    `;
                }).join('')}
            </div>
        `
        : '<p>Žádné události</p>';

    detail.innerHTML = `
        <h3>Alert #${alert.id}</h3>
        <div class="alert-detail-grid">
            <div class="detail-item">
                <label>Scénář</label>
                <div class="value">${alert.scenario}</div>
            </div>
            <div class="detail-item">
                <label>Země</label>
                <div class="value">${flag} ${source.cn || '-'}</div>
            </div>
            <div class="detail-item">
                <label>ASN</label>
                <div class="value">${alert.source_as_number || '-'}</div>
            </div>
            <div class="detail-item">
                <label>AS název</label>
                <div class="value">${alert.source_as_name || '-'}</div>
            </div>
            <div class="detail-item">
                <label>Mapa</label>
                <div class="value">${mapLink}</div>
            </div>
            <div class="detail-item">
                <label>Čas vytvoření</label>
                <div class="value">${formatDateTime(alert.created_at)}</div>
            </div>
            <div class="detail-item">
                <label>Počet událostí</label>
                <div class="value">${alert.events_count || 0}</div>
            </div>
        </div>
        <h4>Rozhodnutí</h4>
        ${alert.decisions && alert.decisions.length > 0 ? `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Typ</th>
                        <th>Hodnota</th>
                        <th>Do</th>
                    </tr>
                </thead>
                <tbody>
                    ${alert.decisions.map(d => `
                        <tr>
                            <td>${d.type}</td>
                            <td>${d.value}</td>
                            <td>${formatDateTime(d.until)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        ` : '<p>Žádné rozhodnutí</p>'}
        <h4>Události</h4>
        ${eventsHtml}
    `;

    modal.classList.add('active');
}

function refreshAlerts() {
    loadAlerts();
    showNotification('Data obnovena', 'success');
}

function getAlertFilters() {
    return {
        scenario: normalizeString(document.getElementById('alertFilterScenario')?.value),
        ip: normalizeString(document.getElementById('alertFilterIp')?.value),
        machine: normalizeString(document.getElementById('alertFilterMachine')?.value),
        country: normalizeString(document.getElementById('alertFilterCountry')?.value),
        repeatedOnly: document.getElementById('alertFilterRepeated')?.checked || false
    };
}

function updateAlertFilterOptions() {
    const scenarios = alertFilterOptions?.scenarios || alertsData.map(alert => alert.scenario);
    const ips = alertFilterOptions?.ips || alertsData.map(alert => alert.source_ip);
    const countries = alertFilterOptions?.countries || alertsData.map(alert => alert.source_country);
    const machines = alertFilterOptions?.machines || alertsData.map(alert => alert.machine_id);
    setDatalistOptions('alertScenarioList', uniqueValues(scenarios.map(scenario => scenario?.split('/').pop())));
    setDatalistOptions('alertIpList', uniqueValues(ips));
    setDatalistOptions('alertCountryList', uniqueValues(countries));
    const machineOptions = uniqueValues(machines);
    const machineSelect = document.getElementById('alertFilterMachine');
    if (machineSelect) {
        const currentValue = machineSelect.value;
        machineSelect.innerHTML = '<option value="">Všechny machine</option>' + machineOptions.map(option => `
            <option value="${option}">${option}</option>
        `).join('');
        if (currentValue) {
            machineSelect.value = currentValue;
        }
    }
}

function clearAlertFilters() {
    const inputs = document.querySelectorAll('#alertFilterScenario, #alertFilterIp, #alertFilterMachine, #alertFilterCountry');
    inputs.forEach(input => {
        if (input) input.value = '';
    });
    const repeatedCheckbox = document.getElementById('alertFilterRepeated');
    if (repeatedCheckbox) repeatedCheckbox.checked = false;
    queueAlertFilterSessionSave();
    loadAlerts();
}

function findAlertById(alertId) {
    return alertsData.find(alert => Number(alert.id) === Number(alertId));
}

async function toggleAlertDecision(alertId) {
    const alert = findAlertById(alertId);
    if (!alert) return;

    const ip = alert.source_ip || '';
    const activeDecisions = (alert.decisions || []).filter(decision => !decision.expired);

    if (activeDecisions.length > 0) {
        if (!confirm(`Opravdu chcete zrušit rozhodnutí pro IP ${ip}?`)) return;
        try {
            await Promise.all(activeDecisions.map(decision => apiDelete(`/decisions.php?id=${decision.id}`)));
            showNotification('Rozhodnutí bylo zrušeno', 'success');
            loadAlerts();
        } catch (error) {
            console.error('Failed to delete decisions:', error);
            showNotification('Nepodařilo se zrušit rozhodnutí', 'error');
        }
    } else {
        showLongTermBanModal(ip);
    }
}

function extendAlertDecision(alertId) {
    const alert = findAlertById(alertId);
    if (!alert) return;
    const ip = alert.source_ip || '';
    const activeDecisions = (alert.decisions || []).filter(decision => !decision.expired);
    if (!ip || activeDecisions.length === 0) return;
    showLongTermBanModal(ip, { reason: 'extend' });
}

async function addAlertIpToWhitelist(ip) {
    if (!ip) return;
    if (!confirm(`Přidat IP ${ip} do whitelistu?`)) return;

    try {
        await apiPost('/whitelist.php', { cidr: ip, reason: 'manual' });
        showNotification('IP byla přidána do whitelistu', 'success');
    } catch (error) {
        console.error('Failed to add IP to whitelist:', error);
        const message = error?.message || 'Nepodařilo se přidat IP do whitelistu';
        showNotification(message, 'error');
    }
}

// Decisions functions
async function loadDecisions() {
    try {
        const includeExpired = document.getElementById('includeExpired')?.checked || false;
        const url = includeExpired ? '/decisions.php?include_expired=true' : '/decisions.php';
        decisionsData = await apiGet(url);
        updateDecisionFilterOptions();
        renderDecisions();
    } catch (error) {
        console.error('Failed to load decisions:', error);
    }
}

function renderDecisions() {
    const tbody = document.querySelector('#decisionsTable tbody');
    if (!tbody) return;

    const hideDuplicates = document.getElementById('hideDuplicates')?.checked || false;
    const filters = getDecisionFilters();

    const filtered = decisionsData.filter(decision => {
        if (hideDuplicates && decision.is_duplicate) return false;
        const statusLabel = decision.expired ? 'expirované' : 'aktivní';
        return (
            matchesFilter(decision.value, filters.value) &&
            matchesFilter(decision.scenario, filters.scenario) &&
            matchesFilter(decision.detail.type, filters.type) &&
            matchesFilter(decision.detail.country, filters.country) &&
            matchesFilter(statusLabel, filters.status)
        );
    });

    const sorted = filtered.sort((a, b) => {
        const key = decisionSortState.key;
        let aValue = '';
        let bValue = '';
        switch (key) {
            case 'id':
                aValue = Number(a.id);
                bValue = Number(b.id);
                break;
            case 'created_at':
                aValue = new Date(a.created_at).getTime();
                bValue = new Date(b.created_at).getTime();
                break;
            case 'value':
                aValue = a.value || '';
                bValue = b.value || '';
                break;
            case 'type':
                aValue = a.detail.type || '';
                bValue = b.detail.type || '';
                break;
            case 'scenario':
                aValue = a.scenario || '';
                bValue = b.scenario || '';
                break;
            case 'country':
                aValue = a.detail.country || '';
                bValue = b.detail.country || '';
                break;
            case 'expiration':
                aValue = new Date(a.detail.expiration).getTime();
                bValue = new Date(b.detail.expiration).getTime();
                break;
            case 'status':
                aValue = a.expired ? 1 : 0;
                bValue = b.expired ? 1 : 0;
                break;
            default:
                aValue = a.id;
                bValue = b.id;
        }
        return compareValues(aValue, bValue, decisionSortState.direction);
    });

    tbody.innerHTML = sorted.map(decision => {
        const expired = decision.expired;
        const statusBadge = expired
            ? '<span class="badge badge-expired">Expirované</span>'
            : '<span class="badge badge-active">Aktivní</span>';

        const duplicateBadge = decision.is_duplicate
            ? '<span class="badge badge-duplicate">Duplikát</span>'
            : '';

        const flag = getCountryFlagHtml(decision.detail.country);

        const statusLabel = expired ? 'Expirované' : 'Aktivní';
        const scenarioLabel = decision.scenario?.split('/').pop() || '';

        return `
            <tr class="${expired ? 'expired' : ''}">
                <td data-filter-target="decisionFilterValue" data-filter-value="${decision.id}">${decision.id}</td>
                <td>${formatRelativeTime(decision.created_at)}</td>
                <td data-filter-target="decisionFilterValue" data-filter-value="${decision.value}">${decision.value}</td>
                <td data-filter-target="decisionFilterType" data-filter-value="${decision.detail.type}">${decision.detail.type}</td>
                <td title="${decision.scenario}" data-filter-target="decisionFilterScenario" data-filter-value="${decision.scenario}">${scenarioLabel}</td>
                <td data-filter-target="decisionFilterCountry" data-filter-value="${decision.detail.country}">${flag} ${decision.detail.country}</td>
                <td>${formatDateTime(decision.detail.expiration)}</td>
                <td data-filter-target="decisionFilterStatus" data-filter-value="${statusLabel}">${statusBadge} ${duplicateBadge}</td>
                <td>
                    ${!expired ? `<button class="btn btn-small btn-danger" onclick="deleteDecision(${decision.id})">Smazat</button>` : '-'}
                </td>
            </tr>
        `;
    }).join('');

    updateSortIndicators('decisionsTable', decisionSortState);
}

async function deleteDecision(id) {
    if (!confirm('Opravdu chcete smazat tento ban?')) return;

    try {
        await apiDelete(`/decisions.php?id=${id}`);
        showNotification('Ban byl úspěšně smazán', 'success');
        loadDecisions();
    } catch (error) {
        console.error('Failed to delete decision:', error);
    }
}

function filterDuplicates() {
    renderDecisions();
}

function refreshDecisions() {
    loadDecisions();
    showNotification('Data obnovena', 'success');
}

function getDecisionFilters() {
    return {
        value: normalizeString(document.getElementById('decisionFilterValue')?.value),
        scenario: normalizeString(document.getElementById('decisionFilterScenario')?.value),
        type: normalizeString(document.getElementById('decisionFilterType')?.value),
        country: normalizeString(document.getElementById('decisionFilterCountry')?.value),
        status: normalizeString(document.getElementById('decisionFilterStatus')?.value)
    };
}

function updateDecisionFilterOptions() {
    setDatalistOptions('decisionValueList', uniqueValues(decisionsData.map(decision => decision.value)));
    setDatalistOptions('decisionScenarioList', uniqueValues(decisionsData.map(decision => decision.scenario?.split('/').pop())));
    setDatalistOptions('decisionTypeList', uniqueValues(decisionsData.map(decision => decision.detail.type)));
    setDatalistOptions('decisionCountryList', uniqueValues(decisionsData.map(decision => decision.detail.country)));
}

function clearDecisionFilters() {
    const inputs = document.querySelectorAll('#decisionFilterValue, #decisionFilterScenario, #decisionFilterType, #decisionFilterCountry, #decisionFilterStatus');
    inputs.forEach(input => {
        if (input) input.value = '';
    });
    renderDecisions();
}

// Whitelist functions
async function loadWhitelist() {
    try {
        whitelistData = await apiGet('/whitelist.php');
        renderWhitelist();
    } catch (error) {
        console.error('Failed to load whitelist:', error);
    }
}

async function loadAllowLists() {
    try {
        allowListsData = await apiGet('/allowlists.php');
        renderAllowListOptions();
    } catch (error) {
        console.error('Failed to load allow lists:', error);
    }
}

function renderAllowListOptions() {
    const select = document.getElementById('whitelistList');
    if (!select) return;
    if (!allowListsData.length) {
        select.innerHTML = '<option value="">Žádné whitelisty</option>';
        select.disabled = true;
        return;
    }

    select.disabled = false;
    select.innerHTML = allowListsData.map(list => `
        <option value="${list.id}">${list.name}</option>
    `).join('');
}

function renderWhitelist() {
    const tbody = document.querySelector('#whitelistTable tbody');
    if (!tbody) return;

    if (!whitelistData.length) {
        tbody.innerHTML = '<tr><td class="table-empty" colspan="8">Žádná data.</td></tr>';
        return;
    }

    const valueFilter = normalizeString(document.getElementById('whitelistFilterCidr')?.value);
    const commentFilter = normalizeString(document.getElementById('whitelistFilterReason')?.value);
    const listFilter = normalizeString(document.getElementById('whitelistFilterList')?.value);

    const filtered = whitelistData.filter(item => {
        return (
            matchesFilter(item.cidr, valueFilter) &&
            matchesFilter(item.reason, commentFilter) &&
            matchesFilter(item.list_name, listFilter)
        );
    });

    const sorted = filtered.sort((a, b) => {
        const key = whitelistSortState.key;
        let aValue = '';
        let bValue = '';
        switch (key) {
            case 'id':
                aValue = Number(a.id);
                bValue = Number(b.id);
                break;
            case 'list_name':
                aValue = a.list_name || '';
                bValue = b.list_name || '';
                break;
            case 'cidr':
                aValue = a.cidr || '';
                bValue = b.cidr || '';
                break;
            case 'reason':
                aValue = a.reason || '';
                bValue = b.reason || '';
                break;
            case 'expires_at':
                aValue = a.expires_at ? new Date(a.expires_at).getTime() : null;
                bValue = b.expires_at ? new Date(b.expires_at).getTime() : null;
                break;
            case 'created_at':
                aValue = a.created_at ? new Date(a.created_at).getTime() : null;
                bValue = b.created_at ? new Date(b.created_at).getTime() : null;
                break;
            case 'updated_at':
                aValue = a.updated_at ? new Date(a.updated_at).getTime() : null;
                bValue = b.updated_at ? new Date(b.updated_at).getTime() : null;
                break;
            default:
                aValue = a.id;
                bValue = b.id;
        }
        return compareValues(aValue, bValue, whitelistSortState.direction);
    });

    tbody.innerHTML = sorted.map(item => {
        return `
            <tr>
                <td>${item.id}</td>
                <td>${item.list_name ?? '-'}</td>
                <td>${item.cidr}</td>
                <td>${item.reason ?? '-'}</td>
                <td>${item.expires_at ? formatDateTime(item.expires_at) : '-'}</td>
                <td>${item.created_at ? formatDateTime(item.created_at) : '-'}</td>
                <td>${item.updated_at ? formatDateTime(item.updated_at) : '-'}</td>
                <td>
                    <button class="btn btn-small btn-ghost" onclick='editWhitelistItem(${JSON.stringify(item)})'>Upravit</button>
                    <button class="btn btn-small btn-danger" onclick="deleteWhitelistItem(${item.id})">Smazat</button>
                </td>
            </tr>
        `;
    }).join('');

    updateSortIndicators('whitelistTable', whitelistSortState);
}

function openWhitelistModal() {
    const modal = document.getElementById('whitelistModal');
    if (!modal) return;
    if (!allowListsData.length) {
        showNotification('Nejprve vytvořte whitelist (např. cscli allowlist create).', 'error');
        return;
    }
    document.getElementById('whitelistModalTitle').textContent = 'Přidat položku';
    document.getElementById('whitelistId').value = '';
    document.getElementById('whitelistList').value = allowListsData[0]?.id ?? '';
    document.getElementById('whitelistCidr').value = '';
    document.getElementById('whitelistReason').value = '';
    document.getElementById('whitelistExpiresAt').value = '';
    modal.classList.add('active');
}

function editWhitelistItem(item) {
    const modal = document.getElementById('whitelistModal');
    if (!modal) return;
    document.getElementById('whitelistModalTitle').textContent = 'Upravit položku';
    document.getElementById('whitelistId').value = item.id;
    document.getElementById('whitelistList').value = item.allow_list_id ?? '';
    document.getElementById('whitelistCidr').value = item.cidr || '';
    document.getElementById('whitelistReason').value = item.reason || '';
    document.getElementById('whitelistExpiresAt').value = item.expires_at ? item.expires_at.replace(' ', 'T').slice(0, 16) : '';
    modal.classList.add('active');
}

async function deleteWhitelistItem(id) {
    if (!confirm('Opravdu chcete smazat tuto položku?')) return;
    try {
        await apiDelete(`/whitelist.php?id=${id}`);
        showNotification('Whitelist položka byla smazána', 'success');
        loadWhitelist();
    } catch (error) {
        console.error('Failed to delete whitelist item:', error);
    }
}

function refreshWhitelist() {
    loadWhitelist();
    showNotification('Data obnovena', 'success');
}

function clearWhitelistFilters() {
    const inputs = document.querySelectorAll('#whitelistFilterCidr, #whitelistFilterReason, #whitelistFilterList');
    inputs.forEach(input => {
        if (input) input.value = '';
    });
    renderWhitelist();
}

// Machines functions
async function loadMachines() {
    try {
        machinesData = await apiGet('/machines.php');
        renderMachines();
    } catch (error) {
        console.error('Failed to load machines:', error);
    }
}

function getMachineStatusBadge(machine) {
    const statusText = (machine.status || '').trim();
    let label = statusText;
    let badgeClass = 'badge-muted';
    const heartbeat = machine.last_heartbeat ? new Date(machine.last_heartbeat) : null;
    const heartbeatValid = heartbeat && !Number.isNaN(heartbeat.getTime());
    const isOnline = Boolean(machine.is_validated) && heartbeatValid && (Date.now() - heartbeat.getTime()) <= 120 * 1000;

    if (isOnline) {
        label = 'Online';
        badgeClass = 'badge-active';
    } else {
        if (!label) {
            label = 'Neaktivní';
        }
        if (label.toLowerCase() === 'active') {
            badgeClass = 'badge-active';
        }
    }

    const validationLabel = machine.is_validated ? 'Validováno' : 'Neověřeno';
    return `<span class="badge ${badgeClass}">${label}</span> <span class="muted">${validationLabel}</span>`;
}

function renderMachines() {
    const tbody = document.querySelector('#machinesTable tbody');
    if (!tbody) return;

    if (!machinesData.length) {
        tbody.innerHTML = '<tr><td class="table-empty" colspan="7">Žádná data.</td></tr>';
        return;
    }

    tbody.innerHTML = machinesData.map(machine => `
        <tr>
            <td>${machine.machine_id || '-'}</td>
            <td>${machine.ip_address || '-'}</td>
            <td>${getMachineStatusBadge(machine)}</td>
            <td>${formatDateTime(machine.last_heartbeat)}</td>
            <td>${formatDateTime(machine.last_push)}</td>
            <td>${machine.alerts_count ?? 0}</td>
            <td>${machine.decisions_count ?? 0}</td>
        </tr>
    `).join('');
}

function showAddDecisionModal() {
    const modal = document.getElementById('addDecisionModal');
    if (!modal) return;
    modal.classList.add('active');
}

function showLongTermBanModal(ip, options = {}) {
    const modal = document.getElementById('longTermBanModal');
    if (!modal) return;
    const ipInput = document.getElementById('longTermBanIp');
    const durationSelect = document.getElementById('longTermBanDuration');
    const reasonInput = document.getElementById('longTermBanReason');
    if (ipInput) {
        ipInput.value = ip || '';
    }
    if (durationSelect) {
        const defaultDuration = durationSelect.querySelector('option[selected]')?.value || durationSelect.value;
        durationSelect.value = options.duration || defaultDuration;
    }
    if (reasonInput) {
        reasonInput.value = options.reason || reasonInput.defaultValue || 'manual';
    }
    modal.classList.add('active');
}

function focusCountryOnMap(countryCode) {
    if (!worldMap || !countryCode) return;
    const code = countryCode.toUpperCase();
    try {
        worldMap.clearSelectedRegions();
        worldMap.setSelectedRegions([code]);
        worldMap.setFocus({ region: code, animate: true, scale: 2 });
    } catch (error) {
        console.warn('Map focus failed:', error);
    }
}

// Modal handling
window.addEventListener('DOMContentLoaded', () => {
    const mapModeSelect = document.getElementById('mapMode');
    if (mapModeSelect) {
        mapModeSelect.addEventListener('change', (event) => {
            setWorldMapMode(event.target.value);
        });
    }

    document.querySelectorAll('.modal-close').forEach(closeBtn => {
        closeBtn.addEventListener('click', (e) => {
            e.target.closest('.modal').classList.remove('active');
        });
    });

    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('active');
            }
        });
    });

    document.querySelectorAll('[data-filter-key]').forEach(input => {
        input.addEventListener('input', () => {
            if (input.id.startsWith('alert')) {
                queueAlertFilterRefresh();
                queueAlertFilterSessionSave();
            } else {
                renderDecisions();
            }
        });
    });

    const alertsTable = document.getElementById('alertsTable');
    if (alertsTable) {
        alertsTable.addEventListener('click', (event) => {
            const cell = event.target.closest('[data-filter-target]');
            if (!cell) return;
            const targetId = cell.dataset.filterTarget;
            const value = cell.dataset.filterValue ?? '';
            const input = document.getElementById(targetId);
            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    const decisionsTable = document.getElementById('decisionsTable');
    if (decisionsTable) {
        decisionsTable.addEventListener('click', (event) => {
            const cell = event.target.closest('[data-filter-target]');
            if (!cell) return;
            const targetId = cell.dataset.filterTarget;
            const value = cell.dataset.filterValue ?? '';
            const input = document.getElementById(targetId);
            if (input) {
                input.value = value;
                input.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });
    }

    const whitelistTable = document.getElementById('whitelistTable');
    if (whitelistTable) {
        initializeSortableTable('whitelistTable', whitelistSortState, renderWhitelist);
    }

    const ipsTable = document.getElementById('ipsTable');
    if (ipsTable) {
        ipsTable.addEventListener('click', (event) => {
            const target = event.target.closest('.ip-action');
            if (!target) return;
            showLongTermBanModal(target.dataset.ip);
        });
    }

    const addForm = document.getElementById('addDecisionForm');
    if (addForm) {
        addForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const ip = document.getElementById('banIp').value;
            const duration = document.getElementById('banDuration').value;
            const reason = document.getElementById('banReason').value;

            try {
                await apiPost('/decisions.php', { ip, duration, reason });
                showNotification('Ban byl úspěšně přidán', 'success');
                document.getElementById('addDecisionModal').classList.remove('active');
                addForm.reset();
                loadDecisions();
            } catch (error) {
                console.error('Failed to add decision:', error);
            }
        });
    }

    const longTermBanForm = document.getElementById('longTermBanForm');
    if (longTermBanForm) {
        longTermBanForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const ip = document.getElementById('longTermBanIp').value;
            const duration = document.getElementById('longTermBanDuration').value;
            const reason = document.getElementById('longTermBanReason').value;

            try {
                await apiPost('/decisions.php', { ip, duration, reason });
                showNotification('Dlouhodobý ban byl úspěšně přidán', 'success');
                document.getElementById('longTermBanModal').classList.remove('active');
                longTermBanForm.reset();
                if (document.getElementById('alertsTable')) {
                    loadAlerts();
                } else {
                    loadDashboard();
                }
            } catch (error) {
                console.error('Failed to add long-term decision:', error);
            }
        });
    }

    const whitelistForm = document.getElementById('whitelistForm');
    if (whitelistForm) {
        whitelistForm.addEventListener('submit', async (e) => {
            e.preventDefault();

            const id = document.getElementById('whitelistId').value;
            const allowListId = document.getElementById('whitelistList').value;
            const value = document.getElementById('whitelistCidr').value;
            const comment = document.getElementById('whitelistReason').value;
            const expiresAtInput = document.getElementById('whitelistExpiresAt').value;
            const expiresAt = expiresAtInput ? expiresAtInput.replace('T', ' ') + ':00' : null;

            try {
                if (!allowListId) {
                    showNotification('Vyberte whitelist (allow list).', 'error');
                    return;
                }
                if (id) {
                    await apiPut('/whitelist.php', { id, allow_list_id: allowListId, cidr: value, reason: comment, expires_at: expiresAt });
                    showNotification('Whitelist položka byla upravena', 'success');
                } else {
                    await apiPost('/whitelist.php', { allow_list_id: allowListId, cidr: value, reason: comment, expires_at: expiresAt });
                    showNotification('Whitelist položka byla přidána', 'success');
                }
                document.getElementById('whitelistModal').classList.remove('active');
                whitelistForm.reset();
                loadWhitelist();
            } catch (error) {
                console.error('Failed to save whitelist item:', error);
            }
        });
    }

    const whitelistFilters = document.querySelectorAll('#whitelistFilterCidr, #whitelistFilterReason, #whitelistFilterList');
    whitelistFilters.forEach(input => {
        input.addEventListener('input', () => {
            renderWhitelist();
        });
    });

    initializeSortableTable('alertsTable', alertSortState, renderAlerts);
    initializeSortableTable('decisionsTable', decisionSortState, renderDecisions);
});

window.loadAllowLists = loadAllowLists;
window.loadWhitelist = loadWhitelist;
window.openWhitelistModal = openWhitelistModal;
window.refreshWhitelist = refreshWhitelist;
window.clearWhitelistFilters = clearWhitelistFilters;
