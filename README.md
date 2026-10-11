# Magento 2 Innovation Lab

A fully Dockerized **Magento Open Source 2.4.9** development environment. One command gets you a working store with Varnish, OpenSearch, Valkey, RabbitMQ and a mail catcher.

```bash
git clone https://github.com/mehdiaichouch2002/magento-features.git
cd magento-features
./setup
```

That's it. `./setup` checks Docker, creates `.env`, asks for your (free) Magento keys, starts the stack and installs Magento. When it finishes, open **http://magento.localhost/**.

---

## Requirements

| | |
|---|---|
| **Docker** | Docker Desktop or Docker Engine 24+ with the Compose v2 plugin. Nothing else is needed: no PHP, Composer or `make` on the host. |
| **Resources** | 4 CPUs, 8 GB RAM for Docker, about 10 GB of disk |
| **Magento keys** | Free: [commercemarketplace.adobe.com → Access Keys](https://commercemarketplace.adobe.com/customer/accessKeys/). Magento packages come from `repo.magento.com`, which needs authentication even for Open Source. |

**Windows:** use [WSL2](https://docs.docker.com/desktop/features/wsl/). Clone the repo **inside** the Linux filesystem (e.g. `~/projects`), not under `/mnt/c`. Magento on Windows-mounted folders is unusably slow.

**Linux:** OpenSearch wants `sudo sysctl -w vm.max_map_count=262144`. `./setup` warns you if it's too low.

---

## Stack

Versions follow the official [Magento 2.4.9 system requirements](https://experienceleague.adobe.com/en/docs/commerce-operations/installation-guide/system-requirements). All of them are set in `.env`.

| Service | Version | Purpose |
|---|---|---|
| `php` | PHP 8.5-FPM (custom image) | Serves HTTP requests only |
| `maintenance` | same image | CLI: `bin/magento`, Composer 2.10, Node 22 |
| `cron` | same image | Runs `bin/magento cron:run` every minute (indexers, emails, scheduled jobs) |
| `nginx` | 1.30 | PHP-FPM proxy |
| `varnish` | 8.0 | HTTP cache layer |
| `db` | MariaDB 12.3 | Database |
| `opensearch` | OpenSearch 3 | Catalog search |
| `valkey` | Valkey 9 | Cache, page cache, sessions (Redis-compatible) |
| `rabbitmq` | RabbitMQ 4.3 | Async message queue |
| `mailpit` | Mailpit | Catches every outbound email |

### URLs and ports

| URL | What |
|---|---|
| http://magento.localhost/ | Storefront through Varnish |
| http://magento.localhost/admin | Admin: `admin` / `Admin123!` (set in `.env`) |
| http://magento.localhost:8080/ | Direct to Nginx, bypassing Varnish |
| http://localhost:8025 | Mailpit inbox |
| http://localhost:15672 | RabbitMQ UI |
| `127.0.0.1:3306` / `:9200` / `:6379` | MariaDB / OpenSearch / Valkey (localhost only) |

`*.localhost` resolves to `127.0.0.1` automatically in browsers and curl, so you don't need to edit `/etc/hosts`. Any port can be changed in `.env` (e.g. `VARNISH_PORT=8000` if port 80 is taken).

> **Dev tip:** Use port `8080` while coding (no cache). Switch to port `80` to test full-page cache behaviour.

---

## Day-to-day: `./robo`

All commands go through `./robo` (`./robo list` shows them all). It uses your host PHP if you have 8.2+. Otherwise it runs inside a small container, so it works with Docker alone.

Tip: `alias robo=./robo`

### Stack

```bash
./robo up                  # Start (installs Magento automatically on first run)
./robo up --sample-data    # First run only: also install sample data
./robo sample:data         # Add sample data to an existing install
./robo down                # Stop containers, keep data
./robo ps                  # Container status
./robo logs [service]      # Tail logs (omit service = all)
./robo build [--no-cache]  # Rebuild the PHP image (after changing PHP_VERSION or the Dockerfile)
./robo shell [service]     # bash in maintenance (default) or any container
```

### Magento

```bash
./robo magento cache:flush
./robo magento indexer:reindex
./robo composer require vendor/package
./robo cache:clean full_page block_html
./robo warmup               # Pre-build CSS/JS (runs automatically after install/upgrade/module:init)
```

### Database

```bash
./robo db:dump                    # → backup.sql.gz
./robo db:dump -- my-backup.sql.gz
./robo db:import backup.sql.gz    # Import + cache:flush
```

### Upgrading Magento

```bash
./robo upgrade 2.4.9
```

This backs up the database, enables maintenance mode, runs the official `composer require-commerce` root update, then `setup:upgrade`, `setup:di:compile` and a full reindex.

**Upgrading an existing 2.4.7/2.4.8 install to 2.4.9:**

1. `git pull && ./setup --update`. Check your `.env` against `.env.example` and copy over the new `*_VERSION` lines and the renamed `MAILPIT_*` / `VALKEY_*` keys.
2. `./robo build && ./robo up`. This builds PHP 8.5 and starts the new services. MariaDB upgrades its data files on its own (`MARIADB_AUTO_UPGRADE`).
3. `./robo upgrade 2.4.9`. The reindex at the end rebuilds the OpenSearch 3 indexes, which now live in a Docker volume.

### Xdebug

```bash
# Once: set XDEBUG_ENABLED=true in .env, then
./robo build
./robo xdebug:on    # Listens on port 9003 (PhpStorm default)
./robo xdebug:off
```

PhpStorm: **Settings → PHP → Servers**. Add host `magento.localhost` and map the project's `src/` to `/var/www/html`.

### Full reset

```bash
./robo reset                 # Asks first, wipes DB + volumes, reinstalls
./robo reset --sample-data
```

---

## Module development

Put each module in `modules/Vendor/ModuleName/`. `./robo up` symlinks them into `src/app/code/` (or run `./robo module:link` yourself).

```bash
./robo module:enable Vendor_ModuleName
./robo module:init  Vendor_ModuleName   # setup:upgrade + di:compile + cache:clean
./robo module:disable Vendor_ModuleName
```

### Code quality

```bash
./robo lint       # PHPCS with the official Magento2 coding standard + PHPStan (level 8)
./robo lint:fix   # Auto-fix with PHPCBF
```

---

## CI

`.github/workflows/ci.yml` runs on every push:

- **lint**: shellcheck, hadolint, `docker compose config`, Robo loads, Varnish VCL compiles, `nginx -t`
- **build**: builds the PHP image for PHP 8.4 and 8.5 and checks every extension Magento requires
- **e2e** (optional): runs `./setup` for a full install and curls the storefront and admin. To turn it on, add `MAGENTO_REPO_PUBLIC_KEY` and `MAGENTO_REPO_PRIVATE_KEY` as repository secrets.

---

## Project structure

```
magento-features/
├── setup                     # One-command install (only needs Docker)
├── robo                      # Robo launcher (host PHP or container)
├── RoboFile.php              # All task automation
├── Makefile                  # Optional aliases: make / make install / make update
├── composer.json / .lock     # Robo + code-quality tools (host-side)
├── .env.example              # Environment template incl. service versions
├── docker-compose.yml
├── docker/
│   ├── php/                  # PHP-FPM image (php + maintenance), php.ini, pool
│   ├── robo/                 # Tiny PHP CLI + docker CLI toolbox for ./robo
│   ├── nginx/                # nginx.conf + Magento vhost (8080 = Varnish backend)
│   ├── mysql/my.cnf          # MariaDB tuning, slow-query log
│   └── varnish/default.vcl   # FPC rules, PURGE/BAN support
├── modules/                  # Your custom modules (Vendor/Module)
├── src/                      # Magento root (created on install, git-ignored)
└── volumes/                  # Composer cache, SSH files for the CLI container
```

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `port is already allocated` | Change the port in `.env` (`VARNISH_PORT`, `NGINX_DIRECT_PORT`, `MYSQL_PORT`…) and run `./robo up` again |
| OpenSearch keeps restarting | Linux: `sudo sysctl -w vm.max_map_count=262144`. Also give Docker at least 8 GB RAM |
| `Version mismatch … Run: ./setup --update` | You pulled changes to the tooling. Run `./setup --update` |
| Install stopped half-way | Fix the cause shown in the output, then `./robo install` (it starts from a clean web root) |
| Very slow on Windows | Move the project into the WSL2 filesystem (`~/…`), not `/mnt/c/…` |
| `magento.localhost` doesn't resolve (rare, some CLI tools) | `echo "127.0.0.1 magento.localhost" \| sudo tee -a /etc/hosts` |

---

## License

MIT
