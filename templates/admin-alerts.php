<?php
/**
 * @var string $tab
 * @var list<array<string,mixed>> $alerts
 * @var array<int,?string> $sources
 * @var array<string,int> $counts
 */

use App\Auth;
use App\CustomAlerts;
use App\View;

$e = [View::class, 'e'];
$csrf = Auth::csrf();
$tabs = ['pending' => 'Waiting for review', 'approved' => 'Approved', 'rejected' => 'Not approved'];
$hidden = static fn(string $action, int $id): string =>
    '<input type="hidden" name="_csrf" value="' . View::e(Auth::csrf()) . '"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="alert_id" value="' . $id . '">';
?>
<div class="page-head">
  <div class="grow">
    <h1>Review alerts</h1>
    <p class="lead">Nothing a streamer submits can play on any stream until you approve it. Approve as <strong>private</strong> (only the submitter can use it) or <strong>public</strong> (every streamer can add it).</p>
  </div>
</div>

<nav class="tabs" aria-label="Status">
  <?php foreach ($tabs as $key => $label): ?>
    <a href="admin-alerts.php?tab=<?= $key ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>><?= $e($label) ?>
      <?php if (!empty($counts[$key])): ?><span class="chip<?= $key === 'pending' ? ' chip-accent' : '' ?>"><?= (int) $counts[$key] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</nav>

<?php if (!$alerts): ?>
  <div class="card empty"><?= View::icon($tab === 'pending' ? 'check' : 'inbox') ?><?= $tab === 'pending' ? 'All caught up. Nothing is waiting for review.' : 'Nothing here.' ?></div>
<?php endif; ?>

<?php foreach ($alerts as $a):
    $id = (int) $a['id'];
    $scan = $a['scan'];
    $media = $scan['media'] ?? null;
    $sound = $scan['sound'] ?? null;
    $html = $scan['html'] ?? null;
