<?php
/**
 * @var array<string,mixed> $user
 * @var array<string,mixed> $settings
 * @var array<string,array{enabled:int,min_count:int}> $rules
 * @var array<string,array{label:string,min:int,page:?string}> $catalog
 * @var array<string,array<string,mixed>> $custom      added custom alerts keyed by "custom-N"
 * @var array<string,string> $labels
 * @var array<string,string> $statuses
 * @var list<string> $eventTypes
 * @var list<array<string,mixed>> $events
 * @var string|null $overlaySeenAt
 * @var string $overlayUrl
 * @var string $previewBase
 */

use App\Auth;
use App\View;

$e = [View::class, 'e'];
$csrf = Auth::csrf();
$typeLabels = [
    'channel.subscription.gift' => 'Gifted subs',
    'channel.subscribe' => 'New subs',
    'channel.subscription.message' => 'Resub messages',
];
$typeNames = ['test' => 'Test', 'gift' => 'Gift', 'sub' => 'New sub', 'resub' => 'Resub'];
$okStatus = ['enabled', 'webhook_callback_verification_pending'];
$linked = 0;
foreach ($eventTypes as $t) {
    if (in_array($statuses[$t] ?? '', $okStatus, true)) { $linked++; }
}
$allLinked = $linked === count($eventTypes);
$seenTs = $overlaySeenAt ? strtotime($overlaySeenAt . ' UTC') : false;
$overlayLive = $seenTs !== false && (time() - $seenTs) < 45;

