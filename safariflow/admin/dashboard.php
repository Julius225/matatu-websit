<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = requireRole('sacco_admin');

startSecureSession();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$saccoId = (int) $user['sacco_id'];
$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';
        $pdo = database();

        try {
            if ($action === 'create_staff') {
                $fullName = trim((string) ($_POST['full_name'] ?? ''));
                $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
                $password = $_POST['password'] ?? '';
                $role = $_POST['role'] ?? '';

                if ($fullName === '' || strlen($fullName) > 150 || $email === false) {
                    $error = 'Enter a staff member’s name and a valid email address.';
                } elseif (!is_string($password) || strlen($password) < 12) {
                    $error = 'The temporary password must be at least 12 characters.';
                } elseif (!in_array($role, ['dispatcher', 'marshal'], true)) {
                    $error = 'Choose a valid staff role.';
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO users (sacco_id, full_name, email, password_hash, role)
                         VALUES (:sacco_id, :full_name, :email, :password_hash, :role)'
                    );
                    $statement->execute([
                        'sacco_id' => $saccoId,
                        'full_name' => $fullName,
                        'email' => $email,
                        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                        'role' => $role,
                    ]);
                    $success = 'Staff account created. Share the temporary password securely.';
                }
            } elseif ($action === 'update_staff_status') {
                $staffId = filter_var($_POST['staff_id'] ?? null, FILTER_VALIDATE_INT);
                $status = filter_var($_POST['is_active'] ?? null, FILTER_VALIDATE_INT);
                if ($staffId === false || $staffId === null || $staffId < 1 || !in_array($status, [0, 1], true)) {
                    $error = 'The staff account update is invalid.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE users SET is_active = :is_active
                         WHERE id = :id AND sacco_id = :sacco_id AND role IN (\'dispatcher\', \'marshal\')'
                    );
                    $statement->execute(['is_active' => $status, 'id' => $staffId, 'sacco_id' => $saccoId]);
                    if ($statement->rowCount() === 0) {
                        $check = $pdo->prepare(
                            'SELECT 1 FROM users
                             WHERE id = :id AND sacco_id = :sacco_id AND role IN (\'dispatcher\', \'marshal\')'
                        );
                        $check->execute(['id' => $staffId, 'sacco_id' => $saccoId]);
                        if ($check->fetchColumn() === false) {
                            $error = 'That staff account could not be found in your SACCO.';
                        }
                    }
                    if ($error === '') {
                        $success = $status === 1 ? 'Staff account activated.' : 'Staff account deactivated.';
                    }
                }
            } elseif ($action === 'create_vehicle') {
                $registration = strtoupper(trim((string) ($_POST['registration'] ?? '')));
                $makeModel = trim((string) ($_POST['make_model'] ?? ''));
                $capacity = filter_var($_POST['capacity'] ?? null, FILTER_VALIDATE_INT);

                if ($registration === '' || strlen($registration) > 20 || $makeModel === '' || strlen($makeModel) > 100) {
                    $error = 'Enter a registration number and vehicle make/model within the specified limits.';
                } elseif ($capacity === false || $capacity < 1 || $capacity > 100) {
                    $error = 'Vehicle capacity must be between 1 and 100 passengers.';
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO vehicles (sacco_id, registration, make_model, capacity)
                         VALUES (:sacco_id, :registration, :make_model, :capacity)'
                    );
                    $statement->execute([
                        'sacco_id' => $saccoId,
                        'registration' => $registration,
                        'make_model' => $makeModel,
                        'capacity' => $capacity,
                    ]);
                    $success = 'Vehicle added to your fleet.';
                }
            } elseif ($action === 'update_vehicle_status') {
                $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
                $status = $_POST['status'] ?? '';
                if ($vehicleId === false || $vehicleId === null || $vehicleId < 1 || !in_array($status, ['active', 'inactive'], true)) {
                    $error = 'The vehicle status request is invalid.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE vehicles SET status = :status WHERE id = :id AND sacco_id = :sacco_id'
                    );
                    $statement->execute(['status' => $status, 'id' => $vehicleId, 'sacco_id' => $saccoId]);
                    if ($statement->rowCount() === 0) {
                        $check = $pdo->prepare('SELECT 1 FROM vehicles WHERE id = :id AND sacco_id = :sacco_id');
                        $check->execute(['id' => $vehicleId, 'sacco_id' => $saccoId]);
                        if ($check->fetchColumn() === false) {
                            $error = 'That vehicle could not be found in your SACCO.';
                        }
                    }
                    if ($error === '') {
                        $success = $status === 'active' ? 'Vehicle activated.' : 'Vehicle marked inactive.';
                    }
                }
            } elseif ($action === 'create_route') {
                $name = trim((string) ($_POST['name'] ?? ''));
                $origin = trim((string) ($_POST['origin'] ?? ''));
                $destination = trim((string) ($_POST['destination'] ?? ''));
                $fare = filter_var($_POST['fare'] ?? null, FILTER_VALIDATE_FLOAT);

                if ($name === '' || strlen($name) > 120 || $origin === '' || strlen($origin) > 120 || $destination === '' || strlen($destination) > 120) {
                    $error = 'Enter a route name, starting point, and destination within the specified limits.';
                } elseif ($fare === false || $fare < 0 || $fare > 1000000) {
                    $error = 'Enter a fare between 0 and 1,000,000.';
                } else {
                    $statement = $pdo->prepare(
                        'INSERT INTO routes (sacco_id, name, origin, destination, fare)
                         VALUES (:sacco_id, :name, :origin, :destination, :fare)'
                    );
                    $statement->execute([
                        'sacco_id' => $saccoId,
                        'name' => $name,
                        'origin' => $origin,
                        'destination' => $destination,
                        'fare' => $fare,
                    ]);
                    $success = 'Route added to your SACCO.';
                }
            } elseif ($action === 'update_route_status') {
                $routeId = filter_var($_POST['route_id'] ?? null, FILTER_VALIDATE_INT);
                $status = $_POST['status'] ?? '';
                if ($routeId === false || $routeId === null || $routeId < 1 || !in_array($status, ['active', 'inactive'], true)) {
                    $error = 'The route status request is invalid.';
                } else {
                    $statement = $pdo->prepare(
                        'UPDATE routes SET status = :status WHERE id = :id AND sacco_id = :sacco_id'
                    );
                    $statement->execute(['status' => $status, 'id' => $routeId, 'sacco_id' => $saccoId]);
                    if ($statement->rowCount() === 0) {
                        $check = $pdo->prepare('SELECT 1 FROM routes WHERE id = :id AND sacco_id = :sacco_id');
                        $check->execute(['id' => $routeId, 'sacco_id' => $saccoId]);
                        if ($check->fetchColumn() === false) {
                            $error = 'That route could not be found in your SACCO.';
                        }
                    }
                    if ($error === '') {
                        $success = $status === 'active' ? 'Route activated.' : 'Route marked inactive.';
                    }
                }
            } else {
                $error = 'The requested action is not available.';
            }

            if ($success !== '') {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
        } catch (PDOException $exception) {
            if ($exception->getCode() === '23000') {
                $error = match ($action) {
                    'create_staff' => 'That email address is already in use.',
                    'create_vehicle' => 'That vehicle registration is already registered.',
                    'create_route' => 'A route with that name already exists in your SACCO.',
                    default => throw $exception,
                };
            } else {
                throw $exception;
            }
        }
    }
}

