<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$user = currentUser();
if ($user !== null) {
    header('Location: ' . dashboardForRole($user['role']));
    exit;
}

header('Location: ' . appUrl('login.php'));
exit;
