<?php
require_once __DIR__ . '/auth.php';
require_member_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_verify();

$ids    = array_filter(array_map('intval', $_POST['member_ids'] ?? []));
$action = $_POST['action'] ?? '';
$mem_year = trim($_POST['membership_year'] ?? '');

if (empty($ids) || !in_array($action, ['mark_paid','mark_unpaid'])) {
    flash('error', 'No members selected.');
    header('Location: index.php'); exit;
}

$pdo  = get_pdo();
$ph   = implode(',', array_fill(0, count($ids), '?'));

if ($action === 'mark_paid') {
    $stmt = $pdo->prepare("UPDATE members SET membership_paid = 1, membership_year = ? WHERE id IN ($ph)");
    $stmt->execute(array_merge([$mem_year], $ids));
    flash('success', count($ids) . ' member(s) marked as paid for ' . $mem_year . '.');
} else {
    $stmt = $pdo->prepare("UPDATE members SET membership_paid = 0, membership_year = '' WHERE id IN ($ph)");
    $stmt->execute($ids);
    flash('success', count($ids) . ' member(s) marked as unpaid.');
}

header('Location: index.php');
exit;
