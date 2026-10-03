<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

// Two sources, merged per event:
//  - event_rsvps   — portal users RSVPing from event-rsvp.php (keyed by user)
//  - event_signups — families signing up from the emailed public link,
//                    event-signup.html (keyed by member, no login needed)
// event_signups only exists once migrate_event_signups.sql has run, so
// its reads are guarded and simply contribute nothing before then.

$events = $pdo->query(
    "SELECT e.* FROM events e
     ORDER BY e.group_label DESC, e.event_date IS NULL, e.event_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Portal RSVPs — each is the user plus guest_count extra people.
$portal = [];
foreach ($pdo->query(
    "SELECT r.event_id, u.name, u.email, r.guest_count, r.created_at FROM event_rsvps r
     JOIN users u ON r.user_id = u.id ORDER BY r.created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $portal[$r['event_id']][] = $r;
}

// Public sign-ups — attendee_count already includes the person signing up.
$signups = [];
$signups_ready = true;
try {
    foreach ($pdo->query(
        "SELECT s.event_id, s.attendee_count, s.note, s.signup_email, s.created_at, s.updated_at,
                m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix, m.class_year,
                m.parent1_first_name, m.parent1_last_name, m.parent2_first_name, m.parent2_last_name
         FROM event_signups s JOIN members m ON m.id = s.member_id
         ORDER BY s.created_at ASC"
    )->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $signups[$s['event_id']][] = $s;
    }
} catch (PDOException $e) {
    $signups_ready = false;
}

// Open for public sign-up — same rule as signup_open_event() in lib.php.
$today = date('Y-m-d');
$is_open = fn(array $e) => !empty($e['visible']) && in_array($e['group_label'], ['upcoming', 'planning'], true)
    && (empty($e['event_date']) || ($e['event_date_end'] ?: $e['event_date']) >= $today);

$headcount = function (int $event_id) use ($portal, $signups): int {
    $n = 0;
    foreach ($portal[$event_id] ?? [] as $r) $n += 1 + (int)$r['guest_count'];
    foreach ($signups[$event_id] ?? [] as $s) $n += (int)$s['attendee_count'];
    return $n;
};

// ── CSV export for one event ─────────────────────────────────────────────
if (isset($_GET['export'])) {
    $eid = (int)$_GET['export'];
    $title = 'event';
    foreach ($events as $e) if ((int)$e['id'] === $eid) $title = $e['title'];
    $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($title)), '-') ?: 'event';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rsvps-' . $slug . '-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Source', 'Name / Family', 'Email', 'Attending', 'Note', 'Signed Up']);
    foreach ($portal[$eid] ?? [] as $r) {
        fputcsv($out, array_map(fn($v) => is_string($v) ? csv_formula_safe($v) : $v,
            ['Portal', $r['name'], $r['email'], 1 + (int)$r['guest_count'], '', $r['created_at']]));
    }
    foreach ($signups[$eid] ?? [] as $s) {
        fputcsv($out, array_map(fn($v) => is_string($v) ? csv_formula_safe($v) : $v,
            ['Email link', member_family_label($s), $s['signup_email'], (int)$s['attendee_count'], (string)($s['note'] ?? ''), $s['created_at']]));
    }
    fclose($out);
    exit;
}

