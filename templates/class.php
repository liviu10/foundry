<?php
/** @var FoundrySite\Site $site */
/** @var array<string, mixed> $class */
/** @var string $body */
$methodCount = count($class['methods']) + count($class['functions']);
?>
<article class="prose doc">
  <header class="doc-head">
    <p class="eyebrow"><a href="/docs/#<?= esc($class['category']['slug']) ?>"><?= esc($class['category']['name']) ?></a></p>
    <h1><?= esc($class['name']) ?></h1>
    <p class="lead"><?= $site->linker->prose($class['lead']) ?></p>
    <ul class="facts" aria-label="Class facts">
      <li><span>Namespace</span><code><?= esc($class['fqcn']) ?></code></li>
      <li><span>File</span><code><?= esc($class['file']) ?></code></li>
      <li><span>Version</span><strong><?= esc($class['version']) ?></strong></li>
      <li><span>Requires</span><strong><?= esc($class['since']) ?>+</strong><?php foreach ($class['extensions'] as $ext): ?> <code class="ext">ext-<?= esc($ext) ?></code><?php endforeach ?></li>
      <li><span>API</span><strong><?= $methodCount ?> public <?= $methodCount === 1 ? 'member' : 'members' ?></strong></li>
    </ul>
  </header>
  <?= $body ?>
</article>
