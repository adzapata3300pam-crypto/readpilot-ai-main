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
 * Last-resort error handling.
 *
 * display_errors is Off in production, which is correct - but it means an
 * uncaught fatal produces an empty response body, and the browser reports
 * "Unexpected end of JSON input" with no clue why. These handlers turn a fatal
 * into a message that names the file, line and reason, so the cause can be
 * read off the response instead of guessed at.
 */
function readpilot_emit_failure(string $kind, string $message, string $file = '', int $line = 0): never
{
    $where = $file !== '' ? ' at ' . basename($file) . ':' . $line : '';
    $text = $kind . $where . ' - ' . $message;
    error_log('ReadPilot ' . $text);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $text . PHP_EOL);
        exit(1);
    }

    if (readpilot_wants_json()) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'ok' => false,
            'error' => $kind . $where,
            'detail' => $message,
            'hint' => 'Full detail is in the Render Logs tab. If this mentions a missing column, re-run the schema import.',
        ]);
        exit;
    }

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo $text . PHP_EOL;
    exit;
}

set_exception_handler(static function (Throwable $throwable): void {
    readpilot_emit_failure(
        'Unhandled ' . get_class($throwable),
        $throwable->getMessage(),
        $throwable->getFile(),
        $throwable->getLine()
    );
});

register_shutdown_function(static function (): void {
    $last = error_get_last();

    // Only fatal classes. Warnings and notices are logged and execution
    // continues, so they must not produce a response.
    if ($last === null || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    // If output already started, appending would corrupt the payload further;
    // log only.
    if (headers_sent()) {
        error_log('ReadPilot fatal after output started: ' . $last['message']);

        return;
    }

    readpilot_emit_failure('Fatal error', $last['message'], $last['file'], $last['line']);
});

/**
 * Renders a readable failure page when the database cannot be reached.
 *
 * Without this a misconfigured environment produces an uncaught PDOException
 * and a stack trace, which is both alarming and unhelpful. The full driver
 * message goes to the error log; the page itself only ever shows the SQLSTATE
 * and a short hint, so credentials cannot leak into a browser.
 */
/**
 * True when the caller is a JSON endpoint rather than a page.
 *
 * The API modules are fetched from JavaScript and always call response.json().
 * Handing one of them an HTML error page produces "Unexpected token '<'" in the
 * browser, which hides the real cause behind a parse error.
 */
function readpilot_wants_json(): bool
{
    $script = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (str_ends_with($script, '-api.php')) {
        return true;
    }

    $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));

    return str_contains($accept, 'application/json');
}

function readpilot_render_db_failure(PDOException $exception): never
{
    $state = (string) ($exception->errorInfo[0] ?? $exception->getCode());
    $driverCode = (int) ($exception->errorInfo[1] ?? 0);

    $hints = match (true) {
        $driverCode === 2002 =>
            'The host refused the connection. Check DB_HOST and DB_PORT, and confirm the database provider allows connections from Render\'s outbound IPs.',
        $driverCode === 1045 =>
            'The username or password was rejected. Check DB_USER and DB_PASS.',
        $driverCode === 1049 =>
            'The server accepted the credentials but has no database by that name. Import database.sql, or point DB_NAME at a database that already exists.',
        $driverCode === 2003 =>
            'The host could not be reached. Check DB_HOST and that the database provider allows public connections.',
        $driverCode === 2005 =>
            'The host was found but the connection was refused. Check DB_PORT.',
        $driverCode === 1153 =>
            'The connection was dropped while waiting. Usually a network or firewall rule between Render and the database.',
        default =>
            'Check the DB_* environment variables and confirm database.sql has been imported into the server.',
    };

    error_log('ReadPilot database connection failed: ' . $exception->getMessage());

    http_response_code(503);

    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'MySQL error ' . $driverCode . ' (' . $state . '): ' . $hints . PHP_EOL);
        exit(1);
    }

    // An API caller must receive JSON, otherwise the browser reports a parse
    // error instead of the actual problem.
    if (readpilot_wants_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'error' => 'The database is not reachable.',
            'mysqlError' => $driverCode ?: null,
            'sqlstate' => $state,
            'detail' => $hints,
        ]);
        exit;
    }

    $codeHtml = $driverCode > 0 ? htmlspecialchars((string) $driverCode, ENT_QUOTES, 'UTF-8') : '';
    $stateHtml = htmlspecialchars($state, ENT_QUOTES, 'UTF-8');
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
  <span class="state">MySQL error {$codeHtml} &middot; SQLSTATE {$stateHtml}</span>
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

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ];

    // ------------------------------------------------------------------
    // TLS
    //
    // PDO's MySQL DSN has no "sslmode" keyword - the documented list is
    // host, port, dbname, user, password and unix_socket only, so appending
    // one is silently discarded and the connection goes out in clear text.
    // TLS is enabled instead through the MYSQL_ATTR_SSL_* driver options,
    // which map onto mysql_ssl_set().
    // ------------------------------------------------------------------
    $sslMode = strtolower(readpilot_setting_any('DB_SSLMODE', 'DB_SSL'));
    $sslDisabled = in_array($sslMode, ['', 'disable', 'disabled', 'false', 'off', '0'], true);
    $caPath = readpilot_setting('DB_SSL_CA');

    if (!$sslDisabled) {
        if ($caPath !== '' && is_file($caPath)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $caPath;

            if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                // VERIFY_CA and above check the certificate against the CA.
                // Bare REQUIRED still encrypts, it just does not check it.
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] =
                    in_array($sslMode, ['verify_ca', 'verify_identity', 'verify-full'], true);
            }
        } else {
            // Never downgrade silently. An operator who asked for TLS and did
            // not get it has a false sense of security, which is worse than
            // an outright failure for credentials in transit.
            error_log(
                'SECURITY: DB_SSLMODE=' . ($sslMode === '' ? '(unset)' : $sslMode)
                . ' was requested but DB_SSL_CA does not point at a readable file. '
                . 'The database connection will be UNENCRYPTED. Download the CA '
                . 'certificate from the provider console and set DB_SSL_CA.'
            );
        }
    }

    try {
        $pdo = new PDO($dsn, $config['user'], $config['pass'], $options);
    } catch (PDOException $exception) {
        readpilot_render_db_failure($exception);
    }

    return $pdo;
}

/**
 * Reports whether the live connection is actually encrypted.
 * Used by /setup.php so TLS can be verified rather than assumed.
 */
function db_uses_tls(PDO $pdo): bool
{
    try {
        $cipher = $pdo->query("SHOW STATUS LIKE 'Ssl_cipher'")->fetch();
    } catch (Throwable $exception) {
        return false;
    }

    return isset($cipher['Value']) && $cipher['Value'] !== '';
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