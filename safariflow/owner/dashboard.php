<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = requireRole('platform_owner');

startSecureSession();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'create_sacco') {
            $saccoName = trim((string) ($_POST['sacco_name'] ?? ''));
            $adminName = trim((string) ($_POST['admin_name'] ?? ''));
            $adminEmail = filter_var(trim((string) ($_POST['admin_email'] ?? '')), FILTER_VALIDATE_EMAIL);
            $adminPassword = $_POST['admin_password'] ?? '';

            if ($saccoName === '' || strlen($saccoName) > 160) {
                $error = 'Enter a SACCO name of 1 to 160 characters.';
            } elseif ($adminName === '' || strlen($adminName) > 150 || $adminEmail === false) {
                $error = 'Enter the administrator’s name and a valid email address.';
            } elseif (!is_string($adminPassword) || strlen($adminPassword) < 12) {
                $error = 'The administrator password must be at least 12 characters.';
            } else {
                $pdo = database();
                try {
                    $pdo->beginTransaction();
                    $createSacco = $pdo->prepare('INSERT INTO saccos (name) VALUES (:name)');
                    $createSacco->execute(['name' => $saccoName]);
                    $saccoId = (int) $pdo->lastInsertId();

                    $createAdmin = $pdo->prepare(
                        'INSERT INTO users (sacco_id, full_name, email, password_hash, role)
                         VALUES (:sacco_id, :full_name, :email, :password_hash, :role)'
                    );
                    $createAdmin->execute([
                        'sacco_id' => $saccoId,
                        'full_name' => $adminName,
                        'email' => $adminEmail,
                        'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
                        'role' => 'sacco_admin',
                    ]);
                    $pdo->commit();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $success = 'SACCO and administrator account created.';
                } catch (PDOException $exception) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    if ($exception->getCode() === '23000') {
                        $error = 'That administrator email is already in use.';
                    } else {
                        throw $exception;
                    }
                }
            }
        } elseif ($action === 'update_status') {
            $saccoId = filter_var($_POST['sacco_id'] ?? null, FILTER_VALIDATE_INT);
            $status = $_POST['status'] ?? '';

            if ($saccoId === false || $saccoId === null || $saccoId < 1 || !in_array($status, ['active', 'suspended'], true)) {
                $error = 'The SACCO status request is invalid.';
            } else {
                $updateStatus = database()->prepare('UPDATE saccos SET status = :status WHERE id = :id');
                $updateStatus->execute(['status' => $status, 'id' => $saccoId]);
                if ($updateStatus->rowCount() === 0) {
                    $exists = database()->prepare('SELECT 1 FROM saccos WHERE id = :id');
                    $exists->execute(['id' => $saccoId]);
                    if ($exists->fetchColumn() === false) {
                        $error = 'That SACCO could not be found.';
                    }
                }
                if ($error === '') {
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $success = $status === 'active' ? 'SACCO activated.' : 'SACCO suspended.';
                }
            }
        } else {
            $error = 'The requested action is not available.';
        }
    }
}

