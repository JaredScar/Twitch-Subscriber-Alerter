<?php
/**
 * @var array<string,mixed> $user
 * @var array<string,array{label:string,min:int,page:?string}> $catalog
 * @var list<array<string,mixed>> $mine
 * @var list<array<string,mixed>> $library
 */

use App\Auth;
use App\CustomAlerts;
use App\View;

$e = [View::class, 'e'];
$csrf = Auth::csrf();
$positions = ['tl' => 'Top left', 't' => 'Top', 'tr' => 'Top right', 'l' => 'Left', 'c' => 'Centre', 'r' => 'Right', 'bl' => 'Bottom left', 'b' => 'Bottom', 'br' => 'Bottom right'];
$statusChip = static function (array $a): string {
    return match ($a['status']) {
        'pending' => '<span class="chip chip-warn">' . View::icon('clock') . ' In review</span>',
        'approved' => '<span class="chip chip-ok">' . View::icon('check') . ' Approved' . ($a['visibility'] === 'public' ? ' &middot; shared with everyone' : ' &middot; only you') . '</span>',
        default => '<span class="chip chip-bad">' . View::icon('x') . ' Not approved</span>',
    };
};
$kindMeta = static fn(array $a): string => $a['kind'] === 'html'
    ? 'Custom HTML'
    : (($a['config']['media']['type'] ?? 'image') === 'video' ? 'Video' : 'Image') . (!empty($a['config']['sound']) ? ' + sound' : '');
$post = static fn(string $action, int $id, string $label, string $class, string $icon, string $confirm = ''): string =>
    '<form method="post" action="alert-actions.php" class="inline"' . ($confirm !== '' ? ' data-confirm="' . View::e($confirm) . '"' : '') . '>'
    . '<input type="hidden" name="_csrf" value="' . View::e(Auth::csrf()) . '">'
    . '<input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="alert_id" value="' . $id . '">'
    . '<button type="submit" class="btn btn-small ' . $class . '">' . View::icon($icon) . ' ' . $label . '</button></form>';
?>
<div class="page-head">
  <div class="grow">
    <h1>Alerts</h1>
    <p class="lead">Use the included alerts as they are, or bring your own. Submitted alerts are checked by the site owner before they can play on any stream.</p>
  </div>
  <a class="btn btn-primary" href="#submit"><?= View::icon('plus') ?> Submit an alert</a>
</div>

<?php if ($catalog): ?>
<div class="section-title"><h2>Included</h2><p>Available to everyone. Turn them on and set the gift minimums on the <a href="dashboard.php">dashboard</a>.</p></div>
<div class="cards">
  <?php foreach ($catalog as $a): ?>
    <div class="acard">
      <div class="acard-top">
        <span class="tier"><?= (int) $a['min'] ?></span>
        <div>
          <h3><?= $e($a['label']) ?></h3>
          <div class="acard-meta"><span>Included</span><span>from <?= (int) $a['min'] ?> gifted subs</span></div>
        </div>
      </div>
      <?php if ($a['page'] !== null): ?>
      <div class="acard-actions">
        <a class="btn btn-small" href="alerts/<?= $e($a['page']) ?>?bg=1&amp;mute=1" target="_blank" rel="noopener"><?= View::icon('external') ?> Preview</a>
      </div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="section-title"><h2>Your submissions</h2><p><?= count($mine) ?> of <?= CustomAlerts::MAX_PER_USER ?> used</p></div>
<?php if (!$mine): ?>
  <div class="card empty"><?= View::icon('inbox') ?>You haven't submitted anything yet. Use the form below to send your first alert for review.</div>
