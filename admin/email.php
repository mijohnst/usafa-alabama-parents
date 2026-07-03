<?php
require_once __DIR__ . '/auth.php';
require_admin();

$recipients = trim($_POST['recipients'] ?? '');
$subject    = trim($_POST['subject']    ?? '');
$body       = trim($_POST['body']       ?? '');
$sent       = false;
$errors     = [];
$valid_count = 0;

// Extract valid email addresses from various formats:
// "Name <email>"  |  "Cadet, Name: email"  |  "plain@email.com"
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['send'])) {
    csrf_verify();
    if (empty($recipients)) $errors[] = 'At least one recipient is required.';
    if (empty($subject))    $errors[] = 'Subject is required.';
    if (empty($body))       $errors[] = 'Message body is required.';

    if (empty($errors)) {
        $valid = extract_emails($recipients);
        if (empty($valid)) {
            $errors[] = 'No valid email addresses found in the recipients field.';
        } else {
            $clean_subject = str_replace(["\r","\n"], '', $subject);
            $headers  = "From: USAFA Parents Club of Alabama <info@alabamafalcons.org>\r\n";
            $headers .= "Reply-To: info@alabamafalcons.org\r\n";
            $headers .= "BCC: " . implode(', ', $valid) . "\r\n";
            $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";

            if (mail('info@alabamafalcons.org', $clean_subject, $body, $headers)) {
                $sent        = true;
                $valid_count = count($valid);
                $recipients  = '';
                $subject     = '';
                $body        = '';
            } else {
                $errors[] = 'Server failed to send the email. Please try again or check with your hosting provider.';
            }
        }
    }
}

// Count recipients for live preview
$preview_count = count(extract_emails($recipients));

admin_header('Compose Email');
?>
<style>
.compose-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:860px}
.from-badge{display:inline-flex;align-items:center;gap:.5rem;background:#f0f4ff;border:1px solid #c7d4f5;border-radius:4px;padding:.45rem .85rem;font-size:.9rem;color:#002554;font-weight:600}
.recipient-count{font-size:.78rem;color:#5a6a7a;margin-top:.3rem}
.char-count{font-size:.78rem;color:#5a6a7a;text-align:right;margin-top:.25rem}
</style>

<div class="page-head">
  <h1>Compose Email</h1>
  <a href="lists.php" class="btn btn-secondary">← Lists</a>
</div>

<?php if ($sent): ?>
  <div class="alert alert-success" style="max-width:860px">
    ✓ Email sent successfully to <strong><?= $valid_count ?></strong> recipient(s) via BCC.
  </div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error" style="max-width:860px">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
  </div>
<?php endif; ?>

<div class="compose-card">
  <form method="POST" id="compose-form">
    <?= csrf_field() ?>

    <div class="form-group">
      <label>From</label>
      <div class="from-badge">✉ info@alabamafalcons.org</div>
    </div>

    <div class="form-group">
      <label>Recipients (BCC) <span style="font-weight:400;text-transform:none;letter-spacing:0;color:#5a6a7a">— one per line, or paste directly from the Lists page</span></label>
      <textarea name="recipients" id="recipients" rows="6"
        placeholder="parent@example.com&#10;Another Parent <parent2@example.com>&#10;..."
        oninput="updateCount()"><?= h($recipients) ?></textarea>
      <div class="recipient-count" id="recipient-count">
        <?= $preview_count > 0 ? $preview_count . ' valid address' . ($preview_count !== 1 ? 'es' : '') . ' detected' : 'Paste addresses above' ?>
      </div>
    </div>

    <div class="form-group">
      <label>Subject</label>
      <input name="subject" id="subject-input" value="<?= h($subject) ?>" placeholder="e.g. Parents Weekend Reminder" maxlength="200">
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
      <span style="font-size:.82rem;color:#5a6a7a">Sends as BCC — recipients will not see each other's addresses.</span>
    </div>
  </form>
</div>

<script>
function extract_count(text) {
  var lines = text.split('\n');
  var count = 0;
  var re = /[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/;
  lines.forEach(function(l){ if (re.test(l.trim())) count++; });
  return count;
}
function updateCount() {
  var n = extract_count(document.getElementById('recipients').value);
  document.getElementById('recipient-count').textContent =
    n > 0 ? n + ' valid address' + (n !== 1 ? 'es' : '') + ' detected' : 'Paste addresses above';
}
function updateChar() {
  var n = document.getElementById('body').value.length;
  document.getElementById('char-count').textContent = n.toLocaleString() + ' character' + (n !== 1 ? 's' : '');
}
updateChar();
</script>

<?php admin_footer(); ?>
