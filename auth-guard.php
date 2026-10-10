<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    if (!headers_sent()) {
        // Secure only when the request actually arrived over TLS, so local
        // XAMPP development over http:// keeps working.
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => '/',
                'secure' => $https,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        } else {
            session_set_cookie_params(0, '/', '', $https, true);
        }
    }

    session_start();
}

/**
 * Resolves a stored profile image to something usable as an <img src>.
 *
 * Values look like "uploads/profile/12-ab.jpg" for rows created before the
 * Supabase move, or "s3:profiles/12-ab.jpg" for rows written afterwards. Local
 * paths are returned as-is; Supabase keys get signed on the fly, which is a
 * local HMAC and costs no network call.
 *
 * storage.php is loaded lazily and defensively: the AWS SDK is optional at
 * runtime, and a missing vendor/ or unconfigured bucket must degrade to the
 * initials fallback rather than fatal on every page.
 */
function profile_image_src(string $stored): string
{
    $stored = trim($stored);

    if ($stored === '' || !str_starts_with($stored, 's3:')) {
        return $stored;
    }

    if (!is_file(__DIR__ . '/vendor/autoload.php')) {
        return '';
    }

    try {
        require_once __DIR__ . '/storage.php';

        return resolve_profile_image_url($stored);
    } catch (Throwable $exception) {
        error_log('Profile image could not be resolved: ' . $exception->getMessage());

        return '';
    }
}

function current_user(): ?array
{
    static $loaded = false;
    static $user = null;

    if ($loaded) {
        return $user;
    }

    $loaded = true;
    $user = $_SESSION['readpilot_user'] ?? null;
    if (!$user || empty($user['id'])) {
        return $user;
    }

    $statement = db()->prepare('SELECT full_name, profile_image FROM users WHERE id = ? LIMIT 1');
    $statement->execute([(int) $user['id']]);
    $account = $statement->fetch();

    if ($account) {
        $user['full_name'] = (string) $account['full_name'];
        $user['profile_image'] = profile_image_src((string) ($account['profile_image'] ?? ''));
        $_SESSION['readpilot_user']['full_name'] = $user['full_name'];
        $_SESSION['readpilot_user']['profile_image'] = $user['profile_image'];
    }

    return $user;
}

function csrf_token(): string
{
    if (!isset($_SESSION['readpilot_csrf_token'])) {
        $_SESSION['readpilot_csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION['readpilot_csrf_token'];
}

function csrf_token_is_valid(mixed $token): bool
{
    $expected = (string) ($_SESSION['readpilot_csrf_token'] ?? '');
    return $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function require_role(string $role): void
{
    $user = current_user();
    if (!$user || $user['role'] !== $role) {
        header('Location: login.php');
        exit;
    }
}

function require_teacher(): void
{
    require_role('teacher');
}

function require_admin(): void
{
    require_role('admin');
}

function redirect_for_role(string $role): void
{
    header('Location: ' . ($role === 'admin' ? 'admin.php' : 'index.php'));
    exit;
}
