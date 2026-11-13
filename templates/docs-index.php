<?php
/** @var FoundrySite\Site $site */
$count = $site->classCount();
?>
<article class="prose doc">
  <header class="doc-head">
    <p class="eyebrow">Documentation</p>
    <h1>Foundry documentation</h1>
    <p class="lead">Foundry is a curated suite of <?= $count ?> production-grade, single-file PHP utility classes. Any problem triggers an explicit, descriptive exception: never a silent <code>false</code>, an unexpected <code>null</code> or an unhandled warning.</p>
  </header>

  <p>Each utility is <strong>a single standalone file</strong> in the <code>Foundry</code> namespace. Copy only what you need: there are no Composer vendor trees and no third-party dependencies in production. The classes are written for PHP 8.3+ and are also available as automatically downgraded PHP 7.4 builds.</p>

  <?= $site->renderer->heading(2, 'getting-started', 'Getting started') ?>
  <div class="cards">
    <?php foreach ($site->guides as $guide): ?>
    <a class="card" href="/docs/<?= esc($guide['slug']) ?>/">
      <span class="card-kicker"><?= esc($guide['group']) ?></span>
      <strong><?= esc($guide['title']) ?></strong>
      <span><?= esc($guide['description']) ?></span>
    </a>
    <?php endforeach ?>
  </div>

  <?php foreach ($site->lib->categories as $cat): ?>
  <?= $site->renderer->heading(2, $cat['slug'], esc($cat['name'])) ?>
  <div class="table-wrap">
    <table class="catalog-table">
      <thead><tr><th scope="col">Class</th><th scope="col">Overview</th></tr></thead>
      <tbody>
        <?php foreach ($cat['classes'] as $name): $c = $site->lib->classes[$name]; ?>
        <tr>
          <td><a class="code-link" href="<?= esc($site->classPath($c)) ?>"><code><?= esc($name) ?></code></a></td>
          <td><?= $site->linker->prose(FoundrySite\Support::plain($c['overview'])) ?></td>
        </tr>
        <?php endforeach ?>
      </tbody>
    </table>
  </div>
  <?php endforeach ?>
</article>
