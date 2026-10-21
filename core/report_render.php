<?php
/**
 * Model laporan generik -> HTML & XLSX.
 *
 * $m = [
 *   'titles' => ['BARIS JUDUL 1', ...],          // rata tengah, tebal
 *   'meta'   => [['SKPD', 'Nama'], ...],           // label : nilai
 *   'cols'   => [lebar kolom excel, ...],          // menentukan jumlah kolom
 *   'head'   => [[cell, ...], ...],               // baris header (boleh cs/rs)
 *   'body'   => [['cls' => ''|'g'|'t', 'cells' => [cell, ...]], ...],
 *   'notes'  => ['catatan', ...],
 *   'ttd'    => ['tempat' => 'Kota, tgl', 'kiri' => [...lines], 'kiri_nama' => '', 'kiri_nip' => '', 'kanan' => [...], 'kanan_nama' => '', 'kanan_nip' => ''],
 *   'landscape' => true,
 * ]
 * cell: string|number atau ['v' => nilai, 't' => 's'|'n'|'q'|'c', 'cs' => n, 'rs' => n]
 *   t: s=teks, n=rupiah, q=kuantitas, c=teks tengah
 */

function rc($v, $t = 's', $cs = 1, $rs = 1)
{
    return ['v' => $v, 't' => $t, 'cs' => $cs, 'rs' => $rs];
}

function rc_norm($c)
{
    if (!is_array($c)) $c = ['v' => $c];
    return array_merge(['v' => '', 't' => 's', 'cs' => 1, 'rs' => 1], $c);
}

function report_html(array $m)
{
    $h = '<div class="report">';
    foreach ($m['titles'] as $t) $h .= '<div class="r-title">' . e($t) . '</div>';
    if (!empty($m['meta'])) {
        $h .= '<table class="r-meta">';
        foreach ($m['meta'] as $mm) $h .= '<tr><td style="padding-right:10px"><b>' . e($mm[0]) . '</b></td><td>: ' . e($mm[1]) . '</td></tr>';
        $h .= '</table>';
    } else $h .= '<div style="height:10px"></div>';
    $h .= '<table class="r' . (!empty($m['matrix']) ? ' matrix' : '') . '"><thead>';
    foreach ($m['head'] as $row) {
        $h .= '<tr>';
        foreach ($row as $c) { $c = rc_norm($c); $h .= '<th' . ($c['cs'] > 1 ? ' colspan="' . $c['cs'] . '"' : '') . ($c['rs'] > 1 ? ' rowspan="' . $c['rs'] . '"' : '') . '>' . nl2br(e($c['v'])) . '</th>'; }
        $h .= '</tr>';
    }
    $h .= '</thead><tbody>';
    foreach ($m['body'] as $row) {
        $cls = $row['cls'] ?? '';
        $h .= '<tr' . ($cls ? ' class="' . $cls . '"' : '') . '>';
        foreach ($row['cells'] as $c) {
            $c = rc_norm($c);
            $attr = ($c['cs'] > 1 ? ' colspan="' . $c['cs'] . '"' : '') . ($c['rs'] > 1 ? ' rowspan="' . $c['rs'] . '"' : '');
            if ($c['t'] === 'n') $h .= '<td class="n"' . $attr . '>' . ($c['v'] === '' || $c['v'] === null ? '' : ((float)$c['v'] == 0 ? '-' : rupiah($c['v']))) . '</td>';
            elseif ($c['t'] === 'q') $h .= '<td class="n"' . $attr . '>' . ($c['v'] === '' || $c['v'] === null ? '' : ((float)$c['v'] == 0 ? '-' : angka($c['v']))) . '</td>';
            elseif ($c['t'] === 'c') $h .= '<td class="c"' . $attr . '>' . e($c['v']) . '</td>';
            else $h .= '<td' . $attr . '>' . e($c['v']) . '</td>';
        }
        $h .= '</tr>';
    }
    $h .= '</tbody></table>';
    foreach ($m['notes'] ?? [] as $n) $h .= '<div style="margin-top:6px;font-size:10.5px;color:#555"><i>' . e($n) . '</i></div>';
    if (!empty($m['ttd'])) {
        $t = $m['ttd'];
        $box = function ($lines, $nama, $nip, $top = '') {
            if (!$lines && !$nama) return '<div class="ttd-box"></div>';
            $s = '<div class="ttd-box">' . ($top !== '' ? '<div>' . e($top) . '</div>' : '<div>&nbsp;</div>');
            foreach ($lines as $l) $s .= '<div><b>' . e($l) . '</b></div>';
            $s .= '<div class="space"></div><div><b><u>' . e($nama ?: '(................................................)') . '</u></b></div>';
            if ($nip) $s .= '<div>NIP. ' . e($nip) . '</div>';
            return $s . '</div>';
        };
        $h .= '<div class="ttd" style="justify-content:space-between">' . $box($t['kiri'], $t['kiri_nama'], $t['kiri_nip']) . $box($t['kanan'], $t['kanan_nama'], $t['kanan_nip'], $t['tempat']) . '</div>';
    }
    return $h . '</div>';
}

