<?php
/**
 * Online donations PDF builder — used by admin/donations-pdf.php.
 * Kept free of DB/auth so it can be rendered standalone (e.g. with fake
 * rows) to check page breaks.
 *
 * $title: e.g. "Class of 2027 Saber Fund — Online Donations"
 * $rows:  campaign_donations() rows (newest first). Missing optional keys
 *         (donor_comment, show_name, honoree_last_name) print as a dash.
 *
 * Same tFPDF approach as lib/saber-inscriptions-pdf.php (and the project's
 * known-mistakes checklist): auto page break OFF, text wrapped up front with
 * vr_wrap(), every row's height computed before drawing, and every page
 * break an explicit AddPage() that repeats the table header.
 */
require_once __DIR__ . '/volunteer-roster-pdf.php'; // vr_wrap(), VR_FONT, tFPDF

// Landscape Letter: 11in × 8.5in, 0.5in margins → 10in of table width.
const DON_PDF_LEFT   = 0.5;
const DON_PDF_BOTTOM = 7.8;  // last y a row may reach; footer sits below

class DonationsPDF extends tFPDF {
    function Footer() {
        $this->SetFont(VR_FONT, '', 8);
        $this->SetTextColor(120, 130, 140);
        $this->SetXY(DON_PDF_LEFT, 8.05);
        $this->Cell(10.0, 0.2, 'USAFA Parents Club of Alabama   ·   Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }
}

function build_donations_pdf(string $title, array $rows): tFPDF {
    $pdf = new DonationsPDF('L', 'in', 'Letter');
    $pdf->AliasNbPages();
    $pdf->AddFont(VR_FONT, '', 'LiberationSans-Regular.ttf', true);
    $pdf->AddFont(VR_FONT, 'B', 'LiberationSans-Bold.ttf', true);
    $pdf->AddFont('Cinzel', '', 'Cinzel-Regular.ttf', true);
    $pdf->SetMargins(DON_PDF_LEFT, 0.5, DON_PDF_LEFT);
    $pdf->SetAutoPageBreak(false); // all breaks are manual, below
    $pdf->SetTitle($title, true);

    // Columns sum to 10.0in.
    $col = [
        'date'    => ['Date',        0.9,  'L'],
        'donor'   => ['Donor',       1.65, 'L'],
        'email'   => ['Email',       2.2,  'L'],
        'amount'  => ['Amount',      0.85, 'R'],
        'public'  => ['Public?',     0.9,  'L'],
        'honoree' => ['In Honor Of', 1.1,  'L'],
        'comment' => ['Comment',     2.4,  'L'],
    ];
    $pad_x  = 0.06;
    $pad_y  = 0.07;
    $line_h = 0.17; // one line of 9pt text
    $head_h = 0.28;
    $size   = 9;

    $table_header = function () use ($pdf, $col, $head_h) {
        $pdf->SetFont(VR_FONT, 'B', 9);
        $pdf->SetFillColor(0, 37, 84);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->SetX(DON_PDF_LEFT);
        foreach ($col as [$label, $w, $align]) {
            $pdf->Cell($w, $head_h, $label, 1, 0, $align === 'R' ? 'R' : 'L', true);
        }
        $pdf->Ln($head_h);
        $pdf->SetTextColor(0, 0, 0);
    };

    $total = array_sum(array_map(fn($r) => (float)$r['amount'], $rows));
    $count = count($rows);

    $pdf->AddPage();
    $pdf->SetFont('Cinzel', '', 16);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->Cell(0, 0.32, $title, 0, 1, 'L');
    $pdf->SetFont(VR_FONT, '', 10);
    $pdf->SetTextColor(60, 74, 87);
    $pdf->Cell(0, 0.2, $count . ' donation' . ($count === 1 ? '' : 's') . '   ·   $' . number_format($total, 2)
        . ' total   ·   Printed ' . date('M j, Y g:i a'), 0, 1, 'L');
    $pdf->SetFont(VR_FONT, '', 8.5);
    $pdf->SetTextColor(120, 130, 140);
    $pdf->Cell(0, 0.18, 'Real donor names are shown regardless of their public choice; "Anonymous" only hides them on the public donor wall.', 0, 1, 'L');
    $pdf->Ln(0.1);

    if (!$rows) {
        $pdf->SetFont(VR_FONT, '', 11);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.4, 'No online donations captured for this campaign yet.', 0, 1, 'L');
        return $pdf;
    }

    $table_header();
    $pdf->SetFont(VR_FONT, '', $size);
    foreach ($rows as $i => $r) {
        $public = !array_key_exists('show_name', $r) ? '—' : ((int)$r['show_name'] === 0 ? 'Anonymous' : 'Shown');
        $values = [
            'date'    => date('M j, Y', strtotime((string)$r['captured_at'])),
            'donor'   => trim((string)($r['donor_name'] ?? '')) ?: '—',
            'email'   => trim((string)($r['donor_email'] ?? '')) ?: '—',
            'amount'  => '$' . number_format((float)$r['amount'], 2),
            'public'  => $public,
            'honoree' => trim((string)($r['honoree_last_name'] ?? '')) ?: '—',
            'comment' => trim((string)($r['donor_comment'] ?? '')),
        ];
        $lines = [];
        $max_lines = 1;
        foreach ($col as $key => [, $w]) {
            $lines[$key] = vr_wrap($pdf, $values[$key], $w - 2 * $pad_x);
            $max_lines = max($max_lines, count($lines[$key]));
        }
        $row_h = $max_lines * $line_h + 2 * $pad_y;

        if ($pdf->GetY() + $row_h > DON_PDF_BOTTOM) {
            $pdf->AddPage();
            $table_header();
            $pdf->SetFont(VR_FONT, '', $size);
        }

        $fill = ($i % 2 === 1);
        $pdf->SetFillColor(241, 244, 249);
        $x = DON_PDF_LEFT;
        $y = $pdf->GetY();
        foreach ($col as $key => [, $w, $align]) {
            $pdf->SetXY($x, $y);
            $pdf->Cell($w, $row_h, '', 1, 0, 'L', $fill);
            foreach ($lines[$key] as $li => $text) {
                $pdf->SetXY($x + $pad_x, $y + $pad_y + $li * $line_h);
                $pdf->Cell($w - 2 * $pad_x, $line_h, $text, 0, 0, $align);
            }
            $x += $w;
        }
        $pdf->SetXY(DON_PDF_LEFT, $y + $row_h);
    }

    // Total row, kept on the same page as at least the header above it.
    $total_h = 0.3;
    if ($pdf->GetY() + $total_h > DON_PDF_BOTTOM) {
        $pdf->AddPage();
        $table_header();
    }
    $pdf->SetFont(VR_FONT, 'B', 9.5);
    $pdf->SetFillColor(226, 232, 242);
    $lead_w = $col['date'][1] + $col['donor'][1] + $col['email'][1];
    $pdf->SetX(DON_PDF_LEFT);
    $pdf->Cell($lead_w, $total_h, 'Total (' . $count . ' donation' . ($count === 1 ? '' : 's') . ')', 1, 0, 'R', true);
    $pdf->Cell($col['amount'][1], $total_h, '$' . number_format($total, 2), 1, 0, 'R', true);
    $pdf->Cell($col['public'][1] + $col['honoree'][1] + $col['comment'][1], $total_h, '', 1, 1, 'L', true);
    return $pdf;
}
