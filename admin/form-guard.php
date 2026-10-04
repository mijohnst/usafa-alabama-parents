<?php
/**
 * Shared spam/abuse guard for public, unauthenticated form endpoints
 * (contact-form.php, membership-handler.php, sendoff-handler.php).
 */

// True if the honeypot field was filled in — real visitors never see or fill it.
// Accepts mixed (not just array) since callers only loosely check the decoded
// JSON body before passing it here — a malformed/non-object body must not crash.
function honeypot_tripped($data, string $field = 'website'): bool {
    if (!is_array($data)) return false;
    return trim((string)($data[$field] ?? '')) !== '';
}

// True if this IP has hit $form_name more than $max times in the last $window_minutes.
// Fails open (returns false = not limited) if the throttle table can't be reached,
// so a DB hiccup never blocks a legitimate submission.
// Public forms fail open during a database outage; authentication callers can
// pass $fail_closed=true so a broken throttle cannot become a login bypass.
function rate_limited(PDO $pdo, string $form_name, int $max = 5, int $window_minutes = 15, bool $fail_closed = false): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip_hash = hash('sha256', $ip);
    $lock_name = 'usafa_rate_' . substr(hash('sha256', $form_name . '|' . $ip_hash), 0, 40);
    $locked = false;
    try {
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 2)');
        $lock->execute([$lock_name]);
        $locked = (int)$lock->fetchColumn() === 1;
        if (!$locked) return $fail_closed;
        if (random_int(1, 100) === 1) {
            $pdo->exec("DELETE FROM form_throttle WHERE created_at < NOW() - INTERVAL 1 DAY");
        }
        $window_minutes = max(1, min(1440, $window_minutes));
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM form_throttle WHERE form_name = ? AND ip_hash = ? AND created_at > NOW() - INTERVAL $window_minutes MINUTE"
        );
        $stmt->execute([$form_name, $ip_hash]);
        if ((int)$stmt->fetchColumn() >= $max) return true;
        $pdo->prepare('INSERT INTO form_throttle (form_name, ip_hash) VALUES (?, ?)')->execute([$form_name, $ip_hash]);
        return false;
    } catch (PDOException $e) {
        error_log('form-guard: rate_limited check failed — ' . $e->getMessage());
        return $fail_closed;
    } finally {
        if ($locked) {
            try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lock_name]); } catch (PDOException $e) {}
        }
    }
}
