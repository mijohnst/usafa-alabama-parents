<?php
require_once __DIR__ . '/auth.php';
require_member_admin(); // admin, tech, officer, secretary

$all_years = ['2026','2027','2028','2029','2030','2031','Prep School','Graduate'];

// ── Extract valid emails from various text formats ─────────────────────────
function extract_emails(string $raw): array {
    $valid = [];
    foreach (array_filter(array_map('trim', explode("\n", $raw))) as $line) {
        if (preg_match('/<([^>@\s]+@[^>\s]+)>/', $line, $m))        $email = trim($m[1]);
        elseif (preg_match('/:\s*([^\s,;]+@[^\s,;]+)/', $line, $m)) $email = trim($m[1]);
        else                                                          $email = trim(explode(' ', $line)[0]);
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) $valid[] = $email;
    }
    return array_values(array_unique($valid));
}

// ── Query DB to build recipient list ──────────────────────────────────────
function load_recipients(PDO $pdo, array $years, string $region, string $paid, string $list_type): string {
    $where  = ['1=1'];
    $params = [];

    $safe_years = array_intersect($years, ['2026','2027','2028','2029','2030','2031','Prep School','Graduate']);
    if (empty($safe_years)) return ''; // nothing selected → no recipients
    $ph = [];
    foreach (array_values($safe_years) as $i => $y) { $ph[] = ":yr$i"; $params[":yr$i"] = $y; }
    $where[] = 'class_year IN (' . implode(',', $ph) . ')';
    if ($region !== '') { $where[] = 'al_region = :region'; $params[':region'] = $region; }
    if ($paid   === '1') $where[] = 'membership_paid = 1';
    if ($paid   === '0') $where[] = 'membership_paid = 0';

    $sql  = 'SELECT parent1_email, parent2_email, cadet_email FROM members WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        switch ($list_type) {
            case 'parent_both':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
            case 'parent1':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                break;
            case 'cadet':
                if ($r['cadet_email']) $lines[] = $r['cadet_email'];
                break;
        }
    }
    return implode("\n", array_unique($lines));
}

$from_options = [
    'president@alabamafalcons.org' => 'President',
    'vp@alabamafalcons.org'        => 'Vice President',
    'secretary@alabamafalcons.org' => 'Secretary',
    'treasurer@alabamafalcons.org' => 'Treasurer',
];

// Maps each From address to its signature's site_settings key (edit signature
// text on the Site Settings page, under "Email Signatures").
$signature_keys = [
    'president@alabamafalcons.org' => 'signature_president',
    'vp@alabamafalcons.org'        => 'signature_vp',
    'secretary@alabamafalcons.org' => 'signature_secretary',
    'treasurer@alabamafalcons.org' => 'signature_treasurer',
];

$pdo = get_pdo();
$signatures = [];
try {
    $sig_rows = $pdo->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'signature_%'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($sig_rows as $sr) $signatures[$sr['setting_key']] = $sr['setting_value'];
} catch (PDOException $e) {
    // site_settings not migrated yet — signatures will just be blank
}

// ── State ─────────────────────────────────────────────────────────────────
$recipients  = trim($_POST['recipients']  ?? '');
$subject     = trim($_POST['subject']     ?? '');
$body        = trim($_POST['body']        ?? '');
$from_email  = $_POST['from_email'] ?? 'president@alabamafalcons.org';
if (!array_key_exists($from_email, $from_options)) $from_email = 'president@alabamafalcons.org';
$sent        = false;
$errors      = [];
$valid_count = 0;

// Filter state
$f_years  = $_POST['f_years']  ?? [];
$f_region = $_POST['f_region'] ?? '';
$f_paid   = $_POST['f_paid']   ?? '';
$f_type   = $_POST['f_type']   ?? 'parent_both';

// ── Handle load recipients ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['load'])) {
    $recipients = load_recipients($pdo, (array)$f_years, $f_region, $f_paid, $f_type);
}

