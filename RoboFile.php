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
    // ─── Container names ──────────────────────────────────────────────────────
    private const PHP_CONTAINER         = 'magento_phpfpm';
    private const MAINTENANCE_CONTAINER = 'magento_maintenance';
    private const DB_CONTAINER          = 'magento_db';

    // ─── Paths inside containers ──────────────────────────────────────────────
    private const WEB_ROOT = '/var/www/html';

    // ─────────────────────────────────────────────────────────────────────────
    // Stack management
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Start all containers (builds images on first run)
     */
    public function up(): void
    {
        $this->_checkmade();
        $this->_callDockerCompose(['up', '-d', '--build']);
    }

    /**
     * Stop containers (keeps volumes and data)
     */
    public function down(): void
    {
        $this->_callDockerCompose(['down', '--remove-orphans']);
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
        $this->_callDockerCompose($args, interactive: true);
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

        $this->say('→ Downloading Magento via Composer…');
        $this->_runInMaintenance(
            sprintf(
                'composer create-project'
                . ' --repository-url=https://repo.magento.com/'
                . ' magento/project-community-edition="%s"'
                . ' %s'
                . ' --no-interaction --prefer-dist',
                $env['MAGENTO_VERSION'] ?? '2.4.7',
                self::WEB_ROOT
            ),
            composerAuth: $this->_buildComposerAuth($env)
        );

        $this->say('→ Running setup:install…');
        $this->_runInMaintenance($this->_buildSetupInstallCommand($env));

        if ($opts['sample-data']) {
            $this->say('→ Installing sample data…');
            $this->_runInMaintenance(
                sprintf('php %s/bin/magento sampledata:deploy', self::WEB_ROOT),
                composerAuth: $this->_buildComposerAuth($env)
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

        $this->_runInMaintenance(sprintf('php %s/bin/magento cache:flush', self::WEB_ROOT));

        $baseUrl  = $env['MAGENTO_BASE_URL'] ?? 'http://magento.local/';
        $adminUri = $env['MAGENTO_ADMIN_URI'] ?? 'admin';

        $this->say('');
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
        $this->say("  Frontend : {$baseUrl}");
        $this->say("  Admin    : {$baseUrl}{$adminUri}");
        $this->say("  MailHog  : http://localhost:" . ($env['MAILHOG_HTTP_PORT'] ?? '8025'));
        $this->say("  RabbitMQ : http://localhost:" . ($env['RABBITMQ_MGMT_PORT'] ?? '15672'));
        $this->say('━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━');
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

        $this->say('→ Starting fresh containers…');
        $this->_callDockerCompose(['up', '-d', '--build']);

        $this->say('→ Waiting for services to be healthy…');
        sleep(20);

        $this->install($opts);
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
            interactive: true
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
            workdir: self::WEB_ROOT,
            composerAuth: $this->_buildComposerAuth($env),
            interactive: true
        );
    }

    /**
     * Open an interactive shell in a container
     *
     * @param string $service Service name (default: maintenance)
     */
    public function shell(string $service = 'maintenance'): void
    {
        $this->_callDockerCompose(['exec', $service, 'bash'], interactive: true);
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
            'mysqldump -u%s -p%s %s | gzip > /tmp/dump.sql.gz',
            $env['MYSQL_USER'] ?? 'magento',
            $env['MYSQL_PASSWORD'] ?? 'magento',
            $env['MYSQL_DATABASE'] ?? 'magento'
        );

        $this->_callDockerCompose(['exec', self::DB_CONTAINER, 'bash', '-c', $dumpCmd]);
        $this->_exec(sprintf('docker cp %s:/tmp/dump.sql.gz %s', self::DB_CONTAINER, $file));
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
        $this->_exec(sprintf('docker cp %s %s', $abs, $dest));

        $importCmd = str_ends_with($file, '.gz')
            ? sprintf('gunzip -c /tmp/import_file | mysql -u%s -p%s %s',
                $env['MYSQL_USER'] ?? 'magento', $env['MYSQL_PASSWORD'] ?? 'magento', $env['MYSQL_DATABASE'] ?? 'magento')
            : sprintf('mysql -u%s -p%s %s < /tmp/import_file',
                $env['MYSQL_USER'] ?? 'magento', $env['MYSQL_PASSWORD'] ?? 'magento', $env['MYSQL_DATABASE'] ?? 'magento');

        $this->say('→ Importing…');
        $this->_callDockerCompose(['exec', self::DB_CONTAINER, 'bash', '-c', $importCmd]);

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
            'exec', self::PHP_CONTAINER, 'bash', '-c',
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
            'exec', self::PHP_CONTAINER, 'bash', '-c',
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
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Run a command in the maintenance container.
     *
     * @param string|null $composerAuth  JSON string for COMPOSER_AUTH env var
     */
    protected function _runInMaintenance(
        string $command,
        ?string $workdir = null,
        ?string $composerAuth = null,
        bool $interactive = false
    ): void {
        $envPrefix = $composerAuth
            ? sprintf("COMPOSER_AUTH='%s' ", addslashes($composerAuth))
            : '';

        $options = $interactive ? ['-it'] : ['-T'];
        if ($workdir !== null) {
            $options[] = "-w $workdir";
        }

        $this->_callDockerCompose(
            array_merge(
                ['exec'],
                $options,
                [self::MAINTENANCE_CONTAINER, 'bash', '-c', $envPrefix . $command]
            ),
            interactive: $interactive
        );
    }

    /**
     * Run a docker compose command.
     */
    protected function _callDockerCompose(array $args, bool $interactive = false): void
    {
        $bin     = $this->_getDockerComposeBin();
        $command = $bin . ' ' . implode(' ', $args);

        if ($interactive) {
            $this->taskExec($command)->run();
        } else {
            $this->_exec($command);
        }
    }

    /**
     * Auto-detect `docker compose` (plugin) vs legacy `docker-compose`.
     */
    protected function _getDockerComposeBin(): string
    {
        exec('docker compose version 2>/dev/null', $output, $returnCode);
        return $returnCode === 0 ? 'docker compose' : 'docker-compose';
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
        $e = static fn(string $key, string $default) use ($env): string => $env[$key] ?? $default;

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
            . ' --opensearch-host=elasticsearch --opensearch-port=9200 --opensearch-index-prefix=magento2'
            . ' --cache-backend=redis --cache-backend-redis-server=redis --cache-backend-redis-db=0'
            . ' --page-cache=redis --page-cache-redis-server=redis --page-cache-redis-db=1'
            . ' --session-save=redis --session-save-redis-host=redis --session-save-redis-db=2'
            . ' --amqp-host=rabbitmq --amqp-port=5672'
            . ' --amqp-user=%s --amqp-password=%s --amqp-virtualhost=%s'
            . ' --cleanup-database --no-interaction',
            self::WEB_ROOT,
            $e('MAGENTO_BASE_URL', 'http://magento.local/'),
            $e('MYSQL_DATABASE', 'magento'), $e('MYSQL_USER', 'magento'), $e('MYSQL_PASSWORD', 'magento'),
            $e('MAGENTO_ADMIN_FIRSTNAME', 'Admin'), $e('MAGENTO_ADMIN_LASTNAME', 'User'),
            $e('MAGENTO_ADMIN_EMAIL', 'admin@magento.local'),
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
            $this->yell('.env not found. Run: make');
            exit(1);
        }

        $vars = [];
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $vars[trim($key)] = trim($value);
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
     * Abort if `make` hasn't been run or is out of date.
     */
    private function _checkmade(): void
    {
        $versionFile = __DIR__ . '/.version';
        $madeFile    = __DIR__ . '/.made';

        if (!file_exists($madeFile)) {
            $this->yell('Project not initialised. Run: make');
            exit(1);
        }

        $version = trim((string) file_get_contents($versionFile));
        $made    = trim((string) file_get_contents($madeFile));

        if ($version !== $made) {
            $this->yell("Version mismatch (expected $version, got $made). Run: make update");
            exit(1);
        }
    }
}
