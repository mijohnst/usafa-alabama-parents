<?php
require_once __DIR__ . '/auth.php';
require_member_admin(); // admin, tech, officer, secretary

$all_years = CLASS_YEAR_LIST;

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

    $safe_years = array_intersect($years, CLASS_YEAR_LIST);
    if (empty($safe_years)) return ''; // nothing selected → no recipients
    $ph = [];
    foreach (array_values($safe_years) as $i => $y) { $ph[] = ":yr$i"; $params[":yr$i"] = $y; }
    $where[] = 'class_year IN (' . implode(',', $ph) . ')';
    if ($region !== '') { $where[] = 'al_region = :region'; $params[':region'] = $region; }
    if ($paid   === '1') $where[] = 'membership_paid = 1';
    if ($paid   === '0') $where[] = 'membership_paid = 0';

    $sql  = 'SELECT parent1_email, parent2_email, cadet_email, parent1_is_board_member, parent2_is_board_member
             FROM members WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = [];
    foreach ($rows as $r) {
        switch ($list_type) {
            case 'everyone':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                if ($r['cadet_email'])   $lines[] = $r['cadet_email'];
                break;
            case 'parent_both':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
            case 'parent1':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                break;
            case 'parent2':
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
            case 'cadet':
                if ($r['cadet_email']) $lines[] = $r['cadet_email'];
                break;
            case 'board':
                if ($r['parent1_is_board_member'] && $r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_is_board_member'] && $r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
        }
    }
    return implode("\n", array_unique($lines));
}

// ── Read + validate uploaded attachments ────────────────────────────────────
// Returns [attachments[], errors[]]. Each attachment: ['name'=>, 'mime'=>, 'content'=>].
function collect_email_attachments(): array {
    $attachments = [];
    $errors      = [];
    if (empty($_FILES['attachments']) || empty($_FILES['attachments']['name'][0])) {
        return [$attachments, $errors];
    }

    $allowed_mime = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp',
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'text/plain',
    ];
    $max_each  = 10 * 1024 * 1024; // 10MB per file
    $max_total = 20 * 1024 * 1024; // 20MB combined
    $total     = 0;
    $finfo     = finfo_open(FILEINFO_MIME_TYPE);

    $count = count($_FILES['attachments']['name']);
    for ($i = 0; $i < $count; $i++) {
        $name = $_FILES['attachments']['name'][$i];
        $err  = $_FILES['attachments']['error'][$i];
        $tmp  = $_FILES['attachments']['tmp_name'][$i];
        $size = (int)$_FILES['attachments']['size'][$i];
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        if ($err !== UPLOAD_ERR_OK) { $errors[] = "$name: upload failed."; continue; }
        if ($size > $max_each)       { $errors[] = "$name: over the 10MB per-file limit, skipped."; continue; }
        if ($total + $size > $max_total) { $errors[] = "$name: skipped, would exceed the 20MB combined attachment limit."; continue; }

        $mime = finfo_file($finfo, $tmp);
        if (!in_array($mime, $allowed_mime, true)) { $errors[] = "$name: file type not allowed, skipped."; continue; }

        $content = file_get_contents($tmp);
        if ($content === false) { $errors[] = "$name: could not read uploaded file."; continue; }

        $total += $size;
        $attachments[] = ['name' => $name, 'mime' => $mime, 'content' => $content];
    }
    finfo_close($finfo);
    return [$attachments, $errors];
}

