<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$filter_status   = $_GET['status']   ?? '';
$filter_category = $_GET['category'] ?? '';
$mine            = isset($_GET['mine']);

$where  = ['1=1'];
$params = [];
if ($filter_status   !== '') { $where[] = 't.status = :status';   $params[':status']   = $filter_status; }
if ($filter_category !== '') { $where[] = 't.category = :cat';    $params[':cat']      = $filter_category; }
if ($mine)                   { $where[] = 't.submitted_by = :me'; $params[':me']       = $_SESSION['user_id'] ?? 0; }

$sql = 'SELECT t.*, u.name as submitter_name,
               (SELECT COUNT(*) FROM ticket_comments c WHERE c.ticket_id=t.id AND c.is_internal=0) as comment_count
        FROM tickets t
        LEFT JOIN users u ON t.submitted_by = u.id
        WHERE ' . implode(' AND ', $where) . '
        ORDER BY FIELD(t.status,"open","in_progress","resolved"), t.created_at DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

$status_colors = ['open'=>['bg'=>'#fff3cd','text'=>'#5f4c00','border'=>'#ffc107'],
                  'in_progress'=>['bg'=>'#e3f2fd','text'=>'#0d47a1','border'=>'#90caf9'],
                  'resolved'=>['bg'=>'#e8f5e9','text'=>'#1b5e20','border'=>'#a5d6a7']];
$priority_colors = ['high'=>'#A6192E','medium'=>'#f57c00','low'=>'#5a6a7a'];

admin_header('Support');
echo show_flash();
?>
<style>
.ticket-row{border-left:4px solid #e1e5eb;transition:border-color .2s}
.ticket-row:hover{border-left-color:#003594}
.t-badge{display:inline-block;padding:.15rem .55rem;border-radius:3px;font-size:.7rem;font-weight:700;white-space:nowrap}
</style>

<div class="page-head">
  <h1>Support Tickets</h1>
  <a href="ticket-new.php" class="btn btn-primary">+ Submit Ticket</a>
</div>

<div class="card" style="padding:1rem 1.5rem;margin-bottom:1rem">
  <form method="GET" class="filter-bar">
    <div class="form-group">
      <label>Status</label>
      <select name="status">
        <option value="">All</option>
        <?php foreach (TICKET_STATUSES as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $filter_status===$k?'selected':''?>><?= $v ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Category</label>
      <select name="category">
        <?php foreach (TICKET_CATEGORIES as $c): ?>
          <option value="<?= h($c) ?>" <?= $filter_category===$c?'selected':''?>><?= $c===''?'All Categories':h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:0;align-self:flex-end">
      <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin-bottom:.55rem">
        <input type="checkbox" name="mine" value="1" <?= $mine?'checked':''?> style="width:auto"> My tickets only
      </label>
    </div>
    <div class="form-group" style="flex:0">
      <label>&nbsp;</label>
      <div style="display:flex;gap:.5rem">
        <button type="submit" class="btn btn-primary">Filter</button>
        <a href="helpdesk.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>
</div>

<div class="card" style="padding:0;overflow-x:auto">
<table>
  <thead>
    <tr>
      <th>Ticket #</th>
      <th>Category</th>
      <th>Subject</th>
      <th>Status</th>
      <th>Priority</th>
      <th>Submitted By</th>
      <th>Date</th>
      <th>Comments</th>
      <th class="actions-head">Action</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($tickets)): ?>
    <tr><td colspan="9" style="text-align:center;padding:2rem;color:#5a6a7a">No tickets found.</td></tr>
  <?php endif; ?>
  <?php foreach ($tickets as $t):
    $sc = $status_colors[$t['status']] ?? $status_colors['open'];
    $mine_row = (int)($t['submitted_by']??-1) === (int)($_SESSION['user_id']??0);
  ?>
    <tr class="ticket-row" style="<?= $mine_row?'background:#fafbff':'' ?>">
      <td style="font-family:monospace;font-weight:700;color:#002554"><?= h($t['ticket_number']) ?></td>
      <td style="font-size:.78rem;color:#5a6a7a"><?= h($t['category']) ?></td>
      <td><strong><?= h($t['subject']) ?></strong></td>
      <td><span class="t-badge" style="background:<?= $sc['bg'] ?>;color:<?= $sc['text'] ?>;border:1px solid <?= $sc['border'] ?>"><?= TICKET_STATUSES[$t['status']] ?></span></td>
      <td><span style="font-size:.75rem;font-weight:700;color:<?= $priority_colors[$t['priority']] ?>"><?= ucfirst($t['priority']) ?></span></td>
      <td style="font-size:.78rem"><?= h($t['submitter_name'] ?? '—') ?><?= $mine_row?' <span style="color:#003594;font-size:.68rem">(you)</span>':'' ?></td>
      <td style="font-size:.78rem;white-space:nowrap;color:#5a6a7a"><?= h(date('M j, Y', strtotime($t['created_at']))) ?></td>
      <td style="text-align:center;color:#5a6a7a"><?= $t['comment_count'] ?: '—' ?></td>
      <td class="actions">
        <a href="ticket-view.php?id=<?= (int)$t['id'] ?>" class="btn btn-secondary btn-sm">View</a>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php admin_footer(); ?>
