# CrowdSec PHP Web UI

PHP web application for CrowdSec alert and decision management with MySQL database support.

## Requirements

- PHP 8.0 or higher
- MySQL/MariaDB 10.3+
- Apache/Nginx web server
- PHP extensions: PDO, `pdo_mysql`, `curl`, `json`

## CrowdSec Prerequisites

- **CrowdSec:** A running CrowdSec instance.
- **Machine account:** Register a machine (watcher) for this web UI so it can push alerts and create decisions.

### Create the machine account

Generate a secure password:

```bash
openssl rand -hex 32
```

Create the machine:

```bash
docker exec crowdsec cscli machines add crowdsec-web-ui --password <generated_password> -f /dev/null
```

**Note:** The `-f /dev/null` flag is important. It prevents `cscli` from overwriting the existing credentials file inside the CrowdSec container. The goal is only to register the machine in the database.

### Trusted IPs for delete operations

By default, CrowdSec may restrict some write operations, such as deleting alerts, to trusted IP addresses. If you receive `403 Forbidden`, add the Web UI IP address or network to CrowdSec trusted IPs.

Example configuration in `/etc/crowdsec/config.yaml`:

```yaml
api:
  server:
    trusted_ips:
      - 127.0.0.1
      - ::1
      - 172.16.0.0/12
```

Or using the `TRUSTED_IPS` environment variable:

```bash
TRUSTED_IPS="127.0.0.1,::1,172.16.0.0/12"
```

## Installation

### 1. Clone the repository

```bash
git clone <repository-url>
cd crowdsec-php-ui
```

## Features

- Dashboard with aggregated CrowdSec alert statistics
- Alerts, decisions, machines, and whitelist management
- WHOIS / IP intel modal for IP addresses
- Auto-refresh on selected pages
- Audit log of user actions

## Environment

Main configuration is loaded from `.env`:

```env
CROWDSEC_URL=http://127.0.0.1:8080
CROWDSEC_USER=local-admin
CROWDSEC_PASSWORD=...

DB_HOST=localhost
DB_PORT=3306
DB_NAME=crowdsec
DB_USER=crowdsec_admin
DB_PASSWORD=...

TIMEZONE=Europe/Prague
LOOKBACK_PERIOD=7d
REFRESH_INTERVAL=30s
```

## Whitelist

`/whitelist.php` and the `Add to whitelist` action from alerts use shell calls through `sudo cscli`.

Used commands:

```bash
sudo cscli allowlists inspect local_whitelist -o json
sudo cscli allowlists add local_whitelist x.x.x.x/24 -d "MILNET"
sudo cscli allowlists remove local_whitelist x.x.x.x/24
```

### Sudo Requirements

The web server / PHP-FPM user must be allowed to execute `sudo cscli` without a password. Otherwise whitelist actions will fail.

Example `sudoers` rule for `www-data`:

```bash
www-data ALL=(ALL) NOPASSWD: /usr/bin/cscli
```

Recommended verification:

```bash
sudo -u www-data sudo cscli allowlists inspect local_whitelist -o json
```

## Dashboard

The dashboard at `/index.php` is based on aggregated data from `/api/stats.php`.
It intentionally does not load the full alert dataset through `/api/alerts.php?limit=0`, because that can exhaust PHP memory on larger installations.

Dashboard widgets:

- Alert timeline for the last 24 hours
- Top scenarios
- Alerts by machines
- Events by machines
- Top countries and top IP tables
- World map colored by attack origin countries

### Map Rendering

The dashboard tries to use `jsVectorMap` first. If the world map asset is not available on the target system, it falls back to Google GeoChart.

External dashboard libraries:

- `Chart.js`
- `jsVectorMap`
- `Google Charts GeoChart` fallback

## Time Handling

CrowdSec stores timestamps in UTC/GMT. The application therefore evaluates times in UTC and converts them to local time for display using the timezone configured in `.env`.

This affects:

- Alerts
- Decisions
- Machine heartbeat / online state
- Dashboard statistics

## Operational Notes

- If the dashboard map does not render, verify access to external CDN scripts.
- If whitelist actions return `permission denied`, verify `sudoers` and PHP-FPM user permissions.
- For larger datasets, make sure indexes for `alerts` and `decisions` are present.