// ── Build a MIME message (HTML body, optionally with attachments) ──────────
// Returns [contentTypeHeaderLine, messageBody].
function build_mime_email(string $html_body, array $attachments): array {
    if (empty($attachments)) {
        return ["Content-Type: text/html; charset=UTF-8\r\n", $html_body];
    }
    $boundary = md5(uniqid((string)mt_rand(), true));
    $msg  = "--$boundary\r\n";
    $msg .= "Content-Type: text/html; charset=UTF-8\r\n";
    $msg .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $msg .= $html_body . "\r\n\r\n";
    foreach ($attachments as $att) {
        $safe_name = str_replace(['"', "\r", "\n"], '', $att['name']);
        $msg .= "--$boundary\r\n";
        $msg .= "Content-Type: {$att['mime']}; name=\"$safe_name\"\r\n";
        $msg .= "Content-Transfer-Encoding: base64\r\n";
        $msg .= "Content-Disposition: attachment; filename=\"$safe_name\"\r\n\r\n";
        $msg .= chunk_split(base64_encode($att['content'])) . "\r\n";
    }
    $msg .= "--$boundary--";
    return ["Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n", $msg];
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
    // Quill's "empty" state is still non-empty HTML (e.g. "<p><br></p>"), so
    // check the stripped text content rather than the raw HTML string.
    $body_text = trim(strip_tags(str_replace('&nbsp;', ' ', $body)));

    if (empty($recipients)) $errors[] = 'At least one recipient is required.';
    if (empty($subject))    $errors[] = 'Subject is required.';
    if ($body_text === '')  $errors[] = 'Message body is required.';

    [$attachments, $attach_errors] = collect_email_attachments();

    if (empty($errors)) {
        $valid = extract_emails($recipients);
        if (empty($valid)) {
            $errors[] = 'No valid email addresses found.';
        } else {
            $sig_key       = $signature_keys[$from_email] ?? '';
            $signature     = trim($signatures[$sig_key] ?? '');
            $full_body     = $signature !== '' ? $body . '<p>-- <br>' . nl2br(htmlspecialchars($signature)) . '</p>' : $body;
            $clean_subject = str_replace(["\r","\n"], '', $subject);

            [$content_type_header, $mime_body] = build_mime_email($full_body, $attachments);

            // The mail server rejects any single send with too many recipients
            // (hit at 113). Batch BCC into chunks well under that ceiling —
            // each batch is its own separate send to info@alabamafalcons.org,
            // reusing the same MIME body/attachments for every batch.
            $batches      = array_chunk($valid, 90);
            $sent_count   = 0;
            $failed_count = 0;

            foreach ($batches as $batch) {
                $headers  = "From: USAFA Parents Club of Alabama <{$from_email}>\r\n";
                $headers .= "Reply-To: {$from_email}\r\n";
                $headers .= "BCC: " . implode(', ', $batch) . "\r\n";
                $headers .= "MIME-Version: 1.0\r\n";
                $headers .= $content_type_header;

                if (mail('info@alabamafalcons.org', $clean_subject, $mime_body, $headers)) {
                    $sent_count += count($batch);
                } else {
                    $failed_count += count($batch);
                    $mail_err = error_get_last();
                    error_log('Compose Email: mail() failed for a batch of ' . count($batch) . ' recipient(s) from ' . $from_email
                        . '. Last PHP error: ' . ($mail_err['message'] ?? 'none captured'));
                }
            }

            if ($sent_count > 0) {
                $sent        = true;
                $valid_count = $sent_count;
                $recipients  = '';
                $subject     = '';
                $body        = '';
                if ($failed_count > 0) {
                    $errors[] = "Sent to $sent_count recipient(s), but $failed_count could not be sent — please try again for those, or contact your hosting provider if it keeps happening.";
                }
            } else {
                $errors[] = 'Server failed to send. Please try again or contact your hosting provider.';
            }
        }
    }

    foreach ($attach_errors as $ae) $errors[] = $ae;
}

$preview_count = count(extract_emails($recipients));

