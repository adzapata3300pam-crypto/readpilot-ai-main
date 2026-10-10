<?php

declare(strict_types=1);

/**
 * ReadPilot deployment diagnostics.
 *
 * Admin-only. Reports whether every moving part of the deployment is actually
 * working: PHP extensions, the database connection and its schema, the seed
 * data, Supabase Storage, the AI provider, and the writable directories.
 *
 * This replaces the previous version of this file, which was reachable without
 * authentication and printed database connectivity to anyone who requested it.
 */

require_once __DIR__ . '/auth-guard.php';
require_admin();

$user = current_user();
$checks = [];

/**
 * Which commit is actually running. Render injects these automatically, so the
 * page can prove what is deployed rather than leaving it to be inferred from
 * build-log layer digests, which identify image contents and not source.
 */
$deployedCommit = readpilot_env('RENDER_GIT_COMMIT') ?: '';
$deployedBranch = readpilot_env('RENDER_GIT_BRANCH') ?: '';
$serviceName = readpilot_env('RENDER_SERVICE') ?: '';

function check(string $label, bool $ok, string $detail = ''): array
{
    return ['label' => $label, 'ok' => $ok, 'detail' => $detail];
}

// ---------------------------------------------------------------------------
// Runtime
// ---------------------------------------------------------------------------
$checks[] = check('PHP 8.1 or newer', PHP_VERSION_ID >= 80100, 'running ' . PHP_VERSION);

foreach (['pdo_mysql' => 'MySQL database', 'curl' => 'AI provider', 'fileinfo' => 'upload MIME checks', 'mbstring' => 'UTF-8 handling', 'zip' => 'resource import', 'openssl' => 'TLS to MySQL'] as $extension => $purpose) {
    $checks[] = check('ext-' . $extension, extension_loaded($extension), $purpose);
}

$checks[] = check(
    'Composer dependencies',
    is_file(__DIR__ . '/vendor/autoload.php'),
    is_file(__DIR__ . '/vendor/autoload.php') ? 'vendor/autoload.php found' : 'Run composer install — Supabase features are unavailable without it'
);

// ---------------------------------------------------------------------------
// Configuration resolution
// ---------------------------------------------------------------------------
$db = readpilot_db_config();
$secretsFile = readpilot_secrets_file();

$checks[] = check(
    'Database host',
    $db['host'] !== '127.0.0.1' || readpilot_setting('DB_HOST') !== '',
    $db['host'] . ':' . $db['port'] . '/' . $db['name'] . ' as ' . ($db['user'] !== '' ? $db['user'] : '(no user)')
);

$checks[] = check(
    'Secrets source',
    true,
    $secretsFile === null
        ? 'environment variables only'
        : basename($secretsFile)
);

// ---------------------------------------------------------------------------
// Database
// ---------------------------------------------------------------------------
$schemaOk = false;
$dbNote = '';

