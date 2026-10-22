<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
startSecureSession();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$error = '';
$setupComplete = false;
$setupToken = getenv('SAFARIFLOW_SETUP_TOKEN');
$setupEnabled = is_string($setupToken) && strlen($setupToken) >= 32;
$userCount = (int) database()->query('SELECT COUNT(*) FROM users')->fetchColumn();

if ($userCount > 0) {
    http_response_code(404);
    exit('Initial setup is already complete.');
}

if (!$setupEnabled) {
    http_response_code(503);
    $error = 'Initial setup is unavailable. Configure SAFARIFLOW_SETUP_TOKEN with at least 32 characters, then reload this page.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } elseif (!is_string($_POST['setup_token'] ?? null) || !hash_equals($setupToken, $_POST['setup_token'])) {
        http_response_code(403);
        $error = 'The setup authorization token is incorrect.';
    } else {
        $name = trim((string) ($_POST['full_name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';

        if ($name === '' || strlen($name) > 150 || $email === false) {
            $error = 'Enter your name and a valid email address.';
        } elseif (!is_string($password) || strlen($password) < 12) {
            $error = 'Choose a password with at least 12 characters.';
        } else {
            $insert = database()->prepare(
                'INSERT INTO users (sacco_id, full_name, email, password_hash, role)
                 VALUES (NULL, :full_name, :email, :password_hash, :role)'
            );
            try {
                $insert->execute([
                    'full_name' => $name,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'platform_owner',
                ]);
                $setupComplete = true;
            } catch (PDOException $exception) {
                if ($exception->getCode() === '23000') {
                    $error = 'An account with that email already exists.';
                } else {
                    throw $exception;
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Initial setup | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body class="auth-page">
<main class="auth-card">
    <a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a>
    <p class="eyebrow">One-time installation</p>
    <h1>Create platform owner</h1>
    <?php if ($setupComplete): ?>
        <p class="success" role="status">Owner account created. Sign in to open the platform dashboard.</p>
        <a class="button-link" href="<?= escape(appUrl('login.php')) ?>">Go to sign in</a>
    <?php else: ?>
        <p class="muted">This form is disabled automatically as soon as the first account is created.</p>
        <?php if ($error !== ''): ?>
            <p class="alert" role="alert"><?= escape($error) ?></p>
        <?php endif; ?>
        <?php if ($setupEnabled): ?>
            <form method="post" action="<?= escape(appUrl('setup.php')) ?>">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <label for="setup_token">Setup authorization token</label>
                <input id="setup_token" name="setup_token" type="password" autocomplete="off" required>
                <label for="full_name">Your name</label>
                <input id="full_name" name="full_name" autocomplete="name" maxlength="150" required>
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" autocomplete="email" required>
                <label for="password">Password (12 characters minimum)</label>
                <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                <button type="submit">Create owner account</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>
