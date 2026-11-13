<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\CustomAlerts;
use App\Database;
use App\Http;
use App\View;

Http::securityHeaders();
$me = Auth::requireAdmin();

$tab = (string) ($_GET['tab'] ?? 'pending');
if (!in_array($tab, ['pending', 'approved', 'rejected'], true)) {
    $tab = 'pending';
}

$alerts = CustomAlerts::adminList($tab);
$sources = [];
foreach ($alerts as $a) {
    if ($a['kind'] === 'html' && $a['status'] === 'pending') {
        $sources[(int) $a['id']] = CustomAlerts::reviewSource($a);
    }
}
$counts = [];
foreach (Database::all('SELECT status, COUNT(*) c FROM custom_alerts GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['c'];
}

View::render('admin-alerts', [
    'title' => 'Review alerts',
    'nav' => 'review',
    'user' => $me,
    'tab' => $tab,
    'alerts' => $alerts,
    'sources' => $sources,
    'counts' => $counts,
    'flash' => Auth::takeFlash(),
]);
