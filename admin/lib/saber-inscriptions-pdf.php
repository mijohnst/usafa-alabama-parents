<?php
/**
 * Saber inscriptions PDF builder — shared by admin/saber-inscriptions-pdf.php.
 * Kept free of DB/auth so it can be rendered standalone (e.g. with fake
 * rows) to check page breaks.
 *
 * $title: e.g. "Class of 2027 Saber Inscriptions"
 * $rows:  list of ['cadet' => full name, 'inscription' => text] — only cadets
 *         with an inscription; $missing is a list of cadet names without one.
 *
 * Two layouts:
 *   'sheet' — one table of every inscription, to send with a combined order.
 *   'slips' — one page per cadet with the inscription large, to attach to
 *             each saber's order individually.
 *
 * Same tFPDF approach as lib/volunteer-roster-pdf.php (and the project's
 * known-mistakes checklist): auto page break OFF, text wrapped up front with
 * vr_wrap(), every row's height computed before drawing, and every page
 * break an explicit AddPage().
 */
require_once __DIR__ . '/volunteer-roster-pdf.php'; // vr_wrap(), VR_FONT, tFPDF

class SaberInscriptionsPDF extends tFPDF {
    function Footer() {
        $this->SetFont(VR_FONT, '', 8);
        $this->SetTextColor(120, 130, 140);
        $this->SetXY(0.75, 10.45);
        $this->Cell(7.0, 0.2, 'USAFA Parents Club of Alabama   ·   Page ' . $this->PageNo() . ' of {nb}', 0, 0, 'R');
    }
}

// Largest bold font size (stepping down by 0.5pt, not below $min) at which
// $text fits on ONE line of $w inches. Inscriptions are a single line, and
// a wrapped line on the printout could be read by the engraver as an
// intended line break — so shrink first, and only wrap as a last resort.
function saber_fit_size(tFPDF $pdf, string $text, float $w, float $max, float $min): float {
    for ($size = $max; $size > $min; $size -= 0.5) {
        $pdf->SetFont(VR_FONT, 'B', $size);
        if ($pdf->GetStringWidth($text) <= $w) return $size;
    }
    return $min;
}

function build_saber_inscriptions_pdf(string $title, array $rows, array $missing, string $layout = 'sheet'): tFPDF {
    $pdf = new SaberInscriptionsPDF('P', 'in', 'Letter');
    $pdf->AliasNbPages();
    $pdf->AddFont(VR_FONT, '', 'LiberationSans-Regular.ttf', true);
    $pdf->AddFont(VR_FONT, 'B', 'LiberationSans-Bold.ttf', true);
    $pdf->AddFont('Cinzel', '', 'Cinzel-Regular.ttf', true);
    $pdf->SetMargins(0.75, 0.75, 0.75);
    $pdf->SetAutoPageBreak(false); // all breaks are manual, below
    $pdf->SetTitle($title, true);

    if ($layout === 'slips' && $rows) {
        saber_pdf_slips($pdf, $title, $rows);
    } else {
        saber_pdf_sheet($pdf, $title, $rows, $missing);
    }
    return $pdf;
}

