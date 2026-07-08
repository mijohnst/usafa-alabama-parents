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
$member_actions = ['archive','restore','delete'];
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
}

header('Location: index.php' . ($action === 'restore' ? '?archived=1' : ''));
exit;
