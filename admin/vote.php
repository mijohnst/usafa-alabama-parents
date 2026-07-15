<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$my_user_stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$my_user_stmt->execute([$user_id]);
$my_user   = $my_user_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$my_member = $my_user ? find_linked_member($pdo, $my_user) : null;

// Which parent slot (1 or 2) on the linked member record is *this* logged-in
// account — matched by login email against parent1_email/parent2_email, same
// linkage find_linked_member() already uses. Falls back to whichever slot
// has a name filled in if the email doesn't match either (e.g. an account
// created before the email link existed).
function my_parent_slot(array $user, ?array $member): int {
    if (!$member) return 0;
    $email = strtolower(trim($user['email'] ?? ''));
    if ($email !== '' && $email === strtolower(trim($member['parent1_email'] ?? ''))) return 1;
    if ($email !== '' && $email === strtolower(trim($member['parent2_email'] ?? ''))) return 2;
    return !empty($member['parent1_first_name']) ? 1 : 2;
}
$my_slot = $my_member ? my_parent_slot($my_user, $my_member) : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? 'vote';

    if ($action === 'nominate') {
        $nom_election_id = (int)($_POST['election_id'] ?? 0);
        $nom_position     = $_POST['position'] ?? '';

        $es = $pdo->prepare("SELECT id FROM elections WHERE id=? AND status='draft'");
        $es->execute([$nom_election_id]);

        if (!$es->fetch() || !$my_member || !$my_slot || !in_array($nom_position, ELECTION_POSITIONS, true)) {
            flash('error', 'Unable to submit that nomination.');
        } else {
            $nom_name = trim(($my_member["parent{$my_slot}_first_name"] ?? '') . ' ' . ($my_member["parent{$my_slot}_last_name"] ?? ''));
            if ($nom_name === '') {
                flash('error', "We couldn't determine your name on the member record — contact the Secretary.");
            } else {
                try {
                    $pdo->prepare("INSERT INTO election_candidates (election_id, position, member_id, parent_slot, name, status) VALUES (?,?,?,?,?,'pending')")
                        ->execute([$nom_election_id, $nom_position, $my_member['id'], $my_slot, $nom_name]);
                    flash('success', "You're nominated for $nom_position — pending Secretary approval.");
                } catch (PDOException $ex) {
                    flash('error', "You've already been nominated for $nom_position.");
                }
            }
        }
        header('Location: vote.php'); exit;
    }

    $election_id = (int)($_POST['election_id'] ?? 0);
    $picks = is_array($_POST['candidate'] ?? null) ? $_POST['candidate'] : [];

    $now_str = date('Y-m-d H:i:s');
    $e = $pdo->prepare("SELECT * FROM elections WHERE id=? AND status='open' AND voting_opens_at <= ? AND voting_closes_at >= ?");
    $e->execute([$election_id, $now_str, $now_str]);
    $election = $e->fetch(PDO::FETCH_ASSOC);

    if (!$election) {
        flash('error', 'Voting is not currently open.');
    } else {
        $valid = $pdo->prepare('SELECT id FROM election_candidates WHERE id=? AND election_id=? AND position=?');
        $ins   = $pdo->prepare('INSERT INTO election_votes (election_id, position, candidate_id, voter_user_id) VALUES (?,?,?,?)');
        $cast = 0; $skipped = 0;
        foreach (ELECTION_POSITIONS as $position) {
            $cid = (int)($picks[$position] ?? 0);
            if (!$cid) continue;
            $valid->execute([$cid, $election_id, $position]);
            if (!$valid->fetch()) continue; // doesn't belong to this election/position — ignore
            try {
                $ins->execute([$election_id, $position, $cid, $user_id]);
                $cast++;
            } catch (PDOException $ex) {
                $skipped++; // already voted for this position
            }
        }
        if ($cast > 0) {
            flash('success', $skipped > 0
                ? "Vote recorded for $cast position" . ($cast == 1 ? '' : 's') . " — $skipped were already cast."
                : 'Your vote has been recorded — thank you!');
        } else {
            flash('error', $skipped > 0 ? 'You already voted in this election.' : 'No selections were made.');
        }
    }
    header('Location: vote.php'); exit;
}

