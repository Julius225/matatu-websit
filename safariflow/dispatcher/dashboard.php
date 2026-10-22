<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = requireRole('dispatcher');

startSecureSession();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$saccoId = (int) $user['sacco_id'];
$success = '';
$error = '';
$pdo = database();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['csrf_token'], $submittedToken)) {
        http_response_code(400);
        $error = 'Your session expired. Refresh the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create_departure') {
            $routeId = filter_var($_POST['route_id'] ?? null, FILTER_VALIDATE_INT);
            $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
            $departureInput = $_POST['departure_at'] ?? '';
            $departureAt = is_string($departureInput)
                ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $departureInput)
                : false;
            $dateErrors = DateTimeImmutable::getLastErrors();

            if ($routeId === false || $routeId === null || $routeId < 1 || $vehicleId === false || $vehicleId === null || $vehicleId < 1) {
                $error = 'Choose an active route and vehicle.';
            } elseif (
                $departureAt === false
                || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $departureAt->format('Y-m-d\TH:i') !== $departureInput
            ) {
                $error = 'Enter a valid departure date and time.';
            } elseif ($departureAt <= new DateTimeImmutable()) {
                $error = 'The departure time must be in the future.';
            } else {
                $routeCheck = $pdo->prepare(
                    'SELECT 1 FROM routes WHERE id = :id AND sacco_id = :sacco_id AND status = \'active\''
                );
                $routeCheck->execute(['id' => $routeId, 'sacco_id' => $saccoId]);
                $vehicleCheck = $pdo->prepare(
                    'SELECT 1 FROM vehicles WHERE id = :id AND sacco_id = :sacco_id AND status = \'active\''
                );
                $vehicleCheck->execute(['id' => $vehicleId, 'sacco_id' => $saccoId]);

                if ($routeCheck->fetchColumn() === false || $vehicleCheck->fetchColumn() === false) {
                    $error = 'The selected route or vehicle is no longer active in your SACCO.';
                } else {
                    try {
                        $statement = $pdo->prepare(
                            'INSERT INTO departures (sacco_id, route_id, vehicle_id, departure_at)
                             VALUES (:sacco_id, :route_id, :vehicle_id, :departure_at)'
                        );
                        $statement->execute([
                            'sacco_id' => $saccoId,
                            'route_id' => $routeId,
                            'vehicle_id' => $vehicleId,
                            'departure_at' => $departureAt->format('Y-m-d H:i:s'),
                        ]);
                        $success = 'Departure scheduled.';
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            $error = 'That vehicle already has a departure scheduled for this time.';
                        } else {
                            throw $exception;
                        }
                    }
                }
            }
        } elseif ($action === 'update_departure_status') {
            $departureId = filter_var($_POST['departure_id'] ?? null, FILTER_VALIDATE_INT);
            $newStatus = $_POST['status'] ?? '';
            if ($departureId === false || $departureId === null || $departureId < 1 || !in_array($newStatus, ['boarding', 'departed', 'cancelled'], true)) {
                $error = 'The departure update is invalid.';
            } else {
                $find = $pdo->prepare(
                    'SELECT status FROM departures WHERE id = :id AND sacco_id = :sacco_id'
                );
                $find->execute(['id' => $departureId, 'sacco_id' => $saccoId]);
                $currentStatus = $find->fetchColumn();
                $transitions = [
                    'scheduled' => ['boarding', 'cancelled'],
                    'boarding' => ['departed', 'cancelled'],
                ];

                if (!is_string($currentStatus)) {
                    $error = 'That departure could not be found in your SACCO.';
                } elseif (!in_array($newStatus, $transitions[$currentStatus] ?? [], true)) {
                    $error = 'That departure can no longer be updated to the selected status.';
                } else {
                    $update = $pdo->prepare(
                        'UPDATE departures SET status = :status
                         WHERE id = :id AND sacco_id = :sacco_id AND status = :current_status'
                    );
                    $update->execute([
                        'status' => $newStatus,
                        'id' => $departureId,
                        'sacco_id' => $saccoId,
                        'current_status' => $currentStatus,
                    ]);
                    if ($update->rowCount() !== 1) {
                        $error = 'The departure changed before your update could be saved. Refresh and try again.';
                    } else {
                        $success = match ($newStatus) {
                            'boarding' => 'Boarding started.',
                            'departed' => 'Departure marked as departed.',
                            'cancelled' => 'Departure cancelled.',
                        };
                    }
                }
            }
        } else {
            $error = 'The requested action is not available.';
        }

        if ($success !== '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }
}

