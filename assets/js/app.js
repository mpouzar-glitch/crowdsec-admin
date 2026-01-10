// API Base URL
var API_BASE = '/api';

// Cache for data
let alertsData = [];
let decisionsData = [];

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

function setDatalistOptions(id, options) {
    const datalist = document.getElementById(id);
    if (!datalist) return;
    datalist.innerHTML = options.map(option => `<option value="${option}"></option>`).join('');
}

function formatDate(dateString) {
    const date = new Date(dateString);
    return date.toLocaleString('cs-CZ');
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

function getCountryFlag(countryCode) {
    if (!countryCode || countryCode.length !== 2) return '';
    const codePoints = countryCode
        .toUpperCase()
        .split('')
        .map(char => 127397 + char.charCodeAt());
    return String.fromCodePoint(...codePoints);
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
        const stats = await apiGet('/stats.php');

        document.getElementById('totalAlerts').textContent = stats.total_alerts || 0;
        document.getElementById('activeDecisions').textContent = stats.active_decisions || 0;

        if (stats.top_scenarios && stats.top_scenarios.length > 0) {
            const topScenario = stats.top_scenarios[0];
            document.getElementById('topScenario').textContent =
                `${topScenario.scenario} (${topScenario.count})`;
        }

        if (stats.top_countries && stats.top_countries.length > 0) {
            const topCountry = stats.top_countries[0];
            const flag = getCountryFlag(topCountry.country);
            document.getElementById('topCountry').textContent =
                `${flag} ${topCountry.country} (${topCountry.count})`;
        }

        if (stats.timeline_24h) {
            updateTimelineChart(stats.timeline_24h);
        }

        if (stats.top_scenarios) {
            updateScenariosChart(stats.top_scenarios);
        }

        if (stats.top_countries) {
            updateCountriesTable(stats.top_countries);
        }

        if (stats.top_ips) {
            updateIpsTable(stats.top_ips);
        }

    } catch (error) {
        console.error('Failed to load dashboard:', error);
    }
}

let timelineChart = null;
function updateTimelineChart(data) {
    const ctx = document.getElementById('timelineChart');
    if (!ctx) return;

    const labels = data.map(d => new Date(d.hour).toLocaleTimeString('cs-CZ', { hour: '2-digit', minute: '2-digit' }));
    const values = data.map(d => d.count);

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
            }
        }
    });
}

