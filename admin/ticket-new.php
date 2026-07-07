<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo    = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $category    = trim($_POST['category']    ?? '');
    $subject     = trim($_POST['subject']     ?? '');
    $description = trim($_POST['description'] ?? '');
    $priority    = $_POST['priority'] ?? 'medium';

    if (!$category)    $errors[] = 'Category is required.';
    if (!$subject)     $errors[] = 'Subject is required.';
    if (!$description) $errors[] = 'Please describe the issue.';
    if (!in_array($priority, array_keys(TICKET_PRIORITIES))) $priority = 'medium';

    if (empty($errors)) {
        // Generate ticket number
        $max = (int)$pdo->query('SELECT COUNT(*) FROM tickets')->fetchColumn();
        $ticket_num = 'TICK-' . str_pad($max + 1, 4, '0', STR_PAD_LEFT);

        $pdo->prepare('INSERT INTO tickets (ticket_number,category,subject,description,priority,status,submitted_by) VALUES (?,?,?,?,?,?,?)')
            ->execute([$ticket_num, $category, $subject, $description, $priority, 'open', $_SESSION['user_id'] ?? null]);
        $ticket_id = (int)$pdo->lastInsertId();

        // Notify tech support and admins
        try {
            $techs = $pdo->query("SELECT name,email FROM users WHERE role IN ('admin','tech') AND active=1")->fetchAll();
            foreach ($techs as $t) {
                $sub  = "New Support Ticket $ticket_num: $subject";
                $body = "USAFA Parents Club of Alabama\nNew Support Ticket\n" . str_repeat('─',48) . "\n\n"
                      . "Ticket:    $ticket_num\n"
                      . "Category:  $category\n"
                      . "Priority:  " . ucfirst($priority) . "\n"
                      . "From:      " . ($t['name'] ?? 'Unknown') . "\n\n"
                      . "Issue:\n$description\n\n"
                      . "View ticket: https://alabamafalcons.org/admin/ticket-view.php?id=$ticket_id\n\n"
                      . str_repeat('─',48) . "\nalabamafalcons.org/admin/";
                mail($t['email'], $sub, $body, "From: USAFA Parents Club of Alabama <info@alabamafalcons.org>\r\nContent-Type: text/plain; charset=UTF-8\r\n");
            }
        } catch (Exception $e) { error_log('ticket-new: notify failed — ' . $e->getMessage()); }

        flash('success', "Ticket $ticket_num submitted. Tech support has been notified.");
        header('Location: ticket-view.php?id=' . $ticket_id); exit;
    }
}

admin_header('Submit Support Ticket');
?>

<div class="page-head">
  <h1>Submit Support Ticket</h1>
  <a href="helpdesk.php" class="btn btn-secondary">← All Tickets</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
<?php endif; ?>

<div class="card" style="max-width:640px">
  <form method="POST">
    <?= csrf_field() ?>

    <div class="form-row col-2">
      <div class="form-group">
        <label>Category *</label>
        <select name="category" required>
          <?php foreach (TICKET_CATEGORIES as $c): ?>
            <option value="<?= h($c) ?>" <?= (($_POST['category']??'')===$c)?'selected':''?>><?= $c===''?'— select —':h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Priority</label>
        <select name="priority">
          <?php foreach (TICKET_PRIORITIES as $k=>$v): ?>
            <option value="<?= $k ?>" <?= (($_POST['priority']??'medium')===$k)?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group">
      <label>Subject *</label>
      <input name="subject" value="<?= h($_POST['subject']??'') ?>" required placeholder="Brief summary of the issue">
    </div>

    <div class="form-group">
      <label>Describe the Issue *</label>
      <textarea name="description" rows="6" required
        placeholder="Please provide as much detail as possible: what happened, what you were trying to do, any error messages you saw…"><?= h($_POST['description']??'') ?></textarea>
    </div>

    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary">Submit Ticket</button>
      <a href="helpdesk.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
