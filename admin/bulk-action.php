<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_mark_dues()) { header('Location: index.php?denied=1'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_verify();

$ids      = array_filter(array_map('intval', $_POST['member_ids'] ?? []));
$action   = $_POST['action'] ?? '';
$mem_year = trim($_POST['membership_year'] ?? '');
$mem_type = $_POST['membership_type'] ?? 'annual';
if (!in_array($mem_type, ['annual','4year'])) $mem_type = 'annual';

$dues_actions   = ['mark_paid','mark_unpaid'];
$member_actions = ['archive','restore','delete','portal_invite'];
$all_actions    = array_merge($dues_actions, $member_actions);

if (empty($ids) || !in_array($action, $all_actions)) {
    flash('error', 'No members selected.');
    header('Location: index.php'); exit;
}

// Archive/restore/delete require member-management permission
if (in_array($action, $member_actions) && !can_manage_members()) {
    header('Location: index.php?denied=1'); exit;
}

$pdo = get_pdo();
$ph  = implode(',', array_fill(0, count($ids), '?'));

if ($action === 'mark_paid') {
    $paid_through = calc_paid_through($mem_year, $mem_type, true);
    $stmt = $pdo->prepare("UPDATE members SET membership_paid = 1, membership_year = ?, membership_type = ?, membership_paid_through = ? WHERE id IN ($ph)");
    $stmt->execute(array_merge([$mem_year, $mem_type, $paid_through], $ids));
    $plan_label = $mem_type === '4year' ? '4-Year (through ' . $paid_through . ')' : 'Annual';
    flash('success', count($ids) . ' member(s) marked paid — ' . $plan_label . '.');

} elseif ($action === 'mark_unpaid') {
    $stmt = $pdo->prepare("UPDATE members SET membership_paid = 0, membership_year = '', membership_type = 'annual', membership_paid_through = '' WHERE id IN ($ph)");
    $stmt->execute($ids);
    flash('success', count($ids) . ' member(s) marked as unpaid.');

} elseif ($action === 'archive') {
    $pdo->prepare("UPDATE members SET archived = 1 WHERE id IN ($ph)")->execute($ids);
    flash('success', count($ids) . ' member(s) archived.');

} elseif ($action === 'restore') {
    $pdo->prepare("UPDATE members SET archived = 0 WHERE id IN ($ph)")->execute($ids);
    flash('success', count($ids) . ' member(s) restored to active roster.');

} elseif ($action === 'delete') {
    $pdo->prepare("DELETE FROM members WHERE id IN ($ph)")->execute($ids);
    flash('success', count($ids) . ' member(s) permanently deleted.');

} elseif ($action === 'portal_invite') {
    require_once __DIR__ . '/mailer.php';
    $stmt = $pdo->prepare("SELECT id,parent1_first_name,parent1_last_name,parent1_email,parent2_first_name,parent2_last_name,parent2_email FROM members WHERE id IN ($ph)");
    $stmt->execute($ids);
    $dup_check = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? OR LOWER(username) = ?');
    $insert    = $pdo->prepare(
        "INSERT INTO users (name,email,username,password_hash,role,active,invite_token,invite_expires,member_id)
         VALUES (?,?,?,?,'member',1,?,DATE_ADD(NOW(), INTERVAL 14 DAY),?)"
    );
    $invited = 0; $skipped = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        foreach ([1, 2] as $slot) {
            $email = strtolower(trim($m["parent{$slot}_email"] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $name = trim($m["parent{$slot}_first_name"] . ' ' . $m["parent{$slot}_last_name"]) ?: $email;

            $dup_check->execute([$email, $email]);
            if ($dup_check->fetch()) { $skipped++; continue; }

            $token = bin2hex(random_bytes(24));
            try {
                $insert->execute([$name, $email, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), $token, $m['id']]);
            } catch (PDOException $e) {
                $skipped++; continue;
            }
            send_portal_invite($email, $name, $token);
            $invited++;
        }
    }
    $msg = "$invited portal invite" . ($invited !== 1 ? 's' : '') . ' sent.';
    if ($skipped) $msg .= " $skipped already had a portal account.";
    flash('success', $msg);
}

header('Location: index.php' . ($action === 'restore' ? '?archived=1' : ''));
exit;