<?php else: ?>
<div class="cards">
  <?php foreach ($mine as $a): $id = (int) $a['id']; ?>
    <div class="acard">
      <div class="acard-top">
        <span class="thumb"><?= View::icon($a['kind'] === 'html' ? 'code' : 'image') ?></span>
        <div class="grow">
          <h3><?= $e($a['name']) ?></h3>
          <div class="acard-meta"><span><?= $e($kindMeta($a)) ?></span><span title="<?= $e($a['created_at']) ?> UTC"><?= $e(View::ago($a['created_at'])) ?></span></div>
        </div>
      </div>
      <div class="chips"><?= $statusChip($a) ?><?php if ($a['status'] === 'approved' && (int) $a['added']): ?><span class="chip chip-accent">In use</span><?php endif; ?></div>
      <?php if (!empty($a['description'])): ?><div class="muted"><?= $e($a['description']) ?></div><?php endif; ?>
      <?php if ($a['status'] === 'rejected'): ?>
        <div class="acard-note bad"><strong>Reason:</strong> <?= $e($a['review_note'] ?: 'No reason given.') ?></div>
      <?php elseif ($a['status'] === 'approved' && !empty($a['review_note'])): ?>
        <div class="acard-note"><strong>Reviewer note:</strong> <?= $e($a['review_note']) ?></div>
      <?php elseif ($a['status'] === 'pending'): ?>
        <div class="acard-note">Waiting for review. It will not play on stream until it is approved.</div>
      <?php endif; ?>
      <div class="acard-actions">
        <a class="btn btn-small" href="preview.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= View::icon('play') ?> Preview</a>
        <?php if ($a['status'] === 'approved'): ?>
          <?= (int) $a['added']
              ? $post('unuse', $id, 'Stop using', '', 'stop')
              : $post('use', $id, 'Use this alert', 'btn-primary', 'check') ?>
        <?php endif; ?>
        <?= $post('delete', $id, 'Delete', 'btn-danger', 'trash', 'Delete "' . $a['name'] . '"? ' . ($a['status'] === 'approved' ? 'It will stop playing on stream. ' : '') . 'This cannot be undone.') ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($library): ?>