$alertOptions = static function (?string $selected) use ($catalog, $custom, $e): string {
    $html = '<option value="">None</option>';
    if ($catalog) {
        $html .= '<optgroup label="Included">';
        foreach ($catalog as $id => $a) {
            $html .= '<option value="' . $e($id) . '"' . ($selected === $id ? ' selected' : '') . '>' . $e($a['label']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    if ($custom) {
        $html .= '<optgroup label="Your custom alerts">';
        foreach ($custom as $id => $a) {
            $html .= '<option value="' . $e($id) . '"' . ($selected === $id ? ' selected' : '') . '>' . $e($a['name']) . '</option>';
        }
        $html .= '</optgroup>';
    }
    return $html;
};
?>
<div class="page-head">
  <?php if (!empty($user['profile_image_url'])): ?>
    <img class="avatar" src="<?= $e($user['profile_image_url']) ?>" alt="" width="56" height="56">
  <?php endif; ?>
  <div class="grow">
    <h1><?= $e($user['display_name']) ?></h1>
    <p class="lead">Choose which alert plays for each kind of subscriber event.</p>
  </div>
  <form method="post" action="actions.php">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="resync">
    <button type="submit" class="btn"><?= View::icon('refresh') ?> Re-sync with Twitch</button>
  </form>
</div>

<?php if ((int) $user['is_disabled']): ?>
  <div class="notice notice-error"><?= View::icon('alert') ?><span>Your account has been disabled by the site owner. Alerts will not play.</span></div>
<?php endif; ?>

<div class="stats">
  <div class="stat">
    <div class="stat-label"><?= View::icon('signal') ?> Overlay</div>
    <div class="stat-value" id="overlay-status" data-seen="<?= $e($overlaySeenAt ?? '') ?>" data-live-for="45">
      <span class="dot <?= $overlayLive ? 'dot-ok' : 'dot-warn' ?>"></span>
      <span class="js-text"><?= $overlayLive ? 'Connected' : 'Not connected' ?></span>
    </div>
    <div class="stat-sub js-sub"><?= $overlayLive ? 'OBS is showing your overlay.' : ($seenTs !== false ? 'Last seen ' . $e(View::ago((string) $overlaySeenAt)) . '.' : 'Add the URL below to OBS.') ?></div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= View::icon('link') ?> Twitch events</div>
    <div class="stat-value"><span class="dot <?= $allLinked ? 'dot-ok' : 'dot-bad' ?>"></span><?= $linked ?>/<?= count($eventTypes) ?> linked</div>
    <div class="chips top-gap">
      <?php foreach ($eventTypes as $t):
          $s = $statuses[$t] ?? 'not linked';
          $good = in_array($s, $okStatus, true); ?>
        <span class="chip <?= $good ? 'chip-ok' : 'chip-bad' ?>" title="<?= $e($t . ': ' . $s) ?>"><?= $e($typeLabels[$t] ?? $t) ?></span>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="stat">
    <div class="stat-label"><?= View::icon('gift') ?> Alerts</div>
    <div class="stat-value"><?php if ($catalog): ?><?= count($catalog) ?> included<?php if ($custom): ?> <span class="chip chip-info">+<?= count($custom) ?> custom</span><?php endif; ?><?php else: ?><?= count($custom) ?> custom<?php endif; ?></div>
    <div class="stat-sub"><a href="my-alerts.php">Browse or submit your own</a></div>
  </div>
</div>

<section class="card">
  <div class="card-head">
    <span class="ico"><?= View::icon('signal') ?></span>
    <div><h2>OBS overlay URL</h2><p>Add a <strong>Browser Source</strong> in OBS with this URL, width <strong>1920</strong>, height <strong>1080</strong>. Treat the URL like a password.</p></div>
  </div>
  <div class="copy-box">
    <input id="overlay-url" class="mono" type="password" readonly value="<?= $e($overlayUrl) ?>" aria-label="Overlay URL">
    <button type="button" class="btn" data-reveal="overlay-url"><?= View::icon('eye') ?> <span>Show</span></button>
    <button type="button" class="btn btn-primary" data-copy="overlay-url"><?= View::icon('copy') ?> <span>Copy</span></button>
  </div>
  <details class="disclosure top-gap">
    <summary>OBS tips</summary>
    <div class="body">
      <ul class="checklist">
        <li><?= View::icon('check') ?>Leave <em>Shutdown source when not visible</em> and <em>Refresh browser when scene becomes active</em> off so no alert is missed.</li>
        <li><?= View::icon('check') ?>If the URL was ever shown on stream, generate a new one below and update OBS.</li>
      </ul>
      <form method="post" action="actions.php" class="top-gap" data-confirm="Generate a new overlay URL? You will need to update OBS.">
        <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="regen_key">
        <button type="submit" class="btn btn-small"><?= View::icon('refresh') ?> Generate a new URL</button>
      </form>
    </div>
  </details>
</section>

<form id="test-form" method="post" action="actions.php">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="action" value="test">
</form>

<form method="post" action="actions.php" class="card" id="settings-form">
  <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
  <input type="hidden" name="action" value="save_settings">

  <div class="card-head">
    <span class="ico"><?= View::icon('gift') ?></span>
    <div><h2>Gifted subs</h2><p>The enabled alert with the <em>highest</em> minimum that is still at or below the number gifted plays. Gifts below every minimum play nothing.</p></div>
  </div>
  <label class="check"><input class="switch" type="checkbox" name="react_gift" value="1" <?= (int) $settings['react_gift'] ? 'checked' : '' ?>> <span>Play an alert when someone gifts subs</span></label>

  <div class="table-wrap top-gap">
  <table class="table table-stack">
    <thead><tr><th>Alert</th><th>On</th><th>From (gifted subs)</th><th>Preview</th><th>Test</th></tr></thead>
    <tbody>
    <?php foreach ($catalog as $id => $a): $r = $rules[$id]; ?>
      <tr>
        <td class="name-td"><div class="name-cell"><span class="tier"><?= (int) $a['min'] ?></span><div><strong><?= $e($a['label']) ?></strong><div class="muted">Included</div></div></div></td>
        <td data-label="On"><input class="switch" type="checkbox" name="rules[<?= $e($id) ?>][enabled]" value="1" <?= (int) $r['enabled'] ? 'checked' : '' ?> aria-label="Enable <?= $e($a['label']) ?>"></td>
        <td data-label="From"><input type="number" class="num" name="rules[<?= $e($id) ?>][min_count]" min="1" max="100000" value="<?= (int) $r['min_count'] ?>" aria-label="Minimum gifts for <?= $e($a['label']) ?>"></td>
        <td data-label="Preview"><?php if ($a['page'] !== null): ?><a class="btn btn-small" href="<?= $e($previewBase . $a['page']) ?>?bg=1&amp;mute=1" target="_blank" rel="noopener"><?= View::icon('external') ?> Open</a><?php else: ?><span class="muted">-</span><?php endif; ?></td>
        <td data-label="Test"><button type="submit" form="test-form" name="alert_id" value="<?= $e($id) ?>" class="btn btn-small"><?= View::icon('play') ?> Send</button></td>
      </tr>
    <?php endforeach; ?>
    <?php foreach ($custom as $id => $a): $r = $rules[$id] ?? ['enabled' => 1, 'min_count' => 5]; ?>
      <tr>
        <td class="name-td"><div class="name-cell"><span class="tier tier-custom"><?= View::icon($a['kind'] === 'html' ? 'code' : 'image') ?></span><div><strong><?= $e($a['name']) ?></strong><div class="muted">Custom<?= (int) $a['owner_id'] !== (int) $user['id'] ? ' &middot; by ' . $e($a['owner_name']) : '' ?></div></div></div></td>
        <td data-label="On"><input class="switch" type="checkbox" name="rules[<?= $e($id) ?>][enabled]" value="1" <?= (int) $r['enabled'] ? 'checked' : '' ?> aria-label="Enable <?= $e($a['name']) ?>"></td>
        <td data-label="From"><input type="number" class="num" name="rules[<?= $e($id) ?>][min_count]" min="1" max="100000" value="<?= (int) $r['min_count'] ?>" aria-label="Minimum gifts for <?= $e($a['name']) ?>"></td>
        <td data-label="Preview"><a class="btn btn-small" href="preview.php?id=<?= (int) $a['id'] ?>" target="_blank" rel="noopener"><?= View::icon('external') ?> Open</a></td>
        <td data-label="Test"><button type="submit" form="test-form" name="alert_id" value="<?= $e($id) ?>" class="btn btn-small"><?= View::icon('play') ?> Send</button></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$catalog && !$custom): ?>
      <tr class="empty"><td colspan="5" class="muted">No alerts yet. <a href="my-alerts.php">Submit one</a> or add one shared by another streamer.</td></tr>
    <?php endif; ?>
    </tbody>
  </table>
  </div>
  <p class="hint">Want more? <a href="my-alerts.php">Add a custom alert</a>. Custom alerts appear here once they have been approved.</p>

  <hr class="divider">

  <div class="card-head">
    <span class="ico"><?= View::icon('users') ?></span>
    <div><h2>Other subscriber events</h2><p>Twitch only reports a resub when the viewer shares it in chat. Gifted subs are never counted twice.</p></div>
  </div>
  <div class="grid-2">
    <div class="option-box">
      <label class="check"><input class="switch" type="checkbox" name="react_sub" value="1" <?= (int) $settings['react_sub'] ? 'checked' : '' ?>> <strong>New subscriptions</strong></label>
      <select name="sub_alert" aria-label="Alert for new subs"><?= $alertOptions($settings['sub_alert']) ?></select>
    </div>
    <div class="option-box">
      <label class="check"><input class="switch" type="checkbox" name="react_resub" value="1" <?= (int) $settings['react_resub'] ? 'checked' : '' ?>> <strong>Resubscriptions</strong></label>
      <select name="resub_alert" aria-label="Alert for resubs"><?= $alertOptions($settings['resub_alert']) ?></select>
    </div>
  </div>

  <hr class="divider">

  <div class="card-head">
    <span class="ico"><?= View::icon('sliders') ?></span>
    <div><h2>Overlay</h2></div>
  </div>
  <label class="check"><input class="switch" type="checkbox" name="overlay_enabled" value="1" <?= (int) $settings['overlay_enabled'] ? 'checked' : '' ?>> <span>Alerts on <span class="muted">(turn off to pause everything)</span></span></label>
  <div class="field top-gap">
    <span class="label">Volume</span>
    <div class="slider-row">
      <input type="range" name="volume" min="0" max="100" value="<?= (int) $settings['volume'] ?>" data-output="volume-out" aria-label="Volume">
      <span><output id="volume-out"><?= (int) $settings['volume'] ?></output>%</span>
    </div>
  </div>

  <div class="savebar">
    <button type="submit" class="btn btn-primary"><?= View::icon('check') ?> Save settings</button>
    <span class="muted">Changes apply to the overlay within a few seconds.</span>
  </div>
</form>

<section class="card">
  <div class="card-head">
    <span class="ico"><?= View::icon('play') ?></span>
    <div><h2>Try a gift count</h2><p>Simulates a gifted-sub batch using your saved rules above.</p></div>
  </div>
  <form method="post" action="actions.php" class="row">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="test_gift">
    <label class="suffix">Simulate <input type="number" class="num" name="count" min="1" max="100000" value="15" required> gifted subs</label>
    <button type="submit" class="btn"><?= View::icon('play') ?> Send test</button>
  </form>
</section>

<section class="card">
  <div class="card-head">
    <span class="ico"><?= View::icon('clock') ?></span>
    <div><h2>Recent events</h2><p>Updates automatically.</p></div>
  </div>
  <div class="table-wrap">
  <table class="table" id="events-table" data-feed="api/events.php">
    <thead><tr><th>When</th><th>Type</th><th>From</th><th>Gifts / months</th><th>Alert</th></tr></thead>
    <tbody>
    <?php if (!$events): ?>
      <tr class="empty"><td colspan="5" class="muted">Nothing yet. Send a test above, or wait for the next sub.</td></tr>
    <?php endif; ?>
    <?php foreach ($events as $ev): ?>
      <tr>
        <td title="<?= $e($ev['created_at']) ?> UTC"><?= $e(View::ago($ev['created_at'])) ?></td>
        <td><span class="chip"><?= $e($typeNames[$ev['type']] ?? $ev['type']) ?></span></td>
        <td><?= $e($ev['actor'] ?? '') ?></td>
        <td><?= $e($ev['gift_count'] ?? $ev['months'] ?? '') ?></td>
        <td><?= $ev['alert_id'] ? $e($labels[$ev['alert_id']] ?? $ev['alert_id']) : '<span class="muted">' . $e($ev['note'] ?? 'none') . '</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>

<details class="card danger">
  <summary><strong>Delete my data</strong></summary>
  <p>Unlinks Twitch events and removes your settings, history and any custom alerts you submitted. You can log in again at any time.</p>
  <form method="post" action="actions.php" class="row" data-confirm="This permanently deletes your account data. Continue?">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="delete_account">
    <label class="suffix">Type <code>DELETE</code> <input type="text" name="confirm" autocomplete="off" size="8"></label>
    <button type="submit" class="btn btn-danger"><?= View::icon('trash') ?> Delete everything</button>
  </form>
</details>
