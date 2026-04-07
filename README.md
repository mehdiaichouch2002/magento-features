# Magento 2 Innovation Lab

A fully Dockerized Magento 2 development environment built as a personal sandbox for developing, testing, and refining production-quality custom modules.

The stack is inspired by real-world production setups: a dedicated maintenance container for all CLI work, Varnish for full-page cache testing, and [Robo](https://robo.li/) as the single task runner replacing all shell scripts.

---

## Stack

| Service | Image | Purpose |
|---|---|---|
| `php` | PHP 8.3-FPM (custom) | Serves HTTP requests only |
| `maintenance` | same image | CLI: `bin/magento`, `composer`, cron |
| `nginx` | nginx:1.25-alpine | PHP-FPM proxy, port 8080 |
| `varnish` | varnish:7.4-alpine | HTTP cache layer, port 80 |
| `db` | mariadb:10.6 | Database |
| `elasticsearch` | opensearch:2.12 | Search engine |
| `redis` | redis:7.2-alpine | Cache / FPC / Sessions |
| `rabbitmq` | rabbitmq:3.13-management | Async message queue |
| `mailhog` | mailhog | Catches all outbound email |

### Port map

| Port | Service | Notes |
|---|---|---|
| `80` | Varnish | Full-page cache enabled |
| `8080` | Nginx | Direct PHP access, bypass cache |
| `8025` | Mailhog UI | Catches all outbound email |
| `15672` | RabbitMQ UI | Management interface |
| `3306` | MySQL | `127.0.0.1` only |
| `9200` | OpenSearch | |
| `6379` | Redis | |

> **Dev tip:** Use port `8080` during active development (no cache friction). Switch to port `80` when testing full-page cache behaviour.

---

## Requirements

- Docker Engine 24+ with the Compose plugin (`docker compose`)
- PHP 8.2+ on the host (for Robo — downloaded automatically if Composer is missing)
- A free [Adobe Commerce Marketplace account](https://commercemarketplace.adobe.com/customer/accessKeys/) for the repo credentials

> **Why credentials?** Magento packages are served from `repo.magento.com`, which requires authentication even for the free Community Edition. The account is free and takes 2 minutes to create.

---

## Quick start

### 1. Clone and initialise

```bash
git clone https://github.com/your-username/magento-features.git
cd magento-features
make
```

`make` does everything once: installs Robo (downloads Composer locally if needed), creates `volumes/`, copies `.env.example → .env`, and writes the `.made` version file.

Then add the `robo` shell alias so you can type `robo` instead of `./robo`:

```bash
# zsh
echo "alias robo='./robo'" >> ~/.zshrc && source ~/.zshrc

# bash
echo "alias robo='./robo'" >> ~/.bashrc && source ~/.bashrc
```

### 2. Add your credentials to `.env`

Open `.env` and fill in the two lines at the top:

```dotenv
MAGENTO_REPO_PUBLIC_KEY=your-public-key
MAGENTO_REPO_PRIVATE_KEY=your-private-key
```

Get keys here (free): https://commercemarketplace.adobe.com/customer/accessKeys/

Everything else in `.env` works out of the box for local development.

### 3. Start

```bash
robo up
```

That's it. `robo up` detects that Magento isn't installed yet and runs the full installer automatically — pulls Magento via Composer, runs `setup:install` wired to all services, sets developer mode, disables 2FA, and symlinks all modules. URLs are printed when done.

To include sample data (products, categories, customers) on a fresh install, run the installer manually instead:

```bash
robo install --sample-data
```

---

## Robo — task runner

All commands go through `robo`. Run `robo list` for the full list.

### Stack

```bash
robo up                  # Start (auto-installs + auto-links modules on first run)
robo down                # Stop containers, keep volumes
robo ps                  # Container status
robo build               # Rebuild images (run this after changing the Dockerfile)
robo build --no-cache    # Rebuild without layer cache
robo logs [service]      # Tail logs (omit service = all)
```

### Magento

```bash
robo magento cache:flush
robo magento setup:upgrade
robo magento indexer:reindex
robo composer require vendor/package
robo shell               # bash in maintenance container
robo shell nginx         # bash in any container
```

### Module workflow

```bash
# Link all modules/ into src/app/code/
# (called automatically by robo up — only needed manually after adding a module
#  without restarting the stack)
robo module:link

# Enable a module
robo module:enable Aichouchm_ProductRelations

# After editing code — re-compile DI and clean caches
robo module:init Aichouchm_ProductRelations

# Disable
robo module:disable Aichouchm_ProductRelations
```

### Cache

```bash
robo cache:flush
robo cache:clean full_page block_html
```

### Database

```bash
robo db:dump                    # → backup.sql.gz
robo db:import backup.sql.gz    # Import a dump + cache:flush
```

### Xdebug

Xdebug is baked into the image when built with `XDEBUG_ENABLED=true`, but can be toggled at runtime without rebuilding:

```bash
robo xdebug:on    # Enables, listens on port 9003 (PhpStorm default)
robo xdebug:off   # Disables
```

### Code quality

```bash
robo lint          # PHPCS (PSR-12) + PHPStan (level 8) on modules/
robo lint:fix      # PHPCBF auto-fix
```

### Full reset

```bash
robo reset                   # Asks for confirmation, wipes DB + volumes, reinstalls
robo reset --sample-data     # Same, with sample data
```

---

## Project structure

```
magento-features/
├── RoboFile.php              # All task automation
├── Makefile                  # First-time init only
├── composer.json             # Robo + code-quality tools (host-side)
├── .version                  # Project version (committed)
├── .env.example              # Environment template
│
├── docker/
│   ├── php/
│   │   ├── Dockerfile        # PHP 8.3-FPM (shared by php + maintenance)
│   │   └── php.ini           # OPcache, memory, error reporting
│   ├── nginx/
│   │   ├── nginx.conf
│   │   └── default.conf      # Listens on 8080 (Varnish backend) + 80
│   ├── mysql/
│   │   └── my.cnf            # InnoDB tuning, slow-query log
│   └── varnish/
│       └── default.vcl       # Magento FPC BAN support, cookie logic
│
├── modules/                  # Custom modules (one dir per module)
│   └── Aichouchm/
│       └── ProductRelations/ # Example module (see below)
│
├── src/                      # Magento root (populated by robo install)
│   └── app/code/             # Module symlinks created by robo module:link
│
├── volumes/
│   ├── .composer/            # Composer cache (mounted into maintenance)
│   └── .ssh/                 # Symlinks to host SSH keys (read-only)
│
├── elasticsearch/
│   └── data/                 # OpenSearch data (survives docker down -v)
│
├── phpcs.xml                 # PHPCS ruleset
└── phpstan.neon              # PHPStan config (level 8)
```

---

## Adding a new module

1. Create it in `modules/Vendor/ModuleName/`
2. Run `robo module:enable Vendor_ModuleName` (symlinks are created automatically by `robo up`; if the stack is already running, run `robo module:link` first)
3. Run `robo module:init Vendor_ModuleName`

Each module is an independent, self-contained Git project. The `modules/` directory is the portfolio.

---

## Modules

### `Aichouchm_ProductRelations`

Manages directional relationships between products (color variants, size siblings, accessories).

**Architecture highlights:**

- Full service-contract stack: `ProductRelationInterface`, `ProductRelationRepositoryInterface`, `SearchResultsInterface`
- Repository pattern with in-memory identity map (no duplicate DB reads per request)
- Declarative schema (`db_schema.xml`) with composite unique constraint, FK cascades, and a covering index
- Single optimised `fetchCol` query for related ID lookups — no N+1 loads
- REST API via `webapi.xml` with ACL-protected routes
- Frontend block with per-product block cache key and configurable lifetime
- Admin configuration via `system.xml` + `config.xml`
- PHPUnit tests covering cache invalidation and `NoSuchEntityException`

**REST API:**

```
GET    /V1/products/:productId/relations    # Get related product IDs
POST   /V1/product-relations               # Create a relation
DELETE /V1/product-relations/:entityId     # Delete a relation
```

---

## Xdebug with PhpStorm

1. In PhpStorm: **Preferences → PHP → Servers** — add a server with:
   - Host: `magento.local` (or `localhost`)
   - Path mappings: project `/src` → `/var/www/html`
2. Run `robo xdebug:on`
3. Start listening in PhpStorm (phone icon)
4. Set a breakpoint and reload the page

---

## Version integrity

The project uses a `.version` / `.made` system (inspired by production workflow):

- `.version` is committed and incremented when the setup changes
- `.made` is written by `make` and must match `.version`
- `robo up` and `robo install` will abort with a clear error if they don't match

After a `git pull`:
```bash
make update   # Re-installs deps and bumps .made
```

---

## License

MIT
