<?php

declare(strict_types=1);

/**
 * ReadPilot runtime configuration.
 *
 * Every value is resolved in this order:
 *   1. Environment variable (how Render, Docker and CI inject settings)
 *   2. A secrets file OUTSIDE the web root (how XAMPP development is set up)
 *   3. A hard-coded development default
 *
 * Production therefore needs environment variables only; nothing has to be
 * edited in this file before deploying.
 */

/** Reads an environment variable, tolerating the various ini-getter quirks. */
function readpilot_env(string $name): ?string
{
    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if ($value === false || $value === null || $value === '') {
        return null;
    }

    $value = (string) $value;

    return trim($value) === '' ? null : $value;
}

/**
 * The secrets file is deliberately kept outside the document root.
 * Locates it via READPILOT_SECRETS_FILE, then the XAMPP layout, then a
 * sibling-of-webroot path.
 */
function readpilot_secrets_file(): ?string
{
    static $secrets = null;

    if ($secrets !== null) {
        return $secrets === false ? null : $secrets;
    }

    $candidates = [];

    $configured = readpilot_env('READPILOT_SECRETS_FILE');
    if ($configured !== null) {
        $candidates[] = $configured;
    }

    // XAMPP:  C:\xampp\htdocs\readpilot  ->  C:\xampp\readpilot-secrets.php
    $candidates[] = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'readpilot-secrets.php';
    $candidates[] = 'C:' . DIRECTORY_SEPARATOR . 'xampp' . DIRECTORY_SEPARATOR . 'readpilot-secrets.php';
    $candidates[] = __DIR__ . DIRECTORY_SEPARATOR . 'readpilot-secrets.php';

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            $secrets = $candidate;
            return $candidate;
        }
    }

    $secrets = false;

    return null;
}

/** Environment variables always win over the secrets file. */
function readpilot_setting(string $name): string
{
    $fromEnv = readpilot_env($name);
    if ($fromEnv !== null) {
        return $fromEnv;
    }

    $file = readpilot_secrets_file();
    if ($file === null) {
        return '';
    }

    $values = (array) (require $file);

    return isset($values[$name]) ? (string) $values[$name] : '';
}

/**
 * First non-empty value among $names. Managed MySQL providers disagree about
 * naming their variables, so both spellings are accepted.
 */