admin_header('Compose Email');
?>
<!-- Quill rich text editor (CDN) -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
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
.ql-editor{min-height:220px;font-family:"Segoe UI",Arial,sans-serif;font-size:.95rem}
.ql-toolbar{border-radius:4px 4px 0 0}
.ql-container{border-radius:0 0 4px 4px;background:#fff}
.editor-toolbar-extra{display:flex;justify-content:flex-end;margin:.4rem 0 .25rem}
.emoji-btn{background:#fff;border:1px solid #d0d5dd;border-radius:4px;padding:.3rem .6rem;font-size:.95rem;cursor:pointer}
.emoji-btn:hover{background:#f5f7fa}
.emoji-panel{display:none;position:absolute;z-index:300;background:#fff;border:1px solid #d0d5dd;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);padding:.5rem;grid-template-columns:repeat(8,1fr);gap:.15rem;margin-top:.25rem}
.emoji-panel.open{display:grid}
.emoji-panel button{background:none;border:none;font-size:1.2rem;padding:.25rem;cursor:pointer;border-radius:3px;line-height:1}
.emoji-panel button:hover{background:#f0f4ff}
.attach-list{display:flex;flex-direction:column;gap:.3rem;margin-top:.5rem}
.attach-chip{display:flex;align-items:center;justify-content:space-between;background:#f5f7fa;border:1px solid #e1e5eb;border-radius:4px;padding:.4rem .65rem;font-size:.82rem;color:#1a2332}
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
          <option value="everyone"    <?= $f_type==='everyone'   ?'selected':''?>>Everyone (Both Parents + Cadet)</option>
          <option value="parent_both" <?= $f_type==='parent_both'?'selected':''?>>Both Parent Emails</option>
          <option value="parent1"     <?= $f_type==='parent1'    ?'selected':''?>>Parent 1 Only</option>
          <option value="parent2"     <?= $f_type==='parent2'    ?'selected':''?>>Parent 2 Only</option>
          <option value="cadet"       <?= $f_type==='cadet'      ?'selected':''?>>Cadet Emails</option>
          <option value="board"       <?= $f_type==='board'      ?'selected':''?>>Board Members Only</option>
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
  <form method="POST" id="compose-form" enctype="multipart/form-data">
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

    <div class="form-group" style="position:relative">
      <label>Message</label>
      <div class="editor-toolbar-extra" style="position:relative">
        <button type="button" class="emoji-btn" id="emoji-btn">😀 Emoji</button>
        <div class="emoji-panel" id="emoji-panel"></div>
      </div>
      <div id="email-editor"><?= $body ?></div>
      <input type="hidden" name="body" id="body_input">
      <div class="char-count" id="char-count">0 characters</div>
    </div>

    <div class="form-group">
      <label>Attachments <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional — up to 10MB per file, 20MB total</span></label>
      <input type="file" name="attachments[]" id="attachments-input" multiple>
      <div class="attach-list" id="attach-list"></div>
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
// ── Message rich text editor ──────────────────────────────────────────────
var quill = new Quill('#email-editor', {
  theme: 'snow',
  modules: {
    toolbar: [
      ['bold','italic','underline','strike'],
      [{ 'color': [] },{ 'background': [] }],
      [{ 'size': ['small',false,'large','huge'] }],
      [{ 'align': [] }],
      [{ 'list': 'ordered' },{ 'list': 'bullet' }],
      ['link'],
      ['clean']
    ]
  }
});
var bodyInput = document.getElementById('body_input');
function updateChar() {
  bodyInput.value = quill.root.innerHTML;
  var n = quill.getText().trim().length;
  document.getElementById('char-count').textContent = n.toLocaleString() + ' character' + (n!==1?'s':'');
}
quill.on('text-change', updateChar);
updateChar();
document.getElementById('compose-form').addEventListener('submit', function() {
  bodyInput.value = quill.root.innerHTML; // safety net
});

// ── Emoji picker ───────────────────────────────────────────────────────────
var EMOJIS = ['😀','😊','😍','🎉','🎊','👍','👏','🙌','🤝','💪','❤️','⭐','✅','📅','📌','📣',
              '✈️','🎓','🦅','🇺🇸','🏈','☀️','🎄','🎃','🥳','🙏','💰','📸','📝','🔔','⚡','🚀'];
var emojiBtn   = document.getElementById('emoji-btn');
var emojiPanel = document.getElementById('emoji-panel');
EMOJIS.forEach(function(e) {
  var b = document.createElement('button');
  b.type = 'button';
  b.textContent = e;
  b.addEventListener('click', function(ev) {
    ev.stopPropagation();
    var range = quill.getSelection(true);
    quill.insertText(range ? range.index : quill.getLength(), e, 'user');
    quill.setSelection((range ? range.index : 0) + e.length, 0);
    emojiPanel.classList.remove('open');
  });
  emojiPanel.appendChild(b);
});
emojiBtn.addEventListener('click', function(e) { e.stopPropagation(); emojiPanel.classList.toggle('open'); });
document.addEventListener('click', function() { emojiPanel.classList.remove('open'); });
emojiPanel.addEventListener('click', function(e) { e.stopPropagation(); });

// ── Attachments — show selected files with size, since <input type=file> ──
// ── doesn't show much on its own once multiple files are chosen ───────────
var attachInput = document.getElementById('attachments-input');
var attachList  = document.getElementById('attach-list');
attachInput.addEventListener('change', function() {
  attachList.innerHTML = '';
  Array.from(attachInput.files).forEach(function(f) {
    var chip = document.createElement('div');
    chip.className = 'attach-chip';
    var kb = f.size / 1024;
    var sizeText = kb > 1024 ? (kb/1024).toFixed(1) + ' MB' : Math.round(kb) + ' KB';
    chip.innerHTML = '<span>📎 ' + f.name + '</span><span style="color:#9aa5b4">' + sizeText + '</span>';
    attachList.appendChild(chip);
  });
});
</script>

<?php admin_footer(); ?>