try {
    $pdo = db();

    $serverVersion = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $checks[] = check('Database connection', true, 'MySQL ' . $serverVersion);

    // Verified against the live session rather than assumed from config.
    $tlsActive = db_uses_tls($pdo);
    $checks[] = check(
        'Database connection is encrypted',
        $tlsActive,
        $tlsActive
            ? 'TLS active'
            : 'UNENCRYPTED - credentials are crossing the network in plain text. See the note below.'
    );

    $requestedTls = strtolower(readpilot_setting_any('DB_SSLMODE', 'DB_SSL'));
    if (!$tlsActive && $requestedTls !== '' && !in_array($requestedTls, ['disable', 'disabled', 'false', 'off', '0'], true)) {
        $checks[] = check(
            'TLS requested but not applied',
            false,
            'DB_SSLMODE=' . $requestedTls . ' cannot be honoured without DB_SSL_CA'
        );
    }

    $expected = [
        'users', 'students', 'sections', 'reading_sessions', 'resources',
        'recommendations', 'access_requests', 'audit_logs', 'teacher_quizzes',
        'session_ai_evaluations',
    ];

    $present = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $missing = array_values(array_diff($expected, $present));
    $schemaOk = $missing === [];

    $checks[] = check(
        'Schema imported',
        $schemaOk,
        $schemaOk
            ? count($expected) . ' expected tables present'
            : 'missing: ' . implode(', ', $missing) . ' — import database.sql'
    );

    if ($schemaOk) {
        $userCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'")->fetchColumn();
        $studentCount = (int) $pdo->query('SELECT COUNT(*) FROM students')->fetchColumn();
        $resourceCount = (int) $pdo->query('SELECT COUNT(*) FROM resources')->fetchColumn();

        $checks[] = check('Accounts', $adminCount > 0, $userCount . ' users, ' . $adminCount . ' active admins');
        $checks[] = check('Demo roster', $studentCount > 0, $studentCount . ' students seeded');
        $checks[] = check('Resource library', $resourceCount > 0, $resourceCount . ' resources seeded');
    }
} catch (Throwable $exception) {
    $checks[] = check('Database connection', false, $exception->getMessage());
}

$failed = count(array_filter($checks, static fn(array $item): bool => !$item['ok']));

// Optional integrations. Reported separately because the app degrades
// gracefully without them rather than breaking.
$optional = [];

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/storage.php';
    $optional[] = check(
        'Supabase Storage',
        storage_is_configured(),
        storage_is_configured() ? 'bucket ' . storage_setting('SUPABASE_BUCKET') : 'not configured — recordings and avatars are disabled'
    );
}

$optional[] = check(
    'Google Gemini',
    readpilot_setting('GEMINI_API_KEY') !== '',
    readpilot_setting('GEMINI_API_KEY') !== '' ? 'API key present' : 'not configured — AI uses the built-in heuristic evaluator'
);

