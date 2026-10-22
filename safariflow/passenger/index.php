<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = requireRole('passenger');

startSecureSession();
$_SESSION['csrf_token'] ??= bin2hex(random_bytes(32));

$passengerId = (int) $user['id'];
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
        $departureId = filter_var($_POST['departure_id'] ?? null, FILTER_VALIDATE_INT);

        if ($departureId === false || $departureId === null || $departureId < 1) {
            $error = 'Choose a valid departure.';
        } elseif (!in_array($action, ['join_queue', 'leave_queue'], true)) {
            $error = 'The requested action is not available.';
        } else {
            $pdo->beginTransaction();
            try {
                $departureStatement = $pdo->prepare(
                    'SELECT d.status, v.capacity
                     FROM departures d
                     INNER JOIN vehicles v ON v.id = d.vehicle_id AND v.sacco_id = d.sacco_id
                     WHERE d.id = :id AND d.sacco_id = :sacco_id
                     FOR UPDATE'
                );
                $departureStatement->execute(['id' => $departureId, 'sacco_id' => $saccoId]);
                $departure = $departureStatement->fetch();

                if ($departure === false) {
                    $error = 'That departure could not be found in your SACCO.';
                } elseif ($departure['status'] !== 'boarding') {
                    $error = 'You can only change your queue registration while boarding is open.';
                } elseif ($action === 'join_queue') {
                    $queueStatement = $pdo->prepare(
                        'SELECT id, status FROM passenger_queue
                         WHERE departure_id = :departure_id AND passenger_id = :passenger_id'
                    );
                    $queueStatement->execute(['departure_id' => $departureId, 'passenger_id' => $passengerId]);
                    $queueEntry = $queueStatement->fetch();

                    if ($queueEntry !== false && $queueEntry['status'] === 'queued') {
                        $error = 'You are already in the queue for this departure.';
                    } else {
                        $countStatement = $pdo->prepare(
                            'SELECT COUNT(*) FROM passenger_queue
                             WHERE departure_id = :departure_id AND status = \'queued\''
                        );
                        $countStatement->execute(['departure_id' => $departureId]);
                        $queueCount = (int) $countStatement->fetchColumn();

                        if ($queueCount >= (int) $departure['capacity']) {
                            $error = 'This vehicle is full. Choose another departure.';
                        } elseif ($queueEntry !== false) {
                            $update = $pdo->prepare(
                                'UPDATE passenger_queue SET status = \'queued\', joined_at = CURRENT_TIMESTAMP
                                 WHERE id = :id AND status = \'cancelled\''
                            );
                            $update->execute(['id' => $queueEntry['id']]);
                            $success = 'You have rejoined the passenger queue.';
                        } else {
                            $insert = $pdo->prepare(
                                'INSERT INTO passenger_queue (departure_id, passenger_id)
                                 VALUES (:departure_id, :passenger_id)'
                            );
                            $insert->execute(['departure_id' => $departureId, 'passenger_id' => $passengerId]);
                            $success = 'You have joined the passenger queue.';
                        }
                    }
                } else {
                    $update = $pdo->prepare(
                        'UPDATE passenger_queue
                         SET status = \'cancelled\'
                         WHERE departure_id = :departure_id AND passenger_id = :passenger_id AND status = \'queued\''
                    );
                    $update->execute(['departure_id' => $departureId, 'passenger_id' => $passengerId]);
                    if ($update->rowCount() === 1) {
                        $success = 'Your queue registration has been cancelled.';
                    } else {
                        $error = 'You do not have an active queue registration for this departure.';
                    }
                }

                if ($success !== '') {
                    $pdo->commit();
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                } else {
                    $pdo->rollBack();
                }
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        }
    }
}

