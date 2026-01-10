// API Base URL
var API_BASE = '/api';

// Cache for data
let alertsData = [];
let decisionsData = [];

// Utility functions
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
        renderAlerts();
    } catch (error) {
        console.error('Failed to load alerts:', error);
    }
}

function renderAlerts() {
    const tbody = document.querySelector('#alertsTable tbody');
    if (!tbody) return;

    const searchTerm = document.getElementById('searchAlerts')?.value.toLowerCase() || '';

    const filtered = alertsData.filter(alert => {
        const searchString = `${alert.id} ${alert.scenario} ${alert.source_ip}`.toLowerCase();
        return searchString.includes(searchTerm);
    });

    tbody.innerHTML = filtered.map(alert => {
        const decisionsCount = alert.decisions ? alert.decisions.length : 0;
        const flag = getCountryFlag(alert.source_country);

        return `
            <tr>
                <td>${alert.id}</td>
                <td>${formatRelativeTime(alert.created_at)}</td>
                <td title="${alert.scenario}">${alert.scenario.split('/').pop()}</td>
                <td>${alert.source_ip || '-'}</td>
                <td>${flag} ${alert.source_country || '-'}</td>
                <td>${alert.events_count || 0}</td>
                <td>${decisionsCount}</td>
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

// Decisions functions
async function loadDecisions() {
    try {
        const includeExpired = document.getElementById('includeExpired')?.checked || false;
        const url = includeExpired ? '/decisions.php?include_expired=true' : '/decisions.php';
        decisionsData = await apiGet(url);
        renderDecisions();
    } catch (error) {
        console.error('Failed to load decisions:', error);
    }
}

function renderDecisions() {
    const tbody = document.querySelector('#decisionsTable tbody');
    if (!tbody) return;

    const hideDuplicates = document.getElementById('hideDuplicates')?.checked || false;

    const filtered = decisionsData.filter(decision => {
        if (hideDuplicates && decision.is_duplicate) return false;
        return true;
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

        return `
            <tr class="${expired ? 'expired' : ''}">
                <td>${decision.id}</td>
                <td>${formatRelativeTime(decision.created_at)}</td>
                <td>${decision.value}</td>
                <td>${decision.detail.type}</td>
                <td title="${decision.scenario}">${decision.scenario.split('/').pop()}</td>
                <td>${flag} ${decision.detail.country}</td>
                <td>${formatDate(decision.detail.expiration)}</td>
                <td>${statusBadge} ${duplicateBadge}</td>
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