?>
<article class="review">
  <div class="review-head">
    <span class="thumb"><?= View::icon($a['kind'] === 'html' ? 'code' : 'image') ?></span>
    <div class="grow">
      <h2><?= $e($a['name']) ?></h2>
      <div class="acard-meta">
        <span>by <a href="https://twitch.tv/<?= $e($a['owner_login']) ?>" target="_blank" rel="noopener"><?= $e($a['owner_name']) ?></a></span>
        <span><?= $a['kind'] === 'html' ? 'Custom HTML' : 'Picture / video' ?></span>
        <span title="<?= $e($a['created_at']) ?> UTC"><?= $e(View::ago($a['created_at'])) ?></span>
        <?php if ($a['status'] === 'approved'): ?><span class="chip <?= $a['visibility'] === 'public' ? 'chip-info' : '' ?>"><?= $e($a['visibility']) ?></span><?php endif; ?>
      </div>
      <?php if (!empty($a['description'])): ?><p class="muted"><?= $e($a['description']) ?></p><?php endif; ?>
    </div>
    <a class="btn" href="preview.php?id=<?= $id ?>" target="_blank" rel="noopener"><?= View::icon('play') ?> Preview</a>
  </div>

  <div class="review-body">
    <dl class="kv">
      <?php if ($media): ?>
        <dt>Picture / video</dt><dd><?= $e($media['mime']) ?>, <?= $e(CustomAlerts::formatBytes((int) $media['bytes'])) ?><?= !empty($media['width']) ? ', ' . (int) $media['width'] . '&times;' . (int) $media['height'] : '' ?></dd>
      <?php endif; ?>
      <?php if ($sound): ?>
        <dt>Sound</dt><dd><?= $e($sound['mime']) ?>, <?= $e(CustomAlerts::formatBytes((int) $sound['bytes'])) ?></dd>
      <?php endif; ?>
      <?php if ($a['kind'] === 'media'): $c = $a['config']; ?>
        <dt>Text</dt><dd><?= $c['textPos'] === 'none' ? '<span class="muted">none</span>' : '&ldquo;' . $e($c['text']) . '&rdquo; (' . $e($c['textPos']) . ')' ?></dd>
        <dt>Shown for</dt><dd><?= (int) $c['duration'] ?> s, <?= $e($c['animation']) ?>, <?= (int) $c['width'] ?>% wide</dd>
      <?php endif; ?>
      <?php if ($html): ?>
        <dt>File size</dt><dd><?= $e(CustomAlerts::formatBytes((int) $html['bytes'])) ?>, <?= (int) $html['data_uris'] ?> embedded data URI(s)</dd>
        <dt>Static scan</dt>
        <dd>
          <?php if (!empty($html['flags'])): ?>
            <div class="chips"><?php foreach ($html['flags'] as $f): ?><span class="chip chip-warn"><?= View::icon('alert') ?> <?= $e($f) ?></span><?php endforeach; ?></div>
          <?php else: ?>
            <span class="chip chip-ok"><?= View::icon('check') ?> nothing suspicious found</span>
          <?php endif; ?>
        </dd>
        <?php if (!empty($html['urls'])): ?>
          <dt>URLs found</dt><dd><?php foreach ($html['urls'] as $u): ?><div class="mono"><?= $e($u) ?></div><?php endforeach; ?></dd>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!empty($a['review_note'])): ?><dt>Note</dt><dd><?= $e($a['review_note']) ?></dd><?php endif; ?>
    </dl>
    <?php if ($a['kind'] === 'html' && $a['status'] === 'pending'): ?>
      <p class="hint">The overlay sandboxes this page (no network, cookies or navigation) whatever it contains, but read the source before sharing it with other streamers.</p>
      <details class="disclosure">
        <summary>View source</summary>
        <div class="body"><pre class="source"><?= $e($sources[$id] ?? '(file missing)') ?></pre></div>
      </details>
    <?php endif; ?>
  </div>

  <?php if ($a['status'] === 'pending'): ?>
  <div class="review-forms">
    <form method="post" action="alert-actions.php">
      <?= $hidden('admin_approve', $id) ?>
      <h4>Approve</h4>
      <div class="seg">
        <label><input type="radio" name="visibility" value="private" checked><span><?= View::icon('shield') ?> Private (submitter only)</span></label>
        <label><input type="radio" name="visibility" value="public"><span><?= View::icon('users') ?> Public (all streamers)</span></label>
      </div>
      <label class="field top-gap"><span class="label">Note <span class="opt">optional, shown to the streamer</span></span><input type="text" name="note" maxlength="255"></label>
      <button type="submit" class="btn btn-ok"><?= View::icon('check') ?> Approve</button>
    </form>
    <form method="post" action="alert-actions.php">
      <?= $hidden('admin_reject', $id) ?>
      <h4>Reject</h4>
      <label class="field"><span class="label">Reason <span class="opt">required, shown to the streamer</span></span><input type="text" name="note" maxlength="255" required></label>
      <button type="submit" class="btn btn-danger"><?= View::icon('x') ?> Reject</button>
    </form>
  </div>
  <?php elseif ($a['status'] === 'approved'): ?>
  <div class="review-forms">
    <form method="post" action="alert-actions.php">
      <?= $hidden('admin_revoke', $id) ?>
      <h4>Take offline</h4>
      <label class="field"><span class="label">Reason <span class="opt">required</span></span><input type="text" name="note" maxlength="255" required></label>
      <button type="submit" class="btn btn-danger"><?= View::icon('x') ?> Take offline</button>
    </form>
    <form method="post" action="alert-actions.php" data-confirm="Delete &quot;<?= $e($a['name']) ?>&quot; permanently? It is removed from every streamer who uses it.">
      <?= $hidden('admin_delete', $id) ?>
      <h4>Delete</h4>
      <p class="muted">Removes the alert and its files for good.</p>
      <button type="submit" class="btn btn-danger"><?= View::icon('trash') ?> Delete</button>
    </form>
  </div>
  <?php else: ?>
  <div class="review-forms">
    <form method="post" action="alert-actions.php" data-confirm="Delete this submission permanently?">
      <?= $hidden('admin_delete', $id) ?>
      <h4>Delete</h4>
      <button type="submit" class="btn btn-danger"><?= View::icon('trash') ?> Delete</button>
    </form>
  </div>
  <?php endif; ?>
</article>
<?php endforeach; ?>