$saccoStatement = $pdo->prepare('SELECT name FROM saccos WHERE id = :id');
$saccoStatement->execute(['id' => $saccoId]);
$saccoName = $saccoStatement->fetchColumn();
if (!is_string($saccoName)) {
    http_response_code(403);
    exit('Your SACCO could not be found.');
}

$routeStatement = $pdo->prepare(
    'SELECT id, name, origin, destination, fare
     FROM routes WHERE sacco_id = :sacco_id AND status = \'active\' ORDER BY name'
);
$routeStatement->execute(['sacco_id' => $saccoId]);
$routes = $routeStatement->fetchAll();

$vehicleStatement = $pdo->prepare(
    'SELECT id, registration, make_model, capacity
     FROM vehicles WHERE sacco_id = :sacco_id AND status = \'active\' ORDER BY registration'
);
$vehicleStatement->execute(['sacco_id' => $saccoId]);
$vehicles = $vehicleStatement->fetchAll();

$departureStatement = $pdo->prepare(
    'SELECT d.id, d.departure_at, d.status,
            r.name AS route_name, r.origin, r.destination, r.fare,
            v.registration, v.make_model, v.capacity,
            (SELECT COUNT(*) FROM passenger_queue pq
             WHERE pq.departure_id = d.id AND pq.status = \'queued\') AS passenger_count
     FROM departures d
     INNER JOIN routes r ON r.id = d.route_id AND r.sacco_id = d.sacco_id
     INNER JOIN vehicles v ON v.id = d.vehicle_id AND v.sacco_id = d.sacco_id
     WHERE d.sacco_id = :sacco_id
       AND (d.status IN (\'scheduled\', \'boarding\') OR d.departure_at >= DATE_SUB(NOW(), INTERVAL 1 DAY))
     ORDER BY d.departure_at ASC
     LIMIT 50'
);
$departureStatement->execute(['sacco_id' => $saccoId]);
$departures = $departureStatement->fetchAll();

$summaryStatement = $pdo->prepare(
    'SELECT COALESCE(SUM(status = \'scheduled\'), 0) AS scheduled,
            COALESCE(SUM(status = \'boarding\'), 0) AS boarding,
            COALESCE(SUM(status = \'departed\' AND DATE(departure_at) = CURDATE()), 0) AS departed_today
     FROM departures WHERE sacco_id = :sacco_id'
);
$summaryStatement->execute(['sacco_id' => $saccoId]);
$summary = $summaryStatement->fetch();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dispatcher dashboard | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body data-queue-counts-url="<?= escape(appUrl('api/queue-counts.php')) ?>">
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
            <p class="eyebrow">Dispatcher workspace</p>
            <h1>Hello, <?= escape($user['full_name']) ?></h1>
            <p class="muted">Schedule vehicles and manage departures for <?= escape($saccoName) ?>.</p>
        </div>
        <a class="button-link" href="#schedule-departure">+ Schedule departure</a>
    </section>

    <?php if ($success !== ''): ?>
        <p class="success" role="status"><?= escape($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="alert" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>

    <section class="stats-grid" aria-label="Dispatch statistics">
        <article class="stat-card"><span class="stat-label">Scheduled</span><strong><?= (int) $summary['scheduled'] ?></strong><span class="stat-note">Upcoming departures</span></article>
        <article class="stat-card"><span class="stat-label">Boarding</span><strong><?= (int) $summary['boarding'] ?></strong><span class="stat-note">Vehicles currently boarding</span></article>
        <article class="stat-card"><span class="stat-label">Departed today</span><strong><?= (int) $summary['departed_today'] ?></strong><span class="stat-note">Completed departures today</span></article>
        <article class="stat-card"><span class="stat-label">Ready to dispatch</span><strong><?= count($routes) ?> / <?= count($vehicles) ?></strong><span class="stat-note">Active routes / active vehicles</span></article>
    </section>

    <section class="dispatcher-layout">
        <section class="panel owner-panel dispatcher-schedule-panel" id="schedule-departure">
            <div class="section-heading"><div><p class="eyebrow">Dispatch plan</p><h2>Schedule a departure</h2></div></div>
            <p class="muted">Assign an active vehicle to one of your SACCO’s active routes.</p>
            <?php if ($routes === [] || $vehicles === []): ?>
                <div class="empty-state dispatcher-notice">
                    <h3>Setup needed</h3>
                    <p class="muted">Your SACCO admin needs to add at least one active route and one active vehicle before you can schedule a departure.</p>
                </div>
            <?php else: ?>
                <form method="post" action="<?= escape(appUrl('dispatcher/dashboard.php')) ?>" class="owner-form">
                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="create_departure">
                    <label for="route_id">Route</label>
                    <select id="route_id" name="route_id" required>
                        <?php foreach ($routes as $route): ?>
                            <option value="<?= (int) $route['id'] ?>"><?= escape($route['name']) ?> — <?= escape($route['origin']) ?> to <?= escape($route['destination']) ?> (KES <?= number_format((float) $route['fare'], 2) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                    <label for="vehicle_id">Vehicle</label>
                    <select id="vehicle_id" name="vehicle_id" required>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= (int) $vehicle['id'] ?>"><?= escape($vehicle['registration']) ?> — <?= escape($vehicle['make_model']) ?> (<?= (int) $vehicle['capacity'] ?> seats)</option>
                        <?php endforeach; ?>
                    </select>
                    <label for="departure_at">Departure date and time</label>
                    <input id="departure_at" name="departure_at" type="datetime-local" min="<?= escape((new DateTimeImmutable())->format('Y-m-d\TH:i')) ?>" required>
                    <button type="submit">Schedule departure</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="panel owner-panel dispatcher-board-panel">
            <div class="section-heading"><div><p class="eyebrow">Live operations</p><h2>Dispatch board</h2></div><span class="count-badge"><?= count($departures) ?> shown</span></div>
            <?php if ($departures === []): ?>
                <div class="empty-state">
                    <span class="empty-icon" aria-hidden="true">↗</span>
                    <h3>No departures yet</h3>
                    <p class="muted">Schedule a vehicle and route to start your dispatch board.</p>
                </div>
            <?php else: ?>
                <div class="departure-list">
                    <?php foreach ($departures as $departure): ?>
                        <article class="departure-card">
                            <div class="departure-card-heading">
                                <div>
                                    <p class="departure-time"><?= escape((new DateTimeImmutable($departure['departure_at']))->format('D, M j · g:i A')) ?></p>
                                    <h3><?= escape($departure['route_name']) ?></h3>
                                </div>
                                <span class="status-badge status-<?= escape($departure['status']) ?>"><?= escape(ucfirst($departure['status'])) ?></span>
                            </div>
                            <p class="departure-route"><?= escape($departure['origin']) ?> <span aria-hidden="true">→</span> <?= escape($departure['destination']) ?></p>
                            <div class="departure-details">
                                <span><strong><?= escape($departure['registration']) ?></strong> · <?= escape($departure['make_model']) ?></span>
                                <span><?= (int) $departure['capacity'] ?> seats · KES <?= number_format((float) $departure['fare'], 2) ?></span>
                                <span data-queue-departure="<?= (int) $departure['id'] ?>" data-queue-capacity="<?= (int) $departure['capacity'] ?>"><?= (int) $departure['passenger_count'] ?> / <?= (int) $departure['capacity'] ?> passengers queued</span>
                            </div>
                            <?php if (in_array($departure['status'], ['scheduled', 'boarding'], true)): ?>
                                <form method="post" action="<?= escape(appUrl('dispatcher/dashboard.php')) ?>" class="departure-actions">
                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="update_departure_status">
                                    <input type="hidden" name="departure_id" value="<?= (int) $departure['id'] ?>">
                                    <?php if ($departure['status'] === 'scheduled'): ?>
                                        <button type="submit" name="status" value="boarding">Start boarding</button>
                                    <?php else: ?>
                                        <button type="submit" name="status" value="departed">Mark departed</button>
                                    <?php endif; ?>
                                    <button type="submit" name="status" value="cancelled" class="text-button suspend-button">Cancel departure</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </section>
</main>
<script src="<?= escape(appUrl('assets/js/queue-counts.js')) ?>" defer></script>
</body>
</html>
