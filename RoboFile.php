<?php

declare(strict_types=1);

use Robo\Tasks;

/**
 * Magento 2 Innovation Lab – Robo task runner
 *
 * Usage:  ./robo <command> [args]
 * List:   ./robo list
 */
class RoboFile extends Tasks
{
    // ─── Service names (used with docker compose exec/ps) ─────────────────────
    private const PHP_SERVICE         = 'php';
    private const MAINTENANCE_SERVICE = 'maintenance';
    private const DB_SERVICE          = 'db';
    private const SEARCH_SERVICE      = 'opensearch';
    private const CACHE_SERVICE       = 'valkey';
    private const MAIL_SERVICE        = 'mailpit';

    // ─── Container names (used with docker inspect / docker cp) ───────────────
    private const PHP_CONTAINER         = 'magento_phpfpm';
    private const MAINTENANCE_CONTAINER = 'magento_maintenance';
    private const DB_CONTAINER          = 'magento_db';

    // ─── Paths inside containers ──────────────────────────────────────────────
    private const WEB_ROOT = '/var/www/html';

    // ─────────────────────────────────────────────────────────────────────────
    // Stack management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start all containers and make them ready for development.
     *
     * On a fresh clone (no src/app/etc/env.php) this will automatically run
     * robo install if the Magento repo credentials are already set in .env.
     * Modules in modules/ are always symlinked before returning.
     *
     * @option bool $sample-data Install sample data if this run installs Magento
     */
    public function up(array $opts = ['sample-data' => false]): void
    {
        $this->_checkmade();

        if (!$this->_composeUpAndWait()) {
            $this->yell('docker compose up failed — fix the error above before continuing.');
            exit(1);
        }

        $installed = file_exists(__DIR__ . '/src/app/etc/env.php');

        if (!$installed) {
            $env    = $this->_readEnv();
            $pubKey = $env['MAGENTO_REPO_PUBLIC_KEY'] ?? '';

            if ($pubKey !== '') {
                $this->say('→ First-time setup: credentials found, installing Magento…');
                $this->_waitForContainer(self::MAINTENANCE_SERVICE);
                $this->install($opts);
                // Link modules after install so app/code/ exists and is writable.
                $this->moduleLink();
            } else {
                $this->say('');
                $this->say('  Containers are up. Magento is not installed yet.');
                $this->say('  Add your repo keys to .env, then run:');
                $this->say('    robo install');
            }

            return;
        }

        // Already installed — re-link modules and print where to go.
        $this->moduleLink();
        $this->_printUrls($this->_readEnv());
    }

    /**
     * Stop containers (keeps volumes and data)
     */
    public function down(): void
    {
        $this->_callDockerCompose(['down']);
    }

    /**
     * Show container status
     */
    public function ps(): void
    {
        $this->_callDockerCompose(['ps']);
    }

    /**
     * Rebuild Docker images
     *
     * @option bool $no-cache Bypass Docker layer cache
     */
    public function build(array $opts = ['no-cache' => false]): void
    {
        $args = ['build'];
        if ($opts['no-cache']) {
            $args[] = '--no-cache';
        }
        $this->_callDockerCompose($args);
    }

