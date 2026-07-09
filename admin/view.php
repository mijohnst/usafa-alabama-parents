<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$stmt->execute([$id]);
$m = $stmt->fetch();
if (!$m) { flash('error', 'Member not found.'); header('Location: index.php'); exit; }

function field(string $label, string $value, bool $email = false, bool $phone = false): void {
    if ($value === '') { return; }
    echo '<div class="vf">';
    echo '<span class="vf-label">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
    $val = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    if ($email)      echo '<a href="mailto:' . $val . '" class="vf-val">' . $val . '</a>';
    elseif ($phone)  echo '<a href="tel:' . preg_replace('/\D/','',$val) . '" class="vf-val">' . $val . '</a>';
    else             echo '<span class="vf-val">' . $val . '</span>';
    echo '</div>';
}

admin_header('View Member');
?>
<style>
.vcard{display:grid;grid-template-columns:1fr 1fr;gap:1.5rem;margin-bottom:1.5rem}
.vsection{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.25rem 1.5rem}
.vsection h3{font-family:"Segoe UI",Arial,sans-serif;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#5a6a7a;margin-bottom:1rem;padding-bottom:.5rem;border-bottom:1px solid #f0f2f5}
.vf{display:flex;flex-direction:column;margin-bottom:.75rem}
.vf:last-child{margin-bottom:0}
.vf-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b4;margin-bottom:.2rem}
.vf-val{font-size:.92rem;color:#1a2332}
a.vf-val{color:#003594}
.paid-badge{display:inline-flex;align-items:center;gap:.4rem;padding:.3rem .75rem;border-radius:4px;font-weight:700;font-size:.85rem}
@media(max-width:700px){.vcard{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <h1><?= h($m['cadet_last_name']) ?>, <?= h(trim($m['cadet_first_name'] . ' ' . $m['cadet_middle_name'])) ?>
    <span style="font-size:.85rem;font-weight:400;color:#5a6a7a">· Class of <?= h($m['class_year']) ?></span>
  </h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!is_viewer()): ?>
      <a href="edit.php?id=<?= $id ?>" class="btn btn-primary">Edit</a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-secondary">← Back</a>
  </div>
</div>

<!-- Dues status banner -->
<div style="margin-bottom:1.25rem">
  <?php if ($m['membership_paid']): ?>
    <?php
      $plan = ($m['membership_type'] ?? '') === '4year'
          ? '4-Year Plan · paid through ' . $m['membership_paid_through']
          : 'Annual · ' . $m['membership_year'];
    ?>
    <span class="paid-badge" style="background:#e8f5e9;color:#1b5e20">✓ Dues Paid — <?= h($plan) ?></span>
  <?php else: ?>
    <span class="paid-badge" style="background:#ffebee;color:#c62828">✗ Dues Not Paid</span>
  <?php endif; ?>
  <?php if ($m['al_region']): ?>
    <span class="badge badge-<?= h($m['al_region']) ?>" style="margin-left:.5rem;font-size:.82rem;padding:.3rem .75rem"><?= h($m['al_region']) ?> Region</span>
  <?php endif; ?>
</div>

<div class="vcard">

  <div class="vsection">
    <h3>Cadet</h3>
    <?php
    field('Last Name',      $m['cadet_last_name']);
    field('First Name',     $m['cadet_first_name']);
    field('Middle Name',    $m['cadet_middle_name']);
    field('Nickname',       $m['nickname'] ?? '');
    field('Class Year',     $m['class_year']);
    field('Birthday',       $m['cadet_birthday'] ? date('F j, Y', strtotime($m['cadet_birthday'])) : '');
    field('PO Box',         $m['cadet_po_box']);
    field('Email',          $m['cadet_email'], true);
    field('Cell',           $m['cadet_cell'], false, true);
    ?>
  </div>

  <div class="vsection">
    <h3>Squadron Assignments</h3>
    <?php
    field('BCT Squadron',    $m['bct_squadron']);
    field('BCT Flight',      $m['bct_flight']);
    field('Fall Squadron',   $m['fall_squadron']);
    field('Yr 2–4 Squadron', $m['squadron_yr2_4']);
    ?>
  </div>

  <div class="vsection">
    <h3>Parent / Contact 1</h3>
    <?php
    field('Name',   trim($m['parent1_first_name'] . ' ' . $m['parent1_last_name']));
    field('Email',  $m['parent1_email'], true);
    field('Cell',   $m['parent1_cell'], false, true);
    field('Street', $m['parent1_street']);
    field('City',   $m['parent1_city']);
    field('State',  $m['parent1_state']);
    field('Zip',    $m['parent1_zip']);
    ?>
  </div>

  <div class="vsection">
    <h3>Parent / Contact 2</h3>
    <?php
    field('Name',   trim($m['parent2_first_name'] . ' ' . $m['parent2_last_name']));
    field('Email',  $m['parent2_email'], true);
    field('Cell',   $m['parent2_cell'], false, true);
    field('Street', $m['parent2_street']);
    field('City',   $m['parent2_city']);
    field('State',  $m['parent2_state']);
    field('Zip',    $m['parent2_zip']);
    ?>
  </div>

  <div class="vsection">
    <h3>Consents</h3>
    <?php
    field('Photo Consent',     $m['photo_consent'] ?? '');
    field('Directory Consent', $m['directory_consent'] ?? '');
    ?>
  </div>

  <?php if ($m['remarks']): ?>
  <div class="vsection">
    <h3>Remarks</h3>
    <p style="font-size:.92rem;color:#1a2332;line-height:1.6"><?= nl2br(h($m['remarks'])) ?></p>
  </div>
  <?php endif; ?>

</div>

<p style="font-size:.75rem;color:#9aa5b4">
  Record added <?= h(date('F j, Y', strtotime($m['created_at']))) ?> &middot;
  Last updated <?= h(date('F j, Y g:ia', strtotime($m['updated_at']))) ?>
</p>

<?php admin_footer(); ?>
