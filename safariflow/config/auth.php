<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

function appBasePath(): string
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    $documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
    $applicationRoot = realpath(dirname(__DIR__));
    if ($documentRoot === false || $applicationRoot === false) {
        throw new RuntimeException('SafariFlow must be served from a configured web document root.');
    }

    $documentRoot = rtrim($documentRoot, DIRECTORY_SEPARATOR);
    if (strncasecmp($applicationRoot, $documentRoot . DIRECTORY_SEPARATOR, strlen($documentRoot) + 1) !== 0) {
        throw new RuntimeException('SafariFlow must be located inside the web document root.');
    }

    $relativePath = substr($applicationRoot, strlen($documentRoot) + 1);
    $segments = explode(DIRECTORY_SEPARATOR, $relativePath);
    $basePath = '/' . implode('/', array_map('rawurlencode', $segments));

    return $basePath;
}

function appUrl(string $path = ''): string
{
    return appBasePath() . '/' . ltrim($path, '/');
}

function startSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
        'path' => '/',
    ]);
    session_start();
}

function currentUser(): ?array
{
    startSecureSession();
    return $_SESSION['user'] ?? null;
}

function requireRole(string $role): array
{
    $user = currentUser();
    if ($user === null) {
        header('Location: ' . appUrl('login.php'));
        exit;
    }

    $statement = database()->prepare(
        'SELECT role, sacco_id, is_active FROM users WHERE id = :id LIMIT 1'
    );
    $statement->execute(['id' => $user['id']]);
    $account = $statement->fetch();

    if ($account === false || (int) $account['is_active'] !== 1) {
        unset($_SESSION['user']);
        http_response_code(403);
        exit('Your account is inactive or no longer available.');
    }

    $user['role'] = $account['role'];
    $user['sacco_id'] = $account['sacco_id'];
    $_SESSION['user'] = $user;

    if ($user['role'] !== $role) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }

    if ($role !== 'platform_owner') {
        if (empty($user['sacco_id'])) {
            http_response_code(403);
            exit('Your account is not assigned to a SACCO.');
        }

        $statement = database()->prepare('SELECT status FROM saccos WHERE id = :id');
        $statement->execute(['id' => $user['sacco_id']]);
        if ($statement->fetchColumn() !== 'active') {
            unset($_SESSION['user']);
            http_response_code(403);
            exit('Your SACCO access is currently suspended.');
        }
    }

    return $user;
}

function dashboardForRole(string $role): string
{
    return match ($role) {
        'platform_owner' => appUrl('owner/dashboard.php'),
        'sacco_admin' => appUrl('admin/dashboard.php'),
        'dispatcher' => appUrl('dispatcher/dashboard.php'),
        'marshal' => appUrl('marshal/dashboard.php'),
        'passenger' => appUrl('passenger/index.php'),
        default => appUrl('login.php'),
    };
}

function escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
