<?php
require_once __DIR__ . '/auth.php';
require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $pdo = get_pdo();
    $row = $pdo->prepare('SELECT membership_paid FROM members WHERE id = ?');
    $row->execute([$id]);
    $m = $row->fetch();
    if ($m) {
        $new_paid = $m['membership_paid'] ? 0 : 1;
        $new_year = $new_paid ? membership_year() : '';
        $pdo->prepare('UPDATE members SET membership_paid = ?, membership_year = ? WHERE id = ?')
            ->execute([$new_paid, $new_year, $id]);
        flash('success', 'Dues status updated.');
    }
}

$return = $_POST['return_url'] ?? 'index.php';
// Whitelist to admin directory only
if (!preg_match('/^[a-zA-Z0-9_\-\.\/\?\=\&\%]+$/', $return)) $return = 'index.php';
header('Location: ' . $return);
exit;
