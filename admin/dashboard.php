<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo  = get_pdo();
$role = $_SESSION['role'] ?? 'member';
$name = current_user_name();

// ── Gather data for alerts and stats ──────────────────────────────────────
$stats = [];

// Member stats — load for all roles (Members tile shown to everyone)
if (true) {
    $ms = $pdo->query("SELECT COUNT(*) as total,
                              SUM(membership_paid) as paid,
                              SUM(CASE WHEN class_year!='2026' AND membership_paid=0 THEN 1 ELSE 0 END) as unpaid,
                              SUM(CASE WHEN created_at>=DATE_FORMAT(NOW(),'%Y-%m-01') THEN 1 ELSE 0 END) as new_month
                       FROM members WHERE archived=0")->fetch();
    $stats['members'] = $ms;
}

// Finance stats
if (can_manage_finances()) {
    $fin = $pdo->query("SELECT
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status='reimbursed' AND YEAR(purchase_date)=YEAR(NOW()) THEN amount_total ELSE 0 END) as ytd
        FROM purchases")->fetch();
    $stats['finance'] = $fin;

    // My own pending submissions (for member/secretary role)
    if (is_member() || is_secretary()) {
        $my_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE submitted_by=? AND status='pending'");
        $my_stmt->execute([$_SESSION['user_id']??0]);
        $stats['my_pending'] = (int)$my_stmt->fetchColumn();
    }
}

// Helpdesk stats
$hd = $pdo->query("SELECT
    SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) as open_count,
    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as inprog_count,
    SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as resolved_count
    FROM tickets")->fetch();
$stats['tickets'] = $hd;

// Extra treasurer finance breakdown
if (is_treasurer()) {
    $tfin = $pdo->query("SELECT
        SUM(CASE WHEN status='pending'    THEN amount_total ELSE 0 END) as pending_amt,
        SUM(CASE WHEN status='approved'   THEN amount_total ELSE 0 END) as approved_amt,
        SUM(CASE WHEN status='reimbursed' AND YEAR(purchase_date)=YEAR(NOW()) THEN amount_total ELSE 0 END) as reimbursed_ytd,
        SUM(CASE WHEN status='reimbursed' AND YEAR(purchase_date)=YEAR(NOW()) THEN amount_tax    ELSE 0 END) as tax_ytd,
        SUM(CASE WHEN YEAR(purchase_date)=YEAR(NOW()) THEN amount_total ELSE 0 END) as all_ytd,
        COUNT(CASE WHEN status='reimbursed' AND YEAR(purchase_date)=YEAR(NOW()) THEN 1 END) as reimbursed_count
        FROM purchases")->fetch();
    $stats['tfin'] = $tfin;

    // Budget utilization
    $budgets_row = $pdo->query("SELECT COUNT(*) as cnt,
        SUM(b.budget) as total_budget,
        SUM((SELECT COALESCE(SUM(p.amount_total),0) FROM purchases p WHERE p.event=b.event)) as total_spent
        FROM event_budgets b")->fetch();
    $stats['budgets'] = $budgets_row;
}

// My tickets
$mt_stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE submitted_by=? AND status!='resolved'");
$mt_stmt->execute([$_SESSION['user_id']??0]);
$stats['my_open_tickets'] = (int)$mt_stmt->fetchColumn();

// Upcoming birthdays (7 days)
$bday_soon = 0;
if (!is_member()) {
    try {
        $b_stmt = $pdo->query("SELECT COUNT(*) FROM members WHERE archived=0 AND class_year!='2026'
            AND cadet_birthday IS NOT NULL AND cadet_birthday!=''
            AND (DAYOFYEAR(cadet_birthday)-DAYOFYEAR(NOW()) BETWEEN 0 AND 7
                 OR DAYOFYEAR(cadet_birthday)-DAYOFYEAR(NOW())+365 BETWEEN 0 AND 7)");
        $bday_soon = (int)$b_stmt->fetchColumn();
    } catch(Exception $e) {}
}

// Role display label and color
$role_display = ['admin'=>'Admin','tech'=>'Tech Support','officer'=>'Officer',
                 'secretary'=>'Secretary','treasurer'=>'Treasurer','member'=>'Member'];
$role_colors  = ['admin'=>'#002554','tech'=>'#bf360c','officer'=>'#1a237e',
                 'secretary'=>'#5c007a','treasurer'=>'#1b5e20','member'=>'#7b3f00'];
$role_label = $role_display[$role] ?? ucfirst($role);
$role_color = $role_colors[$role] ?? '#5a6a7a';

// ── Quick action definitions by role ──────────────────────────────────────
$actions = [];

// Members tile for all roles
$mem_total = $stats['members']['total'] ?? null;
$actions[] = ['icon'=>'👥','label'=>'Members','sub'=>$mem_total!==null?$mem_total.' active':'View roster','href'=>'index.php','color'=>'#002554'];

if (can_manage_members()) {
    $actions[] = ['icon'=>'➕','label'=>'Add Member','sub'=>'Add new cadet','href'=>'add.php','color'=>'#003594'];
    $actions[] = ['icon'=>'📅','label'=>'Events','sub'=>'Manage site events','href'=>'events.php','color'=>'#1565c0'];
    $actions[] = ['icon'=>'👥','label'=>'Leadership','sub'=>'Update officer profiles','href'=>'leadership.php','color'=>'#002554'];
    $actions[] = ['icon'=>'📣','label'=>'Announcements','sub'=>'Site banner notices','href'=>'announcements.php','color'=>'#b71c1c'];
    $actions[] = ['icon'=>'🖼️','label'=>'Gallery','sub'=>'Upload event photos','href'=>'gallery.php','color'=>'#1b5e20'];
    $actions[] = ['icon'=>'🏆','label'=>'Sponsors','sub'=>'Manage sponsor listings','href'=>'sponsors.php','color'=>'#f57f17'];
    $actions[] = ['icon'=>'📋','label'=>'Lists','sub'=>'Email & contact lists','href'=>'lists.php','color'=>'#1565c0'];
    $actions[] = ['icon'=>'✉️','label'=>'Email Members','sub'=>'Compose blast','href'=>'email.php','color'=>'#6a1b9a'];
    $actions[] = ['icon'=>'📖','label'=>'Directory','sub'=>'Printable roster','href'=>'directory.php','color'=>'#1b5e20'];
}

if (can_manage_finances()) {
    $pending  = $stats['finance']['pending_count']  ?? 0;
    $approved = $stats['finance']['approved_count'] ?? 0;
    $actions[] = ['icon'=>'💰','label'=>'Finance','sub'=>$pending>0?"$pending need approval":'View purchases','href'=>'purchases.php','color'=>$pending>0?'#A6192E':'#1b5e20','badge'=>$pending>0?$pending:0];
    $actions[] = ['icon'=>'🧾','label'=>'Add Purchase','sub'=>'Submit an expense','href'=>'purchase-form.php','color'=>'#003594'];
    if (is_treasurer()) {
        $actions[] = ['icon'=>'💳','label'=>'Reimburse','sub'=>$approved>0?"$approved approved":'Nothing pending','href'=>'pending-reimbursements.php','color'=>$approved>0?'#003594':'#5a6a7a','badge'=>$approved>0?$approved:0];
        $actions[] = ['icon'=>'📊','label'=>'Reports','sub'=>'Year-end & budgets','href'=>'report.php','color'=>'#37474f'];
        $actions[] = ['icon'=>'🏆','label'=>'Sponsors','sub'=>'Manage sponsor listings','href'=>'sponsors.php','color'=>'#f57f17'];
    }
}

// Helpdesk — one card for all roles
$open = ($stats['tickets']['open_count'] ?? 0) + ($stats['tickets']['inprog_count'] ?? 0);
if (can_manage_tickets()) {
    $actions[] = ['icon'=>'🎫','label'=>'Support Tickets','sub'=>$open>0?"$open open":'All clear','href'=>'helpdesk.php','color'=>$open>0?'#f57c00':'#1b5e20','badge'=>$open>0?$open:0];
} else {
    $my_open = $stats['my_open_tickets'];
    $actions[] = ['icon'=>'🎫','label'=>'Support','sub'=>$my_open>0?"$my_open open ticket".($my_open>1?'s':''):'Submit a ticket','href'=>'helpdesk.php','color'=>$my_open>0?'#f57c00':'#5a6a7a','badge'=>$my_open>0?$my_open:0];
}

$actions[] = ['icon'=>'🔑','label'=>'My Password','sub'=>'Change password','href'=>'change-password.php','color'=>'#546e7a'];

if (is_super_admin()) {
    $actions[] = ['icon'=>'👤','label'=>'Users','sub'=>'Manage accounts','href'=>'users.php','color'=>'#37474f'];
}

admin_header('Dashboard');
?>
<style>
.welcome-banner{background:linear-gradient(135deg,#002554 0%,#003594 60%,#1565c0 100%);border-radius:8px;padding:1.5rem 2rem;color:#fff;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem}
.welcome-name{font-size:1.6rem;font-weight:700;line-height:1.2}
.welcome-sub{font-size:.85rem;opacity:.7;margin-top:.25rem}
.role-pill{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:99px;padding:.3rem .9rem;font-size:.78rem;font-weight:700;letter-spacing:.04em;white-space:nowrap}
.action-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.85rem;margin-bottom:1.5rem}
.action-card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.25rem 1rem;text-decoration:none;color:#1a2332;display:flex;flex-direction:column;align-items:center;text-align:center;gap:.4rem;transition:all .2s;border:2px solid transparent;position:relative}
.action-card:hover{border-color:#003594;box-shadow:0 4px 16px rgba(0,0,0,.12);transform:translateY(-2px);text-decoration:none;color:#002554}
.action-icon{font-size:2rem;line-height:1}
.action-label{font-size:.82rem;font-weight:700;letter-spacing:.02em}
.action-sub{font-size:.7rem;color:#5a6a7a;line-height:1.3}
.action-badge{position:absolute;top:-.4rem;right:-.4rem;background:#A6192E;color:#fff;font-size:.62rem;font-weight:700;padding:.15rem .4rem;border-radius:99px;min-width:18px;text-align:center}
.alert-strip{display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1.25rem}
.alert-chip{display:flex;align-items:center;gap:.45rem;padding:.55rem .9rem;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none}
.alert-chip:hover{text-decoration:none;filter:brightness(.95)}
.mini-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.6rem;margin-bottom:1.5rem}
.mini-stat{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:.85rem 1rem;text-align:center}
.mini-stat-val{font-size:1.5rem;font-weight:700}
.mini-stat-lbl{font-size:.65rem;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em;margin-top:.15rem}
@media(max-width:500px){.action-grid{grid-template-columns:repeat(2,1fr)}.welcome-name{font-size:1.3rem}}
</style>

<!-- Welcome banner -->
<div class="welcome-banner">
  <div>
    <div class="welcome-name">Welcome back, <?= h($name) ?>!</div>
    <div class="welcome-sub"><?= date('l, F j, Y') ?></div>
  </div>
  <span class="role-pill" style="background:<?= $role_color ?>55;border-color:<?= $role_color ?>88"><?= h($role_label) ?></span>
</div>

<?php
// ── Build alerts ───────────────────────────────────────────────────────────
$alerts = [];
if (can_manage_finances()) {
    $p = $stats['finance']['pending_count'] ?? 0;
    $a = $stats['finance']['approved_count'] ?? 0;
    if ($p) $alerts[] = ['bg'=>'#fff3cd','border'=>'#ffc107','text'=>'#5f4c00','icon'=>'⏳','msg'=>"$p purchase".($p>1?'s':'')." need approval",'href'=>'purchases.php?status=pending'];
    if ($a && is_treasurer()) $alerts[] = ['bg'=>'#e3f2fd','border'=>'#90caf9','text'=>'#0d47a1','icon'=>'💳','msg'=>"$a awaiting reimbursement",'href'=>'pending-reimbursements.php'];
}
if (can_manage_tickets() && $open > 0)
    $alerts[] = ['bg'=>'#fff8e1','border'=>'#ffcc02','text'=>'#5f4c00','icon'=>'🎫','msg'=>"$open support ticket".($open>1?'s':'')." open",'href'=>'helpdesk.php'];
if ($bday_soon > 0)
    $alerts[] = ['bg'=>'#f3e5f5','border'=>'#ce93d8','text'=>'#4a148c','icon'=>'🎂','msg'=>"$bday_soon birthday".($bday_soon>1?'s':'')." in the next 7 days",'href'=>'index.php#bday-panel'];
if ($stats['my_open_tickets'] > 0 && !can_manage_tickets())
    $alerts[] = ['bg'=>'#e8f5e9','border'=>'#a5d6a7','text'=>'#1b5e20','icon'=>'🎫','msg'=>"You have ".$stats['my_open_tickets']." open ticket".($stats['my_open_tickets']>1?'s':''),'href'=>'helpdesk.php?mine=1'];
?>
<?php if (!empty($alerts)): ?>
<div class="alert-strip">
  <?php foreach ($alerts as $al): ?>
  <a href="<?= h($al['href']) ?>" class="alert-chip" style="background:<?= $al['bg'] ?>;border:1px solid <?= $al['border'] ?>;color:<?= $al['text'] ?>">
    <?= $al['icon'] ?> <?= h($al['msg']) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Quick actions -->
<p style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.6rem">Quick Actions</p>
<div class="action-grid">
  <?php foreach ($actions as $ac): ?>
  <a href="<?= h($ac['href']) ?>" class="action-card">
    <?php if (!empty($ac['badge'])): ?>
    <span class="action-badge"><?= (int)$ac['badge'] ?></span>
    <?php endif; ?>
    <span class="action-icon"><?= $ac['icon'] ?></span>
    <span class="action-label" style="color:<?= $ac['color'] ?>"><?= h($ac['label']) ?></span>
    <span class="action-sub"><?= h($ac['sub'] ?? '') ?></span>
  </a>
  <?php endforeach; ?>
</div>

<!-- Mini stats -->

<?php if (is_treasurer() && !empty($stats['tfin'])): $tf = $stats['tfin']; ?>
<p style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.6rem">Finance — <?= date('Y') ?> Detail</p>
<div class="mini-stats" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
  <div class="mini-stat" style="border-left:3px solid #A6192E">
    <div class="mini-stat-val" style="color:#A6192E">$<?= number_format($tf['all_ytd']??0,2) ?></div>
    <div class="mini-stat-lbl">All Purchases YTD</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #1b5e20">
    <div class="mini-stat-val" style="color:#1b5e20">$<?= number_format($tf['reimbursed_ytd']??0,2) ?></div>
    <div class="mini-stat-lbl">Reimbursed YTD (<?= (int)($tf['reimbursed_count']??0) ?>)</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #003594">
    <div class="mini-stat-val" style="color:#003594">$<?= number_format($tf['approved_amt']??0,2) ?></div>
    <div class="mini-stat-lbl">Approved — Unpaid</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #f57c00">
    <div class="mini-stat-val" style="color:#f57c00">$<?= number_format($tf['pending_amt']??0,2) ?></div>
    <div class="mini-stat-lbl">Pending Approval</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #5a6a7a">
    <div class="mini-stat-val" style="color:#5a6a7a">$<?= number_format($tf['tax_ytd']??0,2) ?></div>
    <div class="mini-stat-lbl">Tax Paid YTD</div>
  </div>
  <?php if (!empty($stats['budgets']) && $stats['budgets']['total_budget'] > 0):
    $bu = $stats['budgets'];
    $bpct = round($bu['total_spent']/$bu['total_budget']*100);
    $bcol = $bpct>=100?'#A6192E':($bpct>=75?'#f57c00':'#1b5e20');
  ?>
  <div class="mini-stat" style="border-left:3px solid <?= $bcol ?>">
    <div class="mini-stat-val" style="color:<?= $bcol ?>"><?= $bpct ?>%</div>
    <div class="mini-stat-lbl">Budget Used (<?= (int)$bu['cnt'] ?> events)</div>
  </div>
  <?php endif; ?>
</div>
<div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem">
  <a href="year-end.php" class="btn btn-secondary btn-sm">📋 Year-End Report</a>
  <a href="budgets.php" class="btn btn-secondary btn-sm">🎯 Manage Budgets</a>
  <a href="report.php" class="btn btn-secondary btn-sm">📊 Spending Report</a>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