// Currently open election (voting window bound to PHP's clock, not MySQL's —
// the DB server's timezone isn't guaranteed to match America/Chicago).
$now_str = date('Y-m-d H:i:s');
$o = $pdo->prepare("SELECT * FROM elections WHERE status='open' AND voting_opens_at <= ? AND voting_closes_at >= ? ORDER BY election_date ASC LIMIT 1");
$o->execute([$now_str, $now_str]);
$open = $o->fetch(PDO::FETCH_ASSOC);

$upcoming = null;
if (!$open) {
    $today = date('Y-m-d');
    $u = $pdo->prepare("SELECT * FROM elections WHERE status='draft' AND election_date >= ? ORDER BY election_date ASC LIMIT 1");
    $u->execute([$today]);
    $upcoming = $u->fetch(PDO::FETCH_ASSOC);
}

$candidates_by_position = [];
$voted_positions = [];
if ($open) {
    $c = $pdo->prepare("SELECT * FROM election_candidates WHERE election_id=? AND status='approved' ORDER BY position, name");
    $c->execute([$open['id']]);
    foreach ($c->fetchAll(PDO::FETCH_ASSOC) as $row) $candidates_by_position[$row['position']][] = $row;

    $v = $pdo->prepare('SELECT position FROM election_votes WHERE election_id=? AND voter_user_id=?');
    $v->execute([$open['id'], $user_id]);
    $voted_positions = $v->fetchAll(PDO::FETCH_COLUMN);
}

// My own nomination state for the upcoming (draft) election, if any — lets
// the "Run for Office" section show "pending"/"on the ballot" instead of
// re-offering a position the member already nominated themselves for.
$my_nominations = [];
if ($upcoming && $my_member && $my_slot) {
    $mn = $pdo->prepare('SELECT position, status FROM election_candidates WHERE election_id=? AND member_id=? AND parent_slot=?');
    $mn->execute([$upcoming['id'], $my_member['id'], $my_slot]);
    foreach ($mn->fetchAll(PDO::FETCH_ASSOC) as $row) $my_nominations[$row['position']] = $row['status'];
}

admin_header('Vote');
echo show_flash();
?>

<div class="page-head">
  <h1>Officer Election</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<?php if ($open): ?>
  <p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1rem">
    <?= h($open['title']) ?> — voting closes <?= date('F j, Y \a\t g:ia', strtotime($open['voting_closes_at'])) ?>.
  </p>

  <div class="countdown" data-deadline="<?= strtotime($open['voting_closes_at']) * 1000 ?>" style="display:inline-flex;align-items:center;gap:.5rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:.5rem 1rem;margin-bottom:1.25rem;font-size:.85rem;font-weight:700;color:#5f4c00">Loading…</div>

  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="vote">
    <input type="hidden" name="election_id" value="<?= $open['id'] ?>">
    <?php foreach (ELECTION_POSITIONS as $position): ?>
      <div class="card" style="margin-bottom:1.25rem;max-width:560px">
        <h2><?= h($position) ?></h2>
        <?php if (in_array($position, $voted_positions, true)): ?>
          <p style="color:#1b5e20;font-weight:700">✓ Voted</p>
        <?php elseif (empty($candidates_by_position[$position])): ?>
          <p style="color:#9aa5b4;font-size:.85rem">No candidates listed for this position.</p>
        <?php else: ?>
          <?php foreach ($candidates_by_position[$position] as $c): ?>
            <label style="display:flex;align-items:flex-start;gap:.6rem;padding:.5rem 0;border-bottom:1px solid #f0f2f5;cursor:pointer;font-weight:400;text-transform:none;letter-spacing:0">
              <input type="radio" name="candidate[<?= h($position) ?>]" value="<?= $c['id'] ?>" style="width:auto;margin-top:.2rem">
              <span>
                <strong style="color:#1a2332"><?= h($c['name']) ?></strong>
                <?php if ($c['bio']): ?><div style="font-size:.82rem;color:#5a6a7a;margin-top:.1rem"><?= h($c['bio']) ?></div><?php endif; ?>
              </span>
            </label>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <button type="submit" class="btn btn-primary">Submit Ballot</button>
  </form>

  <script>
  (function() {
    var el = document.querySelector('.countdown');
    if (!el) return;
    var deadline = parseInt(el.getAttribute('data-deadline'), 10);
    function tick() {
      var diff = deadline - Date.now();
      if (diff <= 0) { el.textContent = 'Voting has closed.'; return; }
      var d = Math.floor(diff / 86400000), h = Math.floor((diff % 86400000) / 3600000), m = Math.floor((diff % 3600000) / 60000);
      el.innerHTML = '🗳️ Voting closes in <strong style="margin-left:.25rem">' + d + 'd ' + h + 'h ' + m + 'm</strong>';
    }
    tick(); setInterval(tick, 60000);
  })();
  </script>

