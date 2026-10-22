<?php
declare(strict_types=1);

require_once __DIR__ . '/config/auth.php';

$sessionUser = currentUser();
if ($sessionUser === null) {
    header('Location: ' . appUrl('login.php'));
    exit;
}
if (!in_array($sessionUser['role'] ?? '', ['sacco_admin', 'dispatcher'], true)) {
    http_response_code(403);
    exit('You do not have permission to view operational reports.');
}

$user = requireRole($sessionUser['role']);
$saccoId = (int) $user['sacco_id'];
$today = (new DateTimeImmutable())->format('Y-m-d');
$startInput = $_GET['start_date'] ?? $today;
$endInput = $_GET['end_date'] ?? $today;
$parseDate = static function (mixed $value): ?DateTimeImmutable {
    if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    $errors = DateTimeImmutable::getLastErrors();
    if (
        $date === false
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
        || $date->format('Y-m-d') !== $value
    ) {
        return null;
    }
    return $date;
};
$startDate = $parseDate($startInput);
$endDate = $parseDate($endInput);
$dateError = $startDate === null || $endDate === null || $startDate > $endDate;

if ($dateError) {
    http_response_code(400);
    $startDate = new DateTimeImmutable($today);
    $endDate = new DateTimeImmutable($today);
    $startInput = $today;
    $endInput = $today;
}

$pdo = database();
$saccoStatement = $pdo->prepare('SELECT name FROM saccos WHERE id = :id');
$saccoStatement->execute(['id' => $saccoId]);
$saccoName = $saccoStatement->fetchColumn();
if (!is_string($saccoName)) {
    http_response_code(403);
    exit('Your SACCO could not be found.');
}

$reportStatement = $pdo->prepare(
    'SELECT d.id, d.departure_at, d.status,
            r.name AS route_name, r.origin, r.destination, r.fare,
            v.registration, v.capacity,
            COUNT(CASE WHEN pq.status = \'queued\' THEN pq.id END) AS passenger_count
     FROM departures d
     INNER JOIN routes r ON r.id = d.route_id AND r.sacco_id = d.sacco_id
     INNER JOIN vehicles v ON v.id = d.vehicle_id AND v.sacco_id = d.sacco_id
     LEFT JOIN passenger_queue pq ON pq.departure_id = d.id
     WHERE d.sacco_id = :sacco_id
       AND d.departure_at >= :start_date
       AND d.departure_at < :end_date
     GROUP BY d.id, d.departure_at, d.status, r.name, r.origin, r.destination, r.fare, v.registration, v.capacity
     ORDER BY d.departure_at DESC, d.id DESC'
);
$reportStatement->execute([
    'sacco_id' => $saccoId,
    'start_date' => $startDate->format('Y-m-d 00:00:00'),
    'end_date' => $endDate->modify('+1 day')->format('Y-m-d 00:00:00'),
]);
$departures = $reportStatement->fetchAll();

$summary = [
    'total' => count($departures),
    'departed' => 0,
    'cancelled' => 0,
    'passengers' => 0,
];
$routes = [];
foreach ($departures as $departure) {
    if ($departure['status'] === 'departed') {
        $summary['departed']++;
    } elseif ($departure['status'] === 'cancelled') {
        $summary['cancelled']++;
    }
    $summary['passengers'] += (int) $departure['passenger_count'];

    $routeName = $departure['route_name'];
    if (!isset($routes[$routeName])) {
        $routes[$routeName] = ['departures' => 0, 'passengers' => 0];
    }
    $routes[$routeName]['departures']++;
    $routes[$routeName]['passengers'] += (int) $departure['passenger_count'];
}
uasort($routes, static fn (array $a, array $b): int => $b['departures'] <=> $a['departures']);

if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="safariflow-operations-' . $startInput . '-to-' . $endInput . '.csv"');
    header('Cache-Control: no-store, private');
    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to create the CSV report.');
    }
    fwrite($output, "\xEF\xBB\xBF");
    fputcsv($output, ['Departure ID', 'Departure time', 'Status', 'Route', 'Origin', 'Destination', 'Vehicle', 'Passengers queued', 'Vehicle capacity', 'Fare (KES)']);
    foreach ($departures as $departure) {
        $values = [
            (string) $departure['id'],
            (string) $departure['departure_at'],
            (string) $departure['status'],
            (string) $departure['route_name'],
            (string) $departure['origin'],
            (string) $departure['destination'],
            (string) $departure['registration'],
            (string) $departure['passenger_count'],
            (string) $departure['capacity'],
            number_format((float) $departure['fare'], 2, '.', ''),
        ];
        foreach ($values as &$value) {
            if (preg_match('/^[\s]*[=+\-@]/', $value) === 1) {
                $value = "'" . $value;
            }
        }
        unset($value);
        fputcsv($output, $values);
    }
    fclose($output);
    exit;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Operational reports | SafariFlow</title>
    <link rel="stylesheet" href="<?= escape(appUrl('assets/css/style.css')) ?>">