function saber_pdf_sheet(tFPDF $pdf, string $title, array $rows, array $missing): void {
    // Columns sum to 7.0in — Letter width (8.5in) minus 0.75in margins.
    $col      = ['n' => 0.4, 'cadet' => 2.4, 'ins' => 4.2];
    $pad_x    = 0.08;
    $pad_y    = 0.09;
    $line_h   = 0.24;  // one line of 12pt bold inscription text
    $head_h   = 0.3;
    $bottom_y = 10.25; // 11in page minus 0.75in bottom margin

    $table_header = function () use ($pdf, $col, $head_h) {
        $pdf->SetFont(VR_FONT, 'B', 10);
        $pdf->SetFillColor(0, 37, 84);
        $pdf->SetTextColor(255, 255, 255);
        $pdf->Cell($col['n'], $head_h, '#', 1, 0, 'C', true);
        $pdf->Cell($col['cadet'], $head_h, 'Cadet', 1, 0, 'L', true);
        $pdf->Cell($col['ins'], $head_h, 'Inscription (engrave exactly as shown)', 1, 1, 'L', true);
        $pdf->SetTextColor(0, 0, 0);
    };

    $pdf->AddPage();
    $pdf->SetFont('Cinzel', '', 18);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->Cell(0, 0.35, $title, 0, 1, 'L');
    $pdf->SetFont(VR_FONT, '', 10);
    $pdf->SetTextColor(60, 74, 87);
    $pdf->Cell(0, 0.21, count($rows) . ' inscription' . (count($rows) === 1 ? '' : 's') . '   ·   Printed ' . date('M j, Y g:i a'), 0, 1, 'L');
    $pdf->Ln(0.15);

    if (!$rows) {
        $pdf->SetFont(VR_FONT, '', 11);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.4, 'No inscriptions have been submitted yet.', 0, 1, 'L');
    } else {
        $table_header();
        foreach ($rows as $i => $r) {
            $pdf->SetFont(VR_FONT, '', 10.5);
            $cadet_lines = vr_wrap($pdf, (string)$r['cadet'], $col['cadet'] - 2 * $pad_x);
            $ins_size  = saber_fit_size($pdf, (string)$r['inscription'], $col['ins'] - 2 * $pad_x, 12, 8);
            $ins_lines = vr_wrap($pdf, (string)$r['inscription'], $col['ins'] - 2 * $pad_x);
            $row_h = max(count($cadet_lines), count($ins_lines)) * $line_h + 2 * $pad_y;

            if ($pdf->GetY() + $row_h > $bottom_y) {
                $pdf->AddPage();
                $table_header();
            }

            $fill = ($i % 2 === 1);
            $pdf->SetFillColor(241, 244, 249);
            $x = 0.75;
            $y = $pdf->GetY();
            $cells = [
                'n'     => [[(string)($i + 1)], '', 10.5, 'C'],
                'cadet' => [$cadet_lines, '', 10.5, 'L'],
                'ins'   => [$ins_lines, 'B', $ins_size, 'L'],
            ];
            foreach ($cells as $key => [$lines, $style, $size, $align]) {
                $w = $col[$key];
                $pdf->SetXY($x, $y);
                $pdf->Cell($w, $row_h, '', 1, 0, 'L', $fill);
                $pdf->SetFont(VR_FONT, $style, $size);
                foreach ($lines as $li => $text) {
                    $pdf->SetXY($x + ($align === 'C' ? 0 : $pad_x), $y + $pad_y + $li * $line_h);
                    $pdf->Cell($align === 'C' ? $w : $w - 2 * $pad_x, $line_h, $text, 0, 0, $align);
                }
                $x += $w;
            }
            $pdf->SetXY(0.75, $y + $row_h);
        }
    }

    // Cadets in the class with nothing submitted — so whoever places the
    // order can see at a glance who still needs chasing.
    if ($missing) {
        $pdf->SetFont(VR_FONT, '', 10);
        $lines = vr_wrap($pdf, implode(', ', $missing), 7.0);
        $block_h = 0.25 + 0.3 + count($lines) * 0.2;
        if ($pdf->GetY() + $block_h > $bottom_y) $pdf->AddPage(); else $pdf->Ln(0.25);
        $pdf->SetFont(VR_FONT, 'B', 11);
        $pdf->SetTextColor(166, 25, 46);
        $pdf->Cell(0, 0.3, 'No inscription yet (' . count($missing) . ')', 0, 1, 'L');
        $pdf->SetFont(VR_FONT, '', 10);
        $pdf->SetTextColor(60, 74, 87);
        foreach ($lines as $l) $pdf->Cell(0, 0.2, $l, 0, 1, 'L');
    }
}

function saber_pdf_slips(tFPDF $pdf, string $title, array $rows): void {
    $box_w  = 7.0;
    $line_h = 0.42; // one line of 22pt bold
    foreach ($rows as $r) {
        $pdf->AddPage();
        $pdf->SetFont('Cinzel', '', 16);
        $pdf->SetTextColor(0, 37, 84);
        $pdf->Cell(0, 0.32, $title, 0, 1, 'L');
        $pdf->SetFont(VR_FONT, '', 10);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.2, 'USAFA Parents Club of Alabama   ·   Printed ' . date('M j, Y'), 0, 1, 'L');
        $pdf->Ln(0.4);

        $pdf->SetFont(VR_FONT, 'B', 10);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.22, 'CADET', 0, 1, 'L');
        $pdf->SetFont(VR_FONT, '', 16);
        $pdf->SetTextColor(0, 0, 0);
        foreach (vr_wrap($pdf, (string)$r['cadet'], $box_w) as $l) $pdf->Cell(0, 0.32, $l, 0, 1, 'L');
        $pdf->Ln(0.3);

        $pdf->SetFont(VR_FONT, 'B', 10);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.22, 'INSCRIPTION — ENGRAVE EXACTLY AS SHOWN', 0, 1, 'L');

        $size  = saber_fit_size($pdf, (string)$r['inscription'], $box_w - 0.4, 22, 12);
        $lines = vr_wrap($pdf, (string)$r['inscription'], $box_w - 0.4);
        $box_h = count($lines) * $line_h + 0.6;
        $y = $pdf->GetY() + 0.05;
        $pdf->SetDrawColor(0, 37, 84);
        $pdf->SetLineWidth(0.02);
        $pdf->Rect(0.75, $y, $box_w, $box_h);
        $pdf->SetLineWidth(0.01);
        $pdf->SetTextColor(0, 0, 0);
        foreach ($lines as $li => $l) {
            $pdf->SetXY(0.75, $y + 0.3 + $li * $line_h);
            $pdf->Cell($box_w, $line_h, $l, 0, 0, 'C');
        }
        $pdf->SetXY(0.75, $y + $box_h + 0.15);

        $ins = (string)$r['inscription'];
        $pdf->SetFont(VR_FONT, '', 10);
        $pdf->SetTextColor(60, 74, 87);
        $pdf->Cell(0, 0.22, mb_strlen($ins) . ' characters including spaces. One line of text' . (count($lines) > 1 ? ' — the break above is only from the page width.' : '.'), 0, 1, 'L');
    }
}
