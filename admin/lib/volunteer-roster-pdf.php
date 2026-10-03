<?php
/**
 * Volunteer roster PDF builder — shared by admin/volunteer-signups-pdf.php.
 * Kept free of DB/auth so it can be rendered standalone (e.g. with fake
 * rows) to check page breaks.
 *
 * $opp:  ['title', 'event_date' (Y-m-d|null), 'event_time' (optional), 'location', 'spots_needed']
 * $rows: list of ['name', 'cadet', 'email', 'phone'] in sign-up order.
 *
 * Long values word-wrap instead of being cut off. Per the tFPDF note in the
 * project's known-mistakes checklist, wrapping is done by hand rather than
 * with MultiCell(): each cell's text is split into lines up front
 * (vr_wrap), the row height is computed from the tallest cell, a manual
 * AddPage() happens before any row that wouldn't fit, then each cell's box
 * is drawn at that full height and its lines are placed inside with plain
 * single-line Cell() calls. Auto page break stays OFF, so nothing can
 * break a page mid-row.
 *
 * Font is Liberation Sans (metric-compatible with Arial, SIL OFL — see
 * tfpdf/font/unifont/LiberationSans-LICENSE.txt), since Arial itself can't
 * be redistributed in the repo.
 */
require_once __DIR__ . '/tfpdf/tfpdf.php';
require_once __DIR__ . '/tfpdf/font/unifont/ttfonts.php';

const VR_FONT = 'LiberationSans';

// "Page X of Y" footer via tFPDF's own Footer() hook + AliasNbPages(),
// drawn at a fixed position below the table's bottom line.
class VolunteerRosterPDF extends tFPDF {
    function Footer() {
        $this->SetFont(VR_FONT, '', 8);
        $this->SetTextColor(120, 130, 140);
        $this->SetXY(0.75, 10.45);
        $this->Cell(7.0, 0.2, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }
}

// Split $text into lines that each fit $w inches in the current font.
// Breaks at spaces; a single word longer than the line (e.g. an email
// address) is broken between characters, preferring right after '@' or '.'.
function vr_wrap(tFPDF $pdf, string $text, float $w): array {
    $text = trim(preg_replace('/\s+/', ' ', $text));
    if ($text === '') return [''];
    $lines = [];
    $line  = '';
    foreach (explode(' ', $text) as $word) {
        $try = $line === '' ? $word : $line . ' ' . $word;
        if ($pdf->GetStringWidth($try) <= $w) { $line = $try; continue; }
        if ($line !== '') { $lines[] = $line; $line = ''; }
        // Word alone too wide: chop it.
        while ($pdf->GetStringWidth($word) > $w) {
            $cut = mb_strlen($word);
            while ($cut > 1 && $pdf->GetStringWidth(mb_substr($word, 0, $cut)) > $w) $cut--;
            // Prefer breaking just after an '@' or '.' within the fitting part.
            $part = mb_substr($word, 0, $cut);
            $pref = max((int)mb_strrpos($part, '@'), (int)mb_strrpos($part, '.'));
            if ($pref > 0 && $pref < $cut - 1) $cut = $pref + 1;
            $lines[] = mb_substr($word, 0, $cut);
            $word = mb_substr($word, $cut);
        }
        $line = $word;
    }
    if ($line !== '') $lines[] = $line;
    return $lines;
}

// 2562305484 / (256) 230-5484 → 256-230-5484; anything else is left as typed.
function vr_phone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 11 && $d[0] === '1') $d = substr($d, 1);
    return strlen($d) === 10 ? substr($d, 0, 3) . '-' . substr($d, 3, 3) . '-' . substr($d, 6) : trim($p);
}

