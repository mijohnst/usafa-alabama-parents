<?php
// AJAX endpoint for digest-composer.php — takes raw pasted emails, asks
// Gemini to organize them into one clean digest, returns HTML + plain text.
// Never linked directly; only called via fetch() from digest-composer.php.
require_once __DIR__ . '/auth.php';
require_digest_composer_access();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

csrf_verify();
// csrf_verify() rotates $_SESSION['csrf'] on every call, but this page is
// hit repeatedly via fetch() without a full page reload in between — so
// every response (including error paths below) must hand back the new
// token, and the client must use it for its *next* request.
$fresh_csrf = $_SESSION['csrf'];

require_once __DIR__ . '/config.php';

if (empty(GEMINI_API_KEY)) {
    echo json_encode(['error' => 'AI service isn\'t configured yet. Add a Gemini API key to admin/config.php (GEMINI_API_KEY) to enable this tool.', 'csrf' => $fresh_csrf]);
    exit;
}

$raw = trim($_POST['raw'] ?? '');
if ($raw === '') {
    echo json_encode(['error' => 'Paste in the emails you want organized first.', 'csrf' => $fresh_csrf]);
    exit;
}
// Cap input size — this is meant for "the last several days of forwarded
// emails," not an unbounded paste; keeps API cost/latency predictable.
if (mb_strlen($raw) > 60000) {
    echo json_encode(['error' => 'That\'s a lot of text — try trimming it to the emails you actually want included (60,000 character limit).', 'csrf' => $fresh_csrf]);
    exit;
}

$system_prompt = <<<'PROMPT'
You are helping a volunteer parent-club president turn a pile of forwarded emails into ONE clean, organized digest email for club members. Members are getting fatigued by too many separate emails, so this digest replaces them.

Rules:
- Do not invent, guess, or embellish any fact, date, dollar amount, name, or link. Only use what's actually present in the pasted text. If something is ambiguous or unclear, keep the original wording rather than guessing at what it means.
- Group related items under short, clear topic headings (e.g. "Upcoming Events", "Deadlines & Action Items", "General Updates").
- Put anything with a hard deadline or required action near the top, and make the deadline/date bold.
- Cut greetings, signatures, "sent from my iPhone" footers, forwarded-message headers ("---------- Forwarded message ---------"), and other boilerplate — keep only the substance.
- Preserve links and email addresses exactly as written.
- Keep the tone warm and concise — this is for parents of Air Force Academy cadets.
- Output ONLY the digest body as simple HTML using nothing but these tags: <h3>, <p>, <ul>, <li>, <strong>, <a href="...">. No <html>, <head>, <body>, inline styles, or any other tags. No commentary before or after — just the HTML.
PROMPT;

// Free-tier-eligible Gemini model via Google AI Studio — swap this constant
// if Google retires this one too (it already replaced gemini-2.0-flash once).
const GEMINI_MODEL = 'gemini-3.6-flash';

$payload = json_encode([
    'system_instruction' => ['parts' => [['text' => $system_prompt]]],
    'contents'           => [
        ['role' => 'user', 'parts' => [['text' => "Here are the emails to organize into one digest:\n\n" . $raw]]],
    ],
    // This model spends part of its output budget on internal "thinking"
    // tokens before the visible response (observed ~1,400 for a trivial
    // prompt) — kept generous so a long digest doesn't get cut off.
    'generationConfig'   => ['maxOutputTokens' => 8192],
]);

$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $payload,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'x-goog-api-key: ' . GEMINI_API_KEY,
    ],
    CURLOPT_TIMEOUT => 60,
]);
$resp     = curl_exec($ch);
$code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err = curl_error($ch);
curl_close($ch);

if ($resp === false) {
    error_log('Digest Composer: cURL error — ' . $curl_err);
    echo json_encode(['error' => 'Could not reach the AI service. Please try again.', 'csrf' => $fresh_csrf]);
    exit;
}

$data = json_decode($resp, true);
$candidate = $data['candidates'][0] ?? null;

if ($code !== 200 || !isset($candidate['content']['parts'][0]['text'])) {
    error_log('Digest Composer: API error (HTTP ' . $code . ') — ' . $resp);
    if (($candidate['finishReason'] ?? '') === 'SAFETY') {
        $msg = 'The AI service declined to process this text (flagged by its safety filter). Try again with a smaller excerpt.';
    } else {
        $msg = $data['error']['message'] ?? 'The AI service returned an unexpected response.';
    }
    echo json_encode(['error' => $msg, 'csrf' => $fresh_csrf]);
    exit;
}

if (($candidate['finishReason'] ?? '') === 'MAX_TOKENS') {
    echo json_encode(['error' => 'That draft got cut off — try again with fewer emails pasted in at once.', 'csrf' => $fresh_csrf]);
    exit;
}

$html = trim($candidate['content']['parts'][0]['text']);
// Strip a stray ```html / ``` code fence if the model wraps its output in one.
$html = preg_replace('/^```(?:html)?\s*|\s*```$/i', '', $html);

$text = trim(html_entity_decode(strip_tags(str_replace(
    ['<li>', '</li>', '</p>', '</h3>'],
    ['• ', "\n", "\n\n", "\n\n"],
    $html
)), ENT_QUOTES, 'UTF-8'));
$text = preg_replace("/\n{3,}/", "\n\n", $text);

echo json_encode(['html' => $html, 'text' => $text, 'csrf' => $fresh_csrf]);
