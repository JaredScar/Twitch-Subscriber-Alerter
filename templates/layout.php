<?php
/** @var string $content */
/** @var string $title */
/** @var string|null $nav  which top-nav item is current: dashboard | alerts | admin | review */
/** @var list<array{type:string,message:string}> $flash */

use App\Auth;
use App\CustomAlerts;
use App\View;

$e = [View::class, 'e'];
$current = Auth::user();
$nav = $nav ?? '';
$isAdmin = $current && (int) $current['is_admin'] === 1;
$pending = $isAdmin ? CustomAlerts::pendingCount() : 0;
$link = static fn(string $key, string $href, string $label, string $icon, string $badge = ''): string =>
    '<a href="' . $href . '"' . ($nav === $key ? ' aria-current="page"' : '') . '>' . View::icon($icon) . '<span>' . $label . '</span>' . $badge . '</a>';
$noticeIcon = ['ok' => 'check', 'warn' => 'alert', 'error' => 'alert', 'info' => 'info'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= $e($title ?? 'Subscriber Alerter') ?> &middot; Subscriber Alerter</title>
<link rel="stylesheet" href="assets/css/app.css?v=5">
</head>
<body>
<?= View::partial('_icons') ?>
<header class="topbar">
  <div class="topbar-in">
    <a class="brand" href="<?= $current ? 'dashboard.php' : 'index.php' ?>"><span class="brand-mark"><?= View::icon('zap') ?></span>Subscriber Alerter</a>
    <?php if ($current): ?>
    <nav class="nav" aria-label="Main">
      <?= $link('dashboard', 'dashboard.php', 'Dashboard', 'sliders') ?>
      <?= $link('alerts', 'my-alerts.php', 'Alerts', 'gift') ?>
      <?php if ($isAdmin): ?>
        <?= $link('admin', 'admin.php', 'Streamers', 'users') ?>
        <?= $link('review', 'admin-alerts.php', 'Review', 'inbox', $pending ? '<span class="count" title="Waiting for review">' . $pending . '</span>' : '') ?>
      <?php endif; ?>
    </nav>
    <form method="post" action="logout.php" class="user">
      <?php if (!empty($current['profile_image_url'])): ?>
        <img class="avatar-xs" src="<?= $e($current['profile_image_url']) ?>" alt="" width="28" height="28">
      <?php endif; ?>
      <span class="user-name"><?= $e($current['display_name']) ?></span>
      <input type="hidden" name="_csrf" value="<?= $e(Auth::csrf()) ?>">
      <button type="submit" class="btn btn-ghost btn-small">Log out</button>
    </form>
    <?php endif; ?>
  </div>
</header>
<main class="wrap">
<?php foreach (($flash ?? Auth::takeFlash()) as $f): ?>
  <div class="notice notice-<?= $e($f['type']) ?>" role="status"><?= View::icon($noticeIcon[$f['type']] ?? 'info') ?><span><?= $e($f['message']) ?></span></div>
<?php endforeach; ?>
<?= $content ?>
</main>
<footer class="foot">Alerts run in your OBS Browser Source &middot; no video files, no stream lag.</footer>
<script src="assets/js/app.js?v=3"></script>
</body>
</html>