<?php elseif ($upcoming): ?>
  <div class="card" style="max-width:480px">
    <h2><?= h($upcoming['title']) ?></h2>
    <p style="color:#5a6a7a;margin-bottom:1rem">Voting hasn't opened yet.</p>
    <div class="countdown" data-deadline="<?= strtotime($upcoming['voting_opens_at']) * 1000 ?>" style="display:inline-flex;align-items:center;gap:.5rem;background:#e3f2fd;border:1px solid #90caf9;border-radius:4px;padding:.5rem 1rem;font-size:.85rem;font-weight:700;color:#0d47a1">Loading…</div>
  </div>

  <script>
  (function() {
    var el = document.querySelector('.countdown');
    if (!el) return;
    var deadline = parseInt(el.getAttribute('data-deadline'), 10);
    function tick() {
      var diff = deadline - Date.now();
      if (diff <= 0) { el.textContent = 'Voting is now open — refresh this page.'; return; }
      var d = Math.floor(diff / 86400000), h = Math.floor((diff % 86400000) / 3600000), m = Math.floor((diff % 3600000) / 60000);
      el.innerHTML = '⏰ Voting opens in <strong style="margin-left:.25rem">' + d + 'd ' + h + 'h ' + m + 'm</strong>';
    }
    tick(); setInterval(tick, 60000);
  })();
  </script>

  <?php if ($my_member): ?>
  <div class="card" style="max-width:480px;margin-top:1.25rem">
    <h2>Run for Office</h2>
    <p style="color:#5a6a7a;font-size:.85rem;margin-bottom:1rem">Nominate yourself for a position — the Secretary reviews nominations before voting opens.</p>
    <?php foreach (ELECTION_POSITIONS as $position): $nom_status = $my_nominations[$position] ?? null; ?>
      <div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0;border-bottom:1px solid #f0f2f5">
        <span><?= h($position) ?></span>
        <?php if ($nom_status === 'approved'): ?>
          <span style="color:#1b5e20;font-weight:700;font-size:.85rem">✓ On the ballot</span>
        <?php elseif ($nom_status === 'pending'): ?>
          <span style="color:#5f4c00;font-weight:700;font-size:.85rem">Pending approval</span>
        <?php else: ?>
          <form method="POST" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="nominate">
            <input type="hidden" name="election_id" value="<?= $upcoming['id'] ?>">
            <input type="hidden" name="position" value="<?= h($position) ?>">
            <button type="submit" class="btn btn-secondary btn-sm">Nominate Myself</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php else: ?>
  <p style="font-size:.82rem;color:#9aa5b4;margin-top:1rem">We couldn't find a membership record linked to your account, so you can't self-nominate. Contact <a href="mailto:info@alabamafalcons.org">info@alabamafalcons.org</a> if you believe this is an error.</p>
  <?php endif; ?>
<?php else: ?>
  <p style="color:#9aa5b4">No election is currently open or scheduled.</p>
<?php endif; ?>

<?php admin_footer(); ?>
