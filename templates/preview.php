<?php
/**
 * @var array<string,mixed> $alert
 * @var array<string,mixed> $descriptor
 * @var int $volume
 * @var bool $isAdmin
 */

use App\View;

$e = [View::class, 'e'];
?>
<link rel="stylesheet" href="assets/css/custom-alert.css?v=3">

<div class="page-head">
  <div class="grow">
    <h1><?= $e($alert['name']) ?></h1>
    <p class="lead"><?= $alert['kind'] === 'html' ? 'Custom HTML alert' : 'Picture / video alert' ?> &middot; by <?= $e($alert['owner_name']) ?>
      &middot; <?= match ($alert['status']) { 'approved' => 'approved', 'pending' => 'waiting for review', default => 'not approved' } ?></p>
  </div>
  <a class="btn" href="<?= $isAdmin && (int) $alert['owner_id'] !== (int) \App\Auth::user()['id'] ? 'admin-alerts.php' : 'my-alerts.php' ?>">Back to alerts</a>
</div>

<section class="card" id="preview" data-descriptor="<?= $e(json_encode($descriptor, JSON_UNESCAPED_SLASHES)) ?>">
  <div class="stage" id="stage-box">
    <div class="stage-hint"><span>Press Play to see the alert</span></div>
    <div id="custom"></div>
  </div>

  <div class="row top-gap">
    <label class="suffix">Name <input type="text" id="p-name" value="StreamerFan" maxlength="30" size="14"></label>
    <label class="suffix">Gifted subs <input type="number" class="num" id="p-count" value="25" min="1" max="100000"></label>
    <label class="suffix">Volume <input type="range" id="p-volume" min="0" max="100" value="<?= (int) $volume ?>"></label>
    <button type="button" class="btn btn-primary" id="p-play"><?= View::icon('play') ?> Play</button>
    <button type="button" class="btn" id="p-stop"><?= View::icon('stop') ?> Stop</button>
  </div>
  <p class="hint">This is how it will look on a 1920&times;1080 overlay, scaled down. Nothing is sent to anyone's stream.</p>
</section>

<script src="assets/js/custom-player.js?v=2"></script>
<script src="assets/js/preview.js?v=1"></script>
