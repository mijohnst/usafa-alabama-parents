<?php
/**
 * Shared tFPDF rendering for one parent letter "page" — used by both
 * parent-letters-pdf.php (single letter, public/token-scoped) and
 * admin/parent-letters-pdf-all.php (every letter, admin-only batch).
 * Kept as one function so the two output paths can never visually drift
 * apart from each other.
 */
require_once __DIR__ . '/lib/tfpdf/tfpdf.php';
require_once __DIR__ . '/lib/tfpdf/font/unifont/ttfonts.php';

// Registers the Kalam handwritten font on a fresh document. Call once per
// tFPDF instance, before any AddPage()/render calls.
function setup_parent_letter_pdf(tFPDF $pdf): void {
    $pdf->AddFont('Kalam', '', 'Kalam-Regular.ttf', true);
    $pdf->AddFont('Kalam', 'B', 'Kalam-Bold.ttf', true);
    $pdf->SetMargins(1, 0.75, 1);
    $pdf->SetAutoPageBreak(true, 0.75);
}

// Renders one letter as its own page: logo, tagline, date, body text.
function render_parent_letter_pdf_page(tFPDF $pdf, string $letterDate, string $letterBody): void {
    $pdf->AddPage();
    $page_width = 8.5;

    $logo_path = __DIR__ . '/../logo01.png';
    if (file_exists($logo_path)) {
        $dims = @getimagesize($logo_path);
        $img_w_in = 2.0;
        $img_h_in = $dims ? $img_w_in * ($dims[1] / $dims[0]) : $img_w_in;
        $pdf->Image($logo_path, ($page_width - $img_w_in) / 2, 0.75, $img_w_in, $img_h_in);
        $pdf->SetY(0.75 + $img_h_in + 0.15);
    } else {
        $pdf->SetY(0.75);
    }

    $pdf->SetFont('Kalam', '', 10);
    $pdf->SetTextColor(90, 106, 122);
    $pdf->Cell(0, 0.25, 'A volunteer-run nonprofit supporting Alabama USAFA families · alabamafalcons.org', 0, 1, 'C');
    $pdf->Ln(0.3);

    $pdf->SetFont('Kalam', '', 13);
    $pdf->SetTextColor(51, 51, 51);
    $pdf->Cell(0, 0.25, $letterDate, 0, 1, 'R');
    $pdf->Ln(0.2);

    $pdf->SetFont('Kalam', '', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell(0, 0.34, $letterBody, 0, 'L');
}

// Renders a divider page announcing whose letter follows — used only by
// the admin batch download, so a printed stack still lines up with
// alphabetized envelopes even after the PDF pages get separated.
function render_parent_letter_separator_page(tFPDF $pdf, string $cadetFullName): void {
    $pdf->AddPage();
    $pdf->SetY(4.5);
    $pdf->SetFont('Kalam', 'B', 34);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->MultiCell(0, 0.6, $cadetFullName, 0, 'C');
}
