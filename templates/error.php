<?php
/** @var string $title */
/** @var string $message */
/** @var int $status */

use App\View;
?>
<section class="card narrow">
  <div class="card-head">
    <span class="ico"><?= View::icon('alert') ?></span>
    <div><h2><?= View::e($title) ?></h2><?php if ($message !== ''): ?><p><?= View::e($message) ?></p><?php endif; ?></div>
  </div>
  <a class="btn" href="index.php">Back to the start</a>
</section>
