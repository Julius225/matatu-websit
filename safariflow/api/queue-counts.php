<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/auth.php';

$sessionUser = currentUser();
if ($sessionUser === null) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Sign in to view passenger queue counts.']);
    exit;
}
if (!in_array($sessionUser['role'] ?? '', ['dispatcher', 'marshal', 'passenger'], true)) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'You do not have permission to view passenger queue counts.']);
    exit;
}

$user = requireRole($sessionUser['role']);
$statement = database()->prepare(
    'SELECT d.id, COUNT(pq.id) AS passenger_count
     FROM departures d
     LEFT JOIN passenger_queue pq
       ON pq.departure_id = d.id AND pq.status = \'queued\'
     WHERE d.sacco_id = :sacco_id
       AND (
           d.status IN (\'scheduled\', \'boarding\')
           OR d.departure_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)
           OR EXISTS (
               SELECT 1 FROM passenger_queue own_entry
               WHERE own_entry.departure_id = d.id AND own_entry.passenger_id = :passenger_id
           )
       )
     GROUP BY d.id'
);
$statement->execute([
    'sacco_id' => (int) $user['sacco_id'],
    'passenger_id' => (int) $user['id'],
]);

$counts = [];
foreach ($statement->fetchAll() as $row) {
    $counts[(string) $row['id']] = (int) $row['passenger_count'];
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
echo json_encode(['counts' => $counts], JSON_THROW_ON_ERROR);