</head>
<body>
<header class="topbar owner-topbar">
    <a class="brand" href="<?= escape(appUrl()) ?>">SafariFlow</a>
    <div class="topbar-actions">
        <span class="owner-label"><?= escape($saccoName) ?></span>
        <a href="<?= escape(dashboardForRole($user['role'])) ?>">Dashboard</a>
        <a href="<?= escape(appUrl('logout.php')) ?>">Sign out</a>
    </div>
</header>
<main class="container owner-container">
    <section class="owner-heading">
        <div>
            <p class="eyebrow">Operations</p>
            <h1>Operational reports</h1>
            <p class="muted">Review departure activity and passenger queue registrations for <?= escape($saccoName) ?>.</p>
        </div>
    </section>

    <?php if ($dateError): ?>
        <p class="alert" role="alert">Choose valid dates, with the start date on or before the end date. Showing today’s report.</p>
    <?php endif; ?>

    <section class="panel owner-panel">
        <div class="section-heading"><div><p class="eyebrow">Report period</p><h2>Choose dates</h2></div></div>
        <form method="get" action="<?= escape(appUrl('reports.php')) ?>" class="owner-form report-filter">
            <label for="start_date">From</label>
            <input id="start_date" name="start_date" type="date" value="<?= escape($startInput) ?>" required>
            <label for="end_date">To</label>
            <input id="end_date" name="end_date" type="date" value="<?= escape($endInput) ?>" required>
            <button type="submit">Run report</button>
        </form>
    </section>

    <section class="stats-grid report-stats" aria-label="Operational report summary">
        <article class="stat-card"><span class="stat-label">Departures</span><strong><?= $summary['total'] ?></strong><span class="stat-note">In the selected period</span></article>
        <article class="stat-card"><span class="stat-label">Departed</span><strong><?= $summary['departed'] ?></strong><span class="stat-note">Completed trips</span></article>
        <article class="stat-card"><span class="stat-label">Cancelled</span><strong><?= $summary['cancelled'] ?></strong><span class="stat-note">Cancelled trips</span></article>
        <article class="stat-card"><span class="stat-label">Passengers registered</span><strong><?= $summary['passengers'] ?></strong><span class="stat-note">Queue registrations for departures</span></article>
    </section>

    <section class="panel owner-panel admin-list-panel">
        <div class="section-heading">
            <div><p class="eyebrow">By route</p><h2>Route activity</h2></div>
        </div>
        <?php if ($routes === []): ?>
            <div class="empty-state"><h3>No route activity</h3><p class="muted">No departures were scheduled for these dates.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="sacco-table">
                <thead><tr><th scope="col">Route</th><th scope="col">Departures</th><th scope="col">Passengers registered</th></tr></thead>
                <tbody><?php foreach ($routes as $routeName => $routeSummary): ?>
                    <tr>
                        <td data-label="Route"><strong><?= escape($routeName) ?></strong></td>
                        <td data-label="Departures"><?= $routeSummary['departures'] ?></td>
                        <td data-label="Passengers registered"><?= $routeSummary['passengers'] ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>

    <section class="panel owner-panel admin-list-panel">
        <div class="section-heading">
            <div><p class="eyebrow">Trip details</p><h2>Departures</h2></div>
            <a class="button-link" href="<?= escape(appUrl('reports.php') . '?start_date=' . rawurlencode($startInput) . '&end_date=' . rawurlencode($endInput) . '&format=csv') ?>">Download CSV</a>
        </div>
        <?php if ($departures === []): ?>
            <div class="empty-state"><h3>No departures in this period</h3><p class="muted">Try a different date range.</p></div>
        <?php else: ?>
            <div class="table-scroll"><table class="sacco-table">
                <thead><tr><th scope="col">Departure</th><th scope="col">Route</th><th scope="col">Vehicle</th><th scope="col">Status</th><th scope="col">Passengers</th><th scope="col">Fare</th></tr></thead>
                <tbody><?php foreach ($departures as $departure): ?>
                    <tr>
                        <td data-label="Departure"><?= escape((new DateTimeImmutable($departure['departure_at']))->format('Y-m-d g:i A')) ?></td>
                        <td data-label="Route"><strong><?= escape($departure['route_name']) ?></strong><span class="table-subtext"><?= escape($departure['origin']) ?> → <?= escape($departure['destination']) ?></span></td>
                        <td data-label="Vehicle"><?= escape($departure['registration']) ?></td>
                        <td data-label="Status"><span class="status-badge status-<?= escape($departure['status']) ?>"><?= escape(ucfirst($departure['status'])) ?></span></td>
                        <td data-label="Passengers"><?= (int) $departure['passenger_count'] ?> / <?= (int) $departure['capacity'] ?></td>
                        <td data-label="Fare">KES <?= number_format((float) $departure['fare'], 2) ?></td>
                    </tr>
                <?php endforeach; ?></tbody>
            </table></div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
