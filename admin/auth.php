<?php
// Shared utilities — never served directly (blocked by .htaccess)

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('usafa_admin');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }
}

function require_login(): void {
    start_session();
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

function is_treasurer(): bool {
    return ($_SESSION['role'] ?? '') === 'treasurer';
}

function is_viewer(): bool {
    // "viewer" for member editing purposes: cannot edit members
    return !can_manage_members();
}

function require_member_admin(): void {
    require_login();
    if (!can_manage_members()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

function is_member(): bool {
    return ($_SESSION['role'] ?? '') === 'member';
}

function is_secretary(): bool {
    return ($_SESSION['role'] ?? '') === 'secretary';
}

function is_tech(): bool {
    return ($_SESSION['role'] ?? '') === 'tech';
}

function is_officer(): bool {
    return ($_SESSION['role'] ?? '') === 'officer';
}

// Admin or Tech have full super-admin access (users mgmt + helpdesk mgmt)
function is_super_admin(): bool {
    return is_admin() || is_tech();
}

// Admin, Tech, Officer — full club officer-level access
function is_club_officer(): bool {
    return is_super_admin() || is_officer();
}

// Admin, Tech, Officer, or Secretary can fully manage cadet/member records
function can_manage_members(): bool {
    return is_club_officer() || is_secretary();
}

// Admin and Tech only can manage helpdesk tickets
function can_manage_tickets(): bool {
    return is_super_admin();
}

function can_manage_finances(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'tech', 'officer', 'treasurer', 'member', 'secretary']);
}

// Admin/Treasurer can edit any purchase; Member/Secretary can only edit their own
function can_edit_purchase(array $purchase): bool {
    if (is_admin() || is_treasurer()) return true;
    if (is_member() || is_secretary()) return (int)($purchase['submitted_by'] ?? -1) === (int)($_SESSION['user_id'] ?? 0);
    return false;
}

function current_user_name(): string {
    return $_SESSION['user_name'] ?? 'Unknown';
}

function require_admin(): void {
    require_login();
    if (!is_super_admin()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

function require_finance(): void {
    require_login();
    if (!can_manage_finances()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

// Verify credentials against the users table
function verify_login(PDO $pdo, string $username, string $password): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE (username = :u OR email = :u) AND active = 1 LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
        return $user;
    }
    return null;
}

// Check if the users table exists and has at least one user
function users_table_empty(PDO $pdo): bool {
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    } catch (Exception $e) {
        return true;
    }
}

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    require_once __DIR__ . '/config.php';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => true]
    );
    return $pdo;
}

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_field(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function csrf_verify(): void {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(403); die('Invalid request token.');
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function show_flash(): string {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
}

function admin_css(): string { return '
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f0f2f5;color:#1a2332;font-size:15px}
a{color:#003594;text-decoration:none}
a:hover{text-decoration:underline}
.topbar{background:#002554;color:#fff;padding:.75rem 1.5rem;display:flex;justify-content:space-between;align-items:center;gap:1rem}
.topbar-title{font-weight:700;font-size:1rem;letter-spacing:.03em}
.topbar-title small{font-weight:400;opacity:.65;margin-left:.5rem;font-size:.8rem}
.topbar nav{display:flex;gap:1.25rem;align-items:center}
.topbar nav a{color:rgba(255,255,255,.8);font-size:.85rem}
.topbar nav a:hover{color:#fff;text-decoration:none}
.main{max-width:1300px;margin:2rem auto;padding:0 1rem}
.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem}
h1{font-size:1.4rem;color:#002554}
h2{font-size:1rem;color:#002554;margin-bottom:.75rem;font-weight:700}
.card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;margin-bottom:1.5rem}
table{width:100%;border-collapse:collapse;font-size:.85rem}
th{text-align:left;padding:.55rem .75rem;background:#f5f7fa;border-bottom:2px solid #e1e5eb;color:#5a6a7a;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
td{padding:.55rem .75rem;border-bottom:1px solid #f0f2f5;vertical-align:top}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#f8f9fb}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.45rem 1rem;border-radius:4px;font-size:.82rem;font-weight:600;border:none;cursor:pointer;letter-spacing:.02em;text-decoration:none}
.btn:hover{text-decoration:none}
.btn-primary{background:#003594;color:#fff}
.btn-primary:hover{background:#002268}
.btn-danger{background:#A6192E;color:#fff}
.btn-danger:hover{background:#8a1425}
.btn-secondary{background:#f0f2f5;color:#333;border:1px solid #d0d5dd}
.btn-secondary:hover{background:#e5e8ec}
.btn-sm{padding:.28rem .65rem;font-size:.75rem}
.btn-group{display:flex;gap:.4rem}
input,select,textarea{width:100%;padding:.55rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem;color:#1a2332;background:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
textarea{resize:vertical;min-height:75px}
label{display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em}
.form-group{margin-bottom:.9rem}
.form-row{display:grid;gap:.9rem}
.col-2{grid-template-columns:1fr 1fr}
.col-3{grid-template-columns:1fr 1fr 1fr}
.col-4{grid-template-columns:1fr 1fr 1fr 1fr}
fieldset{border:1px solid #e1e5eb;border-radius:6px;padding:1.1rem 1.25rem;margin-bottom:1.25rem}
legend{font-weight:700;color:#002554;font-size:.82rem;padding:0 .4rem;letter-spacing:.04em;text-transform:uppercase}
.alert{padding:.75rem 1rem;border-radius:4px;margin-bottom:1rem;font-size:.9rem}
.alert-success{background:#e8f5e9;color:#2e7d32;border-left:4px solid #4caf50}
.alert-error{background:#ffebee;color:#c62828;border-left:4px solid #f44336}
.badge{display:inline-block;padding:.15rem .45rem;border-radius:3px;font-size:.7rem;font-weight:700;white-space:nowrap}
.badge-North{background:#e3f2fd;color:#0d47a1}
.badge-Central{background:#f3e5f5;color:#4a148c}
.badge-South{background:#e8f5e9;color:#1b5e20}
.badge-paid{background:#e8f5e9;color:#1b5e20}
.badge-unpaid{background:#ffebee;color:#c62828}
.filter-bar{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem}
.filter-bar .form-group{margin:0;flex:1;min-width:130px}
.count{font-size:.82rem;color:#5a6a7a;margin-top:.5rem}
.actions{white-space:nowrap;position:sticky;right:0;background:#fff;box-shadow:-3px 0 6px rgba(0,0,0,.06)}
tbody tr:hover .actions{background:#f8f9fb}
th.actions-head{position:sticky;right:0;background:#f5f7fa;box-shadow:-3px 0 6px rgba(0,0,0,.06)}
@media(max-width:640px){.col-2,.col-3,.col-4{grid-template-columns:1fr}.topbar{flex-direction:column;align-items:flex-start}}
</style>
'; }

function admin_header(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' — Members Admin</title>';
    echo '<link rel="icon" type="image/png" href="../logo01.png" />';
    echo admin_css();
    echo '</head><body>';
    echo '<div class="topbar">';
    echo '<a href="dashboard.php" class="topbar-title" style="color:#fff;text-decoration:none;display:flex;align-items:center;gap:.65rem"><img src="../logo01.png" alt="" style="height:32px;border-radius:3px"><span>USAFA Parents Club of Alabama <small>Member Admin</small></span></a>';
    echo '<nav>';
    echo '<a href="dashboard.php" title="Dashboard">🏠</a>';
    if (!is_member()) echo '<a href="index.php">Members</a>';
    if (can_manage_members()) echo '<a href="lists.php">Lists</a>';
    if (can_manage_members()) echo '<a href="email.php">Email</a>';
    if (can_manage_finances()) {
        $pending_cnt = 0;
        try { $pending_cnt = (int)get_pdo()->query("SELECT COUNT(*) FROM purchases WHERE status='pending'")->fetchColumn(); } catch(Exception $e) {}
        $badge = $pending_cnt > 0 ? ' <span style="background:#A6192E;color:#fff;font-size:.6rem;padding:.1rem .4rem;border-radius:99px;vertical-align:middle;font-weight:700">' . $pending_cnt . '</span>' : '';
        echo '<a href="purchases.php">Finance' . $badge . '</a>';
    }
    // Support ticket link — all users
    $open_tickets = 0;
    try { $open_tickets = (int)get_pdo()->query("SELECT COUNT(*) FROM tickets WHERE status != 'resolved'")->fetchColumn(); } catch(Exception $e) {}
    $tbadge = (can_manage_tickets() && $open_tickets > 0) ? ' <span style="background:#f57c00;color:#fff;font-size:.6rem;padding:.1rem .4rem;border-radius:99px;vertical-align:middle;font-weight:700">' . $open_tickets . '</span>' : '';
    echo '<a href="helpdesk.php">🎫 Support' . $tbadge . '</a>';
    if (is_super_admin()) echo '<a href="users.php">Users</a>';
    echo '<a href="change-password.php" style="font-size:.75rem;opacity:.55;color:rgba(255,255,255,.8);text-decoration:none;margin-left:.25rem" title="Change password">' . h(current_user_name()) . ' 🔑</a>';
    echo '<a href="logout.php">Log Out</a>';
    echo '</nav></div>';
    echo '<div class="main">';
}

function admin_footer(): void {
    echo '</div></body></html>';
}

const REGIONS = ['', 'North', 'Central', 'South'];
const PAYMENT_METHODS   = ['', 'Check', 'Internet Transfer', 'Other'];
const TICKET_CATEGORIES = ['', 'Website - Main Site', 'Admin Portal - Member Management', 'Admin Portal - Finance', 'Admin Portal - Lists / Email', 'Google Workspace / Gmail', 'Domain / Hosting', 'User Account / Password', 'Other'];
const TICKET_STATUSES   = ['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved'];
const TICKET_PRIORITIES = ['low'=>'Low','medium'=>'Medium','high'=>'High'];
const PURCHASE_CATEGORIES = ['', 'Supplies', 'Food & Beverages', 'Decorations', 'Postage / Shipping', 'Printing', 'Equipment', 'Venue / Facility', 'Transportation', 'Awards / Recognition', 'Technology / Domain Hosting', 'Non-Profit Fees', 'Other'];
const PURCHASE_EVENTS     = ['', 'Parents Weekend', 'Care Packages', 'Appointee Send-off', 'Taste of Home', 'Birthday / Gift', 'General Operations', 'Other'];
const PURCHASE_STATUSES   = ['pending' => 'Pending', 'approved' => 'Approved', 'reimbursed' => 'Reimbursed'];
const CLASS_YEARS = ['', '2026', '2027', '2028', '2029', '2030', 'Prep School', 'Graduate'];

const FIELDS = [
    'class_year','cadet_last_name','cadet_first_middle','nickname','cadet_birthday','cadet_po_box',
    'cadet_email','cadet_cell','bct_squadron','bct_flight','fall_squadron','squadron_yr2_4',
    'parent1_last_name','parent1_first_name','parent1_email','parent1_cell',
    'parent1_street','parent1_city','parent1_state','parent1_zip',
    'parent2_last_name','parent2_first_name','parent2_email','parent2_cell',
    'parent2_street','parent2_city','parent2_state','parent2_zip',
    'al_region','remarks','photo_consent','directory_consent','membership_paid','membership_year'
];

function membership_year(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return $m >= 7 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
}

function member_form(array $m = [], bool $is_edit = false): void {
    $v = function(string $k) use ($m): string {
        return h($m[$k] ?? '');
    };
    $sel = function(string $field, array $opts) use ($m): string {
        $out = '<select name="' . $field . '">';
        foreach ($opts as $o) {
            $s = ($m[$field] ?? '') === $o ? ' selected' : '';
            $out .= '<option value="' . h($o) . '"' . $s . '>' . ($o === '' ? '— select —' : h($o)) . '</option>';
        }
        return $out . '</select>';
    };

    echo '<fieldset><legend>Cadet</legend>';
    echo '<div class="form-row col-3">';
    echo '<div class="form-group"><label>Class Year *</label>' . $sel('class_year', CLASS_YEARS) . '</div>';
    echo '<div class="form-group"><label>Last Name *</label><input name="cadet_last_name" value="' . $v('cadet_last_name') . '" required></div>';
    echo '<div class="form-group"><label>First / Middle Name</label><input name="cadet_first_middle" value="' . $v('cadet_first_middle') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Nickname</label><input name="nickname" value="' . $v('nickname') . '"></div>';
    echo '<div class="form-group"><label>Birthday</label><input type="date" name="cadet_birthday" value="' . $v('cadet_birthday') . '"></div>';
    echo '<div class="form-group"><label>PO Box</label><input name="cadet_po_box" value="' . $v('cadet_po_box') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="cadet_cell" value="' . $v('cadet_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-2">';
    echo '<div class="form-group"><label>Email</label><input type="email" name="cadet_email" value="' . $v('cadet_email') . '"></div>';
    echo '</div>';
    echo '</fieldset>';

    echo '<fieldset><legend>Squadron Assignments</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>BCT Squadron</label><input name="bct_squadron" value="' . $v('bct_squadron') . '"></div>';
    echo '<div class="form-group"><label>BCT Flight</label><input name="bct_flight" value="' . $v('bct_flight') . '"></div>';
    echo '<div class="form-group"><label>Fall Squadron</label><input name="fall_squadron" value="' . $v('fall_squadron') . '"></div>';
    echo '<div class="form-group"><label>Yr 2–4 Squadron</label><input name="squadron_yr2_4" value="' . $v('squadron_yr2_4') . '"></div>';
    echo '</div></fieldset>';

    echo '<fieldset><legend>Parent / Contact 1</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Last Name</label><input name="parent1_last_name" value="' . $v('parent1_last_name') . '"></div>';
    echo '<div class="form-group"><label>First Name</label><input name="parent1_first_name" value="' . $v('parent1_first_name') . '"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="parent1_email" value="' . $v('parent1_email') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="parent1_cell" value="' . $v('parent1_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Street</label><input name="parent1_street" value="' . $v('parent1_street') . '"></div>';
    echo '<div class="form-group"><label>City</label><input name="parent1_city" value="' . $v('parent1_city') . '"></div>';
    echo '<div class="form-group"><label>State</label><input name="parent1_state" maxlength="2" value="' . $v('parent1_state') . '"></div>';
    echo '<div class="form-group"><label>Zip</label><input name="parent1_zip" value="' . $v('parent1_zip') . '"></div>';
    echo '</div></fieldset>';

    // Detect if parent 2 address already matches parent 1
    $addr_same = !empty($m['parent1_street'])
              && ($m['parent1_street'] ?? '') === ($m['parent2_street'] ?? '')
              && ($m['parent1_city']   ?? '') === ($m['parent2_city']   ?? '');

    echo '<fieldset><legend>Parent / Contact 2</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Last Name</label><input name="parent2_last_name" value="' . $v('parent2_last_name') . '"></div>';
    echo '<div class="form-group"><label>First Name</label><input name="parent2_first_name" value="' . $v('parent2_first_name') . '"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="parent2_email" value="' . $v('parent2_email') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="parent2_cell" value="' . $v('parent2_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-group" style="margin:.25rem 0 .75rem">';
    echo '<label>Address</label>';
    echo '<div style="display:flex;gap:1.5rem;margin-top:.3rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="radio" name="p2_addr_same" value="1" style="width:auto" onchange="syncP2Addr(this)"' . ($addr_same ? ' checked' : '') . '> Same as Parent 1</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="radio" name="p2_addr_same" value="0" style="width:auto" onchange="syncP2Addr(this)"' . (!$addr_same ? ' checked' : '') . '> Different address</label>';
    echo '</div></div>';
    echo '<div id="p2-addr-fields" style="' . ($addr_same ? 'opacity:.5;pointer-events:none' : '') . '">';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Street</label><input id="p2_street" name="parent2_street" value="' . $v('parent2_street') . '"></div>';
    echo '<div class="form-group"><label>City</label><input id="p2_city" name="parent2_city" value="' . $v('parent2_city') . '"></div>';
    echo '<div class="form-group"><label>State</label><input id="p2_state" name="parent2_state" maxlength="2" value="' . $v('parent2_state') . '"></div>';
    echo '<div class="form-group"><label>Zip</label><input id="p2_zip" name="parent2_zip" value="' . $v('parent2_zip') . '"></div>';
    echo '</div></div></fieldset>';
    echo '<script>
function syncP2Addr(radio) {
  var same = radio.value === "1";
  var wrap = document.getElementById("p2-addr-fields");
  wrap.style.opacity = same ? ".5" : "1";
  wrap.style.pointerEvents = same ? "none" : "auto";
  if (same) {
    document.getElementById("p2_street").value = document.querySelector("[name=parent1_street]").value;
    document.getElementById("p2_city").value   = document.querySelector("[name=parent1_city]").value;
    document.getElementById("p2_state").value  = document.querySelector("[name=parent1_state]").value;
    document.getElementById("p2_zip").value    = document.querySelector("[name=parent1_zip]").value;
  }
}
// Sync on load if "same" is pre-selected
(function(){ var r = document.querySelector("[name=p2_addr_same]:checked"); if (r) syncP2Addr(r); })();
</script>';

    echo '<fieldset><legend>Region, Consents &amp; Notes</legend>';
    echo '<div class="form-row col-2">';
    echo '<div class="form-group"><label>AL Region</label>' . $sel('al_region', REGIONS) . '</div>';
    echo '<div class="form-group"><label>Remarks</label><textarea name="remarks">' . $v('remarks') . '</textarea></div>';
    echo '</div>';
    echo '<div class="form-row col-2" style="margin-top:.5rem">';
    $pc = $m['photo_consent'] ?? '';
    echo '<div class="form-group"><label>Photo Consent</label><div style="display:flex;gap:1.5rem;margin-top:.4rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="photo_consent" value="Yes" style="width:auto" ' . ($pc==='Yes'?'checked':'') . '> Yes</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="photo_consent" value="No" style="width:auto" ' . ($pc==='No'?'checked':'') . '> No</label>';
    echo '</div></div>';
    $dc = $m['directory_consent'] ?? '';
    echo '<div class="form-group"><label>Directory Consent</label><div style="display:flex;gap:1.5rem;margin-top:.4rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="directory_consent" value="Yes" style="width:auto" ' . ($dc==='Yes'?'checked':'') . '> Yes</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="directory_consent" value="No" style="width:auto" ' . ($dc==='No'?'checked':'') . '> No</label>';
    echo '</div></div>';
    echo '</div></fieldset>';

    $paid     = (int)($m['membership_paid'] ?? 0);
    $mem_year = $m['membership_year'] ?? membership_year();
    if (!$mem_year) $mem_year = membership_year();
    echo '<fieldset><legend>Membership Dues</legend>';
    echo '<div class="form-row col-2">';
    echo '<div class="form-group"><label>Dues Paid?</label>';
    echo '<div style="display:flex;gap:1.5rem;margin-top:.4rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:600;font-size:.95rem;text-transform:none;letter-spacing:0;color:#2e7d32;cursor:pointer">'
       . '<input type="radio" name="membership_paid" value="1" style="width:auto;accent-color:#2e7d32"' . ($paid ? ' checked' : '') . '> ✓ Paid</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:600;font-size:.95rem;text-transform:none;letter-spacing:0;color:#c62828;cursor:pointer">'
       . '<input type="radio" name="membership_paid" value="0" style="width:auto;accent-color:#c62828"' . (!$paid ? ' checked' : '') . '> ✗ Not Paid</label>';
    echo '</div></div>';
    echo '<div class="form-group"><label>Membership Year</label>'
       . '<input name="membership_year" value="' . h($mem_year) . '" placeholder="e.g. 2026-2027"></div>';
    echo '</div></fieldset>';
}