// ── Handle send ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send'])) {
    csrf_verify();
    if (empty($recipients)) $errors[] = 'At least one recipient is required.';
    if (empty($subject))    $errors[] = 'Subject is required.';
    if (empty($body))       $errors[] = 'Message body is required.';

    if (empty($errors)) {
        $valid = extract_emails($recipients);
        if (empty($valid)) {
            $errors[] = 'No valid email addresses found.';
        } else {
            $sig_key   = $signature_keys[$from_email] ?? '';
            $signature = trim($signatures[$sig_key] ?? '');
            $full_body = $signature !== '' ? $body . "\n\n-- \n" . $signature : $body;

            $clean_subject = str_replace(["\r","\n"], '', $subject);
            $headers  = "From: USAFA Parents Club of Alabama <{$from_email}>\r\n";
            $headers .= "Reply-To: {$from_email}\r\n";
            $headers .= "BCC: " . implode(', ', $valid) . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            if (mail('info@alabamafalcons.org', $clean_subject, $full_body, $headers)) {
                $sent        = true;
                $valid_count = count($valid);
                $recipients  = '';
                $subject     = '';
                $body        = '';
            } else {
                $mail_err = error_get_last();
                error_log('Compose Email: mail() failed for ' . count($valid) . ' recipient(s) from ' . $from_email
                    . '. Last PHP error: ' . ($mail_err['message'] ?? 'none captured'));
                $errors[] = 'Server failed to send. Please try again or contact your hosting provider.';
            }
        }
    }
}

$preview_count = count(extract_emails($recipients));

