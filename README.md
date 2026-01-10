# CrowdSec PHP Web UI

PHP webová aplikace pro správu CrowdSec alertů a rozhodnutí s podporou MySQL databáze.

## Požadavky

- PHP 8.0 nebo vyšší
- MySQL/MariaDB 10.3+
- Apache/Nginx web server
- PHP extensions: PDO, pdo_mysql, curl, json


## CrowdSec prerequisites

- **CrowdSec:** A running CrowdSec instance.
- **Machine account:** Register a "Machine" (Watcher) for this web UI so it can push alerts (add decisions).

### Create the machine account

Generate a secure password:

```bash
openssl rand -hex 32
```

Create the machine:

```bash
docker exec crowdsec cscli machines add crowdsec-web-ui --password <generated_password> -f /dev/null
```

**Note:** The `-f /dev/null` flag is crucial. It tells `cscli` not to overwrite the existing credentials file of the CrowdSec container. We only want to register the machine in the database, not change the container's local config.

### Trusted IPs for delete operations (optional)

By default, CrowdSec may restrict certain write operations (like deleting alerts) to trusted IP addresses. If you encounter `403 Forbidden` errors when trying to delete alerts, add the Web UI's IP to CrowdSec's trusted IPs list.

Docker setup: add the Web UI container's network to the CrowdSec configuration in `/etc/crowdsec/config.yaml` or via environment variable:

```yaml
api:
  server:
    trusted_ips:
      - 127.0.0.1
      - ::1
      - 172.16.0.0/12  # Docker default bridge network
```

Or using the `TRUSTED_IPS` environment variable on the CrowdSec container:

```bash
TRUSTED_IPS="127.0.0.1,::1,172.16.0.0/12"
```

## Instalace

### 1. Naklonujte repozitář

```bash
git clone <repository-url>
cd crowdsec-php-ui

