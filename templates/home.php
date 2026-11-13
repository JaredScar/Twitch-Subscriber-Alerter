<?php
/**
 * @var bool $configured
 * @var bool $restricted
 * @var array<string,array{label:string,min:int,page:?string}> $catalog
 */

use App\View;
?>
<section class="hero">
  <span class="eyebrow"><?= View::icon('zap') ?> Lag-free sub alerts for OBS</span>
  <h1>Sub alerts that <em>don't lag</em> your stream</h1>
  <p>Link your Twitch channel and get animated gifted-sub alerts as a lightweight browser source. No video files in OBS: they are drawn live in the browser. Use the built-in alerts, or submit your own.</p>
  <?php if ($configured): ?>
    <a class="btn btn-primary btn-lg" href="login.php"><?= View::icon('link') ?> Log in with Twitch</a>
    <?php if (!empty($restricted)): ?>
      <small>This is a private site: only accounts approved by the site owner can log in.</small>
    <?php endif; ?>
    <small>We only ask to read your channel's subscriptions (<code>channel:read:subscriptions</code>). We never post or chat as you.</small>
  <?php else: ?>
    <div class="notice notice-warn"><?= View::icon('alert') ?><span>Twitch login isn't configured yet. Set <code>TWITCH_CLIENT_ID</code>, <code>TWITCH_CLIENT_SECRET</code> and <code>APP_URL</code> in <code>.env</code>.</span></div>
  <?php endif; ?>
</section>

<ol class="steps">
  <li><h3>Link Twitch</h3><p>Log in once. The site subscribes to your channel's sub events automatically.</p></li>
  <li><h3>Add the overlay</h3><p>Paste your private overlay URL into an OBS Browser Source (1920&times;1080).</p></li>
  <li><h3>Tune &amp; test</h3><p>Choose which alert plays at which gift count and fire test alerts from the dashboard.</p></li>
</ol>

<?php if ($catalog): ?>
<div class="section-title"><h2>Included alerts</h2><p>Ready to use for every streamer. The highest tier you have reached plays.</p></div>
<div class="tiers">
  <?php foreach ($catalog as $a): ?>
    <div class="tier-card"><span class="tier"><?= (int) $a['min'] ?></span><div><strong><?= View::e($a['label']) ?></strong><div class="muted">gifted subs</div></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="card top-gap">
  <div class="card-head">
    <span class="ico"><?= View::icon('upload') ?></span>
    <div><h2>Bring your own alert</h2><p>Upload your own image, GIF or video with a sound and text, or a custom HTML alert. Every submission is reviewed before it can go live.</p></div>
  </div>
</div>