    /**
     * Tail container logs
     *
     * @param string $service Service name (php, nginx, db, varnish…). Empty = all.
     */
    public function logs(string $service = ''): void
    {
        $args = ['logs', '-f', '--tail=100'];
        if ($service !== '') {
            $args[] = $service;
        }
        $this->_callDockerCompose($args, true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Magento installation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Install Magento from scratch
     *
     * Credentials are read from MAGENTO_REPO_PUBLIC_KEY / MAGENTO_REPO_PRIVATE_KEY in .env.
     * Get free keys at: https://commercemarketplace.adobe.com/customer/accessKeys/
     *
     * @option bool $sample-data Also install Magento sample data
     */
    public function install(array $opts = ['sample-data' => false]): void
    {
        $this->_checkmade();

        $env = $this->_readEnv();
        $this->_assertRepoKeys($env);

        $this->say('→ Preparing web root for fresh install…');
        $this->_runInMaintenance('find /var/www/html -mindepth 1 -maxdepth 1 -exec rm -rf {} +');

        $this->say('→ Downloading Magento via Composer…');
        $this->_runInMaintenance(
            sprintf(
                'composer create-project'
                . ' --repository-url=https://repo.magento.com/'
                . ' magento/project-community-edition="%s"'
                . ' %s'
                . ' --no-interaction --prefer-dist',
                $env['MAGENTO_VERSION'] ?? '2.4.9',
                self::WEB_ROOT
            ),
            null,
            $this->_buildComposerAuth($env)
        );

        $this->say('→ Running setup:install…');
        $this->_runInMaintenance($this->_buildSetupInstallCommand($env));

        if ($opts['sample-data']) {
            $this->say('→ Installing sample data…');
            $this->_runInMaintenance(
                sprintf('php %s/bin/magento sampledata:deploy', self::WEB_ROOT),
                null,
                $this->_buildComposerAuth($env)
            );
            $this->_runInMaintenance(
                sprintf('php %s/bin/magento setup:upgrade --no-interaction', self::WEB_ROOT)
            );
        }

        $this->say('→ Enabling developer mode…');
        $this->_runInMaintenance(
            sprintf('php %s/bin/magento deploy:mode:set developer', self::WEB_ROOT)
        );

        $this->say('→ Disabling 2FA (local dev only)…');
        $this->_runInMaintenance(sprintf(
            'php %s/bin/magento module:disable Magento_AdminAdobeImsTwoFactorAuth Magento_TwoFactorAuth --clear-static-content',
            self::WEB_ROOT
        ));

        $this->say('→ Routing all email to Mailpit…');
        foreach ([
            'system/smtp/disable'   => '0',
            'system/smtp/transport' => 'smtp',
            'system/smtp/host'      => self::MAIL_SERVICE,
            'system/smtp/port'      => '1025',
        ] as $path => $value) {
            $this->_runInMaintenance(sprintf(
                'php %s/bin/magento config:set %s %s',
                self::WEB_ROOT,
                escapeshellarg($path),
                escapeshellarg($value)
            ));
        }

        $this->_runInMaintenance(sprintf('php %s/bin/magento cache:flush', self::WEB_ROOT));

        $this->_printUrls($env);
    }

    /**
     * Upgrade Magento to another version (default: MAGENTO_VERSION from .env)
     *
     * Takes a DB backup first, then runs the official composer root-update
     * flow followed by setup:upgrade, DI compile and a full reindex.
     * When the target release needs a newer stack, bump PHP_VERSION and the
     * service versions in .env and run `robo build` + `robo up` beforehand.
     *
     * Example: ./robo upgrade 2.4.9
     */
    public function upgrade(string $version = ''): void
    {
        $this->_checkmade();

        $env     = $this->_readEnv();
        $version = $version !== '' ? $version : ($env['MAGENTO_VERSION'] ?? '');

        if ($version === '') {
            $this->yell('No target version: pass one (robo upgrade 2.4.9) or set MAGENTO_VERSION in .env');
            exit(1);
        }

        if (!file_exists(__DIR__ . '/src/app/etc/env.php')) {
            $this->yell('Magento is not installed yet — run robo up instead.');
            exit(1);
        }

        $this->_assertRepoKeys($env);

        $backup = sprintf('backup-before-%s-%s.sql.gz', $version, date('Ymd-His'));
        $this->say("→ Backing up the database to {$backup}…");
        $this->dbDump($backup);

        $this->say('→ Enabling maintenance mode…');
        $this->_runInMaintenance(sprintf('php %s/bin/magento maintenance:enable', self::WEB_ROOT));

        $this->say("→ Updating composer requirements to {$version}…");
        $this->_runInMaintenance(
            sprintf(
                'composer require-commerce magento/product-community-edition %s'
                . ' --no-update'
                . ' && composer update --with-all-dependencies --no-interaction',
                escapeshellarg($version)
            ),
            self::WEB_ROOT,
            $this->_buildComposerAuth($env)
        );

        $this->say('→ Running setup:upgrade, di:compile and reindex…');
        foreach ([
            'setup:upgrade --no-interaction',
            'setup:di:compile',
            'indexer:reindex',
            'cache:flush',
            'maintenance:disable',
        ] as $cmd) {
            $this->_runInMaintenance(sprintf('php %s/bin/magento %s', self::WEB_ROOT, $cmd));
        }

        $this->say("Upgraded to Magento {$version}. Set MAGENTO_VERSION={$version} in .env to keep it in sync.");
        $this->say("Rollback: restore src/composer.json + composer.lock, then robo db:import {$backup}");
    }

    /**
     * Destroy all data and reinstall from scratch
     *
     * @option bool $sample-data Also install sample data after reinstall
     */
    public function reset(array $opts = ['sample-data' => false]): void
    {
        if (!$this->confirm('This will DESTROY the database and all generated files. Continue?')) {
            $this->say('Aborted.');
            return;
        }

        $this->say('→ Stopping containers and removing volumes…');
        $this->_callDockerCompose(['down', '-v', '--remove-orphans']);

        $this->say('→ Clearing generated files…');
        foreach ([
            'var/cache', 'var/page_cache', 'var/session', 'var/view_preprocessed',
            'pub/static/frontend', 'pub/static/adminhtml',
            'generated/code', 'generated/metadata',
        ] as $dir) {
            $path = __DIR__ . "/src/$dir";
            if (is_dir($path)) {
                $this->_deleteDir($path);
            }
        }

        $this->say('→ Starting fresh containers (waiting for health checks)…');
        $this->_callDockerCompose(['build']);
        if (!$this->_composeUpAndWait()) {
            $this->yell('Services did not become healthy — see robo ps / robo logs.');
            exit(1);
        }

        $this->install($opts);
        $this->moduleLink();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Magento CLI
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run a bin/magento command
     *
     * Example: ./robo magento cache:flush
     *          ./robo magento indexer:reindex
     */
    public function magento(array $args): void
    {
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $this->_runInMaintenance(
            sprintf('php %s/bin/magento %s', self::WEB_ROOT, $cmd),
            null,
            null,
            true
        );
    }

    /**
     * Run a Composer command inside the maintenance container
     *
     * Example: ./robo composer require vendor/package
     */
    public function composer(array $args): void
    {
        $env = $this->_readEnv();
        $cmd = implode(' ', array_map('escapeshellarg', $args));
        $this->_runInMaintenance(
            sprintf('composer %s', $cmd),
            self::WEB_ROOT,
            $this->_buildComposerAuth($env),
            true
        );
    }

    /**
     * Open an interactive shell in a container
     *
     * @param string $service Service name (default: maintenance)
     */
    public function shell(string $service = 'maintenance'): void
    {
        $this->_callDockerCompose(['exec', $service, 'bash'], true);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Cache
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Flush all Magento caches
     */
    public function cacheFlush(): void
    {
        $this->magento(['cache:flush']);
    }

    /**
     * Clean specific cache types
     *
     * Example: ./robo cache:clean full_page block_html
     */
    public function cacheClean(array $types = []): void
    {
        $this->magento(array_merge(['cache:clean'], $types));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Database
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Dump the database to a gzipped file
     *
     * Example: ./robo db:dump
     *          ./robo db:dump -- my-backup.sql.gz
     */
    public function dbDump(string $file = 'backup.sql.gz'): void
    {
        $env = $this->_readEnv();
        $dumpCmd = sprintf(
            // MariaDB 11+ images only ship the mariadb-* client names.
            'MYSQL_PWD=%s mariadb-dump --single-transaction --quick -u%s %s | gzip > /tmp/dump.sql.gz',
            escapeshellarg($env['MYSQL_PASSWORD'] ?? 'magento'),
            escapeshellarg($env['MYSQL_USER'] ?? 'magento'),
            escapeshellarg($env['MYSQL_DATABASE'] ?? 'magento')
        );

        if ($this->_callDockerCompose(['exec', '-T', self::DB_SERVICE, 'bash', '-c', $dumpCmd]) !== 0) {
            $this->yell('Database dump failed — see output above.');
            exit(1);
        }
        $this->_exec(sprintf('docker cp %s:/tmp/dump.sql.gz %s', self::DB_CONTAINER, escapeshellarg($file)));
        $this->say("Dump saved to: $file");
    }

    /**
     * Import a SQL dump (.sql or .sql.gz) into the dev database
     *
     * Example: ./robo db:import backup.sql.gz
     */
    public function dbImport(string $file): void
    {
        if (!file_exists($file)) {
            $this->yell("File not found: $file");
            return;
        }

        $env  = $this->_readEnv();
        $abs  = realpath($file);
        $dest = self::DB_CONTAINER . ':/tmp/import_file';

        $this->say('→ Copying dump into db container…');
        $this->_exec(sprintf('docker cp %s %s', escapeshellarg((string) $abs), $dest));

        $client = sprintf(
            'MYSQL_PWD=%s mariadb -u%s %s',
            escapeshellarg($env['MYSQL_PASSWORD'] ?? 'magento'),
            escapeshellarg($env['MYSQL_USER'] ?? 'magento'),
            escapeshellarg($env['MYSQL_DATABASE'] ?? 'magento')
        );
        $importCmd = str_ends_with($file, '.gz')
            ? "gunzip -c /tmp/import_file | {$client}"
            : "{$client} < /tmp/import_file";

        $this->say('→ Importing…');
        if ($this->_callDockerCompose(['exec', '-T', self::DB_SERVICE, 'bash', '-c', $importCmd]) !== 0) {
            $this->yell('Import failed — see output above.');
            exit(1);
        }

        $this->say('→ Flushing caches…');
        $this->cacheFlush();
        $this->say('Done.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Xdebug
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enable Xdebug at runtime (no rebuild needed)
     */
    public function xdebugOn(): void
    {
        $ini = '/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini';
        $this->_callDockerCompose([
            'exec', self::PHP_SERVICE, 'bash', '-c',
            "[ -f {$ini}.disabled ] && mv {$ini}.disabled {$ini}; kill -USR2 1 2>/dev/null || true",
        ]);
        $this->say('Xdebug enabled — listening on port 9003.');
    }

    /**
     * Disable Xdebug at runtime (no rebuild needed)
     */
    public function xdebugOff(): void
    {
        $ini = '/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini';
        $this->_callDockerCompose([
            'exec', self::PHP_SERVICE, 'bash', '-c',
            "[ -f {$ini} ] && mv {$ini} {$ini}.disabled; kill -USR2 1 2>/dev/null || true",
        ]);
        $this->say('Xdebug disabled.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Module development (optional, separate concern)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Symlink all modules/ into src/app/code/
     */
    public function moduleLink(): void
    {
        $modulesDir = __DIR__ . '/modules';
        $appCodeDir = __DIR__ . '/src/app/code';

        if (!is_dir($modulesDir)) {
            $this->say('No modules/ directory found.');
            return;
        }

        foreach (glob("$modulesDir/*/*", GLOB_ONLYDIR) as $modulePath) {
            $parts  = explode('/', $modulePath);
            $module = array_pop($parts);
            $vendor = array_pop($parts);
            $target = "$appCodeDir/$vendor/$module";

            if (!is_dir("$appCodeDir/$vendor")) {
                mkdir("$appCodeDir/$vendor", 0755, true);
            }

            if (is_link($target)) {
                unlink($target);
            }

            if (!is_dir($target)) {
                symlink("../../../../modules/$vendor/$module", $target);
                $this->say("  Linked: app/code/$vendor/$module");
            }
        }
    }

    /**
     * Enable a module + flush cache
     */
    public function moduleEnable(string $module): void
    {
        $this->magento(['module:enable', $module]);
        $this->magento(['cache:flush']);
    }

    /**
     * Disable a module + flush cache
     */
    public function moduleDisable(string $module): void
    {
        $this->magento(['module:disable', $module]);
        $this->magento(['cache:flush']);
    }

    /**
     * Re-compile DI and clean caches after module code changes
     *
     * Example: ./robo module:init Aichouchm_ProductRelations
     */
    public function moduleInit(string $module = ''): void
    {
        $this->magento(['setup:upgrade', '--no-interaction']);
        $this->magento(['setup:di:compile']);
        $this->magento(['cache:clean']);

        if ($module !== '') {
            $this->say("$module is ready.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Code quality
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run PHPCS (Magento2 standard) and PHPStan on modules/
     */
    public function lint(): void
    {
        if (!$this->_hasModules()) {
            $this->say('No modules in modules/ — nothing to lint.');
            return;
        }

        $phpcs   = $this->_exec(sprintf('php %s/bin/phpcs --standard=%s/phpcs.xml', __DIR__, __DIR__))->getExitCode();
        $phpstan = $this->_exec(sprintf(
            'php %s/bin/phpstan analyse --configuration=%s/phpstan.neon --memory-limit=1G',
            __DIR__,
            __DIR__
        ))->getExitCode();

        if ($phpcs !== 0 || $phpstan !== 0) {
            exit(1);
        }
    }

    /**
     * Auto-fix coding-standard violations in modules/ (PHPCBF)
     */
    public function lintFix(): void
    {
        if (!$this->_hasModules()) {
            $this->say('No modules in modules/ — nothing to fix.');
            return;
        }

        $this->_exec(sprintf('php %s/bin/phpcbf --standard=%s/phpcs.xml', __DIR__, __DIR__));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start every service and block until all health checks pass.
     */
    protected function _composeUpAndWait(): bool
    {
        return $this->_callDockerCompose(['up', '-d', '--wait', '--wait-timeout', '600']) === 0;
    }

    private function _hasModules(): bool
    {
        return (glob(__DIR__ . '/modules/*/*', GLOB_ONLYDIR) ?: []) !== [];
    }

    private function _printUrls(array $env): void
    {
        $baseUrl  = $env['MAGENTO_BASE_URL'] ?? 'http://magento.localhost/';
        $adminUri = $env['MAGENTO_ADMIN_URI'] ?? 'admin';
        $host     = parse_url($baseUrl, PHP_URL_HOST) ?: 'magento.localhost';
        $login    = ($env['MAGENTO_ADMIN_USER'] ?? 'admin') . ' / ' . ($env['MAGENTO_ADMIN_PASSWORD'] ?? 'Admin123!');

        $this->say('');
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->say("  Frontend : {$baseUrl}");
        $this->say("  Admin    : {$baseUrl}{$adminUri}  ({$login})");
        $this->say("  Bypass   : http://{$host}:" . ($env['NGINX_DIRECT_PORT'] ?? '8080') . '  (no Varnish)');
        $this->say('  Mailpit  : http://localhost:' . ($env['MAILPIT_HTTP_PORT'] ?? '8025'));
        $this->say('  RabbitMQ : http://localhost:' . ($env['RABBITMQ_MGMT_PORT'] ?? '15672'));
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
    }

    /**
     * Block until a service is exec-ready (max $maxSeconds).
     * Probes via `docker compose exec -T <service> true` — the same mechanism
     * that install() will use, so if this succeeds, exec is guaranteed to work.
     */
    protected function _waitForContainer(string $service, int $maxSeconds = 90): void
    {
        $bin   = $this->_getDockerComposeBin();
        $start = time();
        $this->say("  Waiting for {$service} to be exec-ready…");

        while (time() - $start < $maxSeconds) {
            exec(
                sprintf('%s exec -T %s true 2>/dev/null', $bin, escapeshellarg($service)),
                $out,
                $code
            );

            if ($code === 0) {
                return;
            }

            sleep(3);
            $out = [];
        }

        $this->yell("Timed out waiting for {$service}. Run 'robo install' manually once services are ready.");
        exit(1);
    }

    /**
     * Run a command in the maintenance container.
     * Returns the exit code; exits the process with a yell on non-zero by default.
     *
     * @param string|null $composerAuth  JSON string for COMPOSER_AUTH env var
     */
    protected function _runInMaintenance(
        string $command,
        ?string $workdir = null,
        ?string $composerAuth = null,
        bool $interactive = false,
        bool $failOnError = true
    ): int {
        $execArgs = ['exec'];
        $execArgs[] = $interactive ? '-it' : '-T';

        if ($workdir !== null) {
            $execArgs[] = '-w';
            $execArgs[] = $workdir;
        }

        if ($composerAuth !== null) {
            $execArgs[] = '-e';
            $execArgs[] = 'COMPOSER_AUTH=' . $composerAuth;
        }

        $execArgs[] = self::MAINTENANCE_SERVICE;
        $execArgs[] = 'bash';
        $execArgs[] = '-c';
        $execArgs[] = $command;

        $code = $this->_callDockerCompose($execArgs, $interactive);

        if ($code !== 0 && $failOnError) {
            $this->yell("Command failed (exit $code) — see output above.");
            exit($code);
        }

        return $code;
    }

    /**
     * Run a docker compose command. Returns the exit code.
     *
     * Non-flag arguments are shell-quoted so that values containing spaces
     * (bash scripts, file paths, env var assignments) are passed as single tokens.
     * Flag-style arguments (starting with -) are passed through unquoted.
     */
    protected function _callDockerCompose(array $args, bool $interactive = false): int
    {
        $parts = [$this->_getDockerComposeBin()];
        foreach ($args as $arg) {
            $parts[] = (strncmp((string) $arg, '-', 1) === 0) ? (string) $arg : escapeshellarg((string) $arg);
        }
        $command = implode(' ', $parts);

        if ($interactive) {
            $result = $this->taskExec($command)->run();
            return $result->getExitCode();
        }

        $result = $this->_exec($command);
        return $result->getExitCode();
    }

    /**
     * Returns the Docker Compose binary path.
     *
     * Prefers the compose plugin invoked directly (bypasses the docker CLI
     * plugin-discovery mechanism, which is unreliable in non-interactive
     * shell contexts). Falls back to `docker compose` if no plugin is found.
     */
    protected function _getDockerComposeBin(): string
    {
        static $bin = null;
        if ($bin !== null) {
            return $bin;
        }
        $pluginPaths = [
            '/usr/libexec/docker/cli-plugins/docker-compose',
            '/usr/local/lib/docker/cli-plugins/docker-compose',
            '/usr/lib/docker/cli-plugins/docker-compose',
            '/usr/local/libexec/docker/cli-plugins/docker-compose',
        ];
        foreach ($pluginPaths as $path) {
            if (is_executable($path)) {
                $bin = $path;
                return $bin;
            }
        }
        $bin = 'docker compose';
        return $bin;
    }

    /**
     * Build COMPOSER_AUTH JSON from env vars — no auth.json file needed.
     */
    private function _buildComposerAuth(array $env): ?string
    {
        $pubKey  = $env['MAGENTO_REPO_PUBLIC_KEY']  ?? '';
        $privKey = $env['MAGENTO_REPO_PRIVATE_KEY'] ?? '';

        if ($pubKey === '' || $privKey === '') {
            return null;
        }

        return json_encode([
            'http-basic' => [
                'repo.magento.com' => [
                    'username' => $pubKey,
                    'password' => $privKey,
                ],
            ],
        ]);
    }

    /**
     * Build the full setup:install command from .env values.
     */
    private function _buildSetupInstallCommand(array $env): string
    {
        // Values are shell-quoted: the command runs through `bash -c`.
        $e = static fn(string $key, string $default): string => escapeshellarg(
            ($env[$key] ?? '') !== '' ? $env[$key] : $default
        );

        return sprintf(
            'php %s/bin/magento setup:install'
            . ' --base-url=%s'
            . ' --db-host=db --db-name=%s --db-user=%s --db-password=%s'
            . ' --admin-firstname=%s --admin-lastname=%s'
            . ' --admin-email=%s --admin-user=%s --admin-password=%s'
            . ' --admin-use-security-key=0'
            . ' --backend-frontname=%s'
            . ' --language=en_US --currency=USD --timezone=UTC --use-rewrites=1'
            . ' --search-engine=opensearch'
            . ' --opensearch-host=' . self::SEARCH_SERVICE
            . ' --opensearch-port=9200 --opensearch-index-prefix=magento2'
            // Valkey speaks the Redis protocol, so Magento's redis backends work unchanged.
            . ' --cache-backend=redis --cache-backend-redis-server=' . self::CACHE_SERVICE
            . ' --cache-backend-redis-db=0'
            . ' --page-cache=redis --page-cache-redis-server=' . self::CACHE_SERVICE
            . ' --page-cache-redis-db=1'
            . ' --session-save=redis --session-save-redis-host=' . self::CACHE_SERVICE
            . ' --session-save-redis-db=2'
            . ' --amqp-host=rabbitmq --amqp-port=5672'
            . ' --amqp-user=%s --amqp-password=%s --amqp-virtualhost=%s'
            . ' --cleanup-database --no-interaction',
            self::WEB_ROOT,
            $e('MAGENTO_BASE_URL', 'http://magento.localhost/'),
            $e('MYSQL_DATABASE', 'magento'), $e('MYSQL_USER', 'magento'), $e('MYSQL_PASSWORD', 'magento'),
            $e('MAGENTO_ADMIN_FIRSTNAME', 'Admin'), $e('MAGENTO_ADMIN_LASTNAME', 'User'),
            $e('MAGENTO_ADMIN_EMAIL', 'admin@example.com'),
            $e('MAGENTO_ADMIN_USER', 'admin'), $e('MAGENTO_ADMIN_PASSWORD', 'Admin123!'),
            $e('MAGENTO_ADMIN_URI', 'admin'),
            $e('RABBITMQ_USER', 'magento'), $e('RABBITMQ_PASSWORD', 'magento'), $e('RABBITMQ_VHOST', 'magento')
        );
    }

    /**
     * Parse .env into an associative array.
     */
    private function _readEnv(): array
    {
        $file = __DIR__ . '/.env';
        if (!file_exists($file)) {
            $this->yell('.env not found. Run: ./setup');
            exit(1);
        }

        $vars = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (strncmp(trim($line), '#', 1) === 0 || strpos($line, '=') === false) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value);
            // Accept KEY="value" / KEY='value' as written by most editors and tools.
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $vars[trim($key)] = $value;
        }

        return $vars;
    }

    /**
     * Abort early with a helpful message if repo keys are missing.
     */
    private function _assertRepoKeys(array $env): void
    {
        $pub  = $env['MAGENTO_REPO_PUBLIC_KEY']  ?? '';
        $priv = $env['MAGENTO_REPO_PRIVATE_KEY'] ?? '';

        if ($pub === '' || $priv === '') {
            $this->yell(
                'Magento repo credentials missing in .env' . PHP_EOL .
                '  Get free keys (requires a free Adobe account):' . PHP_EOL .
                '  https://commercemarketplace.adobe.com/customer/accessKeys/' . PHP_EOL .
                PHP_EOL .
                '  Then add to .env:' . PHP_EOL .
                '  MAGENTO_REPO_PUBLIC_KEY=your-public-key' . PHP_EOL .
                '  MAGENTO_REPO_PRIVATE_KEY=your-private-key'
            );
            exit(1);
        }
    }

    /**
     * Abort if ./setup hasn't been run or is out of date.
     */
    private function _checkmade(): void
    {
        $versionFile = __DIR__ . '/.version';
        $madeFile    = __DIR__ . '/.made';

        if (!file_exists($madeFile)) {
            $this->yell('Project not initialised. Run: ./setup');
            exit(1);
        }

        $version = trim((string) file_get_contents($versionFile));
        $made    = trim((string) file_get_contents($madeFile));

        if ($version !== $made) {
            $this->yell("Version mismatch (expected $version, got $made). Run: ./setup --update");
            exit(1);
        }
    }
}
