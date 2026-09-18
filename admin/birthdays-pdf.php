<?php
/**
 * Cadet Birthday Cards — PDF Download
 * Same data/view as birthdays.php (by month or the full year), rendered
 * as a downloadable PDF instead of a web page — one page per month, with
 * paid-member rows shaded to match the on-screen highlight. Uses the
 * tFPDF library already vendored for parent-letters-pdf-all.php.
 */
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();
require_once __DIR__ . '/lib/tfpdf/tfpdf.php';
require_once __DIR__ . '/lib/tfpdf/font/unifont/ttfonts.php';

$view     = trim((string)($_GET['month'] ?? date('n')));
$show_all = ($view === 'all');
$month    = $show_all ? 0 : (int)$view;
if (!$show_all && ($month < 1 || $month > 12)) $month = (int)date('n');

if ($show_all) {
    $stmt = $pdo->query(
        "SELECT cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, cadet_birthday,
                cadet_po_box, class_year, membership_paid
         FROM members
         WHERE archived = 0 AND cadet_birthday IS NOT NULL
         ORDER BY MONTH(cadet_birthday), DAY(cadet_birthday), cadet_last_name"
    );
} else {
    $stmt = $pdo->prepare(
        "SELECT cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, cadet_birthday,
                cadet_po_box, class_year, membership_paid
         FROM members
         WHERE archived = 0 AND cadet_birthday IS NOT NULL AND MONTH(cadet_birthday) = ?
         ORDER BY DAY(cadet_birthday), cadet_last_name"
    );
    $stmt->execute([$month]);
}
$cadets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
if ($show_all) {
    foreach ($cadets as $c) $groups[(int)date('n', strtotime($c['cadet_birthday']))][] = $c;
} else {
    $groups[$month] = $cadets;
}

// Columns sum to 6.5in — Letter width minus 0.75in margins each side.
$col_w = ['date' => 0.9, 'cadet' => 2.5, 'class' => 0.8, 'addr' => 2.3];
$header_row_h = 0.32;
$line_h  = 0.24;  // one line of Kalam body text
$data_row_h = $line_h * 2; // every data row is 2 lines tall, to fit a 2-line mailing address without overflow
$bottom_y = 10.25; // Letter (11in) minus the 0.75in bottom margin set below — kept in sync with SetAutoPageBreak

function birthday_pdf_table_header(tFPDF $pdf, array $col_w, float $row_h): void {
    $pdf->SetFont('Kalam', 'B', 10);
    $pdf->SetFillColor(0, 37, 84);
    $pdf->SetTextColor(255, 255, 255);
    $pdf->Cell($col_w['date'], $row_h, 'Date', 1, 0, 'L', true);
    $pdf->Cell($col_w['cadet'], $row_h, 'Cadet', 1, 0, 'L', true);
    $pdf->Cell($col_w['class'], $row_h, 'Class', 1, 0, 'L', true);
    $pdf->Cell($col_w['addr'], $row_h, 'Mailing Address', 1, 1, 'L', true);
    $pdf->SetTextColor(0, 0, 0);
}

