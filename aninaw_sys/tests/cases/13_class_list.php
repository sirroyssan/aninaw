<?php
/** Class list import from PDF: text extraction, parsing, review rules and import. Fictitious names only. */
declare(strict_types=1);

require_once APP_ROOT . '/includes/services/class_list.php';

section('Participants: class list PDF import');

/** Assembles a PDF from object bodies (index 0 = object 1), with an xref table and /Root 1 0 R. */
function fx_pdf_assemble(array $objects): string
{
    $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $body . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $offset) {
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }
    return $pdf . "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
}

function fx_pdf_stream(string $data, string $extra = ''): string
{
    return '<< /Length ' . strlen($data) . $extra . " >>\nstream\n" . $data . "\nendstream";
}

/** Simple PDF: Helvetica (WinAnsi), one Tj per cell, uncompressed. $pages = [[[x, y, text], ...], ...] */
function fx_simple_pdf(array $pages): string
{
    $objects = ['<< /Type /Catalog /Pages 2 0 R >>', '', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'];
    $kids = [];
    foreach ($pages as $cells) {
        $content = '';
        foreach ($cells as [$x, $y, $text]) {
            $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], mb_convert_encoding($text, 'Windows-1252', 'UTF-8'));
            $content .= "BT /F1 10 Tf {$x} {$y} Td ({$text}) Tj ET\n";
        }
        $objects[] = fx_pdf_stream($content);
        $contentId = count($objects);
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 1008] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        $kids[] = count($objects) . ' 0 R';
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    return fx_pdf_assemble($objects);
}

/**
 * Browser-style PDF (like Chrome's "Save as PDF"): Type0 Identity-H font with a ToUnicode map and
 * widths, one glyph per Tj moved with Td, a flipped coordinate system and Flate-compressed streams.
 * $pages = [[[x, yFromTop, text], ...], ...]
 */
function fx_browser_pdf(array $pages): string
{
    $chars = [];
    foreach ($pages as $cells) {
        foreach ($cells as [, , $text]) {
            foreach (mb_str_split($text) as $char) {
                $chars[$char] = true;
            }
        }
    }
    $gids = [];
    $bfchar = '';
    $widths = '';
    foreach (array_keys($chars) as $i => $char) {
        $char = (string) $char; // digits come back as integer keys
        $gid = $i + 3;
        $gids[$char] = $gid;
        $bfchar .= sprintf("<%04X> <%s>\n", $gid, strtoupper(bin2hex(mb_convert_encoding($char, 'UTF-16BE', 'UTF-8'))));
        $widths .= $gid . ' [' . ($char === ' ' ? 250 : 600) . '] ';
    }
    $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n"
        . count($gids) . " beginbfchar\n{$bfchar}endbfchar\nendcmap\nend\nend\n";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>', '',
        '<< /Type /Font /Subtype /Type0 /BaseFont /AAAAAA+Roboto /Encoding /Identity-H /DescendantFonts [4 0 R] /ToUnicode 5 0 R >>',
        "<< /Type /Font /Subtype /CIDFontType2 /BaseFont /AAAAAA+Roboto /DW 1000 /W [{$widths}] >>",
        fx_pdf_stream(gzcompress($cmap), ' /Filter /FlateDecode'),
    ];
    $kids = [];
    foreach ($pages as $cells) {
        $content = ".24 0 0 -.24 0 1008 cm\nq\n3.125 0 0 3.125 0 0 cm\n";
        foreach ($cells as [$x, $y, $text]) {
            $content .= "BT\n/F1 12 Tf\n1 0 0 -1 {$x} {$y} Tm\n";
            foreach (mb_str_split($text) as $i => $char) {
                $content .= ($i ? (($char === ' ' ? 3.0 : 7.2) . ' 0 Td ') : '') . sprintf('<%04X> Tj', $gids[$char]) . "\n";
            }
            $content .= "ET\n";
        }
        $content .= "Q\n";
        $objects[] = fx_pdf_stream(gzcompress($content), ' /Filter /FlateDecode');
        $contentId = count($objects);
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 1008] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
        $kids[] = count($objects) . ' 0 R';
    }
    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    return fx_pdf_assemble($objects);
}

