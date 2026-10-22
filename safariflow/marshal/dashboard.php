<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';
$user = requireRole('marshal');

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
        $departureId = filter_var($_POST['departure_id'] ?? null, FILTER_VALIDATE_INT);
        $emailInput = $_POST['email'] ?? '';
        $email = is_string($emailInput)
            ? filter_var(trim($emailInput), FILTER_VALIDATE_EMAIL)
            : false;

        if ($departureId === false || $departureId === null || $departureId < 1) {
            $error = 'Choose a valid departure.';
        } elseif ($email === false) {
            $error = 'Enter the passenger’s valid email address.';
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
                    $error = 'Passengers can only be registered while boarding is open.';
                } else {
                    $passengerStatement = $pdo->prepare(
                        'SELECT id FROM users
                         WHERE email = :email AND sacco_id = :sacco_id AND role = \'passenger\' AND is_active = 1'
                    );
                    $passengerStatement->execute(['email' => $email, 'sacco_id' => $saccoId]);
                    $passengerId = $passengerStatement->fetchColumn();

                    if ($passengerId === false) {
                        $error = 'No active passenger account with that email was found in your SACCO.';
                    } else {
                        $queueStatement = $pdo->prepare(
                            'SELECT status FROM passenger_queue
                             WHERE departure_id = :departure_id AND passenger_id = :passenger_id'
                        );
                        $queueStatement->execute(['departure_id' => $departureId, 'passenger_id' => $passengerId]);
                        $queueStatus = $queueStatement->fetchColumn();

                        if ($queueStatus === 'queued') {
                            $error = 'That passenger is already in the queue for this departure.';
                        } else {
                            $countStatement = $pdo->prepare(
                                'SELECT COUNT(*) FROM passenger_queue
                                 WHERE departure_id = :departure_id AND status = \'queued\''
                            );
                            $countStatement->execute(['departure_id' => $departureId]);

                            if ((int) $countStatement->fetchColumn() >= (int) $departure['capacity']) {
                                $error = 'This vehicle is full. No more passengers can be registered.';
                            } elseif ($queueStatus === 'cancelled') {
                                $update = $pdo->prepare(
                                    'UPDATE passenger_queue SET status = \'queued\', joined_at = CURRENT_TIMESTAMP
                                     WHERE departure_id = :departure_id AND passenger_id = :passenger_id
                                       AND status = \'cancelled\''
                                );
                                $update->execute(['departure_id' => $departureId, 'passenger_id' => $passengerId]);
                                $success = 'Passenger rejoined the queue.';
                            } else {
                                $insert = $pdo->prepare(
                                    'INSERT INTO passenger_queue (departure_id, passenger_id)
                                     VALUES (:departure_id, :passenger_id)'
                                );
                                $insert->execute(['departure_id' => $departureId, 'passenger_id' => $passengerId]);
                                $success = 'Passenger added to the queue.';
                            }
                        }
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

$saccoStatement = $pdo->prepare('SELECT name FROM saccos WHERE id = :id');
$saccoStatement->execute(['id' => $saccoId]);
$saccoName = $saccoStatement->fetchColumn();
if (!is_string($saccoName)) {
    http_response_code(403);
    exit('Your SACCO could not be found.');
}

$departureStatement = $pdo->prepare(
    'SELECT d.id, d.departure_at,
            r.name AS route_name, r.origin, r.destination,
            v.registration, v.make_model, v.capacity,
            (SELECT COUNT(*) FROM passenger_queue pq
             WHERE pq.departure_id = d.id AND pq.status = \'queued\') AS passenger_count
     FROM departures d
     INNER JOIN routes r ON r.id = d.route_id AND r.sacco_id = d.sacco_id
     INNER JOIN vehicles v ON v.id = d.vehicle_id AND v.sacco_id = d.sacco_id
     WHERE d.sacco_id = :sacco_id AND d.status = \'boarding\'
     ORDER BY d.departure_at ASC
     LIMIT 50'
);
$departureStatement->execute(['sacco_id' => $saccoId]);
$departures = $departureStatement->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Marshal dashboard | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body data-queue-counts-url="<?= escape(appUrl('api/queue-counts.php')) ?>">
<header class="topbar owner-topbar">
    <a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a>
    <div class="topbar-actions">
        <span class="owner-label"><?= escape($saccoName) ?></span>
        <a href="<?= escape(appUrl('logout.php')) ?>">Sign out</a>
    </div>
</header>
<main class="container owner-container">
    <section class="owner-heading">
        <div>
            <p class="eyebrow">Marshal workspace</p>
            <h1>Hello, <?= escape($user['full_name']) ?></h1>
            <p class="muted">Help passengers join the queue for departures currently boarding at <?= escape($saccoName) ?>.</p>
        </div>
    </section>

    <?php if ($success !== ''): ?>
        <p class="success" role="status"><?= escape($success) ?></p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="alert" role="alert"><?= escape($error) ?></p>
    <?php endif; ?>

    <section class="panel owner-panel">
        <div class="section-heading">
            <div><p class="eyebrow">Boarding</p><h2>Open queues</h2></div>
            <span class="count-badge"><?= count($departures) ?> boarding</span>
        </div>
        <p class="muted">Enter the email used by the passenger’s active account. Queue registrations count toward the vehicle’s capacity.</p>
        <?php if ($departures === []): ?>
            <div class="empty-state">
                <h3>No departures are boarding</h3>
                <p class="muted">Open queues will appear here when a dispatcher starts boarding.</p>
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
                            <span class="status-badge status-boarding">Boarding</span>
                        </div>
                        <p class="departure-route"><?= escape($departure['origin']) ?> <span aria-hidden="true">→</span> <?= escape($departure['destination']) ?></p>
                        <div class="departure-details">
                            <span><strong><?= escape($departure['registration']) ?></strong> · <?= escape($departure['make_model']) ?></span>
                            <span data-queue-departure="<?= (int) $departure['id'] ?>" data-queue-capacity="<?= (int) $departure['capacity'] ?>"><?= (int) $departure['passenger_count'] ?> / <?= (int) $departure['capacity'] ?> passengers queued</span>
                        </div>
                        <form method="post" action="<?= escape(appUrl('marshal/dashboard.php')) ?>" class="owner-form">
                            <input type="hidden" name="csrf_token" value="<?= escape($_SESSION['csrf_token']) ?>">
                            <input type="hidden" name="departure_id" value="<?= (int) $departure['id'] ?>">
                            <label for="passenger_email_<?= (int) $departure['id'] ?>">Passenger email</label>
                            <input id="passenger_email_<?= (int) $departure['id'] ?>" name="email" type="email" maxlength="254" autocomplete="email" required>
                            <button type="submit">Register passenger</button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script src="<?= escape(appUrl('assets/js/queue-counts.js')) ?>" defer></script>
</body>
</html>