function build_volunteer_roster_pdf(array $opp, array $rows): tFPDF {
    // Columns sum to 7.0in — Letter width (8.5in) minus 0.75in margins.
    $col  = ['n' => 0.35, 'name' => 1.6, 'cadet' => 1.85, 'email' => 1.8, 'phone' => 1.05, 'chk' => 0.35];
    $pad_x    = 0.06;  // left/right text inset inside a cell
    $pad_y    = 0.07;  // top/bottom text inset — real slack, not a tight fit
    $line_h   = 0.19;  // one line of 9.5pt text
    $head_h   = 0.3;
    $bottom_y = 10.25; // 11in page minus 0.75in bottom margin

    $pdf = new VolunteerRosterPDF('P', 'in', 'Letter');
    $pdf->AliasNbPages();
    $pdf->AddFont(VR_FONT, '', 'LiberationSans-Regular.ttf', true);
    $pdf->AddFont(VR_FONT, 'B', 'LiberationSans-Bold.ttf', true);
    $pdf->SetMargins(0.75, 0.75, 0.75);
    $pdf->SetAutoPageBreak(false); // all breaks are manual, below
    $pdf->SetTitle('Volunteer Roster — ' . $opp['title'], true);

    $table_header = function () use ($pdf, $col, $head_h) {
        $pdf->SetFont(VR_FONT, 'B', 9.5);
        $pdf->SetFillColor(0, 37, 84);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($col['n'], $head_h, '#', 1, 0, 'C', true);
        $pdf->Cell($col['name'], $head_h, 'Volunteer', 1, 0, 'L', true);
        $pdf->Cell($col['cadet'], $head_h, 'Cadet', 1, 0, 'L', true);
        $pdf->Cell($col['email'], $head_h, 'Email', 1, 0, 'L', true);
        $pdf->Cell($col['phone'], $head_h, 'Phone', 1, 0, 'L', true);
        $pdf->Cell($col['chk'], $head_h, 'In', 1, 1, 'C', true); // check-in box
        $pdf->SetTextColor(0, 0, 0);
    };

    $pdf->AddPage();

    // ── Title block (title and location may wrap; no table yet, so no page risk) ──
    $pdf->SetFont(VR_FONT, 'B', 16);
    $pdf->SetTextColor(0, 37, 84);
    foreach (vr_wrap($pdf, (string)$opp['title'], 7.0) as $l) $pdf->Cell(0, 0.3, $l, 0, 1, 'L');
    $pdf->SetFont(VR_FONT, '', 10);
    $pdf->SetTextColor(60, 74, 87);
    $when  = !empty($opp['event_date']) ? date('l, F j, Y', strtotime($opp['event_date'])) : 'Date TBD';
    if (!empty($opp['event_time'])) $when .= ', ' . $opp['event_time'];
    $pdf->Cell(0, 0.21, $when, 0, 1, 'L');
    $where = trim((string)($opp['location'] ?? ''));
    if ($where !== '') foreach (vr_wrap($pdf, $where, 7.0) as $l) $pdf->Cell(0, 0.21, $l, 0, 1, 'L');
    $filled = count($rows);
    $spots  = (int)($opp['spots_needed'] ?? 0);
    $pdf->Cell(0, 0.21, 'Volunteers: ' . $filled . ($spots ? ' of ' . $spots . ' spots filled' : '') . '   ·   Printed ' . date('M j, Y g:i a'), 0, 1, 'L');
    $pdf->SetTextColor(90, 106, 122);
    $pdf->Cell(0, 0.2, 'USAFA Parents Club of Alabama', 0, 1, 'L');
    $pdf->Ln(0.15);

    if (!$rows) {
        $pdf->SetFont(VR_FONT, '', 11);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.4, 'No one has signed up for this opportunity yet.', 0, 1, 'L');
        return $pdf;
    }

    $table_header();
    $pdf->SetFont(VR_FONT, '', 9.5);
    foreach ($rows as $i => $r) {
        // Wrap every cell first so the row height is known before drawing.
        $cells = [
            'n'     => [(string)($i + 1)],
            'name'  => vr_wrap($pdf, (string)$r['name'],  $col['name']  - 2 * $pad_x),
            'cadet' => vr_wrap($pdf, (string)$r['cadet'], $col['cadet'] - 2 * $pad_x),
            'email' => vr_wrap($pdf, (string)$r['email'], $col['email'] - 2 * $pad_x),
            'phone' => vr_wrap($pdf, vr_phone((string)$r['phone']), $col['phone'] - 2 * $pad_x),
            'chk'   => [''],
        ];
        $n_lines = max(array_map('count', $cells));
        $row_h   = $n_lines * $line_h + 2 * $pad_y;

        if ($pdf->GetY() + $row_h > $bottom_y) {
            $pdf->AddPage();
            $table_header();
            $pdf->SetFont(VR_FONT, '', 9.5);
        }

        $fill = ($i % 2 === 1);
        $pdf->SetFillColor(241, 244, 249);
        $x = $pdf->GetX();
        $y = $pdf->GetY();
        foreach ($cells as $key => $lines) {
            $w = $col[$key];
            // Box at full row height, then its lines placed inside it.
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $row_h, '', 1, 0, 'L', $fill);
            $align = ($key === 'n') ? 'C' : 'L';
            foreach ($lines as $li => $text) {
                $pdf->SetXY($x + ($align === 'C' ? 0 : $pad_x), $y + $pad_y + $li * $line_h);
                $pdf->Cell($align === 'C' ? $w : $w - 2 * $pad_x, $line_h, $text, 0, 0, $align);
            }
            $x += $w;
        }
        $pdf->SetXY(0.75, $y + $row_h);
    }
    return $pdf;
}
