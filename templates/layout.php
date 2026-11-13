<?php
/** @var FoundrySite\Site $site */
/** @var array<string, mixed> $page */
/** @var string $content */
$isDocs = $page['layout'] === 'docs';
$og = $site->url($site->asset('img/og-image.png'));
?>
<!doctype html>
<html lang="<?= esc($site->config['language']) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($page['title']) ?></title>
<meta name="description" content="<?= esc($page['description']) ?>">
<link rel="canonical" href="<?= esc($page['canonical']) ?>">
<meta name="robots" content="<?= esc($page['robots']) ?>">
<meta name="theme-color" media="(prefers-color-scheme: light)" content="#ffffff">
<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#0f1115">
<meta name="color-scheme" content="light dark">
<meta property="og:type" content="<?= esc($page['type']) ?>">
<meta property="og:site_name" content="<?= esc($site->config['name']) ?>">
<meta property="og:locale" content="en_US">
<meta property="og:title" content="<?= esc($page['title']) ?>">
<meta property="og:description" content="<?= esc($page['description']) ?>">
<meta property="og:url" content="<?= esc($page['canonical']) ?>">
<meta property="og:image" content="<?= esc($og) ?>">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Foundry — standalone PHP utility classes">
<?php if ($page['type'] === 'article' && $page['lastmod'] !== null): ?>
<meta property="article:modified_time" content="<?= esc($page['lastmod']) ?>">
<?php endif ?>
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= esc($page['title']) ?>">
<meta name="twitter:description" content="<?= esc($page['description']) ?>">
<meta name="twitter:image" content="<?= esc($og) ?>">
<?php if ($page['markdown'] !== null): ?>
<link rel="alternate" type="text/markdown" href="<?= esc($page['markdown']) ?>" title="Markdown version">
<?php endif ?>
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="stylesheet" href="<?= esc($site->asset('css/site.css')) ?>">
<script src="<?= esc($site->asset('js/theme.js')) ?>"></script>
<script src="<?= esc($site->asset('js/app.js')) ?>" defer></script>
<script src="<?= esc($site->asset('vendor/alpine-csp.min.js')) ?>" defer></script>
<?php foreach ($page['jsonld'] as $ld): ?>
<?= FoundrySite\Site::jsonLd($ld) ?>

<?php endforeach ?>
</head>
<body class="<?= $isDocs ? 'is-docs' : 'is-home' ?>">
<div class="app" x-data="site" x-on:keydown.window="onKey">
<a class="skip-link" href="#main">Skip to content</a>

<header class="topbar">
  <div class="topbar-inner">
    <?php if ($isDocs): ?>
    <button type="button" class="icon-btn menu-btn" x-cloak x-on:click="toggleDrawer" aria-controls="sidebar" x-bind:aria-expanded="drawerExpanded" aria-label="Open navigation">
      <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" stroke="currentColor" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
    </button>
    <?php endif ?>
    <a class="brand" href="/" aria-label="Foundry home">
      <svg class="brand-mark" viewBox="0 0 32 32" width="28" height="28" aria-hidden="true"><rect width="32" height="32" rx="7" fill="var(--accent)"/><path d="M10 8h13v4h-8.5v3.5H21v4h-6.5V25H10z" fill="#fff"/></svg>
      <span>Foundry</span>
    </a>
    <nav class="topnav" aria-label="Main">
      <a href="/docs/"<?= str_starts_with($page['path'], '/docs/') ? ' aria-current="true"' : '' ?>>Docs</a>
      <a href="/docs/installation/#download">Download</a>
    </nav>
    <div class="topbar-actions">
      <button type="button" class="search-btn" x-cloak x-on:click="openSearch" aria-label="Search documentation" aria-keyshortcuts="Control+K Meta+K /">
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2" fill="none"/><path d="m20 20-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <span class="search-btn-label">Search</span>
        <kbd class="search-btn-kbd">Ctrl K</kbd>
      </button>
      <button type="button" class="icon-btn" x-cloak x-on:click="toggleTheme" x-bind:aria-label="themeLabel" x-bind:title="themeLabel">
        <svg x-show="isDark" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="12" cy="12" r="4.5" fill="currentColor"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
        <svg x-show="isLight" viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path d="M20.5 14.5A8.5 8.5 0 0 1 9.5 3.5a8.5 8.5 0 1 0 11 11z" fill="currentColor"/></svg>
      </button>
    </div>
  </div>
</header>

