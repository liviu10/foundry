<?php
/** @var FoundrySite\Site $site */
/** @var string $current */
?>
<nav class="nav">
  <?php foreach ($site->nav as $group): ?>
  <div class="nav-group">
    <p class="nav-title"><?= esc($group['title']) ?></p>
    <ul>
      <?php foreach ($group['items'] as $item): ?>
      <li><a href="<?= esc($item['url']) ?>"<?= $item['url'] === $current ? ' aria-current="page"' : '' ?>><?= esc($item['title']) ?></a></li>
      <?php endforeach ?>
    </ul>
  </div>
  <?php endforeach ?>
</nav>