$privateDir = readpilot_private_directory();
$optional[] = check(
    'Private upload directory',
    is_dir($privateDir) ? is_writable($privateDir) : @mkdir($privateDir, 0700, true),
    $privateDir . (is_dir($privateDir) ? '' : ' (created)')
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>ReadPilot — deployment check</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font:15px/1.6 system-ui,-apple-system,"Segoe UI",sans-serif;background:#f5f6f8;color:#1f2430}
  .wrap{max-width:820px;margin:0 auto;padding:40px 24px 64px}
  h1{margin:0 0 6px;font-size:24px}
  .sub{margin:0 0 28px;color:#6b7684}
  .banner{padding:14px 18px;border-radius:12px;margin-bottom:26px;font-weight:600}
  .ok{background:#e8f6ec;color:#1c6b38;border:1px solid #b7e2c5}
  .bad{background:#fdecec;color:#a4232b;border:1px solid #f2c2c2}
  table{width:100%;border-collapse:collapse;background:#fff;border:1px solid #e3e6ec;border-radius:12px;overflow:hidden}
  th,td{text-align:left;padding:11px 16px;border-bottom:1px solid #eef0f4}
  tr:last-child td{border-bottom:0}
  .dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:9px;vertical-align:baseline}
  .pass{background:#2e9e5b}
  .fail{background:#d64545}
  td.detail{color:#6b7684;font-size:14px;width:52%}
  h2{font-size:15px;text-transform:uppercase;letter-spacing:.06em;color:#8a93a1;margin:32px 0 10px}
  .note{background:#fff;border:1px solid #e3e6ec;border-radius:12px;padding:16px 18px;font-size:14px;color:#3d4759}
  code{background:#eef1f5;padding:1px 6px;border-radius:4px;font-size:13px}
</style>
</head>
<body>
<div class="wrap">
  <h1>ReadPilot deployment check</h1>
  <p class="sub">Signed in as <?= htmlspecialchars((string) ($user['full_name'] ?? 'administrator'), ENT_QUOTES, 'UTF-8') ?>. Server time <?= htmlspecialchars(date('Y-m-d H:i:s T'), ENT_QUOTES, 'UTF-8') ?>.</p>

  <?php if ($failed === 0): ?>
    <div class="banner ok">All <?= count($checks) ?> checks passed. ReadPilot is ready to use.</div>
  <?php else: ?>
    <div class="banner bad"><?= $failed ?> of <?= count($checks) ?> checks failed. See the rows below.</div>
  <?php endif; ?>

  <table>
    <?php foreach ($checks as $item): ?>
      <tr>
        <td style="width:34%"><span class="dot <?= $item['ok'] ? 'pass' : 'fail' ?>"></span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="detail"><?= htmlspecialchars($item['detail'], ENT_QUOTES, 'UTF-8') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2>Deployment</h2>
  <table>
    <tr>
      <td style="width:34%"><span class="dot <?= $deployedCommit !== '' ? 'pass' : 'fail' ?>"></span>Commit deployed</td>
      <td class="detail"><code><?= htmlspecialchars($deployedCommit !== '' ? substr($deployedCommit, 0, 12) : 'RENDER_GIT_COMMIT is not set', ENT_QUOTES, 'UTF-8') ?></code></td>
    </tr>
    <tr>
      <td><span class="dot <?= $deployedBranch !== '' ? 'pass' : 'fail' ?>"></span>Branch</td>
      <td class="detail"><code><?= htmlspecialchars($deployedBranch !== '' ? $deployedBranch : 'unknown', ENT_QUOTES, 'UTF-8') ?></code></td>
    </tr>
    <tr>
      <td><span class="dot <?= $serviceName !== '' ? 'pass' : 'fail' ?>"></span>Service</td>
      <td class="detail"><?= htmlspecialchars($serviceName !== '' ? $serviceName : 'unknown', ENT_QUOTES, 'UTF-8') ?></td>
    </tr>
  </table>
  <div class="note" style="margin-top:14px">
    Compare the commit above with <code>git rev-parse --short HEAD</code> on your
    machine. If they differ, Render is running an older build - trigger
    <b>Manual Deploy</b>. A <code>sha256:</code> value in the build log is a
    Docker image layer, not a commit.
  </div>

  <h2>Optional integrations</h2>
  <div class="note" style="margin-bottom:14px">
    The app degrades gracefully without these, so they are reported separately.
    Reading recordings and profile photos need Supabase Storage. Without a
    <code>GEMINI_API_KEY</code> the AI diagnostics fall back to a deterministic
    heuristic engine, so every feature still works.
  </div>
  <table>
    <?php foreach ($optional as $item): ?>
      <tr>
        <td style="width:34%"><span class="dot <?= $item['ok'] ? 'pass' : 'fail' ?>"></span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="detail"><?= htmlspecialchars($item['detail'], ENT_QUOTES, 'UTF-8') ?></td>
      </tr>
    <?php endforeach; ?>
  </table>

  <h2>Hardening</h2>
  <div class="note">
    <p><b>Enabling TLS to the database.</b> The PDO MySQL DSN has no
    <code>sslmode</code> keyword, so setting <code>DB_SSLMODE</code> alone does
    nothing. Download the CA certificate from your provider's console, save it
    as <code>certs/ca.pem</code> in the project, and set
    <code>DB_SSL_CA=/var/www/html/certs/ca.pem</code>. On Aiven the certificate
    is under <b>Connection information &rarr; CA certificate &rarr; Download</b>.</p>
    <p>Demo accounts seeded by <code>database.sql</code> use weak, published
    passwords: <code>admin@readpilot.local</code> is <code>Admin123!</code> and
    <code>teacher@readpilot.local</code> is <code>Teacher123!</code>. Change the
    admin password from <b>Settings</b>, or delete these accounts from the admin
    panel, before the site is reachable by anyone other than you.</p>
  </div>
</div>
</body>
</html>