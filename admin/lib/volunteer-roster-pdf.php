<?php
/**
 * Volunteer roster PDF builder — shared by admin/volunteer-signups-pdf.php.
 * Kept free of DB/auth so it can be rendered standalone (e.g. with fake
 * rows) to check page breaks.
 *
 * $opp:  ['title', 'event_date' (Y-m-d|null), 'location', 'spots_needed']
 * $rows: list of ['name', 'cadet', 'email', 'phone'] in sign-up order.
 *
 * Every row is a single fixed-height line (long text is trimmed to fit,
 * never wrapped), there's no MultiCell anywhere, auto page break is OFF,
 * and every page break is an explicit AddPage() made before a row starts —
 * see the tFPDF note in the project's known-mistakes checklist for why.
 */
require_once __DIR__ . '/tfpdf/tfpdf.php';
require_once __DIR__ . '/tfpdf/font/unifont/ttfonts.php';

// "Page X of Y" footer via tFPDF's own Footer() hook + AliasNbPages(),
// drawn at a fixed position below the table's bottom line.
class VolunteerRosterPDF extends tFPDF {
    function Footer() {
        $this->SetFont('Kalam', '', 8.5);
        $this->SetTextColor(120, 130, 140);
        $this->SetXY(0.75, 10.45);
        $this->Cell(7.0, 0.2, 'Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }
}

// Trim $text with an ellipsis until it fits $w inches in the current font.
function vr_fit(tFPDF $pdf, string $text, float $w): string {
    $w -= 0.1; // cell padding
    if ($pdf->GetStringWidth($text) <= $w) return $text;
    while ($text !== '' && $pdf->GetStringWidth($text . '…') > $w) {
        $text = mb_substr($text, 0, -1);
    }
    return rtrim($text) . '…';
}

function build_volunteer_roster_pdf(array $opp, array $rows): tFPDF {
    // Columns sum to 7.0in — Letter width (8.5in) minus 0.75in margins.
    $col = ['n' => 0.4, 'name' => 1.7, 'cadet' => 1.6, 'email' => 1.9, 'phone' => 1.0, 'chk' => 0.4];
    $head_h   = 0.32;
    $row_h    = 0.3;
    $bottom_y = 10.25; // 11in page minus 0.75in bottom margin

    $pdf = new VolunteerRosterPDF('P', 'in', 'Letter');
    $pdf->AliasNbPages();
    // Only Kalam/Cinzel are vendored (no core-font metrics) — see birthdays-pdf.php.
    $pdf->AddFont('Kalam', '', 'Kalam-Regular.ttf', true);
    $pdf->AddFont('Kalam', 'B', 'Kalam-Bold.ttf', true);
    $pdf->AddFont('Cinzel', '', 'Cinzel-Regular.ttf', true);
    $pdf->SetMargins(0.75, 0.75, 0.75);
    $pdf->SetAutoPageBreak(false); // all breaks are manual, below
    $pdf->SetTitle('Volunteer Roster — ' . $opp['title'], true);

    $table_header = function () use ($pdf, $col, $head_h) {
        $pdf->SetFont('Kalam', 'B', 10);
        $pdf->SetFillColor(0, 37, 84);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($col['n'], $head_h, '#', 1, 0, 'C', true);
        $pdf->Cell($col['name'], $head_h, 'Volunteer', 1, 0, 'L', true);
        $pdf->Cell($col['cadet'], $head_h, 'Cadet', 1, 0, 'L', true);
        $pdf->Cell($col['email'], $head_h, 'Email', 1, 0, 'L', true);
        $pdf->Cell($col['phone'], $head_h, 'Phone', 1, 0, 'L', true);
        $pdf->Cell($col['chk'], $head_h, 'In', 1, 1, 'C', true); // check-in box (Kalam has no ✓ glyph)
        $pdf->SetTextColor(0, 0, 0);
    };

    $pdf->AddPage();

    // ── Title block ──
    $pdf->SetFont('Cinzel', '', 17);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->Cell(0, 0.34, vr_fit($pdf, (string)$opp['title'], 7.0), 0, 1, 'L');
    $pdf->SetFont('Kalam', '', 10.5);
    $pdf->SetTextColor(60, 74, 87);
    $when  = !empty($opp['event_date']) ? date('l, F j, Y', strtotime($opp['event_date'])) : 'Date TBD';
    $where = trim((string)($opp['location'] ?? ''));
    $pdf->Cell(0, 0.22, vr_fit($pdf, $when . ($where !== '' ? '  ·  ' . $where : ''), 7.0), 0, 1, 'L');
    $filled = count($rows);
    $spots  = (int)($opp['spots_needed'] ?? 0);
    $pdf->Cell(0, 0.22, 'Volunteers: ' . $filled . ($spots ? ' of ' . $spots . ' spots filled' : '') . '   ·   Printed ' . date('M j, Y g:i a'), 0, 1, 'L');
    $pdf->SetTextColor(90, 106, 122);
    $pdf->Cell(0, 0.2, 'USAFA Parents Club of Alabama', 0, 1, 'L');
    $pdf->Ln(0.15);

    if (!$rows) {
        $pdf->SetFont('Kalam', '', 12);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.4, 'No one has signed up for this opportunity yet.', 0, 1, 'L');
        return $pdf;
    }

    $table_header();
    $pdf->SetFont('Kalam', '', 10);
    foreach ($rows as $i => $r) {
        if ($pdf->GetY() + $row_h > $bottom_y) {
            $pdf->AddPage();
            $table_header();
            $pdf->SetFont('Kalam', '', 10);
        }
        $fill = ($i % 2 === 1);
        $pdf->SetFillColor(241, 244, 249);
        $pdf->Cell($col['n'], $row_h, (string)($i + 1), 1, 0, 'C', $fill);
        $pdf->Cell($col['name'], $row_h, vr_fit($pdf, (string)$r['name'], $col['name']), 1, 0, 'L', $fill);
        $pdf->Cell($col['cadet'], $row_h, vr_fit($pdf, (string)$r['cadet'], $col['cadet']), 1, 0, 'L', $fill);
        $pdf->Cell($col['email'], $row_h, vr_fit($pdf, (string)$r['email'], $col['email']), 1, 0, 'L', $fill);
        $pdf->Cell($col['phone'], $row_h, vr_fit($pdf, (string)$r['phone'], $col['phone']), 1, 0, 'L', $fill);
        $pdf->Cell($col['chk'], $row_h, '', 1, 1, 'C', $fill); // blank box for day-of check-in
    }
    return $pdf;
}
