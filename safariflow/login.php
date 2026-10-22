<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';
startSecureSession();

$user = currentUser();
if ($user !== null) {
    header('Location: ' . dashboardForRole($user['role']));
    exit;
}

$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';

        if ($email === false || !is_string($password) || $password === '') {
            $error = 'Enter a valid email address and password.';
        } else {
            $statement = database()->prepare(
                'SELECT u.id, u.sacco_id, u.full_name, u.email, u.password_hash, u.role
                 FROM users u
                 LEFT JOIN saccos s ON s.id = u.sacco_id
                 WHERE u.email = :email AND u.is_active = 1
                   AND (u.role = \'platform_owner\' OR s.status = \'active\')
                 LIMIT 1'
            );
            $statement->execute(['email' => $email]);
            $account = $statement->fetch();

            if ($account === false || !password_verify($password, $account['password_hash'])) {
                $error = 'The email or password is incorrect.';
            } else {
                session_regenerate_id(true);
                unset($account['password_hash']);
                $_SESSION['user'] = $account;
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: ' . dashboardForRole($account['role']));
                exit;
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
    <title>Sign in | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body class="auth-page">
<main class="auth-card">
    <a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a>
    <p class="eyebrow">Matatu SACCO Queue &amp; Dispatch Management</p>
    <h1>Welcome back</h1>
    <p class="muted">Sign in to continue to your workspace.</p>
    <?php if ($error !== ''): ?>
        <p class="alert" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>
    <form method="post" action="<?= escape(appUrl('login.php')) ?>">
        <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
        <label for="email">Email address</label>
        <input id="email" name="email" type="email" autocomplete="username" required>
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
        <button type="submit">Sign in</button>
    </form>
    <p class="hint">New installation? <a href="<?= escape(appUrl('setup.php')) ?>">Create the platform owner account</a>.</p>
</main>
</body>
</html>