admin_header('Compose Email');
?>
<style>
.compose-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:900px;margin-bottom:1.25rem}
.loader-card{background:#f0f4ff;border:1px solid #c7d4f5;border-radius:6px;padding:1.25rem 1.5rem;max-width:900px;margin-bottom:1.25rem}
.loader-card h2{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#003594;margin-bottom:1rem}
.from-badge{display:inline-flex;align-items:center;gap:.5rem;background:#f0f4ff;border:1px solid #c7d4f5;border-radius:4px;padding:.45rem .85rem;font-size:.9rem;color:#002554;font-weight:600}
.recipient-count{font-size:.78rem;margin-top:.3rem}
.char-count{font-size:.78rem;color:#5a6a7a;text-align:right;margin-top:.25rem}
.cd{position:relative}
.cd-btn{width:100%;text-align:left;background:#fff;border:1px solid #d0d5dd;border-radius:4px;padding:.55rem .75rem;cursor:pointer;font-size:.9rem;color:#1a2332;display:flex;justify-content:space-between;align-items:center;font-family:inherit}
.cd-btn::after{content:'▾';font-size:.8rem;color:#5a6a7a;flex-shrink:0}
.cd-btn:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
.cd-panel{display:none;position:absolute;top:calc(100% + 3px);left:0;right:0;background:#fff;border:1px solid #d0d5dd;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:200;padding:.4rem 0;min-width:160px}
.cd.open .cd-panel{display:block}
.cd-panel label{display:flex;align-items:center;gap:.55rem;padding:.38rem .8rem;cursor:pointer;font-size:.875rem;color:#1a2332;font-weight:400;text-transform:none;letter-spacing:0;white-space:nowrap}
.cd-panel label:hover{background:#f5f7fa}
.cd-panel input[type=checkbox]{width:auto;accent-color:#003594;cursor:pointer}
.cd-footer{border-top:1px solid #e1e5eb;padding:.4rem .8rem 0;display:flex;gap:.5rem;margin-top:.25rem}
</style>

<div class="page-head">
  <h1>Compose Email</h1>
  <a href="lists.php" class="btn btn-secondary">← Lists</a>
</div>

<?php if ($sent): ?>
  <div class="alert alert-success" style="max-width:900px">
    ✓ Email sent to <strong><?= $valid_count ?></strong> recipient(s) via BCC.
  </div>
<?php endif; ?>
<?php if (!empty($errors)): ?>
  <div class="alert alert-error" style="max-width:900px">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
  </div>
<?php endif; ?>

<!-- BCC Quick Loader -->
<div class="loader-card">
  <h2>Load Recipients from Database</h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="subject"    value="<?= h($subject) ?>">
    <input type="hidden" name="body"       value="<?= h($body) ?>">
    <input type="hidden" name="recipients" value="<?= h($recipients) ?>">
    <input type="hidden" name="from_email" value="<?= h($from_email) ?>">

    <div class="form-row" style="grid-template-columns:1fr 1fr 1fr 1fr auto;align-items:flex-end;gap:.75rem">

      <div class="form-group" style="margin:0">
        <label>Class Year</label>
        <div class="cd" id="yr-cd">
          <button type="button" class="cd-btn" id="yr-btn">All Years</button>
          <div class="cd-panel">
            <?php foreach ($all_years as $y): ?>
            <label>
              <input type="checkbox" name="f_years[]" value="<?= h($y) ?>"
                     <?= in_array($y,(array)$f_years)?'checked':''?>>
              <?= h($y) ?>
            </label>
            <?php endforeach; ?>
            <div class="cd-footer">
              <button type="button" class="btn btn-secondary btn-sm" onclick="setYrs(true)">All</button>
              <button type="button" class="btn btn-secondary btn-sm" onclick="setYrs(false)">None</button>
            </div>
          </div>
        </div>
      </div>

      <div class="form-group" style="margin:0">
        <label>AL Region</label>
        <select name="f_region">
          <option value="">All Regions</option>
          <?php foreach (['North','Central','South'] as $r): ?>
            <option value="<?= h($r) ?>" <?= $f_region===$r?'selected':''?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <label>Dues Status</label>
        <select name="f_paid">
          <option value="">All Members</option>
          <option value="1" <?= $f_paid==='1'?'selected':''?>>Paid Only</option>
          <option value="0" <?= $f_paid==='0'?'selected':''?>>Unpaid Only</option>
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <label>Email List</label>
        <select name="f_type">
          <option value="parent_both" <?= $f_type==='parent_both'?'selected':''?>>Both Parent Emails</option>
          <option value="parent1"     <?= $f_type==='parent1'    ?'selected':''?>>Parent 1 Only</option>
          <option value="cadet"       <?= $f_type==='cadet'      ?'selected':''?>>Cadet Emails</option>
        </select>
      </div>

      <div class="form-group" style="margin:0">
        <label>&nbsp;</label>
        <div style="display:flex;gap:.5rem">
          <button type="submit" name="load" value="1" class="btn btn-primary">Load →</button>
          <button type="button" class="btn btn-secondary" onclick="resetFilter()">Reset</button>
        </div>
      </div>

    </div>
  </form>
</div>

<!-- Compose Form -->
<div class="compose-card">
  <form method="POST" id="compose-form">
    <?= csrf_field() ?>
    <?php foreach ((array)$f_years as $fy): ?><input type="hidden" name="f_years[]" value="<?= h($fy) ?>"><?php endforeach; ?>
    <input type="hidden" name="f_region"  value="<?= h($f_region) ?>">
    <input type="hidden" name="f_paid"    value="<?= h($f_paid) ?>">
    <input type="hidden" name="f_type"    value="<?= h($f_type) ?>">

    <div class="form-group" style="max-width:360px">
      <label>From</label>
      <select name="from_email" id="from-select" style="font-weight:600;color:#002554" onchange="updateSigPreview()">
        <?php foreach ($from_options as $addr => $title): ?>
          <option value="<?= h($addr) ?>" <?= $from_email===$addr?'selected':''?>>
            <?= h($title) ?> &lt;<?= h($addr) ?>&gt;
          </option>
        <?php endforeach; ?>
      </select>
      <div id="sig-preview" style="font-size:.75rem;color:#5a6a7a;margin-top:.5rem;white-space:pre-line;border-left:2px solid #e1e5eb;padding-left:.6rem"></div>
    </div>

    <div class="form-group">
      <label>Recipients (BCC)</label>
      <textarea name="recipients" id="recipients" rows="5"
        placeholder="Load from the filters above, or paste addresses manually…"
        oninput="updateCount()"><?= h($recipients) ?></textarea>
      <div class="recipient-count" id="recipient-count" style="color:<?= $preview_count>0?'#2e7d32':'#5a6a7a' ?>">
        <?= $preview_count > 0 ? '✓ ' . $preview_count . ' valid address' . ($preview_count!==1?'es':'') . ' loaded' : 'No addresses loaded yet' ?>
      </div>
    </div>

    <div class="form-group">
      <label>Subject</label>
      <input name="subject" value="<?= h($subject) ?>" placeholder="e.g. Parents Weekend Reminder" maxlength="200">
    </div>

    <div class="form-group">
      <label>Message</label>
      <textarea name="body" id="body" rows="12"
        placeholder="Type your message here…"
        oninput="updateChar()"><?= h($body) ?></textarea>
      <div class="char-count" id="char-count">0 characters</div>
    </div>

    <div style="display:flex;gap:.75rem;align-items:center;flex-wrap:wrap">
      <button type="submit" name="send" value="1" class="btn btn-primary">Send Email</button>
      <a href="email.php" class="btn btn-secondary">Clear</a>
      <span style="font-size:.82rem;color:#5a6a7a">Sends as BCC — recipients will not see each other's addresses. Your signature (shown above) is added automatically.</span>
    </div>
  </form>
</div>

<script>
// ── Signature preview ─────────────────────────────────────────────────────
var signatures = <?= json_encode($signatures) ?>;
var sigKeyMap   = <?= json_encode($signature_keys) ?>;
function updateSigPreview() {
  var from = document.getElementById('from-select').value;
  var sig  = signatures[sigKeyMap[from]] || '';
  document.getElementById('sig-preview').textContent = sig ? 'Signature: ' + sig : '';
}
updateSigPreview();

// ── Year checkbox dropdown ────────────────────────────────────────────────
var yrCd  = document.getElementById('yr-cd');
var yrBtn = document.getElementById('yr-btn');
var yrCbs = yrCd.querySelectorAll('input[type=checkbox]');

function updateYrLabel() {
  var checked = Array.from(yrCbs).filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
  yrBtn.childNodes[0].textContent = checked.length === 0          ? 'No Years'  :
                                    checked.length === yrCbs.length ? 'All Years' :
                                    checked.join(', ');
}
yrBtn.addEventListener('click', function(e){ e.stopPropagation(); yrCd.classList.toggle('open'); });
document.addEventListener('click', function(){ yrCd.classList.remove('open'); });
yrCd.querySelector('.cd-panel').addEventListener('click', function(e){ e.stopPropagation(); });
yrCbs.forEach(function(cb){ cb.addEventListener('change', updateYrLabel); });
updateYrLabel();

function setYrs(state) {
  yrCbs.forEach(function(cb){ cb.checked = state; });
  updateYrLabel();
}
function resetFilter() {
  setYrs(false);
  document.querySelector('select[name=f_region]').value = '';
  document.querySelector('select[name=f_paid]').value   = '';
  document.querySelector('select[name=f_type]').value   = 'parent_both';
}
function extract_count(text) {
  var re = /[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/;
  return text.split('\n').filter(function(l){ return re.test(l.trim()); }).length;
}
function updateCount() {
  var n = extract_count(document.getElementById('recipients').value);
  var el = document.getElementById('recipient-count');
  el.style.color = n > 0 ? '#2e7d32' : '#5a6a7a';
  el.textContent = n > 0 ? '✓ ' + n + ' valid address' + (n!==1?'es':'') + ' loaded' : 'No addresses loaded yet';
}
function updateChar() {
  var n = document.getElementById('body').value.length;
  document.getElementById('char-count').textContent = n.toLocaleString() + ' character' + (n!==1?'s':'');
}
updateChar();
</script>

<?php admin_footer(); ?>