let scenariosChart = null;
function updateScenariosChart(data) {
    const ctx = document.getElementById('scenariosChart');
    if (!ctx) return;

    const labels = data.map(d => d.scenario.split('/').pop());
    const values = data.map(d => d.count);

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
                backgroundColor: '#2563eb'
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
                <span class="country-flag">${getCountryFlag(row.country)}</span>
                ${row.country || 'Unknown'}
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
            <td>${row.ip || 'Unknown'}</td>
            <td>${row.count}</td>
        </tr>
    `).join('');
}

// Alerts functions
async function loadAlerts() {
    try {
        alertsData = await apiGet('/alerts.php');
        updateAlertFilterOptions();
        renderAlerts();
    } catch (error) {
        console.error('Failed to load alerts:', error);
    }
}

function renderAlerts() {
    const tbody = document.querySelector('#alertsTable tbody');
    if (!tbody) return;

    const filters = getAlertFilters();

    const filtered = alertsData.filter(alert => {
        const searchString = normalizeString(`${alert.id} ${alert.scenario} ${alert.source_ip}`);
        const scenarioValue = normalizeString(alert.scenario);
        const ipValue = normalizeString(alert.source_ip);
        const countryValue = normalizeString(alert.source_country);
        const decisionsCount = `${alert.decisions ? alert.decisions.length : 0}`;

        return (
            matchesFilter(searchString, filters.search) &&
            matchesFilter(scenarioValue, filters.scenario) &&
            matchesFilter(ipValue, filters.ip) &&
            matchesFilter(countryValue, filters.country) &&
            matchesFilter(decisionsCount, filters.decisions)
        );
    });

    tbody.innerHTML = filtered.map(alert => {
        const decisionsCount = alert.decisions ? alert.decisions.length : 0;
        const flag = getCountryFlag(alert.source_country);
        const scenarioLabel = alert.scenario?.split('/').pop() || '';

        return `
            <tr>
                <td data-filter-target="searchAlerts" data-filter-value="${alert.id}">${alert.id}</td>
                <td>${formatRelativeTime(alert.created_at)}</td>
                <td title="${alert.scenario}" data-filter-target="alertFilterScenario" data-filter-value="${alert.scenario}">${scenarioLabel}</td>
                <td data-filter-target="alertFilterIp" data-filter-value="${alert.source_ip || ''}">${alert.source_ip || '-'}</td>
                <td data-filter-target="alertFilterCountry" data-filter-value="${alert.source_country || ''}">${flag} ${alert.source_country || '-'}</td>
                <td>${alert.events_count || 0}</td>
                <td data-filter-target="alertFilterDecisions" data-filter-value="${decisionsCount}">${decisionsCount}</td>
                <td>
                    <button class="btn btn-small" onclick="viewAlert(${alert.id})">Detail</button>
                    <button class="btn btn-small btn-danger" onclick="deleteAlert(${alert.id})">Smazat</button>
                </td>
            </tr>
        `;
    }).join('');
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
    const flag = getCountryFlag(source.cn);

    detail.innerHTML = `
        <h3>Alert #${alert.id}</h3>
        <div class="alert-detail-grid">
            <div class="detail-item">
                <label>Scénář</label>
                <div class="value">${alert.scenario}</div>
            </div>
            <div class="detail-item">
                <label>IP adresa</label>
                <div class="value">${source.ip || '-'}</div>
            </div>
            <div class="detail-item">
                <label>Země</label>
                <div class="value">${flag} ${source.cn || '-'}</div>
            </div>
            <div class="detail-item">
                <label>AS</label>
                <div class="value">${source.as_name || '-'}</div>
            </div>
            <div class="detail-item">
                <label>Čas vytvoření</label>
                <div class="value">${formatDate(alert.created_at)}</div>
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
                            <td>${formatDate(d.until)}</td>
                        </tr>
                    `).join('')}
                </tbody>
            </table>
        ` : '<p>Žádné rozhodnutí</p>'}
    `;

    modal.classList.add('active');
}

async function deleteAlert(id) {
    if (!confirm('Opravdu chcete smazat tento alert?')) return;

    try {
        await apiDelete(`/alerts.php?id=${id}`);
        showNotification('Alert byl úspěšně smazán', 'success');
        loadAlerts();
    } catch (error) {
        console.error('Failed to delete alert:', error);
    }
}

function refreshAlerts() {
    loadAlerts();
    showNotification('Data obnovena', 'success');
}

function getAlertFilters() {
    return {
        search: normalizeString(document.getElementById('searchAlerts')?.value),
        scenario: normalizeString(document.getElementById('alertFilterScenario')?.value),
        ip: normalizeString(document.getElementById('alertFilterIp')?.value),
        country: normalizeString(document.getElementById('alertFilterCountry')?.value),
        decisions: normalizeString(document.getElementById('alertFilterDecisions')?.value)
    };
}

function updateAlertFilterOptions() {
    setDatalistOptions('alertScenarioList', uniqueValues(alertsData.map(alert => alert.scenario?.split('/').pop())));
    setDatalistOptions('alertIpList', uniqueValues(alertsData.map(alert => alert.source_ip)));
    setDatalistOptions('alertCountryList', uniqueValues(alertsData.map(alert => alert.source_country)));
}

function clearAlertFilters() {
    const inputs = document.querySelectorAll('#searchAlerts, #alertFilterScenario, #alertFilterIp, #alertFilterCountry, #alertFilterDecisions');
    inputs.forEach(input => {
        if (input) input.value = '';
    });
    renderAlerts();
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

    tbody.innerHTML = filtered.map(decision => {
        const expired = decision.expired;
        const statusBadge = expired
            ? '<span class="badge badge-expired">Expirované</span>'
            : '<span class="badge badge-active">Aktivní</span>';

        const duplicateBadge = decision.is_duplicate
            ? '<span class="badge badge-duplicate">Duplikát</span>'
            : '';

        const flag = getCountryFlag(decision.detail.country);

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
                <td>${formatDate(decision.detail.expiration)}</td>
                <td data-filter-target="decisionFilterStatus" data-filter-value="${statusLabel}">${statusBadge} ${duplicateBadge}</td>
                <td>
                    ${!expired ? `<button class="btn btn-small btn-danger" onclick="deleteDecision(${decision.id})">Smazat</button>` : '-'}
                </td>
            </tr>
        `;
    }).join('');
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

function showAddDecisionModal() {
    const modal = document.getElementById('addDecisionModal');
    if (!modal) return;
    modal.classList.add('active');
}

// Modal handling
window.addEventListener('DOMContentLoaded', () => {
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

    const searchInput = document.getElementById('searchAlerts');
    if (searchInput) {
        searchInput.addEventListener('input', renderAlerts);
    }

    document.querySelectorAll('[data-filter-key]').forEach(input => {
        input.addEventListener('input', () => {
            if (input.id.startsWith('alert')) {
                renderAlerts();
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
});