$departureStatement = $pdo->prepare(
    'SELECT d.id, d.departure_at, d.status,
            r.name AS route_name, r.origin, r.destination, r.fare,
            v.registration, v.make_model, v.capacity,
            (SELECT COUNT(*) FROM passenger_queue active_queue
             WHERE active_queue.departure_id = d.id AND active_queue.status = \'queued\') AS queue_count,
            (SELECT own_queue.status FROM passenger_queue own_queue
             WHERE own_queue.departure_id = d.id AND own_queue.passenger_id = :passenger_id
             LIMIT 1) AS my_queue_status,
            (SELECT COUNT(*) FROM passenger_queue position_queue
             WHERE position_queue.departure_id = d.id AND position_queue.status = \'queued\'
               AND (position_queue.joined_at < (
                        SELECT joined_at FROM passenger_queue
                        WHERE departure_id = d.id AND passenger_id = :position_passenger_id
                    ) OR (
                        position_queue.joined_at = (
                            SELECT joined_at FROM passenger_queue
                            WHERE departure_id = d.id AND passenger_id = :tie_passenger_id
                        ) AND position_queue.id <= (
                            SELECT id FROM passenger_queue
                            WHERE departure_id = d.id AND passenger_id = :id_passenger_id
                        )
                    ))) AS queue_position
     FROM departures d
     INNER JOIN routes r ON r.id = d.route_id AND r.sacco_id = d.sacco_id
     INNER JOIN vehicles v ON v.id = d.vehicle_id AND v.sacco_id = d.sacco_id
     WHERE d.sacco_id = :sacco_id
       AND (d.status = \'boarding\' OR EXISTS (
           SELECT 1 FROM passenger_queue own_entry
           WHERE own_entry.departure_id = d.id AND own_entry.passenger_id = :history_passenger_id
       ))
     ORDER BY (d.status = \'boarding\') DESC, d.departure_at ASC
     LIMIT 50'
);
$departureStatement->execute([
    'passenger_id' => $passengerId,
    'position_passenger_id' => $passengerId,
    'tie_passenger_id' => $passengerId,
    'id_passenger_id' => $passengerId,
    'sacco_id' => $saccoId,
    'history_passenger_id' => $passengerId,
]);
$departures = $departureStatement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passenger | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body data-queue-counts-url="<?= escape(appUrl('api/queue-counts.php')) ?>">
<header class="topbar"><a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a><a href="<?= escape(appUrl('logout.php')) ?>">Sign out</a></header>
<main class="container">
    <p class="eyebrow">Passenger</p>
    <h1>Hello, <?= escape($user['full_name']) ?></h1>
    <p class="muted">Join an open boarding queue and keep track of your place.</p>
    <?php if ($success !== ''): ?>
        <p class="success" role="status"><?= escape($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="alert" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>

    <section class="panel passenger-queue-panel">
        <div class="section-heading"><div><p class="eyebrow">Passenger queue</p><h2>Available departures</h2></div></div>
        <?php if ($departures === []): ?>
            <div class="empty-state">
                <h3>No boarding queues are open</h3>
                <p class="muted">Departures will appear here when your SACCO dispatcher starts boarding.</p>
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
                            <span data-queue-departure="<?= (int) $departure['id'] ?>" data-queue-capacity="<?= (int) $departure['capacity'] ?>"><?= (int) $departure['queue_count'] ?> / <?= (int) $departure['capacity'] ?> passengers queued</span>
                            <span>KES <?= number_format((float) $departure['fare'], 2) ?></span>
                        </div>
                        <?php if ($departure['my_queue_status'] === 'queued'): ?>
                            <p class="queue-position" role="status">Your queue position: <strong><?= (int) $departure['queue_position'] ?></strong></p>
                            <?php if ($departure['status'] === 'boarding'): ?>
                                <form method="post" action="<?= escape(appUrl('passenger/index.php')) ?>" class="departure-actions">
                                    <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="action" value="leave_queue">
                                    <input type="hidden" name="departure_id" value="<?= (int) $departure['id'] ?>">
                                    <button type="submit" class="text-button suspend-button">Cancel queue registration</button>
                                </form>
                            <?php endif; ?>
                        <?php elseif ($departure['status'] !== 'boarding'): ?>
                            <p class="queue-position muted">This departure is <?= escape($departure['status']) ?>.</p>
                        <?php elseif ($departure['my_queue_status'] === 'cancelled'): ?>
                            <form method="post" action="<?= escape(appUrl('passenger/index.php')) ?>" class="departure-actions">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="join_queue">
                                <input type="hidden" name="departure_id" value="<?= (int) $departure['id'] ?>">
                                <button type="submit">Rejoin queue</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= escape(appUrl('passenger/index.php')) ?>" class="departure-actions">
                                <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="join_queue">
                                <input type="hidden" name="departure_id" value="<?= (int) $departure['id'] ?>">
                                <button type="submit">Join queue</button>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script src="<?= escape(appUrl('assets/js/queue-counts.js')) ?>" defer></script>
</body>
</html>
