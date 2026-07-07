<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_treasurer()) {
    header('Location: dashboard.php?denied=1'); exit;
}

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
$dl  = isset($_GET['download']);

if (!$id) { header('Location: vault.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM vault_documents WHERE id=?');
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc || !preg_match('/^[a-zA-Z0-9._-]+$/', $doc['filename'])) {
    header('Location: vault.php'); exit;
}

$file = __DIR__ . '/vault/' . $doc['filename'];
if (!file_exists($file)) {
    flash('error', 'File not found on server.');
    header('Location: vault.php'); exit;
}

$mime = $doc['mime_type'] ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));

if ($dl) {
    header('Content-Disposition: attachment; filename="' . addslashes($doc['filename']) . '"');
} else {
    header('Content-Disposition: inline; filename="' . addslashes($doc['filename']) . '"');
}

readfile($file);
exit;
