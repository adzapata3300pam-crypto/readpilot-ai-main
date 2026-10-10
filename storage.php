<?php
declare(strict_types=1);

// Supabase Storage S3 helper. S3 keys are server-only and must stay outside
// the web root; requests use AWS Signature Version 4.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/vendor/autoload.php';

use Aws\S3\S3Client;

function storage_setting(string $name): string
{
    return readpilot_setting($name);
}

/** True when every Supabase Storage credential is present. */
function storage_is_configured(): bool
{
    foreach (['SUPABASE_S3_ENDPOINT', 'SUPABASE_S3_REGION', 'SUPABASE_S3_ACCESS_KEY', 'SUPABASE_S3_SECRET_KEY', 'SUPABASE_BUCKET'] as $setting) {
        if (storage_setting($setting) === '') {
            return false;
        }
    }

    return true;
}

function supabase_s3_client(): S3Client
{
    static $client = null;
    if ($client instanceof S3Client) return $client;

    $endpoint = rtrim(storage_setting('SUPABASE_S3_ENDPOINT'), '/');
    $region = storage_setting('SUPABASE_S3_REGION');
    $key = storage_setting('SUPABASE_S3_ACCESS_KEY');
    $secret = storage_setting('SUPABASE_S3_SECRET_KEY');
    if ($endpoint === '' || $region === '' || $key === '' || $secret === '') {
        throw new RuntimeException('Supabase Storage S3 endpoint, region, access key, and secret are not configured.');
    }

    return $client = new S3Client([
        'version' => 'latest',
        'region' => $region,
        'endpoint' => $endpoint,
        'use_path_style_endpoint' => true,
        'signature_version' => 'v4',
        'credentials' => ['key' => $key, 'secret' => $secret],
        'retries' => ['mode' => 'standard', 'max_attempts' => 4],
        'http' => ['connect_timeout' => 10, 'timeout' => 60],
        'request_checksum_calculation' => 'when_required',
        'response_checksum_validation' => 'when_required',
    ]);
}

function recording_bucket(): string
{
    $bucket = storage_setting('SUPABASE_BUCKET');
    if ($bucket === '') throw new RuntimeException('Supabase Storage bucket is not configured.');
    return $bucket;
}

/** Asks the bucket itself (not our database) what it holds for $key. Throws if the object doesn't exist. */
function recording_object_info(string $key): array
{
    $info = supabase_s3_client()->headObject(['Bucket' => recording_bucket(), 'Key' => $key]);
    return [
        'size' => (int) ($info['ContentLength'] ?? 0),
        'etag' => trim((string) ($info['ETag'] ?? ''), '"'),
        'modified' => $info['LastModified'] instanceof DateTimeInterface ? $info['LastModified']->format(DATE_ATOM) : '',
    ];
}

function upload_recording_object(string $localPath, string $key, string $mime): void
{
    $stream = fopen($localPath, 'rb');
    if ($stream === false) throw new RuntimeException('Unable to read the uploaded recording.');
    try {
        supabase_s3_client()->putObject([
            'Bucket' => recording_bucket(),
            'Key' => $key,
            'Body' => $stream,
            'ContentType' => $mime,
        ]);
    } finally {
        fclose($stream);
    }
}

function recording_presigned_url(string $key, int $minutes = 10): string
{
    $command = supabase_s3_client()->getCommand('GetObject', ['Bucket' => recording_bucket(), 'Key' => $key]);
    return (string) supabase_s3_client()->createPresignedRequest($command, '+' . $minutes . ' minutes')->getUri();
}

function delete_recording_object(string $key): void
{
    supabase_s3_client()->deleteObject(['Bucket' => recording_bucket(), 'Key' => $key]);
}

/** Writes a tiny object, reads its metadata back from the bucket, deletes it. */
function storage_selftest(string $prefix): array
{
    $key = 'selftest/' . $prefix . '-' . time() . '.txt';
    $body = 'ReadPilot storage self-test ' . date('c');
    supabase_s3_client()->putObject(['Bucket' => recording_bucket(), 'Key' => $key, 'Body' => $body, 'ContentType' => 'text/plain']);
    try {
        $info = recording_object_info($key);
    } finally {
        delete_recording_object($key);
    }
    return ['bucket' => recording_bucket(), 'key' => $key] + $info;
}

// ---------------------------------------------------------------------------
// Profile photos
//
// Stored in the same private bucket under a "profiles/" prefix. The database
// only ever holds the object key (prefixed "s3:"); a signed URL is generated on
// demand so it never expires in a stored value. This keeps profile photos alive
// across redeploys, unlike the historical uploads/profile directory.
// ---------------------------------------------------------------------------

const PROFILE_IMAGE_PREFIX = 'profiles/';

/** Marks a users.profile_image value as a Supabase object key rather than a local path. */
const PROFILE_IMAGE_SCHEME = 's3:';

function profile_image_key(int $userId, string $extension): string
{
    return PROFILE_IMAGE_PREFIX . $userId . '-' . bin2hex(random_bytes(12)) . '.' . $extension;
}

function is_profile_image_key(string $stored): bool
{
    return str_starts_with($stored, PROFILE_IMAGE_SCHEME);
}

function upload_profile_image_object(string $localPath, string $key, string $mime): void
{
    $stream = fopen($localPath, 'rb');
    if ($stream === false) throw new RuntimeException('Unable to read the uploaded profile photo.');
    try {
        supabase_s3_client()->putObject([
            'Bucket' => recording_bucket(),
            'Key' => $key,
            'Body' => $stream,
            'ContentType' => $mime,
        ]);
    } finally {
        fclose($stream);
    }
}

function delete_profile_image_object(string $key): void
{
    supabase_s3_client()->deleteObject(['Bucket' => recording_bucket(), 'Key' => $key]);
}

/**
 * Signed read URL for an avatar. Valid for a week: long enough that avatars
 * render on cached pages, short enough to stay private if one ever leaks.
 */
function profile_image_public_url(string $key): string
{
    $client = supabase_s3_client();
    $command = $client->getCommand('GetObject', ['Bucket' => recording_bucket(), 'Key' => $key]);

    return (string) $client->createPresignedRequest($command, '+7 days')->getUri();
}

/**
 * Turns a stored users.profile_image value into something usable as an <img src>.
 * Local legacy paths are returned untouched; "s3:" keys become signed URLs.
 * Returns '' when Supabase is unavailable so callers fall back to the initials icon.
 */
function resolve_profile_image_url(string $stored): string
{
    $stored = trim($stored);

    if ($stored === '' || !is_profile_image_key($stored)) {
        return $stored;
    }

    if (!is_file(__DIR__ . '/vendor/autoload.php') || !storage_is_configured()) {
        return '';
    }

    try {
        return profile_image_public_url(substr($stored, strlen(PROFILE_IMAGE_SCHEME)));
    } catch (Throwable $exception) {
        error_log('Profile image URL could not be signed: ' . $exception->getMessage());
        return '';
    }
}