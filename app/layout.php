<?php
/**
 * Page chrome: header, navigation, footer (Designed by Zimgeo).
 */
declare(strict_types=1);

function page_header(string $title, string $active = '', array $opt = []): void
{
    $u = current_user();
    $bodyClass = $opt['body_class'] ?? '';
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="author" content="Zimgeo (Map Tech Pvt Ltd)">
<meta name="robots" content="noindex, nofollow">
<title><?= e($title) ?> · <?= e(app_name()) ?></title>
<link rel="icon" type="image/png" href="<?= asset('img/timb-logo.png') ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('css/app.css') ?>">
<?= $opt['head'] ?? '' ?>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
<?php if (empty($opt['bare'])): ?>
<header class="topbar">
  <a class="brand" href="<?= url('index.php') ?>">
    <span class="brand-logo"><img src="<?= asset('img/timb-logo.png') ?>" alt="TIMB — Tobacco Industry and Marketing Board"></span>
    <span class="brand-text">
      <strong>Tobacco Crop Monitoring</strong>
      <small>Satellite-based field detection &amp; yield estimates</small>
    </span>
  </a>
  <?php if ($u): ?>
  <nav class="mainnav" aria-label="Main">
    <a href="<?= url('index.php') ?>" class="<?= $active === 'dashboard' ? 'active' : '' ?>">Dashboard</a>
    <?php if ($u['role'] === ROLE_ADMIN): ?>
      <a href="<?= url('admin/index.php') ?>" class="<?= strpos($active, 'admin') === 0 ? 'active' : '' ?>">Administration</a>
    <?php endif; ?>
  </nav>
  <details class="usermenu">
    <summary><span class="avatar"><?= e(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></span><span class="uname"><?= e($u['name']) ?></span></summary>
    <div class="menu">
      <div class="menu-head"><?= e($u['email']) ?><br><span class="pill <?= $u['role'] ?>"><?= $u['role'] === 'admin' ? 'Administrator' : 'Viewer' ?></span></div>
      <a href="<?= url('account.php') ?>">My account</a>
      <form method="post" action="<?= url('logout.php') ?>"><?= csrf_field() ?><button type="submit">Sign out</button></form>
    </div>
  </details>
  <?php endif; ?>
</header>
<?php if (strpos($active, 'admin') === 0): ?>
<nav class="subnav" aria-label="Administration">
  <?php foreach ([
      'admin' => ['admin/index.php', 'Overview'],
      'admin-seasons' => ['admin/seasons.php', 'Seasons'],
      'admin-upload' => ['admin/upload.php', 'Upload data'],
      'admin-users' => ['admin/users.php', 'Users'],
      'admin-activity' => ['admin/activity.php', 'Activity & email'],
  ] as $k => [$href, $label]): ?>
    <a href="<?= url($href) ?>" class="<?= $active === $k ? 'active' : '' ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</nav>
<?php endif; ?>
<?php endif; ?>
<main class="<?= e($opt['main_class'] ?? '') ?>">
<?php foreach (take_flashes() as [$type, $msg]): ?>
  <div class="flash <?= e($type) ?>" role="status"><?= e($msg) ?></div>
<?php endforeach; ?>
<?php
}

function page_footer(array $scripts = []): void
{
    ?>
</main>
<footer class="sitefooter">
  <span>&copy; <?= date('Y') ?> Tobacco Industry and Marketing Board · <?= e(app_name()) ?></span>
  <span class="designed">Designed by <a href="https://www.zimgeo.co.zw" target="_blank" rel="noopener">Zimgeo</a></span>
</footer>
<?php foreach ($scripts as $s): ?>
<script src="<?= $s ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}

/** Centered card layout for sign-in, reset, install. */
function auth_header(string $title): void
{
    page_header($title, '', ['bare' => true, 'body_class' => 'auth-body', 'main_class' => 'auth-main']);
    ?>
<div class="auth-wrap">
  <section class="auth-hero" aria-hidden="true">
    <div class="auth-hero-inner">
      <img src="<?= asset('img/timb-logo.png') ?>" alt="" class="auth-logo">
      <h2>Tobacco Crop<br>Monitoring Portal</h2>
      <p>Sentinel-2 satellite detection of tobacco fields, hectarage and estimated yield, by district and season.</p>
    </div>
    <div class="auth-hero-grid"></div>
  </section>
  <section class="auth-card">
<?php
}

function auth_footer(): void
{
    ?>
  </section>
</div>
<?php
    page_footer();
}
