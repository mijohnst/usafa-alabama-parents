<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: helpdesk.php'); exit; }

$stmt = $pdo->prepare(
    'SELECT t.*, u.name as submitter_name, u.email as submitter_email,
            a.name as assigned_name
     FROM tickets t
     LEFT JOIN users u ON t.submitted_by=u.id
     LEFT JOIN users a ON t.assigned_to=a.id
     WHERE t.id=?'
);
$stmt->execute([$id]);
$ticket = $stmt->fetch();
if (!$ticket) { flash('error','Ticket not found.'); header('Location: helpdesk.php'); exit; }

$is_mine = (int)($ticket['submitted_by']??-1) === (int)($_SESSION['user_id']??0);

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'comment' && (can_manage_tickets() || $is_mine)) {
        $comment     = trim($_POST['comment']     ?? '');
        $is_internal = can_manage_tickets() && !empty($_POST['is_internal']) ? 1 : 0;
        if ($comment) {
            $pdo->prepare('INSERT INTO ticket_comments (ticket_id,user_id,comment,is_internal) VALUES (?,?,?,?)')
                ->execute([$id, $_SESSION['user_id']??null, $comment, $is_internal]);

            // Notify if tech replied to user's ticket or user replied
            if (can_manage_tickets() && !$is_internal && !empty($ticket['submitter_email'])) {
                $body = "USAFA Parents Club of Alabama\nSupport Ticket Update — {$ticket['ticket_number']}\n" . str_repeat('─',48) . "\n\n"
                      . "A reply has been added to your ticket:\n\n"
                      . "Ticket: {$ticket['ticket_number']}\nSubject: {$ticket['subject']}\n\n"
                      . "Reply:\n$comment\n\n"
                      . "View ticket: https://alabamafalcons.org/admin/ticket-view.php?id=$id\n\n"
                      . str_repeat('─',48) . "\nalabamafalcons.org/admin/";
                mail($ticket['submitter_email'], "Reply on Ticket {$ticket['ticket_number']}: {$ticket['subject']}", $body,
                     "From: USAFA Parents Club <info@alabamafalcons.org>\r\nContent-Type: text/plain; charset=UTF-8\r\n");
            }
            flash('success', 'Comment added.');
        }
    }

    if ($action === 'update_status' && can_manage_tickets()) {
        $new_status  = $_POST['new_status']  ?? '';
        $assigned_to = (int)($_POST['assigned_to'] ?? 0) ?: null;
        if (in_array($new_status, array_keys(TICKET_STATUSES))) {
            $pdo->prepare('UPDATE tickets SET status=?,assigned_to=?,updated_at=NOW() WHERE id=?')
                ->execute([$new_status, $assigned_to, $id]);
            // Log status change
            $pdo->prepare('INSERT INTO ticket_comments (ticket_id,user_id,comment,is_internal) VALUES (?,?,?,1)')
                ->execute([$id, $_SESSION['user_id']??null,
                           'Status changed to: ' . TICKET_STATUSES[$new_status] . ($assigned_to ? ' · Assigned to user #'.$assigned_to : '')]);
            // Notify submitter
            if (!empty($ticket['submitter_email'])) {
                $body = "Your support ticket {$ticket['ticket_number']} status has been updated to: " . TICKET_STATUSES[$new_status] . "\n\n"
                      . "View ticket: https://alabamafalcons.org/admin/ticket-view.php?id=$id\n\nalabamafalcons.org/admin/";
                mail($ticket['submitter_email'], "Ticket {$ticket['ticket_number']} Updated: " . TICKET_STATUSES[$new_status],
                     $body, "From: USAFA Parents Club <info@alabamafalcons.org>\r\nContent-Type: text/plain; charset=UTF-8\r\n");
            }
            flash('success', 'Ticket updated.');
        }
    }

    header('Location: ticket-view.php?id=' . $id); exit;
}

// Reload after POST
$stmt->execute([$id]);
$ticket = $stmt->fetch();

// Comments (non-tech see only public; tech see all)
$comments_sql = can_manage_tickets()
    ? 'SELECT c.*, u.name as author_name, u.role as author_role FROM ticket_comments c LEFT JOIN users u ON c.user_id=u.id WHERE c.ticket_id=? ORDER BY c.created_at ASC'
    : 'SELECT c.*, u.name as author_name, u.role as author_role FROM ticket_comments c LEFT JOIN users u ON c.user_id=u.id WHERE c.ticket_id=? AND c.is_internal=0 ORDER BY c.created_at ASC';
$comments = $pdo->prepare($comments_sql);
$comments->execute([$id]);
$comments = $comments->fetchAll();

$tech_users = $pdo->query("SELECT id,name FROM users WHERE role IN ('admin','tech') AND active=1 ORDER BY name")->fetchAll();

$sc = ['open'=>['bg'=>'#fff3cd','text'=>'#5f4c00','border'=>'#ffc107'],
       'in_progress'=>['bg'=>'#e3f2fd','text'=>'#0d47a1','border'=>'#90caf9'],
       'resolved'=>['bg'=>'#e8f5e9','text'=>'#1b5e20','border'=>'#a5d6a7']][$ticket['status']] ?? [];

