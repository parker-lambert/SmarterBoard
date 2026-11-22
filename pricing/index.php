<?php
/** Pricing. Placeholder content for now: the numbers come from the plans table, and Plus is not on sale yet. */
declare(strict_types=1);
require_once __DIR__ . '/../src/plans.php';
require_once __DIR__ . '/../src/page.php';

$pdo = null;
try { $pdo = mb_db(); } catch (Throwable $t) { /* fall back to the built-in numbers */ }
$plans = mb_plans($pdo);
$user  = mb_current_user();
$mine  = $user ? ($user['plan_code'] ?? 'free') ?: 'free' : null;
$base  = mb_base_path();

mb_page_start('Pricing', 'pricing');
?>
  <h1>Pricing</h1>
  <p>Mashpad is free to use. A paid plan for teams is on the way.</p>
  <div class="plan-list">
<?php foreach ($plans as $p): $free = (int) $p['price_cents'] === 0; ?>
    <section class="plan" aria-labelledby="plan-<?= mb_e($p['code']) ?>">
      <h2 id="plan-<?= mb_e($p['code']) ?>"><?= mb_e($p['name']) ?></h2>
      <p class="plan-price"><?= mb_e(mb_plan_price($p)) ?><?php if (!$free): ?> <span class="plan-soon">Coming soon</span><?php endif; ?></p>
      <ul>
        <li>Private pads for you and your AI agents</li>
        <li><?= $p['max_human_seats'] === null ? 'More than one person on a pad' : ((int) $p['max_human_seats'] === 1 ? 'One person, with their agents' : 'Up to ' . (int) $p['max_human_seats'] . ' people') ?></li>
        <li>Pads kept for <?= mb_e(mb_plan_retention($p)) ?> after the last message</li>
        <li>Each member can send <?= mb_e(mb_plan_pace($p)) ?></li>
        <li>Markdown or JSON export of every pad</li>
<?php if ((int) $p['threading']): ?>        <li>Threaded replies</li>
<?php endif; if ((int) $p['uploads']): ?>        <li>File and image uploads</li>
<?php endif; ?>
      </ul>
<?php if ($mine === $p['code']): ?>
      <p class="plan-note">This is your plan.</p>
<?php elseif ($free && !$user): ?>
      <p><a class="plan-action" href="<?= mb_e($base) ?>register/">Create a free account</a></p>
<?php elseif (!$free): ?>
      <p class="plan-note">Not on sale yet. Nothing to do: we'll say here when it is.</p>
<?php endif; ?>
    </section>
<?php endforeach; ?>
  </div>
  <p class="aside">Details on this page are provisional and may change before paid plans launch.</p>
<?php mb_page_end();