function readpilot_setting_any(string ...$names): string
{
    foreach ($names as $name) {
        $value = readpilot_setting($name);
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

/** Database credentials, environment first and XAMPP defaults last. */
function readpilot_db_config(): array
{
    return [
        'host' => readpilot_setting_any('DB_HOST', 'MYSQL_HOST') ?: '127.0.0.1',
        'port' => readpilot_setting_any('DB_PORT', 'MYSQL_PORT') ?: '3306',
        'name' => readpilot_setting_any('DB_NAME', 'MYSQL_DATABASE') ?: 'readpilot',
        'user' => readpilot_setting_any('DB_USER', 'MYSQL_USER') ?: 'root',
        'pass' => readpilot_setting_any('DB_PASS', 'DB_PASSWORD', 'MYSQL_PASSWORD'),
    ];
}

/**
 * Renders a readable failure page when the database cannot be reached.
 *
 * Without this a misconfigured environment produces an uncaught PDOException
 * and a stack trace, which is both alarming and unhelpful. The full driver
 * message goes to the error log; the page itself only ever shows the SQLSTATE
 * and a short hint, so credentials cannot leak into a browser.
 */
function readpilot_render_db_failure(PDOException $exception): never
{
    $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
    $detail = 'Cannot reach the ReadPilot database (SQLSTATE ' . htmlspecialchars($state, ENT_QUOTES, 'UTF-8') . ').';

    $hints = match (true) {
        $state === 'HY000' && (int) ($exception->errorInfo[1] ?? 0) === 2002 =>
            'The host refused the connection. Check DB_HOST and DB_PORT, and confirm the database provider allows connections from Render\'s outbound IPs.',
        $state === 'HY000' && (int) ($exception->errorInfo[1] ?? 0) === 1045 =>
            'The username or password was rejected. Check DB_USER and DB_PASS.',
        $state === 'HY000' && (int) ($exception->errorInfo[1] ?? 0) === 1049 =>
            'That database name does not exist. Check DB_NAME, and make sure database.sql has been imported.',
        default =>
            'Check the DB_* environment variables and confirm database.sql has been imported into the server.',
    };

    error_log('ReadPilot database connection failed: ' . $exception->getMessage());

    http_response_code(503);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $detail . ' ' . $hints . PHP_EOL);
        exit(1);
    }

    $detailHtml = htmlspecialchars($detail, ENT_QUOTES, 'UTF-8');
    $hintHtml = htmlspecialchars($hints, ENT_QUOTES, 'UTF-8');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ReadPilot — database unavailable</title>
<style>
  body{margin:0;font:16px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f7f9;color:#1f2430;display:grid;min-height:100vh;place-items:center;padding:24px}
  .card{max-width:620px;background:#fff;border:1px solid #e3e6ec;border-radius:14px;padding:32px;box-shadow:0 1px 3px rgba(16,24,40,.06)}
  h1{margin:0 0 4px;font-size:21px}
  p{margin:0 0 14px}
  .state{display:inline-block;background:#fdecec;color:#a4232b;border-radius:999px;padding:3px 11px;font-size:12px;font-weight:600;letter-spacing:.04em;margin-bottom:16px}
  .hint{background:#f4f6f9;border-left:3px solid #9aa4b5;padding:12px 14px;border-radius:0 8px 8px 0;color:#3d4759}
  code{background:#eef1f5;padding:1px 6px;border-radius:4px;font-size:14px}
  footer{margin-top:22px;font-size:13px;color:#6b7684}
</style></head>
<body><div class="card">
  <span class="state">SQLSTATE {$detailHtml}</span>
  <h1>The database is not reachable</h1>
  <p>ReadPilot cannot start a session without its MySQL server.</p>
  <div class="hint">{$hintHtml}</div>
  <footer>On Render, set these under <b>Environment</b>: <code>DB_HOST</code> <code>DB_PORT</code> <code>DB_NAME</code> <code>DB_USER</code> <code>DB_PASS</code>.</footer>
</div></body></html>
HTML;

    exit;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = readpilot_db_config();

    $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port']
        . ';dbname=' . $config['name'] . ';charset=utf8mb4';

    // Managed MySQL (Aiven, PlanetScale, TiDB, Render) exposes TLS and usually
    // expects certificate verification rather than a trusted local CA store.
    $sslMode = strtolower(readpilot_setting_any('DB_SSLMODE', 'DB_SSL'));
    if ($sslMode !== '' && $sslMode !== 'disable' && $sslMode !== 'false' && $sslMode !== '0') {
        $dsn .= ';sslmode=' . $sslMode;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    $caBundle = readpilot_setting('DB_SSL_CA');
    if ($caBundle !== '' && is_file($caBundle)) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $caBundle;
    }

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
    } catch (PDOException $exception) {
        readpilot_render_db_failure($exception);
    }

    return $pdo;
}

/**
 * Directory for private, non-public uploads (teacher ID documents).
 *
 * Must resolve somewhere outside the document root and writable by the web
 * server user:
 *   1. READPILOT_PRIVATE_DIR, when set (point this at a Render disk to keep
 *      submitted ID documents across redeploys)
 *   2. the XAMPP root, when the project really is served out of an htdocs
 *      folder, i.e. C:\xampp\htdocs\readpilot -> C:\xampp
 *   3. the system temp directory otherwise
 *
 * Rule 2 has to be conditional: blindly going two levels up from an unpacked
 * project directory lands in the user's home folder, which is neither tidy
 * nor meaningful.
 */
function readpilot_private_directory(): string
{
    static $directory = null;

    if ($directory !== null) {
        return $directory;
    }

    $configured = readpilot_env('READPILOT_PRIVATE_DIR');
    if ($configured !== null) {
        return $directory = rtrim($configured, '/\\');
    }

    // XAMPP: htdocs/<project> -> <xampp>, which is writable and not served.
    $parent = dirname(__DIR__);
    if (strtolower(basename($parent)) === 'htdocs' && is_writable(dirname($parent))) {
        return $directory = dirname($parent);
    }

    // Anything else: the temp directory is never web-served and is always
    // writable, including by the unprivileged user inside the container.
    return $directory = rtrim(sys_get_temp_dir(), '/\\')
        . DIRECTORY_SEPARATOR . 'readpilot-private';
}

function access_id_storage_directory(): string
{
    return readpilot_private_directory() . DIRECTORY_SEPARATOR . 'access-ids';
}