/** Class list layout shared by both generators: [page => [[x, y, text], ...]]. Rows: [no, id, name]. */
function fx_class_list_pages(array $male, array $female, array $totals, bool $topDown): array
{
    $y = $topDown ? 40 : 960;
    $step = $topDown ? 20 : -20;
    $page = [];
    $add = static function (float $x, string $text) use (&$page, &$y) {
        $page[] = [$x, $y, $text];
    };
    $add(60, 'SECTION :'); $add(150, 'BSAB - 2X'); $add(330, 'DAY :'); $add(400, 'T/TH'); $y += $step;
    $add(60, 'YEAR :'); $add(150, '2026-2027'); $y += $step;
    $add(60, 'SEMESTER :'); $add(150, '1st Semester'); $add(330, 'TOTAL MALE :'); $add(450, (string) $totals[0]); $y += $step;
    $add(60, 'Course Code :'); $add(150, 'AB101'); $add(330, 'TOTAL FEMALE :'); $add(450, (string) $totals[1]); $y += $step;
    $add(60, 'Instructor :'); $add(150, 'T.TESTER'); $add(330, 'TOTAL STUDENTS :'); $add(450, (string) ($totals[0] + $totals[1])); $y += $step * 2;
    $pages = [];
    foreach (['MALE' => $male, 'FEMALE' => $female] as $heading => $rows) {
        $add(250, $heading); $y += $step;
        $add(30, 'No'); $add(100, 'Student No'); $add(220, 'Name'); $y += $step;
        foreach ($rows as [$no, $id, $name]) {
            $add(35, (string) $no); $add(100, $id); $add(220, $name); $y += $step;
        }
        $y += $step;
        if ($heading === 'MALE') { // females continue on page 2
            $add(30, 'https://enrollment.example.test/print.php'); $add(500, '1/2');
            $pages[] = $page;
            $page = [];
            $y = $topDown ? 40 : 960;
        }
    }
    $pages[] = $page;
    return $pages;
}

function fx_class_list_file(string $pdf): string
{
    $path = tempnam(sys_get_temp_dir(), 'cls');
    file_put_contents($path, $pdf);
    return $path;
}

$GLOBALS['fx_class_males'] = [[1, 'T26-001', 'DELA CRUZ, JUAN MIGUEL SANTOS'], [2, 'T26-002', 'REYES , JOSE JR MERCADO'], [3, 'T26-003', 'TAN, LEO']];
$GLOBALS['fx_class_females'] = [[1, 'T26-101', 'PEÑA, MARIA LUISA DE LOS REYES'], [2, 'T26-102', 'GO, ANA MAE LIM']];

test('the PDF reader extracts text from simple and browser-generated PDFs', function () {
    foreach (['simple' => false, 'browser' => true] as $kind => $browser) {
        $pages = fx_class_list_pages($GLOBALS['fx_class_males'], $GLOBALS['fx_class_females'], [3, 2], $browser);
        $pdf = $browser ? fx_browser_pdf($pages) : fx_simple_pdf($pages);
        $lines = pdf_text_lines(fx_class_list_file($pdf));
        assert_same(2, count($lines), "{$kind}: two pages");
        $text = array_map(static fn($page) => array_column($page, 'text'), $lines);
        assert_same('SECTION : BSAB - 2X DAY : T/TH', $text[0][0], "{$kind}: header line, words joined and columns kept apart");
        assert_true(in_array('1 T26-001 DELA CRUZ, JUAN MIGUEL SANTOS', $text[0], true), "{$kind}: first student row");
        assert_true(in_array('1 T26-101 PEÑA, MARIA LUISA DE LOS REYES', $text[1], true), "{$kind}: accented letters survive");
    }
    assert_rejected(fn() => read_class_list_pdf(fx_class_list_file('%PDF-1.4 nothing here')), 'No student rows');
    assert_rejected(fn() => read_class_list_pdf(fx_class_list_file('not a pdf')), 'could not be read');
});

test('class lists are parsed into ID numbers, names, sex and section details, and checked against their totals', function () {
    $pages = fx_class_list_pages($GLOBALS['fx_class_males'], $GLOBALS['fx_class_females'], [3, 2], true);
    $list = read_class_list_pdf(fx_class_list_file(fx_browser_pdf($pages)));
    assert_same(['BSAB - 2X', '2026-2027', '1st Semester', 'AB101', 'T.TESTER'], [$list['meta']['section'], $list['meta']['academic_year'], $list['meta']['term'], $list['meta']['course_code'], $list['meta']['instructor']]);
    assert_same([], $list['warnings'], 'totals agree');
    assert_same(5, count($list['students']));
    $byId = array_column($list['students'], null, 'participant_number');
    assert_same(['DELA CRUZ', 'JUAN MIGUEL', 'SANTOS', 'male'], [$byId['T26-001']['last_name'], $byId['T26-001']['first_name'], $byId['T26-001']['middle_name'], $byId['T26-001']['sex']]);
    assert_same(['REYES', 'JOSE Jr.', 'MERCADO'], [$byId['T26-002']['last_name'], $byId['T26-002']['first_name'], $byId['T26-002']['middle_name']], 'suffix stays with the first name');
    assert_same(['LEO', ''], [$byId['T26-003']['first_name'], $byId['T26-003']['middle_name']], 'one given name: no middle name');
    assert_same(['MARIA LUISA', 'DE LOS REYES', 'female'], [$byId['T26-101']['first_name'], $byId['T26-101']['middle_name'], $byId['T26-101']['sex']], 'particles belong to the middle name');
    assert_same('Dela Cruz', class_list_name_case('DELA CRUZ'));
    assert_same('Peña', class_list_name_case('PEÑA'));
    assert_same('MJ', class_list_name_case('MJ'));
    assert_same('BSAB-2X', class_list_group_code('BSAB - 2X'));

    $missing = fx_class_list_pages([[1, 'T26-001', 'DELA CRUZ, JUAN'], [3, 'T26-003', 'TAN, LEO']], $GLOBALS['fx_class_females'], [3, 2], false);
    $warnings = read_class_list_pdf(fx_class_list_file(fx_simple_pdf($missing)))['warnings'];
    assert_true((bool) array_filter($warnings, static fn($w) => str_contains($w, 'says 3 male students but 2 were read')), 'total mismatch is reported');
    assert_true((bool) array_filter($warnings, static fn($w) => str_contains($w, 'Row number 2 (male)')), 'numbering gap is reported');
});

