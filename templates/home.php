<?php
/** @var FoundrySite\Site $site */
$count = $site->classCount();
$hero = <<<'PHP'
use Foundry\Cast;
use Foundry\Date;
use Foundry\Money;

// '12' -> 12; 'abc', '' or missing -> exception
$id = Cast::toInt($_GET['id'] ?? null);

// 31.02.2026 -> exception, never rolled over to March
$date = Date::parse('15.03.2026', 'd.m.Y');

// '1350.49', with no floating-point rounding errors
$total = Money::add('1250.50', '99.99');
PHP;
$native = <<<'PHP'
// Native PHP: silent failure
$data = json_decode('{"a":1,}', true);

var_dump($data);
// NULL - was the input "null", or broken?
PHP;
$foundry = <<<'PHP'
// Foundry: explicit exception
$data = Json::decode('{"a":1,}');

// JsonException: Json::decode():
// Syntax error (input length: 8 bytes).
PHP;
?>
<section class="hero">
  <div class="hero-inner">
    <div class="hero-copy">
      <p class="eyebrow">PHP 8.3+ · PHP 7.4 · zero dependencies</p>
      <h1>Standalone PHP utility classes that <span class="accent">fail loudly</span>, never silently.</h1>
      <p class="hero-lead">Foundry is a suite of <?= $count ?> production-grade, single-file PHP classes for validation, CSV and Excel imports, databases, HTTP, security and more. Copy only the files you need. No Composer vendor tree, no third-party code, no silent <code>false</code> or <code>null</code>.</p>
      <div class="hero-cta">
        <a class="btn btn-primary" href="/docs/installation/">Get started</a>
        <a class="btn btn-ghost" href="<?= esc($site->download['url']) ?>" download>Download .zip <span class="btn-meta"><?= esc(FoundrySite\Support::humanBytes($site->download['size'])) ?></span></a>
      </div>
    </div>
    <div class="hero-code">
      <?= $site->renderer->codeBlock($hero, 'php', 'bootstrap.php') ?>
    </div>
  </div>
</section>

<section class="stats" aria-label="Library at a glance">
  <div class="stats-inner">
    <div><strong><?= $count ?></strong><span>standalone classes</span></div>
    <div><strong><?= $site->lib->methodCount() ?></strong><span>documented methods</span></div>
    <div><strong>0</strong><span>third-party dependencies</span></div>
    <div><strong>2</strong><span>PHP targets: 8.3+ and 7.4</span></div>
  </div>
</section>

<section class="band">
  <div class="band-inner">
    <h2 class="section-title">Why Foundry</h2>
    <div class="features">
      <article class="feature">
        <h3>Zero dependencies</h3>
        <p>100% native PHP. Composer is a development tool for the library, never a runtime requirement for your project.</p>
      </article>
      <article class="feature">
        <h3>One file, one tool</h3>
        <p>Every class is a single file with no links to other files. Copy <code>Json.php</code> on its own and it works.</p>
      </article>
      <article class="feature">
        <h3>Explicit errors</h3>
        <p>Failures throw standard SPL exceptions that name the operation, the context and the root cause. Native warnings are converted, never leaked.</p>
      </article>
      <article class="feature">
        <h3>No personal data in errors</h3>
        <p>Exception messages report lengths, types and paths, never the payload. Logs and dumps mask passwords, tokens, CNP and IBAN values.</p>
      </article>
    </div>
  </div>
</section>

<section class="band band-alt">
  <div class="band-inner">
    <h2 class="section-title">The difference, in one line</h2>
    <p class="section-lead">Native PHP functions often return <code>false</code> or <code>null</code> and emit a warning. Foundry turns every one of those cases into an exception you can catch, log and fix.</p>
    <div class="compare">
      <?= $site->renderer->codeBlock($native, 'php', 'Native PHP') ?>
      <?= $site->renderer->codeBlock($foundry, 'php', 'Foundry') ?>
    </div>
  </div>
</section>

<section class="band">
  <div class="band-inner">
    <h2 class="section-title">The catalog</h2>
    <p class="section-lead"><?= $count ?> classes in <?= count($site->lib->categories) ?> groups. Each one has a full reference page with signatures, parameters, return types and exceptions.</p>
    <div class="catalog">
      <?php foreach ($site->lib->categories as $cat): ?>
      <section class="catalog-group" aria-labelledby="cat-<?= esc($cat['slug']) ?>">
        <h3 id="cat-<?= esc($cat['slug']) ?>"><?= esc($cat['name']) ?></h3>
        <ul>
          <?php foreach ($cat['classes'] as $name): $c = $site->lib->classes[$name]; ?>
          <li><a href="<?= esc($site->classPath($c)) ?>"><code><?= esc($name) ?></code><span><?= esc($site->phrase($c)) ?></span></a></li>
          <?php endforeach ?>
        </ul>
      </section>
      <?php endforeach ?>
    </div>
  </div>
</section>

<section class="band band-cta">
  <div class="band-inner cta">
    <h2>Copy a file. Ship it.</h2>
    <p>Download the archive, pick the classes you need and load them with <code>require_once</code>, an autoloader or Composer PSR-4.</p>
    <div class="hero-cta">
      <a class="btn btn-primary" href="/docs/installation/">Read the installation guide</a>
      <a class="btn btn-ghost" href="/docs/">Browse the documentation</a>
    </div>
  </div>
</section>