<?php if ($isDocs): ?>
<div class="shell">
  <aside class="sidebar" id="sidebar" x-bind:class="drawerClass" aria-label="Documentation navigation">
    <?= $site->render('partials/sidebar', ['current' => $page['path']]) ?>
  </aside>
  <div class="drawer-backdrop" x-cloak x-show="drawerOpen" x-on:click="closeDrawer"></div>

  <main id="main" class="content" tabindex="-1">
    <?php if ($page['breadcrumbs'] !== []): ?>
    <nav class="breadcrumbs" aria-label="Breadcrumb">
      <ol>
        <?php foreach ($page['breadcrumbs'] as $i => [$name, $url]): ?>
        <?php if ($i === array_key_last($page['breadcrumbs'])): ?>
        <li><span aria-current="page"><?= esc($name) ?></span></li>
        <?php else: ?>
        <li><a href="<?= esc($url) ?>"><?= esc($name) ?></a></li>
        <?php endif ?>
        <?php endforeach ?>
      </ol>
    </nav>
    <?php endif ?>

    <?= $content ?>

    <?php if ($page['prev'] !== null || $page['next'] !== null): ?>
    <nav class="pager" aria-label="Previous and next pages">
      <?php if ($page['prev'] !== null): ?>
      <a class="pager-prev" href="<?= esc($page['prev']['url']) ?>" rel="prev"><span>Previous</span><strong><?= esc($page['prev']['title']) ?></strong></a>
      <?php else: ?><span></span><?php endif ?>
      <?php if ($page['next'] !== null): ?>
      <a class="pager-next" href="<?= esc($page['next']['url']) ?>" rel="next"><span>Next</span><strong><?= esc($page['next']['title']) ?></strong></a>
      <?php endif ?>
    </nav>
    <?php endif ?>

    <div class="page-meta">
      <?php if ($page['lastmod'] !== null): ?>
      <span>Last updated <time datetime="<?= esc($page['lastmod']) ?>"><?= esc(date('F j, Y', (int) strtotime($page['lastmod']))) ?></time></span>
      <?php endif ?>
      <?php if ($page['markdown'] !== null): ?>
      <a href="<?= esc($page['markdown']) ?>">View as Markdown</a>
      <?php endif ?>
    </div>
  </main>

  <?php if ($page['toc'] !== []): ?>
  <aside class="toc" aria-label="On this page">
    <p class="toc-title">On this page</p>
    <ul>
      <?php foreach ($page['toc'] as $item): ?>
      <li class="toc-l<?= (int) $item['level'] ?>"><a href="#<?= esc($item['id']) ?>"><?= esc($item['text']) ?></a></li>
      <?php endforeach ?>
    </ul>
  </aside>
  <?php endif ?>
</div>
<?php else: ?>
<main id="main" class="home" tabindex="-1">
  <?= $content ?>
</main>
<?php endif ?>

<footer class="site-footer">
  <div class="site-footer-inner">
    <p><strong>Foundry</strong> — standalone, single-file PHP utility classes. &copy; <?= esc($site->buildYear) ?> · <?= esc($site->config['license']) ?> license.</p>
    <nav aria-label="Footer">
      <a href="/docs/">Documentation</a>
      <a href="/docs/installation/">Installation</a>
      <a href="/llms.txt">llms.txt</a>
      <a href="/sitemap.xml">Sitemap</a>
    </nav>
  </div>
</footer>

<div class="search-modal" x-cloak x-show="searchOpen" role="dialog" aria-modal="true" aria-label="Search documentation" x-on:keydown="onSearchKey">
  <div class="search-backdrop" x-on:click="closeSearch"></div>
  <div class="search-panel">
    <div class="search-field">
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><circle cx="11" cy="11" r="7" stroke="currentColor" stroke-width="2" fill="none"/><path d="m20 20-4-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
      <input x-ref="input" type="search" placeholder="Search classes, methods and guides" autocomplete="off" spellcheck="false" aria-label="Search" aria-controls="search-results" aria-autocomplete="list" x-bind:aria-activedescendant="activeId" x-on:input="onInput">
      <button type="button" class="kbd-btn" x-on:click="closeSearch" aria-label="Close search">Esc</button>
    </div>
    <ul id="search-results" class="search-results" role="listbox" aria-label="Results" x-ref="list">
      <template x-for="item in results" x-bind:key="item.id">
        <li role="option" x-bind:id="item.id" x-bind:aria-selected="item.selected" x-bind:class="item.cls" x-bind:data-index="item.i" x-on:mousemove="hover" x-on:click="closeSearch">
          <a x-bind:href="item.u" tabindex="-1">
            <span class="r-kind" x-text="item.kl"></span>
            <span class="r-main"><span class="r-title" x-text="item.t"></span><span class="r-desc" x-text="item.d"></span></span>
            <span class="r-sec" x-text="item.s"></span>
          </a>
        </li>
      </template>
    </ul>
    <p class="search-status" x-show="showStatus" x-text="statusText"></p>
    <div class="search-foot" aria-hidden="true"><span><kbd>↑</kbd><kbd>↓</kbd> navigate</span><span><kbd>Enter</kbd> open</span><span><kbd>Esc</kbd> close</span></div>
  </div>
</div>
</div>
</body>
</html>