$pdo = database();
$saccoStatement = $pdo->prepare('SELECT name FROM saccos WHERE id = :id');
$saccoStatement->execute(['id' => $saccoId]);
$saccoName = $saccoStatement->fetchColumn();
if (!is_string($saccoName)) {
    http_response_code(403);
    exit('Your SACCO could not be found.');
}

$staffStatement = $pdo->prepare(
    'SELECT id, full_name, email, role, is_active
     FROM users
     WHERE sacco_id = :sacco_id AND role IN (\'dispatcher\', \'marshal\')
     ORDER BY role, full_name'
);
$staffStatement->execute(['sacco_id' => $saccoId]);
$staff = $staffStatement->fetchAll();

$vehicleStatement = $pdo->prepare(
    'SELECT id, registration, make_model, capacity, status
     FROM vehicles WHERE sacco_id = :sacco_id ORDER BY registration'
);
$vehicleStatement->execute(['sacco_id' => $saccoId]);
$vehicles = $vehicleStatement->fetchAll();

$routeStatement = $pdo->prepare(
    'SELECT id, name, origin, destination, fare, status
     FROM routes WHERE sacco_id = :sacco_id ORDER BY name'
);
$routeStatement->execute(['sacco_id' => $saccoId]);
$routes = $routeStatement->fetchAll();

$activeStaff = count(array_filter($staff, static fn (array $member): bool => (int) $member['is_active'] === 1));
$activeVehicles = count(array_filter($vehicles, static fn (array $vehicle): bool => $vehicle['status'] === 'active'));
$activeRoutes = count(array_filter($routes, static fn (array $route): bool => $route['status'] === 'active'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SACCO dashboard | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body>
<header class="topbar owner-topbar">
    <a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a>
    <div class="topbar-actions">
        <span class="owner-label"><?= escape($saccoName) ?></span>
        <a href="<?= escape(appUrl('reports.php')) ?>">Reports</a>
        <a href="<?= escape(appUrl('logout.php')) ?>">Sign out</a>
    </div>
</header>
<main class="container owner-container">
    <section class="owner-heading">
        <div>
            <p class="eyebrow">SACCO administrator</p>
            <h1>Hello, <?= escape($user['full_name']) ?></h1>
            <p class="muted">Manage your staff, vehicles, and routes for <?= escape($saccoName) ?>.</p>
        </div>
    </section>

    <?php if ($success !== ''): ?>
        <p class="success" role="status"><?= escape($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="alert" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>

    <section class="stats-grid" aria-label="SACCO statistics">
        <article class="stat-card">
            <span class="stat-label">Active staff</span>
            <strong><?= $activeStaff ?></strong>
            <span class="stat-note"><?= count($staff) ?> dispatcher and marshal accounts</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Active vehicles</span>
            <strong><?= $activeVehicles ?></strong>
            <span class="stat-note"><?= count($vehicles) ?> vehicles registered</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">Active routes</span>
            <strong><?= $activeRoutes ?></strong>
            <span class="stat-note"><?= count($routes) ?> routes configured</span>
        </article>
        <article class="stat-card">
            <span class="stat-label">SACCO</span>
            <strong class="stat-name"><?= escape($saccoName) ?></strong>
            <span class="stat-note">Your organization workspace</span>
        </article>
    </section>

    <section class="admin-section-grid">
        <section class="panel owner-panel">
            <div class="section-heading">
                <div><p class="eyebrow">Team</p><h2>Add staff</h2></div>
            </div>
            <p class="muted">Create dispatcher and marshal accounts for your SACCO.</p>
            <form method="post" action="<?= escape(appUrl('admin/dashboard.php')) ?>" class="owner-form">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_staff">
                <label for="full_name">Full name</label>
                <input id="full_name" name="full_name" maxlength="150" autocomplete="name" required>
                <label for="email">Email address</label>
                <input id="email" name="email" type="email" maxlength="254" autocomplete="email" required>
                <label for="role">Role</label>
                <select id="role" name="role" required>
                    <option value="dispatcher">Dispatcher</option>
                    <option value="marshal">Marshal</option>
                </select>
                <label for="password">Temporary password</label>
                <input id="password" name="password" type="password" minlength="12" autocomplete="new-password" required>
                <p class="field-hint">At least 12 characters. Share it securely and ask the staff member to change it.</p>
                <button type="submit">Create staff account</button>
            </form>
        </section>

        <section class="panel owner-panel">
            <div class="section-heading">
                <div><p class="eyebrow">Operations</p><h2>Add vehicle</h2></div>
            </div>
            <p class="muted">Register a vehicle in your SACCO fleet.</p>
            <form method="post" action="<?= escape(appUrl('admin/dashboard.php')) ?>" class="owner-form">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_vehicle">
                <label for="registration">Registration number</label>
                <input id="registration" name="registration" maxlength="20" required placeholder="e.g. KAA 123A">
                <label for="make_model">Make and model</label>
                <input id="make_model" name="make_model" maxlength="100" required placeholder="e.g. Toyota Hiace">
                <label for="capacity">Passenger capacity</label>
                <input id="capacity" name="capacity" type="number" min="1" max="100" required>
                <button type="submit">Add vehicle</button>
            </form>
        </section>

        <section class="panel owner-panel">
            <div class="section-heading">
                <div><p class="eyebrow">Network</p><h2>Add route</h2></div>
            </div>
            <p class="muted">Set up a route with its boarding point, destination, and fare in KES.</p>
            <form method="post" action="<?= escape(appUrl('admin/dashboard.php')) ?>" class="owner-form">
                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="create_route">
                <label for="route_name">Route name</label>
                <input id="route_name" name="name" maxlength="120" required placeholder="e.g. Town - Airport">
                <label for="origin">Starting point</label>
                <input id="origin" name="origin" maxlength="120" required>
                <label for="destination">Destination</label>
                <input id="destination" name="destination" maxlength="120" required>
                <label for="fare">One-way fare (KES)</label>
                <input id="fare" name="fare" type="number" min="0" max="1000000" step="0.01" required>
                <button type="submit">Add route</button>
            </form>
        </section>
    </section>

    <section class="panel owner-panel admin-list-panel">
        <div class="section-heading"><div><p class="eyebrow">Team</p><h2>Staff accounts</h2></div><span class="count-badge"><?= count($staff) ?> total</span></div>
        <?php if ($staff === []): ?>
            <div class="empty-state"><h3>No staff accounts yet</h3><p class="muted">Create your first dispatcher or marshal account above.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="sacco-table">
                <thead><tr><th scope="col">Name</th><th scope="col">Email</th><th scope="col">Role</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody><?php foreach ($staff as $member): ?>
                    <tr>
                        <td data-label="Name"><strong><?= escape($member['full_name']) ?></strong></td>
                        <td data-label="Email"><?= escape($member['email']) ?></td>
                        <td data-label="Role"><?= escape(ucfirst($member['role'])) ?></td>
                        <td data-label="Status"><span class="status-badge status-<?= (int) $member['is_active'] === 1 ? 'active' : 'suspended' ?>"><?= (int) $member['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                        <td data-label="Actions">
                            <form method="post" action="<?= escape(appUrl('admin/dashboard.php')) ?>" class="status-form">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update_staff_status">
                                <input type="hidden" name="staff_id" value="<?= (int) $member['id'] ?>">
                                <input type="hidden" name="is_active" value="<?= (int) $member['is_active'] === 1 ? 0 : 1 ?>">
                                <button type="submit" class="text-button"><?= (int) $member['is_active'] === 1 ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel owner-panel admin-list-panel">
        <div class="section-heading"><div><p class="eyebrow">Fleet</p><h2>Vehicles</h2></div><span class="count-badge"><?= count($vehicles) ?> total</span></div>
        <?php if ($vehicles === []): ?>
            <div class="empty-state"><h3>No vehicles registered</h3><p class="muted">Add the first vehicle to start setting up your fleet.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="sacco-table">
                <thead><tr><th scope="col">Registration</th><th scope="col">Vehicle</th><th scope="col">Capacity</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody><?php foreach ($vehicles as $vehicle): ?>
                    <tr>
                        <td data-label="Registration"><strong><?= escape($vehicle['registration']) ?></strong></td>
                        <td data-label="Vehicle"><?= escape($vehicle['make_model']) ?></td>
                        <td data-label="Capacity"><?= (int) $vehicle['capacity'] ?> passengers</td>
                        <td data-label="Status"><span class="status-badge status-<?= escape($vehicle['status'] === 'active' ? 'active' : 'suspended') ?>"><?= escape(ucfirst($vehicle['status'])) ?></span></td>
                        <td data-label="Actions">
                            <form method="post" action="<?= escape(appUrl('admin/dashboard.php')) ?>" class="status-form">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update_vehicle_status">
                                <input type="hidden" name="vehicle_id" value="<?= (int) $vehicle['id'] ?>">
                                <input type="hidden" name="status" value="<?= escape($vehicle['status'] === 'active' ? 'inactive' : 'active') ?>">
                                <button type="submit" class="text-button"><?= $vehicle['status'] === 'active' ? 'Mark inactive' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel owner-panel admin-list-panel">
        <div class="section-heading"><div><p class="eyebrow">Network</p><h2>Routes</h2></div><span class="count-badge"><?= count($routes) ?> total</span></div>
        <?php if ($routes === []): ?>
            <div class="empty-state"><h3>No routes configured</h3><p class="muted">Add a route to define a starting point, destination, and passenger fare.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="sacco-table">
                <thead><tr><th scope="col">Route</th><th scope="col">From / To</th><th scope="col">Fare</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
                <tbody><?php foreach ($routes as $route): ?>
                    <tr>
                        <td data-label="Route"><strong><?= escape($route['name']) ?></strong></td>
                        <td data-label="From / To"><?= escape($route['origin']) ?> → <?= escape($route['destination']) ?></td>
                        <td data-label="Fare">KES <?= number_format((float) $route['fare'], 2) ?></td>
                        <td data-label="Status"><span class="status-badge status-<?= escape($route['status'] === 'active' ? 'active' : 'suspended') ?>"><?= escape(ucfirst($route['status'])) ?></span></td>
                        <td data-label="Actions">
                            <form method="post" action="<?= escape(appUrl('admin/dashboard.php')) ?>" class="status-form">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="update_route_status">
                                <input type="hidden" name="route_id" value="<?= (int) $route['id'] ?>">
                                <input type="hidden" name="status" value="<?= escape($route['status'] === 'active' ? 'inactive' : 'active') ?>">
                                <button type="submit" class="text-button"><?= $route['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
