<?php

declare(strict_types=1);

require_once __DIR__ . '/auth-guard.php';

$user = current_user();
if (!$user) {
    http_response_code(401);
    exit('Unauthorized');
}

header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

function account_response(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function fresh_account(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare('SELECT id, full_name, email, role, status, school, grade_level, section_name, bio, profile_image, created_at, last_login FROM users WHERE id = ?');
    $statement->execute([$userId]);
    $account = $statement->fetch() ?: [];

    if ($account) {
        $account['profile_image'] = profile_image_src((string) ($account['profile_image'] ?? ''));
    }

    return $account;
}

/** Removes whatever the previous avatar was, whether on disk or in Supabase. */
function discard_previous_profile_image(PDO $pdo, int $userId, string $stored): void
{
    $stored = trim($stored);
    if ($stored === '') {
        return;
    }

    if (str_starts_with($stored, 's3:')) {
        try {
            require_once __DIR__ . '/storage.php';
            delete_profile_image_object(substr($stored, strlen('s3:')));
        } catch (Throwable $exception) {
            error_log('Previous profile image could not be removed from storage: ' . $exception->getMessage());
        }
        return;
    }

    // Legacy local file from before the Supabase move.
    if (str_starts_with($stored, 'uploads/profile/')) {
        @unlink(__DIR__ . '/' . $stored);
    }
}

$pdo = db();
$action = $_POST['action'] ?? '';
$userId = (int) $user['id'];

try {
    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $school = trim((string) ($_POST['school'] ?? ''));
        $bio = trim((string) ($_POST['bio'] ?? ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $school === '') {
            account_response(['error' => 'Name, valid email, and school are required.'], 422);
        }
        $statement = $pdo->prepare('UPDATE users SET full_name = ?, email = ?, school = ?, bio = ? WHERE id = ?');
        $statement->execute([$name, $email, $school, $bio, $userId]);
        $_SESSION['readpilot_user'] = fresh_account($pdo, $userId);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([$userId, 'profile_updated', 'Profile details updated']);
        account_response(['ok' => true, 'user' => $_SESSION['readpilot_user']]);
    }

    if ($action === 'upload_profile_photo') {
        if (!isset($_FILES['profile_photo']) || $_FILES['profile_photo']['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($_FILES['profile_photo']['tmp_name'])) {
            account_response(['error' => 'Please choose a profile photo.'], 422);
        }
        $file = $_FILES['profile_photo'];
        if ($file['size'] > 5 * 1024 * 1024) account_response(['error' => 'Image is larger than 5MB.'], 422);
        $imageInfo = @getimagesize($file['tmp_name']);
        $mimeToExtension = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $mime = $imageInfo['mime'] ?? '';
        if (!$imageInfo || !isset($mimeToExtension[$mime])) account_response(['error' => 'Please choose a JPG, PNG, WEBP, or GIF image.'], 422);

        $oldPathStatement = $pdo->prepare('SELECT profile_image FROM users WHERE id = ?');
        $oldPathStatement->execute([$userId]);
        $oldPath = (string) $oldPathStatement->fetchColumn();

        // Preferred path: keep avatars in Supabase Storage so they survive a
        // redeploy, exactly like reading recordings already do.
        $useStorage = is_file(__DIR__ . '/vendor/autoload.php');
        if ($useStorage) {
            require_once __DIR__ . '/storage.php';
            $useStorage = storage_is_configured();
        }

        if ($useStorage) {
            $storageKey = profile_image_key($userId, $mimeToExtension[$mime]);
            upload_profile_image_object($file['tmp_name'], $storageKey, $mime);
            $storedValue = 's3:' . $storageKey;
            $displayValue = $storedValue;
        } else {
            // No Supabase configured (local XAMPP): keep the original
            // uploads/profile behaviour rather than failing the upload.
            $uploadDirectory = __DIR__ . '/uploads/profile';
            if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0755, true) && !is_dir($uploadDirectory)) {
                account_response(['error' => 'Unable to prepare profile photo storage.'], 500);
            }
            $relativePath = 'uploads/profile/' . $userId . '-' . bin2hex(random_bytes(12)) . '.' . $mimeToExtension[$mime];
            if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $relativePath)) {
                account_response(['error' => 'Unable to save profile photo.'], 500);
            }
            $storedValue = $relativePath;
            $displayValue = $relativePath;
        }

        $pdo->prepare('UPDATE users SET profile_image = ? WHERE id = ?')->execute([$storedValue, $userId]);
        discard_previous_profile_image($pdo, $userId, $oldPath);
        $_SESSION['readpilot_user'] = fresh_account($pdo, $userId);
        account_response(['ok' => true, 'profile_image' => $_SESSION['readpilot_user']['profile_image'] ?: $displayValue]);
    }

    if ($action === 'remove_profile_photo') {
        $oldPathStatement = $pdo->prepare('SELECT profile_image FROM users WHERE id = ?');
        $oldPathStatement->execute([$userId]);
        $oldPath = (string) $oldPathStatement->fetchColumn();
        $pdo->prepare("UPDATE users SET profile_image = '' WHERE id = ?")->execute([$userId]);
        discard_previous_profile_image($pdo, $userId, $oldPath);
        $_SESSION['readpilot_user'] = fresh_account($pdo, $userId);
        account_response(['ok' => true]);
    }

    if ($action === 'change_password') {
        $current = (string) ($_POST['current_password'] ?? '');
        $new = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['confirm_password'] ?? '');
        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $statement->execute([$userId]);
        $hash = (string) $statement->fetchColumn();
        if (!password_verify($current, $hash)) account_response(['error' => 'Current password is incorrect.'], 422);
        if (strlen($new) < 8 || $new !== $confirm) account_response(['error' => 'New passwords must match and contain at least 8 characters.'], 422);
        $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, details) VALUES (?, ?, ?)')->execute([$userId, 'password_changed', 'Password changed']);
        account_response(['ok' => true]);
    }

    account_response(['error' => 'Unknown account action'], 400);
} catch (PDOException $exception) {
    account_response(['error' => $exception->getCode() === '23000' ? 'That email address is already in use.' : 'Unable to update account.'], 409);
}
