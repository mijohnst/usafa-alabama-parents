<?php
// ── Google Sheets CSV Import ────────────────────────────────────────────────
// 1. In Google Sheets: File → Download → Comma Separated Values (.csv)
// 2. Upload that file here — your existing ~80 members will be imported.
// 3. Delete this file from the server when done.
// ─────────────────────────────────────────────────────────────────────────────
require_once __DIR__ . '/auth.php';
require_login();

$results = null;
$errors  = [];

// Parse a "dd-Mon-yy" or "d-Mon-yy" date to YYYY-MM-DD, or return null
function parse_date(string $raw): ?string {
    if (trim($raw) === '') return null;
    $dt = DateTime::createFromFormat('d-M-y', trim($raw));
    if (!$dt) $dt = DateTime::createFromFormat('j-M-y', trim($raw));
    return $dt ? $dt->format('Y-m-d') : null;
}

// Column indices in the exported Google Sheet CSV
//  0  class_year          1  cadet_last_name      2  cadet_first_middle
//  3  cadet_birthday      4  cadet_po_box          5  parent1_last_name
//  6  parent1_first_name  7  parent2_first_name    8  bct_squadron
//  9  bct_flight         10  fall_squadron         11 squadron_yr2_4
// 12  cadet_email        13  cadet_cell            14 home_phone (skipped)
// 15  parent1_email      16  parent1_cell          17 parent2_email
// 18  parent2_cell       19  parent1_street        20 parent1_city
// 21  parent1_state      22  parent1_zip           23 al_region
// 24  remarks

$COL = [
    'class_year'         => 0,
    'cadet_last_name'    => 1,
    'cadet_first_middle' => 2,
    'cadet_birthday'     => 3,
    'cadet_po_box'       => 4,
    'parent1_last_name'  => 5,
    'parent1_first_name' => 6,
    'parent2_first_name' => 7,
    'bct_squadron'       => 8,
    'bct_flight'         => 9,
    'fall_squadron'      => 10,
    'squadron_yr2_4'     => 11,
    'cadet_email'        => 12,
    'cadet_cell'         => 13,
    // 14 = home_phone, skipped
    'parent1_email'      => 15,
    'parent1_cell'       => 16,
    'parent2_email'      => 17,
    'parent2_cell'       => 18,
    'parent1_street'     => 19,
    'parent1_city'       => 20,
    'parent1_state'      => 21,
    'parent1_zip'        => 22,
    'al_region'          => 23,
    'remarks'            => 24,
];

// Rows to skip regardless of content
$SKIP_KEYWORDS = ['LEGEND', 'Email rejected', 'Email corrected', 'Cadet still enrolled', 'Sheet'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csvfile'])) {
    csrf_verify();
    $file = $_FILES['csvfile'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Upload error — please try again.';
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) { $errors[] = 'Could not read uploaded file.'; }
    }

    if (empty($errors)) {
        $pdo      = get_pdo();
        $inserted = 0;
        $skipped  = 0;
        $row_num  = 0;
        $current_year = '';

        // Prepare insert
        $cols = implode(', ', array_map(fn($f) => "`$f`", FIELDS));
        $ph   = implode(', ', array_map(fn($f) => ":$f", FIELDS));
        $stmt = $pdo->prepare("INSERT INTO members ($cols) VALUES ($ph)");

        while (($row = fgetcsv($handle)) !== false) {
            $row_num++;
            // Skip first 2 header rows
            if ($row_num <= 2) continue;

            // Extend row to at least 25 columns
            while (count($row) < 25) $row[] = '';

            $last_name = trim($row[1]);

            // Skip empty, legend, or separator rows
            if ($last_name === '') { $skipped++; continue; }
            $skip = false;
            foreach ($SKIP_KEYWORDS as $kw) {
                if (stripos($last_name, $kw) !== false || stripos($row[0], $kw) !== false) {
                    $skip = true; break;
                }
            }
            if ($skip) { $skipped++; continue; }

            // Carry forward class year
            if (trim($row[0]) !== '') $current_year = trim($row[0]);

            $m = [];
            foreach ($COL as $field => $idx) {
                $val = trim($row[$idx] ?? '');
                $m[$field] = $val;
            }
            $m['class_year']       = $current_year;
            $m['cadet_birthday']   = parse_date($m['cadet_birthday']);
            // parent2 address left blank (single address in source sheet)
            $m['parent2_last_name'] = '';
            $m['parent2_street']    = '';
            $m['parent2_city']      = '';
            $m['parent2_state']     = '';
            $m['parent2_zip']       = '';

            // Ensure all FIELDS keys exist
            foreach (FIELDS as $f) { if (!isset($m[$f])) $m[$f] = ''; }
            if ($m['cadet_birthday'] === '') $m['cadet_birthday'] = null;

            try {
                $stmt->execute($m);
                $inserted++;
            } catch (PDOException $e) {
                $errors[] = "Row $row_num ({$last_name}): " . $e->getMessage();
            }
        }
        fclose($handle);
        $results = ['inserted' => $inserted, 'skipped' => $skipped];
    }
}

admin_header('Import Members');
?>

<div class="page-head">
  <h1>Import from Google Sheets CSV</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error">
    <strong>Errors:</strong><br><?= implode('<br>', array_map('htmlspecialchars', $errors))?>
  </div>
<?php endif; ?>

<?php if ($results): ?>
  <div class="alert alert-success">
    ✅ Import complete: <strong><?= $results['inserted'] ?></strong> members added,
    <strong><?= $results['skipped'] ?></strong> rows skipped (headers / legend / blank rows).
    <br><br>
    ⚠️ <strong>Delete <code>import.php</code> from the server now</strong> — it is no longer needed.
    <br><a href="index.php" class="btn btn-primary" style="margin-top:.75rem;display:inline-flex">View Members →</a>
  </div>
<?php else: ?>
  <div class="card">
    <h2>Instructions</h2>
    <ol style="margin:.75rem 0 1.5rem 1.25rem;line-height:2;font-size:.9rem;color:#333">
      <li>Open your Google Sheet.</li>
      <li>Go to <strong>File → Download → Comma Separated Values (.csv)</strong>.</li>
      <li>Upload that file below.</li>
      <li>Each existing member will be imported. Parent 2 addresses will be blank (fill them in via Edit as needed).</li>
      <li><strong>Delete this page from the server after importing.</strong></li>
    </ol>
    <form method="POST" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <div class="form-group" style="max-width:400px">
        <label>CSV File</label>
        <input type="file" name="csvfile" accept=".csv,text/csv" required>
      </div>
      <button type="submit" class="btn btn-primary" style="margin-top:.5rem">Import Members</button>
    </form>
  </div>
<?php endif; ?>

<?php admin_footer(); ?>