test('importing a reviewed class list registers students once and builds the section group', function () {
    act_as('cashier', 'TST');
    assert_forbidden('organization.manage');

    act_as('admin', 'TST');
    $list = read_class_list_pdf(fx_class_list_file(fx_simple_pdf(fx_class_list_pages($GLOBALS['fx_class_males'], $GLOBALS['fx_class_females'], [3, 2], false))));
    $rows = array_map(static fn($s) => ['participant_number' => $s['participant_number'], 'last_name' => class_list_name_case($s['last_name']),
        'first_name' => class_list_name_case($s['first_name']), 'middle_name' => class_list_name_case($s['middle_name'])], $list['students']);
    $group = ['mode' => 'new', 'group_code' => 'BSAB-2X', 'group_name' => 'BSAB - 2X', 'academic_year' => '2026-2027', 'term' => '1st Semester'];

    $bad = $rows;
    $bad[1]['first_name'] = '';
    assert_rejected(fn() => import_class_list($bad, $group, 'list.pdf'), 'Nothing was imported');
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM participants WHERE participant_number LIKE 'T26-%'"), 'all or nothing');
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM `groups` WHERE group_code = 'BSAB-2X'"), 'no group created on failure');
    $twice = $rows;
    $twice[] = $rows[0];
    assert_rejected(fn() => import_class_list($twice, $group, 'list.pdf'), 'appears twice');

    $result = import_class_list(array_slice($rows, 0, 4), $group, 'list.pdf');
    assert_same(['created' => 4, 'existing' => 0, 'grouped' => 4], array_intersect_key($result, array_flip(['created', 'existing', 'grouped'])));
    $groupRow = db_one('SELECT * FROM `groups` WHERE id = ?', [$result['group_id']]);
    assert_same(['BSAB-2X', 'section', '2026-2027', '1st Semester'], [$groupRow['group_code'], $groupRow['group_type'], $groupRow['academic_year'], $groupRow['term']]);
    $juan = db_one("SELECT * FROM participants WHERE participant_number = 'T26-001'");
    assert_same(['Juan Miguel', 'Santos', 'Dela Cruz', 'student', (int) current_institution_id()], [$juan['first_name'], $juan['middle_name'], $juan['last_name'], $juan['participant_type'], (int) $juan['institution_id']]);

    // The full list again: existing students are left unchanged and not duplicated; the new one is added.
    $rows[0]['first_name'] = 'Changed';
    $again = import_class_list($rows, ['mode' => 'existing', 'group_id' => (string) $result['group_id']], 'list.pdf');
    assert_same(['created' => 1, 'existing' => 4, 'grouped' => 1], array_intersect_key($again, array_flip(['created', 'existing', 'grouped'])));
    assert_same('Juan Miguel', db_value("SELECT first_name FROM participants WHERE participant_number = 'T26-001'"), 'registered records are never overwritten by an import');
    assert_same(5, (int) db_value("SELECT COUNT(*) FROM group_memberships WHERE group_id = ? AND status = 'active'", [$result['group_id']]));
    assert_true((bool) db_value("SELECT 1 FROM audit_logs WHERE action = 'participant.class_list_imported' AND institution_id = ?", [current_institution_id()]), 'import is audited');

    act_as('other_treasurer', 'OTH');
    assert_same(0, (int) db_value("SELECT COUNT(*) FROM participants WHERE institution_id = ? AND participant_number LIKE 'T26-%'", [current_institution_id()]), 'other institutions are unaffected');
});