// Draws one cadet row. Date/Cadet/Class are plain fixed-height Cells (short
// text, never wraps); the address needs up to two lines, and MultiCell's
// own border draws a separate box per wrapped line (which would put a
// divider between the two address lines instead of one unified cell), so
// the bordered/filled box is drawn first via an empty Cell() and the text
// is placed inside it afterward with border off.
function birthday_pdf_row(tFPDF $pdf, array $col_w, float $data_row_h, float $line_h, array $c): void {
    $paid = (bool)$c['membership_paid'];
    [$addr1, $addr2] = cadet_mailing_address($c['cadet_po_box']);
    $fill = $paid;
    if ($fill) $pdf->SetFillColor(232, 245, 233); else $pdf->SetFillColor(255, 255, 255);

    $x0 = $pdf->GetX();
    $y0 = $pdf->GetY();

    $pdf->SetFont('Kalam', '', 10);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Cell($col_w['date'], $data_row_h, date('M j', strtotime($c['cadet_birthday'])), 1, 0, 'L', $fill);
    $pdf->Cell($col_w['cadet'], $data_row_h, cadet_full_name($c) . ($paid ? ' (Paid)' : ''), 1, 0, 'L', $fill);
    $pdf->Cell($col_w['class'], $data_row_h, $c['class_year'], 1, 0, 'L', $fill);

    $addr_x = $pdf->GetX();
    $pdf->Cell($col_w['addr'], $data_row_h, '', 1, 1, 'L', $fill);
    $pdf->SetXY($addr_x + 0.05, $y0 + 0.04);
    $pdf->MultiCell($col_w['addr'] - 0.1, $line_h, $addr1 . ($addr2 !== '' ? "\n" . $addr2 : ''), 0, 'L');

    $pdf->SetXY($x0, $y0 + $data_row_h);
}

$pdf = new tFPDF('P', 'in', 'Letter');
// Only Kalam/Cinzel are actually vendored under admin/lib/tfpdf/font/ (this
// copy never shipped the standard core-font metrics files) — SetFont() with
// a core font name like 'Helvetica' fatals at runtime since there's no
// metrics file for it to load, so every font used here must be one of these
// two, registered exactly like setup_parent_letter_pdf() already does.
$pdf->AddFont('Kalam', '', 'Kalam-Regular.ttf', true);
$pdf->AddFont('Kalam', 'B', 'Kalam-Bold.ttf', true);
$pdf->AddFont('Cinzel', '', 'Cinzel-Regular.ttf', true);
$pdf->SetMargins(0.75, 0.75, 0.75);
$pdf->SetAutoPageBreak(true, 0.75);

if (!empty($cadets)) {
    $pdf->AddPage();
    $first_group = true;

    foreach ($groups as $gmonth => $gcadets) {
        if (empty($gcadets)) continue;

        // Months flow continuously on shared pages (to save paper on the
        // full-year download) rather than one page per month — but avoid
        // stranding a month's heading alone at the bottom of a page with
        // its table starting on the next one.
        $month_block_h = 0.32 + 0.2 + 0.15 + $header_row_h + $data_row_h;
        if (!$first_group && $pdf->GetY() + $month_block_h > $bottom_y) {
            $pdf->AddPage();
        } elseif (!$first_group) {
            $pdf->Ln(0.3);
        }
        $first_group = false;

        $pdf->SetFont('Cinzel', '', 18);
        $pdf->SetTextColor(0, 37, 84);
        $pdf->Cell(0, 0.32, date('F', mktime(0, 0, 0, $gmonth, 1)) . ' Birthdays', 0, 1, 'L');
        $pdf->SetFont('Kalam', '', 10);
        $pdf->SetTextColor(90, 106, 122);
        $pdf->Cell(0, 0.2, 'USAFA Parents Club of Alabama', 0, 1, 'L');
        $pdf->Ln(0.15);

        birthday_pdf_table_header($pdf, $col_w, $header_row_h);

        foreach ($gcadets as $c) {
            if ($pdf->GetY() + $data_row_h > $bottom_y) {
                $pdf->AddPage();
                birthday_pdf_table_header($pdf, $col_w, $header_row_h);
            }
            birthday_pdf_row($pdf, $col_w, $data_row_h, $line_h, $c);
        }
    }
} else {
    $pdf->AddPage();
    $pdf->SetFont('Kalam', '', 13);
    $pdf->Cell(0, 0.3, 'No cadets have birthdays on file.', 0, 1, 'L');
}

$label = $show_all ? 'Full-Year' : date('F', mktime(0, 0, 0, $month, 1));
$pdf->Output('D', 'Birthday-List-' . $label . '-' . date('Y-m-d') . '.pdf');
