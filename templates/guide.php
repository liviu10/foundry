<?php
/** @var FoundrySite\Site $site */
/** @var array<string, mixed> $guide */
/** @var string $body */
?>
<article class="prose doc">
  <header class="doc-head">
    <p class="eyebrow"><?= esc($guide['group']) ?></p>
    <h1><?= esc($guide['title']) ?></h1>
    <p class="lead"><?= esc($guide['description']) ?></p>
  </header>
  <?= $body ?>
</article>