/** Tulis model ke sheet aktif XlsxWriter */
function report_xlsx(XlsxWriter $x, array $m)
{
    $nc = count($m['cols']);
    foreach ($m['cols'] as $i => $w) $x->width($i + 1, $w);
    if (!empty($m['landscape'])) $x->landscape();
    $r = 1;
    foreach ($m['titles'] as $i => $t) { $x->set($r, 1, $t, $i === 0 ? 6 : 9)->merge($r, 1, $r, $nc); $r++; }
    $r++;
    foreach ($m['meta'] ?? [] as $mm) { $x->set($r, 1, $mm[0], 1)->set($r, 3, ': ' . $mm[1]); $r++; }
    if (!empty($m['meta'])) $r++;
    $headStart = $r;
    // header dengan merge (cs/rs) memakai peta okupansi
    $occ = [];
    foreach ($m['head'] as $ri => $row) {
        $c = 1;
        foreach ($row as $cell) {
            $cell = rc_norm($cell);
            while (!empty($occ[$r][$c])) $c++;
            $x->set($r, $c, $cell['v'], 2);
            for ($rr = 0; $rr < $cell['rs']; $rr++) for ($cc = 0; $cc < $cell['cs']; $cc++) {
                $occ[$r + $rr][$c + $cc] = true;
                if ($rr || $cc) $x->set($r + $rr, $c + $cc, '', 2);
            }
            if ($cell['cs'] > 1 || $cell['rs'] > 1) $x->merge($r, $c, $r + $cell['rs'] - 1, $c + $cell['cs'] - 1);
            $c += $cell['cs'];
        }
        $r++;
    }
    $x->freeze($r - 1);
    foreach ($m['body'] as $row) {
        $cls = $row['cls'] ?? '';
        $c = 1;
        foreach ($row['cells'] as $cell) {
            $cell = rc_norm($cell);
            $v = $cell['v'];
            $bold = in_array($cls, ['g', 't'], true);
            if ($cell['t'] === 'n') { $style = $bold ? 13 : 4; $v = ($v === '' || $v === null) ? '' : round((float)$v, 2); }
            elseif ($cell['t'] === 'q') { $style = $bold ? 16 : 8; $v = ($v === '' || $v === null) ? '' : (float)$v; }
            elseif ($cell['t'] === 'c') $style = 14;
            else $style = $bold ? 12 : 10;
            $x->set($r, $c, $v, $style);
            for ($cc = 1; $cc < $cell['cs']; $cc++) $x->set($r, $c + $cc, '', $style);
            if ($cell['cs'] > 1) $x->merge($r, $c, $r, $c + $cell['cs'] - 1);
            $c += $cell['cs'];
        }
        $r++;
    }
    foreach ($m['notes'] ?? [] as $n) { $x->set($r++, 1, $n, 11); }
    if (!empty($m['ttd'])) {
        $t = $m['ttd'];
        $r += 1;
        $cl = 2; $cr = max(3, $nc - 3);
        $x->set($r, $cr, $t['tempat'], 15)->merge($r, $cr, $r, $nc);
        $lr = $r + 1;
        foreach ($t['kiri'] as $i => $l) $x->set($lr + $i, $cl, $l, 1);
        foreach ($t['kanan'] as $i => $l) { $x->set($lr + $i, $cr, $l, 9)->merge($lr + $i, $cr, $lr + $i, $nc); }
        $nr = $lr + max(count($t['kiri']), count($t['kanan'])) + 4;
        if ($t['kiri'] || $t['kiri_nama']) { $x->set($nr, $cl, $t['kiri_nama'] ?: '(................................)', 1); if ($t['kiri_nip']) $x->set($nr + 1, $cl, 'NIP. ' . $t['kiri_nip']); }
        $x->set($nr, $cr, $t['kanan_nama'] ?: '(................................)', 9)->merge($nr, $cr, $nr, $nc);
        if ($t['kanan_nip']) $x->set($nr + 1, $cr, 'NIP. ' . $t['kanan_nip'], 15)->merge($nr + 1, $cr, $nr + 1, $nc);
    }
}
