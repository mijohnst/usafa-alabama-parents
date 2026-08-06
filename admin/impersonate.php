<?php
/**
 * "Login As" — lets a real admin temporarily view the portal as another
 * user, to test what each role/position actually sees and can do.
 */
require_once __DIR__ . '/auth.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_verify();
$pdo    = get_pdo();
$action = $_POST['action'] ?? '';

if ($action === 'start') {
    // Must be a real admin, from their own session — impersonating already
    // flips $_SESSION['role'] away from 'admin', so this also blocks
    // starting a second, nested impersonation.
    if (($_SESSION['role'] ?? '') !== 'admin') {
        flash('error', 'Only an admin can switch users.');
        header('Location: users.php'); exit;
    }

    $target_id = (int)($_POST['user_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT id, name, email, role, active FROM users WHERE id = ?');
    $stmt->execute([$target_id]);
    $target = $stmt->fetch();

    if (!$target) {
        flash('error', 'User not found.');
    } elseif ($target['role'] === 'admin') {
        flash('error', 'Cannot switch into another admin account.');
    } elseif (!$target['active']) {
        flash('error', 'That account is deactivated.');
    } else {
        $_SESSION['impersonator_id']    = $_SESSION['user_id'];
        $_SESSION['impersonator_name']  = $_SESSION['user_name'];
        $_SESSION['impersonator_email'] = $_SESSION['user_email'];
        $_SESSION['user_id']    = $target['id'];
        $_SESSION['role']       = $target['role'];
        $_SESSION['user_name']  = $target['name'];
        $_SESSION['user_email'] = $target['email'];
        error_log('impersonate: admin id=' . $_SESSION['impersonator_id']
            . " started viewing as user id={$target['id']} ({$target['name']}, role={$target['role']})");
        flash('success', 'Now viewing as ' . $target['name'] . ' (' . ucfirst($target['role']) . ').');
    }
    header('Location: dashboard.php'); exit;

} elseif ($action === 'stop') {
    if (is_impersonating()) {
        error_log('impersonate: admin id=' . $_SESSION['impersonator_id']
            . ' stopped viewing as user id=' . $_SESSION['user_id']);
        $_SESSION['user_id']    = $_SESSION['impersonator_id'];
        $_SESSION['user_name']  = $_SESSION['impersonator_name'];
        $_SESSION['user_email'] = $_SESSION['impersonator_email'];
        $_SESSION['role']       = 'admin';
        unset($_SESSION['impersonator_id'], $_SESSION['impersonator_name'], $_SESSION['impersonator_email']);
        flash('success', 'Back to your admin account.');
    }
    header('Location: users.php'); exit;
}

header('Location: dashboard.php'); exit;
