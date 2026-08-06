<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$db_ready = true;
try { $pdo->query('SELECT 1 FROM polls LIMIT 1'); } catch (PDOException $e) { $db_ready = false; }

// Paid-member eligibility — same linkage vote.php already uses for elections.
$my_user_stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$my_user_stmt->execute([$user_id]);
$my_user   = $my_user_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$my_member = $my_user ? find_linked_member($pdo, $my_user) : null;
$eligible  = $my_member && !empty($my_member['membership_paid']);

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $poll_id   = (int)($_POST['poll_id'] ?? 0);
    $option_id = (int)($_POST['option_id'] ?? 0);

    if (!$eligible) {
        flash('error', 'Only paid members can vote.');
    } else {
        $p = $pdo->prepare("SELECT * FROM polls WHERE id=? AND status='open'");
        $p->execute([$poll_id]);
        $poll = $p->fetch(PDO::FETCH_ASSOC);

        if (!$poll || strtotime($poll['expires_at']) <= time()) {
            flash('error', 'Voting is not currently open for that poll.');
        } else {
            $o = $pdo->prepare('SELECT id FROM poll_options WHERE id=? AND poll_id=?');
            $o->execute([$option_id, $poll_id]);
            if (!$o->fetch()) {
                flash('error', 'Invalid option.');
            } else {
                try {
                    $pdo->prepare('INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (?,?,?)')
                        ->execute([$poll_id, $option_id, $user_id]);
                    flash('success', 'Vote recorded — thank you!');
                } catch (PDOException $e) {
                    flash('error', "You've already voted on that poll.");
                }
            }
        }
    }
    header('Location: polls.php'); exit;
}

$polls = [];
$my_votes = [];
if ($db_ready) {
    $polls = $pdo->query(
        "SELECT * FROM polls ORDER BY (status='open') DESC, expires_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($user_id) {
        $mv = $pdo->prepare('SELECT poll_id, option_id FROM poll_votes WHERE user_id=?');
        $mv->execute([$user_id]);
        foreach ($mv->fetchAll(PDO::FETCH_ASSOC) as $r) $my_votes[$r['poll_id']] = $r['option_id'];
    }
}

$options_by_poll = []; $tallies = [];
if (!empty($polls)) {
    $ids = array_column($polls, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $orows = $pdo->prepare("SELECT * FROM poll_options WHERE poll_id IN ($ph) ORDER BY sort_order ASC");
    $orows->execute($ids);
    foreach ($orows->fetchAll(PDO::FETCH_ASSOC) as $o) $options_by_poll[$o['poll_id']][] = $o;

    $trows = $pdo->prepare("SELECT option_id, COUNT(*) AS cnt FROM poll_votes WHERE poll_id IN ($ph) GROUP BY option_id");
    $trows->execute($ids);
    foreach ($trows->fetchAll(PDO::FETCH_ASSOC) as $t) $tallies[$t['option_id']] = (int)$t['cnt'];
}

admin_header('Vote');
echo show_flash();
?>
<style>
.poll-card{border-left:3px solid #003594;padding:.85rem 1rem;margin-bottom:.85rem;background:#fff;border-radius:0 4px 4px 0}
.poll-card.closed{border-left-color:#9aa5b4}
.poll-meta{font-size:.78rem;color:#5a6a7a;margin-top:.15rem;margin-bottom:.6rem}
.poll-choice{display:flex;align-items:center;gap:.55rem;padding:.4rem 0;font-size:.92rem}
.poll-bar-row{display:flex;align-items:center;gap:.6rem;margin-top:.3rem;font-size:.82rem}
.poll-bar-track{flex:1;height:10px;background:#f0f2f5;border-radius:99px;overflow:hidden}
.poll-bar-fill{height:100%;background:#003594;border-radius:99px}
</style>

<div class="page-head">
  <h1>Vote</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (is_officer() || is_admin()): ?><a href="polls-manage.php" class="btn btn-primary">Manage Polls</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Club decisions the President or VP have opened up for a member vote.</p>

<?php if (!$db_ready): ?>
  <p style="color:#9aa5b4">No polls have been set up yet.</p>
<?php elseif (empty($polls)): ?>
  <p style="color:#9aa5b4">No polls right now — check back later.</p>
<?php else: ?>
  <?php if (!$eligible): ?>
  <div class="alert alert-error">Only paid members can vote — you can still see results below.</div>
  <?php endif; ?>
  <?php foreach ($polls as $p):
    $opts = $options_by_poll[$p['id']] ?? [];
    $total_votes = array_sum(array_map(fn($o) => $tallies[$o['id']] ?? 0, $opts));
    $is_open   = $p['status'] === 'open' && strtotime($p['expires_at']) > time();
    $already   = isset($my_votes[$p['id']]);
    $show_ballot = $is_open && $eligible && !$already;
  ?>
  <div class="poll-card <?= $is_open ? '' : 'closed' ?>">
    <strong style="color:#002554"><?= h($p['title']) ?></strong>
    <?php if (!$is_open): ?><span style="color:#9aa5b4;font-size:.75rem"> · Closed</span>
    <?php elseif ($already): ?><span style="color:#1b5e20;font-size:.75rem"> · ✓ You voted</span><?php endif; ?>
    <div class="poll-meta">
      <?= $is_open ? 'Voting closes' : 'Closed' ?> <?= date('M j, Y g:ia', strtotime($p['expires_at'])) ?>
      <?php if ($p['description']): ?><br><?= h($p['description']) ?><?php endif; ?>
    </div>

    <?php if ($show_ballot): ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="poll_id" value="<?= $p['id'] ?>">
        <?php foreach ($opts as $o): ?>
        <label class="poll-choice">
          <input type="radio" name="option_id" value="<?= $o['id'] ?>" required style="width:auto">
          <?= h($o['option_text']) ?>
        </label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.5rem">Cast Vote</button>
      </form>
    <?php else: ?>
      <?php foreach ($opts as $o): $cnt = $tallies[$o['id']] ?? 0; $pct = $total_votes > 0 ? round($cnt / $total_votes * 100) : 0; ?>
      <div class="poll-bar-row">
        <span style="min-width:110px"><?= h($o['option_text']) ?><?= $already && $my_votes[$p['id']] == $o['id'] ? ' (your vote)' : '' ?></span>
        <div class="poll-bar-track"><div class="poll-bar-fill" style="width:<?= $pct ?>%"></div></div>
        <span style="color:#5a6a7a;min-width:70px;text-align:right"><?= $cnt ?> (<?= $pct ?>%)</span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_footer(); ?>