admin_header('Event RSVPs');
?>
<style>
.er-row{border-left:3px solid #1565c0;padding:.75rem .9rem;margin-bottom:.6rem;background:#fff;border-radius:0 4px 4px 0}
.er-row.open{border-left-color:#2e7d32}
.er-top{display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem;flex-wrap:wrap}
.er-meta{font-size:.78rem;color:#5a6a7a;margin-top:.2rem}
.er-roster{font-size:.8rem;color:#33414f;margin-top:.5rem;border-top:1px solid #f0f2f5;padding-top:.5rem}
.er-roster li{margin:.15rem 0 .15rem 1rem}
.er-roster .src{font-size:.68rem;color:#9aa5b4;text-transform:uppercase;letter-spacing:.04em;margin-left:.3rem}
.er-roster .note{color:#5a6a7a;font-style:italic}
.er-link{display:flex;gap:.4rem;margin-top:.55rem;flex-wrap:wrap;align-items:center}
.er-link input{flex:1;min-width:220px;max-width:460px;font-size:.78rem;padding:.35rem .5rem;border:1px solid #cfd6df;border-radius:4px;color:#33414f;background:#f7f9fb}
</style>

<div class="page-head">
  <h1>Event RSVPs</h1>
  <div style="display:flex;gap:.5rem">
    <a href="events.php" class="btn btn-secondary">Manage Events</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Headcounts from members RSVPing on their dashboard <strong>and</strong> families signing up from an emailed link.
  Open events show a <strong>sign-up link</strong> to paste into your email — families verify with their cadet's last name, birthday, and an email on file (no portal login).
</p>
<?php if (!$signups_ready): ?>
  <div class="alert alert-error">Email-link sign-ups aren't switched on yet — run <code>migrate_event_signups.sql</code> in phpMyAdmin. Portal RSVPs below still work.</div>
<?php endif; ?>

<?php
$shown = 0;
foreach ($events as $e):
    $eid = (int)$e['id'];
    $open = $is_open($e);
    $rows_p = $portal[$eid] ?? [];
    $rows_s = $signups[$eid] ?? [];
    if (!$open && !$rows_p && !$rows_s) continue;
    $shown++;
    $total = $headcount($eid);
    $families = count($rows_p) + count($rows_s);
    $link = 'https://alabamafalcons.org/event-signup.html?event=' . $eid;
?>
  <div class="er-row <?= $open ? 'open' : '' ?>">
    <div class="er-top">
      <div>
        <strong style="color:#002554"><?= h($e['title']) ?></strong>
        <span style="font-size:.78rem;color:#1565c0;font-weight:700"> — <?= $total ?> attending<?= $families ? ' (' . $families . ' sign-up' . ($families !== 1 ? 's' : '') . ')' : '' ?></span>
        <div class="er-meta">
          <?php if ($e['event_date']): ?><?= date('M j, Y', strtotime($e['event_date'])) ?><?php endif; ?>
          <?php if (!empty($e['location'])): ?> · <?= h($e['location']) ?><?php endif; ?>
          <?= $open ? ' · <span style="color:#2e7d32;font-weight:700">Sign-ups open</span>' : ' · Sign-ups closed' ?>
        </div>
      </div>
      <?php if ($families): ?>
        <a href="event-rsvps.php?export=<?= $eid ?>" class="btn btn-secondary btn-sm">Export CSV</a>
      <?php endif; ?>
    </div>

    <?php if ($open): ?>
    <div class="er-link">
      <input type="text" readonly value="<?= h($link) ?>" onclick="this.select()" aria-label="Sign-up link for <?= h($e['title']) ?>">
      <button type="button" class="btn btn-primary btn-sm" onclick="copySignupLink(this)">Copy Sign-Up Link</button>
      <a href="<?= h($link) ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm">Preview</a>
    </div>
    <?php endif; ?>

    <?php if ($rows_p || $rows_s): ?>
    <ul class="er-roster" style="list-style:disc">
      <?php foreach ($rows_s as $s): ?>
        <li><?= h(member_family_label($s)) ?> — <strong><?= (int)$s['attendee_count'] ?></strong><span class="src">email link</span>
          <?php if (!empty($s['note'])): ?><br><span class="note">“<?= h($s['note']) ?>”</span><?php endif; ?></li>
      <?php endforeach; ?>
      <?php foreach ($rows_p as $r): ?>
        <li><?= h($r['name']) ?> — <strong><?= 1 + (int)$r['guest_count'] ?></strong><span class="src">portal</span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($shown === 0): ?>
  <p style="color:#9aa5b4">No open events or RSVPs yet. Add an event under <a href="events.php">Manage Events</a> (Upcoming, visible) to get a sign-up link.</p>
<?php endif; ?>

<script>
function copySignupLink(btn) {
  var input = btn.parentNode.querySelector('input');
  var done = function() { var t = btn.textContent; btn.textContent = 'Copied!'; setTimeout(function() { btn.textContent = t; }, 1500); };
  if (navigator.clipboard) {
    navigator.clipboard.writeText(input.value).then(done, function() { input.select(); document.execCommand('copy'); done(); });
  } else {
    input.select(); document.execCommand('copy'); done();
  }
}
</script>

<?php admin_footer(); ?>
