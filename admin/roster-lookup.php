<?php
/**
 * Roster Lookup — lightweight, mobile-first, read-only cadet/parent lookup.
 * Same PII gate as index.php (the full Members dashboard), but skips the
 * stat boxes and edit/archive/delete actions entirely — this exists purely
 * for officers pulling up a family's contact info on their phone at an
 * event, not for managing the roster. Nothing here mutates data, so it's
 * a plain GET-only page with no CSRF-protected forms.
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!can_view_member_pii()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$search = trim($_GET['q'] ?? '');
$year   = trim($_GET['year'] ?? '');
if (!in_array($year, CLASS_YEAR_LIST, true)) $year = '';

$current_years = array_merge(current_class_years(), ['Prep School']);

// Nothing is queried on a bare load — a phone pulling up a blank search box
// loads instantly instead of shipping the whole active roster every time.
$members = [];
if ($search !== '' || $year !== '') {
    $where  = ['archived = 0'];
    $params = [];
    if ($search !== '') {
        $where[] = '(cadet_last_name LIKE :q OR cadet_first_name LIKE :q OR cadet_middle_name LIKE :q
                     OR parent1_last_name LIKE :q OR parent1_first_name LIKE :q
                     OR parent2_last_name LIKE :q OR parent2_first_name LIKE :q
                     OR cadet_email LIKE :q OR parent1_email LIKE :q OR parent2_email LIKE :q
                     OR cadet_cell LIKE :q OR parent1_cell LIKE :q OR parent2_cell LIKE :q)';
        $params[':q'] = '%' . $search . '%';
    }
    if ($year !== '') {
        $where[] = 'class_year = :yr';
        $params[':yr'] = $year;
    }
    $sql = 'SELECT * FROM members WHERE ' . implode(' AND ', $where)
         . ' ORDER BY cadet_last_name ASC, cadet_first_name ASC LIMIT 100';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll();
}

admin_header('Roster Lookup');
?>
<style>
.main{margin-top:1rem}
.rl-search-wrap{position:sticky;top:0;background:#f0f2f5;padding:.6rem 0 .85rem;z-index:10}
.rl-search{display:flex;gap:.5rem}
.rl-search input{flex:1;font-size:1.05rem;padding:.85rem 1rem;border:1px solid #d0d5dd;border-radius:8px}
.rl-search .btn{padding:.85rem 1.1rem;border-radius:8px}
.rl-chips{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.65rem}
.rl-chip{background:#fff;border:1px solid #d0d5dd;border-radius:99px;padding:.4rem .95rem;font-size:.82rem;font-weight:600;color:#1a2332;text-decoration:none}
.rl-chip:hover{text-decoration:none;background:#f5f7fa}
.rl-chip.active{background:#003594;border-color:#003594;color:#fff}
.rl-empty{color:#5a6a7a;text-align:center;padding:3rem 1rem;font-size:.95rem}
.rl-count{font-size:.8rem;color:#5a6a7a;margin-bottom:.6rem}
.rl-list{display:flex;flex-direction:column;gap:.75rem}
.rl-card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1rem 1.1rem}
.rl-card-top{display:flex;justify-content:space-between;align-items:flex-start;gap:.5rem;flex-wrap:wrap;margin-bottom:.35rem}
.rl-cadet-name{font-weight:700;font-size:1.05rem;color:#002554}
.rl-badges{display:flex;gap:.35rem;flex-wrap:wrap}
.rl-sub{font-size:.78rem;color:#5a6a7a;margin-bottom:.3rem}
.rl-parents{display:flex;flex-direction:column;gap:.6rem;margin-top:.55rem;border-top:1px solid #f0f2f5;padding-top:.55rem}
.rl-parent{display:flex;flex-wrap:wrap;align-items:center;gap:.45rem;font-size:.85rem}
.rl-parent-label{font-size:.65rem;font-weight:700;color:#9aa5b4;background:#f0f2f5;padding:.15rem .4rem;border-radius:3px;flex-shrink:0}
.rl-parent-name{font-weight:600;color:#1a2332}
.rl-contact{display:inline-flex;align-items:center;gap:.3rem;background:#e8edf6;color:#003594;padding:.4rem .75rem;border-radius:6px;font-size:.82rem;text-decoration:none;font-weight:600}
.rl-contact:hover{background:#d7e2f5;text-decoration:none}
.rl-mailbox{display:inline-flex;align-items:center;gap:.3rem;background:#f0f2f5;color:#5a6a7a;padding:.4rem .75rem;border-radius:6px;font-size:.82rem;font-weight:600}
@media(min-width:700px){.rl-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(340px,1fr));gap:.85rem}}
</style>

<h1 style="margin-bottom:.85rem">📋 Roster Lookup</h1>

<div class="rl-search-wrap">
  <form method="GET" class="rl-search">
    <?php if ($year !== ''): ?><input type="hidden" name="year" value="<?= h($year) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= h($search) ?>" placeholder="Search cadet or parent name, email, phone…" autofocus>
    <button type="submit" class="btn btn-primary">Search</button>
  </form>
  <div class="rl-chips">
    <a href="roster-lookup.php<?= $search !== '' ? '?' . http_build_query(['q' => $search]) : '' ?>" class="rl-chip <?= $year === '' ? 'active' : '' ?>">All Years</a>
    <?php foreach ($current_years as $y): ?>
    <a href="roster-lookup.php?<?= http_build_query(array_filter(['q' => $search, 'year' => $y])) ?>" class="rl-chip <?= $year === $y ? 'active' : '' ?>"><?= h($y === 'Prep School' ? 'Prep' : $y) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($search === '' && $year === ''): ?>
  <p class="rl-empty">Search a name, email, or phone number — or tap a class year — to look someone up.</p>
<?php elseif (empty($members)): ?>
  <p class="rl-empty">No matches found.</p>
<?php else: ?>
  <div class="rl-count"><?= count($members) ?> result<?= count($members) !== 1 ? 's' : '' ?><?= count($members) === 100 ? ' (showing first 100 — narrow your search)' : '' ?></div>
  <div class="rl-list">
    <?php foreach ($members as $m):
      $sqd = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
      $cadet_fm = trim($m['cadet_first_name'] . ' ' . $m['cadet_middle_name']);
    ?>
    <div class="rl-card">
      <div class="rl-card-top">
        <div class="rl-cadet-name"><?= h($m['cadet_last_name']) ?><?= !empty($m['cadet_suffix']) ? ' ' . h($m['cadet_suffix']) : '' ?><?= $cadet_fm ? ', ' . h($cadet_fm) : '' ?></div>
        <div class="rl-badges">
          <span class="badge"><?= h($m['class_year']) ?></span>
          <?php if ($m['al_region']): ?><span class="badge badge-<?= h($m['al_region']) ?>"><?= h($m['al_region']) ?></span><?php endif; ?>
          <?php if ($m['membership_paid']): ?><span class="badge badge-paid">✓ Paid</span><?php else: ?><span class="badge badge-unpaid">✗ Unpaid</span><?php endif; ?>
        </div>
      </div>
      <?php if ($sqd): ?><div class="rl-sub">Squadron <?= h($sqd) ?></div><?php endif; ?>

      <div class="rl-parents">
        <?php
          $cadet_box   = trim($m['cadet_po_box'] ?? '');
          $cadet_cell  = trim($m['cadet_cell']    ?? '');
          $cadet_email = trim($m['cadet_email']   ?? '');
        ?>
        <?php if ($cadet_box !== '' || $cadet_cell !== '' || $cadet_email !== ''): ?>
        <div class="rl-parent">
          <span class="rl-parent-label">Cadet</span>
          <?php if ($cadet_box !== ''): ?><span class="rl-mailbox">📦 PO Box <?= h($cadet_box) ?></span><?php endif; ?>
          <?php if ($cadet_cell !== ''): ?><a class="rl-contact" href="tel:<?= h(preg_replace('/\D/', '', $cadet_cell)) ?>">📞 <?= h($cadet_cell) ?></a><?php endif; ?>
          <?php if ($cadet_email !== ''): ?><a class="rl-contact" href="mailto:<?= h($cadet_email) ?>">✉️ <?= h($cadet_email) ?></a><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php foreach ([1, 2] as $n):
          $pname  = trim($m["parent{$n}_first_name"] . ' ' . $m["parent{$n}_last_name"]);
          $pcell  = $m["parent{$n}_cell"]  ?? '';
          $pemail = $m["parent{$n}_email"] ?? '';
          if ($pname === '' && $pcell === '' && $pemail === '') continue;
        ?>
        <div class="rl-parent">
          <span class="rl-parent-label">P<?= $n ?></span>
          <span class="rl-parent-name"><?= h($pname ?: '—') ?></span>
          <?php if ($pcell): ?><a class="rl-contact" href="tel:<?= h(preg_replace('/\D/', '', $pcell)) ?>">📞 <?= h($pcell) ?></a><?php endif; ?>
          <?php if ($pemail): ?><a class="rl-contact" href="mailto:<?= h($pemail) ?>">✉️ <?= h($pemail) ?></a><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php admin_footer(); ?>
