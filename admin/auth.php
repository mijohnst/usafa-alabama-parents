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
.badge{display:inline-block;padding:.15rem .45rem;border-radius:3px;font-size:.7rem;font-weight:700}
.badge-North{background:#e3f2fd;color:#0d47a1}
.badge-Central{background:#f3e5f5;color:#4a148c}
.badge-South{background:#e8f5e9;color:#1b5e20}
.filter-bar{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem}
.filter-bar .form-group{margin:0;flex:1;min-width:130px}
.count{font-size:.82rem;color:#5a6a7a;margin-top:.5rem}
.actions{white-space:nowrap}
@media(max-width:640px){.col-2,.col-3,.col-4{grid-template-columns:1fr}.topbar{flex-direction:column;align-items:flex-start}}
</style>
'; }

function admin_header(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' — Members Admin</title>';
    echo admin_css();
    echo '</head><body>';
    echo '<div class="topbar">';
    echo '<span class="topbar-title">USAFA Parents Club of Alabama <small>Member Admin</small></span>';
    echo '<nav>';
    echo '<a href="index.php">Members</a>';
    echo '<a href="add.php">+ Add Member</a>';
    echo '<a href="logout.php">Log Out</a>';
    echo '</nav></div>';
    echo '<div class="main">';
}

function admin_footer(): void {
    echo '</div></body></html>';
}

const REGIONS = ['', 'North', 'Central', 'South'];
const CLASS_YEARS = ['', '2026', '2027', '2028', '2029', '2030', 'Prep School', 'Graduate'];

const FIELDS = [
    'class_year','cadet_last_name','cadet_first_middle','cadet_birthday','cadet_po_box',
    'cadet_email','cadet_cell','bct_squadron','bct_flight','fall_squadron','squadron_yr2_4',
    'parent1_last_name','parent1_first_name','parent1_email','parent1_cell',
    'parent1_street','parent1_city','parent1_state','parent1_zip',
    'parent2_last_name','parent2_first_name','parent2_email','parent2_cell',
    'parent2_street','parent2_city','parent2_state','parent2_zip',
    'al_region','remarks'
];

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
    echo '<div class="form-group"><label>Birthday</label><input type="date" name="cadet_birthday" value="' . $v('cadet_birthday') . '"></div>';
    echo '<div class="form-group"><label>PO Box</label><input name="cadet_po_box" value="' . $v('cadet_po_box') . '"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="cadet_email" value="' . $v('cadet_email') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="cadet_cell" value="' . $v('cadet_cell') . '"></div>';
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

    echo '<fieldset><legend>Parent / Contact 2</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Last Name</label><input name="parent2_last_name" value="' . $v('parent2_last_name') . '"></div>';
    echo '<div class="form-group"><label>First Name</label><input name="parent2_first_name" value="' . $v('parent2_first_name') . '"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="parent2_email" value="' . $v('parent2_email') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="parent2_cell" value="' . $v('parent2_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Street</label><input name="parent2_street" value="' . $v('parent2_street') . '"></div>';
    echo '<div class="form-group"><label>City</label><input name="parent2_city" value="' . $v('parent2_city') . '"></div>';
    echo '<div class="form-group"><label>State</label><input name="parent2_state" maxlength="2" value="' . $v('parent2_state') . '"></div>';
    echo '<div class="form-group"><label>Zip</label><input name="parent2_zip" value="' . $v('parent2_zip') . '"></div>';
    echo '</div></fieldset>';

    echo '<fieldset><legend>Region &amp; Notes</legend>';
    echo '<div class="form-row col-2">';
    echo '<div class="form-group"><label>AL Region</label>' . $sel('al_region', REGIONS) . '</div>';
    echo '<div class="form-group"><label>Remarks</label><textarea name="remarks">' . $v('remarks') . '</textarea></div>';
    echo '</div></fieldset>';
}