$summary = database()->query(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'active'), 0) AS active,
            COALESCE(SUM(status = 'suspended'), 0) AS suspended
     FROM saccos"
)->fetch();
$staffCount = (int) database()->query(
    'SELECT COUNT(*) FROM users WHERE sacco_id IS NOT NULL AND is_active = 1'
)->fetchColumn();
$saccos = database()->query(
    'SELECT s.id, s.name, s.status, s.created_at,
            COUNT(DISTINCT u.id) AS user_count,
            COUNT(DISTINCT CASE WHEN u.role = \'sacco_admin\' AND u.is_active = 1 THEN u.id END) AS admin_count
     FROM saccos s
     LEFT JOIN users u ON u.sacco_id = s.id
     GROUP BY s.id, s.name, s.status, s.created_at
     ORDER BY s.created_at DESC, s.id DESC'
)->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Platform owner | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body>
<header class="topbar owner-topbar">
    <a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a>
    <div class="topbar-actions">
        <span class="owner-label">Platform owner</span>
        <a href="<?= escape(appUrl('logout.php')) ?>">Sign out</a>
    </div>
</header>
<main class="container owner-container">
    <section class="owner-heading">
        <div>
            <p class="eyebrow">Platform overview</p>
            <h1>Good to see you, <?= escape($user['full_name']) ?></h1>
            <p class="muted">Manage SACCOs and their administrator access from one place.</p>
        </div>
        <a class="button-link" href="#create-sacco">+ Add a SACCO</a>
    </section>

    <?php if ($success !== ''): ?>
        <p class="success" role="status"><?= escape($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="alert" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>

    <section class="stats-grid" aria-label="Platform statistics">
        <article class="stat-card">
            <span class="stat-label">Total SACCOs</span>
            <strong><?= (int) $summary['total'] ?></strong>
            <span class="stat-note">Organizations on SafariFlow</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Active</span>
            <strong><?= (int) $summary['active'] ?></strong>
            <span class="stat-note">Ready to use the platform</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Suspended</span>
            <strong><?= (int) $summary['suspended'] ?></strong>
            <span class="stat-note">Access currently paused</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">SACCO staff</span>
            <strong><?= $staffCount ?></strong>
            <span class="stat-note">Active accounts across SACCOs</span>
        </article>
    </section>

    <section class="owner-content-grid">
        <section class="panel owner-panel" id="create-sacco">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">New organization</p>
                    <h2>Add a SACCO</h2>
                </div>
            </div>
            <p class="muted">Create the SACCO and its first administrator together.</p>
            <form method="post" action="<?= escape(appUrl('owner/dashboard.php')) ?>" class="owner-form">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_sacco">
                <label for="sacco_name">SACCO name</label>
                <input id="sacco_name" name="sacco_name" maxlength="160" required placeholder="e.g. Umoja Matatu SACCO">
                <label for="admin_name">Administrator’s full name</label>
                <input id="admin_name" name="admin_name" maxlength="150" autocomplete="name" required>
                <label for="admin_email">Administrator’s email</label>
                <input id="admin_email" name="admin_email" type="email" maxlength="254" autocomplete="email" required>
                <label for="admin_password">Temporary password</label>
                <input id="admin_password" name="admin_password" type="password" minlength="12" autocomplete="new-password" required>
                <p class="field-hint">Use at least 12 characters. Share the initial password securely and ask the administrator to change it.</p>
                <button type="submit">Create SACCO account</button>
            </form>
        </section>

        <section class="panel owner-panel sacco-list-panel">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">Organizations</p>
                    <h2>Your SACCOs</h2>
                </div>
                <span class="count-badge"><?= count($saccos) ?> total</span>
            </div>
            <?php if ($saccos === []): ?>
                <div class="empty-state">
                    <span class="empty-icon" aria-hidden="true">＋</span>
                    <h3>No SACCOs yet</h3>
                    <p class="muted">Create your first SACCO to start setting up its team and operations.</p>
                </div>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="sacco-table">
                        <thead>
                            <tr>
                                <th scope="col">SACCO</th>
                                <th scope="col">Staff</th>
                                <th scope="col">Status</th>
                                <th scope="col"><span class="visually-hidden">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($saccos as $sacco): ?>
                                <tr>
                                    <td data-label="SACCO">
                                        <strong><?= escape($sacco['name']) ?></strong>
                                        <span class="table-subtext"><?= (int) $sacco['admin_count'] ?> administrator<?= (int) $sacco['admin_count'] === 1 ? '' : 's' ?></span>
                                    </td>
                                    <td data-label="Staff"><?= (int) $sacco['user_count'] ?></td>
                                    <td data-label="Status">
                                        <span class="status-badge status-<?= escape($sacco['status']) ?>"><?= escape(ucfirst($sacco['status'])) ?></span>
                                    </td>
                                    <td data-label="Actions">
                                        <form method="post" action="<?= escape(appUrl('owner/dashboard.php')) ?>" class="status-form">
                                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="sacco_id" value="<?= (int) $sacco['id'] ?>">
                                            <?php if ($sacco['status'] === 'active'): ?>
                                                <input type="hidden" name="status" value="suspended">
                                                <button type="submit" class="text-button suspend-button">Suspend</button>
                                            <?php else: ?>
                                                <input type="hidden" name="status" value="active">
                                                <button type="submit" class="text-button">Activate</button>
                                            <?php endif; ?>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
</body>
</html>
