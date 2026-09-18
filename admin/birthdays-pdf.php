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
$row_h = 0.32; // Kalam (the only body font vendored here) runs taller than a core font at the same point size

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

foreach ($groups as $gmonth => $gcadets) {
    if (empty($gcadets)) continue;
    $pdf->AddPage();

    $pdf->SetFont('Cinzel', '', 18);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->Cell(0, 0.32, date('F', mktime(0, 0, 0, $gmonth, 1)) . ' Birthdays', 0, 1, 'L');
    $pdf->SetFont('Kalam', '', 10);
    $pdf->SetTextColor(90, 106, 122);
    $pdf->Cell(0, 0.2, 'USAFA Parents Club of Alabama', 0, 1, 'L');
    $pdf->Ln(0.15);

    birthday_pdf_table_header($pdf, $col_w, $row_h);

    foreach ($gcadets as $c) {
        $paid = (bool)$c['membership_paid'];
        $addr = $c['cadet_po_box']
            ? 'P.O. Box ' . $c['cadet_po_box'] . ', USAF Academy, CO 80841-' . $c['cadet_po_box']
            : 'No PO Box on file';

        $pdf->SetFont('Kalam', '', 10);
        $pdf->SetTextColor(0, 0, 0);
        if ($paid) $pdf->SetFillColor(232, 245, 233); else $pdf->SetFillColor(255, 255, 255);
        $pdf->Cell($col_w['date'], $row_h, date('M j', strtotime($c['cadet_birthday'])), 1, 0, 'L', true);
        $pdf->Cell($col_w['cadet'], $row_h, cadet_full_name($c) . ($paid ? ' (Paid)' : ''), 1, 0, 'L', true);
        $pdf->Cell($col_w['class'], $row_h, $c['class_year'], 1, 0, 'L', true);
        $pdf->Cell($col_w['addr'], $row_h, $addr, 1, 1, 'L', true);
    }
}

if (empty($cadets)) {
    $pdf->AddPage();
    $pdf->SetFont('Kalam', '', 13);
    $pdf->Cell(0, 0.3, 'No cadets have birthdays on file.', 0, 1, 'L');
}

$label = $show_all ? 'Full-Year' : date('F', mktime(0, 0, 0, $month, 1));
$pdf->Output('D', 'Birthday-List-' . $label . '-' . date('Y-m-d') . '.pdf');