<div class="section-title"><h2>Shared by other streamers</h2><p>Approved by the site owner and open to everyone.</p></div>
<div class="cards">
  <?php foreach ($library as $a): $id = (int) $a['id']; ?>
    <div class="acard">
      <div class="acard-top">
        <span class="thumb"><?= View::icon($a['kind'] === 'html' ? 'code' : 'image') ?></span>
        <div class="grow">
          <h3><?= $e($a['name']) ?></h3>
          <div class="acard-meta"><span><?= $e($kindMeta($a)) ?></span><span>by <?= $e($a['owner_name']) ?></span></div>
        </div>
      </div>
      <?php if (!empty($a['description'])): ?><div class="muted"><?= $e($a['description']) ?></div><?php endif; ?>
      <div class="acard-actions">
        <a class="btn btn-small" href="preview.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= View::icon('play') ?> Preview</a>
        <?= (int) $a['added']
            ? $post('unuse', $id, 'Stop using', '', 'stop')
            : $post('use', $id, 'Use this alert', 'btn-primary', 'check') ?>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<section class="card" id="submit">
  <div class="card-head">
    <span class="ico"><?= View::icon('upload') ?></span>
    <div><h2>Submit your own alert</h2><p>The site owner reviews every submission and decides whether it goes live, and whether it stays private to you or is shared with all streamers. Approved alerts are locked: to change one, delete it and submit a new version.</p></div>
  </div>

  <form method="post" action="alert-actions.php" enctype="multipart/form-data">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="submit">

    <div class="field-row">
      <label class="field"><span class="label">Name</span><input type="text" name="name" maxlength="60" required minlength="3" placeholder="e.g. Neon celebration"></label>
      <label class="field"><span class="label">Description <span class="opt">optional</span></span><input type="text" name="description" maxlength="255" placeholder="Shown to the reviewer"></label>
    </div>

    <div class="field">
      <span class="label">Type</span>
      <div class="seg" data-kind-switch>
        <label><input type="radio" name="kind" value="media" checked><span><?= View::icon('image') ?> Picture / video</span></label>
        <label><input type="radio" name="kind" value="html"><span><?= View::icon('code') ?> Custom HTML</span></label>
      </div>
    </div>

    <div class="kind-panel" data-kind="media">
      <div class="field-row">
        <label class="field"><span class="label">Picture or video</span><input type="file" name="media" accept="image/png,image/jpeg,image/gif,image/webp,video/webm,video/mp4" required>
          <span class="hint">PNG, JPG, GIF, WebP, WebM or MP4 &middot; up to <?= $e(CustomAlerts::formatBytes(CustomAlerts::MEDIA_MAX)) ?>. Videos loop for the alert's duration.</span></label>
        <label class="field"><span class="label">Sound <span class="opt">optional</span></span><input type="file" name="sound" accept="audio/ogg,audio/mpeg,audio/wav">
          <span class="hint">OGG, MP3 or WAV &middot; up to <?= $e(CustomAlerts::formatBytes(CustomAlerts::SOUND_MAX)) ?>.</span></label>
      </div>

      <div class="field-row">
        <label class="field"><span class="label">Text</span><input type="text" name="text" maxlength="120" value="<?= $e(CustomAlerts::DEFAULT_TEXT) ?>">
          <span class="hint">Use <code>{name}</code> for the gifter and <code>{count}</code> for the number of subs.</span></label>
        <label class="field"><span class="label">Text position</span>
          <select name="textPos"><option value="below">Below the picture</option><option value="above">Above the picture</option><option value="none">No text</option></select></label>
        <div class="field"><span class="label">Text colour</span><input type="color" name="textColor" value="#ffffff" aria-label="Text colour"></div>
      </div>

      <div class="field-row">
        <label class="field"><span class="label">Text size</span><div class="suffix"><input class="num" type="number" name="fontSize" min="16" max="160" value="56"> px</div></label>
        <label class="field"><span class="label">Picture width</span><div class="suffix"><input class="num" type="number" name="width" min="5" max="100" value="40"> % of screen</div></label>
        <label class="field"><span class="label">Show for</span><div class="suffix"><input class="num" type="number" name="duration" min="1" max="30" value="8"> seconds</div></label>
        <label class="field"><span class="label">Animation</span>
          <select name="animation"><option value="pop">Pop</option><option value="fade">Fade</option><option value="slide">Slide up</option></select></label>
      </div>

      <div class="field">
        <span class="label">Position on screen</span>
        <div class="pos-grid" role="radiogroup" aria-label="Position on screen">
          <?php foreach ($positions as $key => $label): ?>
            <label title="<?= $e($label) ?>"><input type="radio" name="position" value="<?= $key ?>" <?= $key === 'c' ? 'checked' : '' ?>><span></span></label>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <div class="kind-panel" data-kind="html" hidden>
      <div class="callout">
        <p>A single self-contained <code>.html</code> file. It runs in a locked-down sandbox on the overlay: <strong>no network access, no cookies, no navigation</strong>, so images, fonts and sounds must be embedded (<code>data:</code> URIs). Alert details arrive as a <code>postMessage</code>.</p>
        <p><a href="alerts/custom-template.html" download>Download a starter template</a></p>
      </div>
      <div class="field-row top-gap">
        <label class="field"><span class="label">HTML file</span><input type="file" name="html" accept=".html,.htm,text/html" required>
          <span class="hint">Up to <?= $e(CustomAlerts::formatBytes(CustomAlerts::HTML_MAX)) ?>.</span></label>
        <label class="field"><span class="label">Show for</span><div class="suffix"><input class="num" type="number" name="html_duration" min="1" max="60" value="10"> seconds</div>
          <span class="hint">Your alert can also finish early by sending <code>{type:'done'}</code>.</span></label>
      </div>
    </div>

    <div class="savebar">
      <button type="submit" class="btn btn-primary"><?= View::icon('upload') ?> Submit for review</button>
      <span class="muted">You can have up to <?= CustomAlerts::MAX_PENDING_PER_USER ?> submissions waiting at once.</span>
    </div>
  </form>
</section>
