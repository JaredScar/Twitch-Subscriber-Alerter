<?php
/**
 * @var array<string,mixed> $user
 * @var list<array<string,mixed>> $users
 * @var array<int,array<string,string>> $subs
 * @var array<int,int> $counts
 * @var array<int,string> $lastEvent
 * @var array<int,string> $overlaySeen
 * @var array<int,int> $customCounts
 * @var list<string> $eventTypes
 * @var string $callbackUrl
 */

use App\Auth;
use App\View;

$e = [View::class, 'e'];
$csrf = Auth::csrf();
$okStatus = ['enabled', 'webhook_callback_verification_pending'];
?>
<div class="page-head">
  <div class="grow">
    <h1>Streamers</h1>
    <p class="lead"><?= count($users) ?> linked &middot; webhook callback <code><?= $e($callbackUrl) ?></code></p>
  </div>
  <form method="post" action="actions.php" data-confirm="Re-check every streamer's EventSub subscriptions with Twitch?">
    <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="admin_resync_all">
    <button type="submit" class="btn"><?= View::icon('refresh') ?> Re-sync everyone</button>
  </form>
</div>

<section class="card">
  <div class="table-wrap">
  <table class="table table-stack">
    <thead>
      <tr><th>Streamer</th><th>Twitch events</th><th>Overlay</th><th>Alerts logged</th><th>Custom</th><th>Last event</th><th>Last login</th><th>Status</th><th>Actions</th></tr>
    </thead>
    <tbody>
    <?php foreach ($users as $u):
        $uid = (int) $u['id'];
        $st = $subs[$uid] ?? [];
        $linked = 0;
        foreach ($eventTypes as $t) {
            if (in_array($st[$t] ?? '', $okStatus, true)) { $linked++; }
        }
        $self = $uid === (int) $user['id'];
        $seen = $overlaySeen[$uid] ?? null;
        $live = $seen !== null && (time() - (int) strtotime($seen . ' UTC')) < 45;
    ?>
      <tr>
        <td class="name-td">
          <div class="name-cell">
            <?php if (!empty($u['profile_image_url'])): ?><img class="avatar-sm" src="<?= $e($u['profile_image_url']) ?>" alt="" width="32" height="32"><?php endif; ?>
            <div>
              <a href="https://twitch.tv/<?= $e($u['login']) ?>" target="_blank" rel="noopener"><?= $e($u['display_name']) ?></a>
              <?php if ((int) $u['is_admin']): ?><span class="chip chip-accent">admin</span><?php endif; ?>
            </div>
          </div>
        </td>
        <td data-label="Twitch events"><span class="chip <?= $linked === count($eventTypes) ? 'chip-ok' : 'chip-bad' ?>"><?= $linked ?>/<?= count($eventTypes) ?> linked</span></td>
        <td data-label="Overlay"><?php if ($live): ?><span class="chip chip-ok">connected</span><?php elseif ($seen): ?><span class="chip" title="<?= $e($seen) ?> UTC">seen <?= $e(View::ago($seen)) ?></span><?php else: ?><span class="muted">never</span><?php endif; ?></td>
        <td data-label="Alerts logged"><?= (int) ($counts[$uid] ?? 0) ?></td>
        <td data-label="Custom"><?= (int) ($customCounts[$uid] ?? 0) ?></td>
        <td data-label="Last event"><?= isset($lastEvent[$uid]) ? $e(View::ago($lastEvent[$uid])) : '<span class="muted">never</span>' ?></td>
        <td data-label="Last login" title="<?= $e($u['last_login_at']) ?> UTC"><?= $e(View::ago($u['last_login_at'])) ?></td>
        <td data-label="Status"><?= (int) $u['is_disabled'] ? '<span class="chip chip-bad">disabled</span>' : '<span class="chip chip-ok">active</span>' ?></td>
        <td class="actions">
          <form method="post" action="actions.php" class="inline">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <button type="submit" name="action" value="admin_resync" class="btn btn-small">Re-sync</button>
            <?php if (!$self): ?>
              <button type="submit" name="action" value="admin_toggle" class="btn btn-small"><?= (int) $u['is_disabled'] ? 'Enable' : 'Disable' ?></button>
            <?php endif; ?>
          </form>
          <?php if (!$self): ?>
          <form method="post" action="actions.php" class="inline" data-confirm="Delete <?= $e($u['display_name']) ?> and all their data?">
            <input type="hidden" name="_csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="user_id" value="<?= $uid ?>">
            <button type="submit" name="action" value="admin_delete" class="btn btn-small btn-danger">Delete</button>
          </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