admin_header('Ticket ' . h($ticket['ticket_number']));
echo show_flash();
?>
<style>
.comment-bubble{border-radius:6px;padding:.85rem 1rem;margin-bottom:.75rem;font-size:.88rem;line-height:1.6}
.comment-meta{font-size:.72rem;color:#9aa5b4;margin-bottom:.3rem}
.internal-note{background:#fff8e1;border-left:3px solid #ffc107}
.user-comment{background:#f0f4ff;border-left:3px solid #90caf9}
.tech-comment{background:#f1f8e9;border-left:3px solid #a5d6a7}
</style>

<div class="page-head">
  <h1><?= h($ticket['ticket_number']) ?> — <?= h($ticket['subject']) ?></h1>
  <a href="helpdesk.php" class="btn btn-secondary">← All Tickets</a>
</div>

<div style="display:grid;grid-template-columns:1fr 280px;gap:1.25rem;align-items:start">

  <!-- Main content -->
  <div>
    <!-- Ticket details -->
    <div class="card" style="padding:1.25rem;margin-bottom:1rem">
      <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:.5rem;margin-bottom:.75rem">
        <div>
          <span style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em"><?= h($ticket['category']) ?></span>
          · <span style="font-size:.75rem;font-weight:700;color:<?= ['high'=>'#A6192E','medium'=>'#f57c00','low'=>'#5a6a7a'][$ticket['priority']] ?>"><?= ucfirst($ticket['priority']) ?> priority</span>
        </div>
        <span style="background:<?= $sc['bg']?? '#eee' ?>;color:<?= $sc['text']??'#333' ?>;border:1px solid <?= $sc['border']??'#ccc' ?>;border-radius:4px;padding:.2rem .65rem;font-size:.78rem;font-weight:700"><?= TICKET_STATUSES[$ticket['status']] ?></span>
      </div>
      <p style="color:#333;line-height:1.7;white-space:pre-wrap"><?= h($ticket['description']) ?></p>
      <div style="font-size:.75rem;color:#9aa5b4;margin-top:.75rem">
        Submitted by <?= h($ticket['submitter_name']??'Unknown') ?> on <?= h(date('F j, Y g:ia', strtotime($ticket['created_at']))) ?>
        <?php if ($ticket['assigned_name']): ?> · Assigned to <?= h($ticket['assigned_name']) ?><?php endif; ?>
      </div>
    </div>

    <!-- Comments -->
    <?php foreach ($comments as $c):
      $cls = $c['is_internal'] ? 'internal-note' : (in_array($c['author_role'],['admin','tech']) ? 'tech-comment' : 'user-comment');
    ?>
    <div class="comment-bubble <?= $cls ?>">
      <div class="comment-meta">
        <strong><?= h($c['author_name'] ?? 'Unknown') ?></strong>
        <?= in_array($c['author_role'],['admin','tech']) ? '<span style="background:#002554;color:#fff;font-size:.62rem;padding:.1rem .35rem;border-radius:3px;margin-left:.3rem">SUPPORT</span>' : '' ?>
        <?= $c['is_internal'] ? '<span style="background:#ffc107;color:#5f4c00;font-size:.62rem;padding:.1rem .35rem;border-radius:3px;margin-left:.3rem">INTERNAL</span>' : '' ?>
        · <?= h(date('M j, Y g:ia', strtotime($c['created_at']))) ?>
      </div>
      <?= nl2br(h($c['comment'])) ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($comments)): ?><p style="color:#9aa5b4;font-size:.85rem;margin-bottom:1rem">No comments yet.</p><?php endif; ?>

    <!-- Add comment -->
    <?php if (can_manage_tickets() || $is_mine): ?>
    <div class="card" style="padding:1.25rem">
      <h2 style="margin-bottom:.75rem">Add <?= can_manage_tickets() ? 'Reply or Note' : 'Details' ?></h2>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="comment">
        <div class="form-group">
          <textarea name="comment" rows="4" required placeholder="Type your message…"></textarea>
        </div>
        <?php if (can_manage_tickets()): ?>
        <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem">
          <input type="checkbox" name="is_internal" id="is_internal" value="1" style="width:auto">
          <label for="is_internal" style="font-size:.82rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0;color:#5a6a7a">Internal note (only visible to support staff)</label>
        </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">Post <?= can_manage_tickets() ? 'Reply' : 'Details' ?></button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <!-- Sidebar: status management (tech/admin only) -->
  <?php if (can_manage_tickets()): ?>
  <div class="card" style="padding:1.25rem">
    <h2 style="margin-bottom:1rem">Manage Ticket</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="update_status">
      <div class="form-group">
        <label>Status</label>
        <select name="new_status">
          <?php foreach (TICKET_STATUSES as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $ticket['status']===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Assign To</label>
        <select name="assigned_to">
          <option value="">— Unassigned —</option>
          <?php foreach ($tech_users as $u): ?>
            <option value="<?= $u['id'] ?>" <?= (int)($ticket['assigned_to']??0)===(int)$u['id']?'selected':''?>><?= h($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary" style="width:100%">Update</button>
    </form>
  </div>
  <?php endif; ?>

</div>

<?php admin_footer(); ?>
