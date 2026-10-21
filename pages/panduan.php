<?php
defined('BASE_PATH') or exit;
$u = user();

/** Pengurai Markdown minimal (judul, paragraf, daftar, tabel, tebal, miring, kode) */
function md_inline($s)
{
    $s = e($s);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<b>$1</b>', $s);
    $s = preg_replace('/(?<![\w*])\*([^*\s][^*]*)\*(?![\w*])/', '<i>$1</i>', $s);
    return $s;
}

function md_render($md, &$toc)
{
    $lines = preg_split('/\r?\n/', $md);
    $h = ''; $para = []; $list = null; $table = [];
    $flushP = function () use (&$para, &$h) { if ($para) { $h .= '<p>' . md_inline(implode(' ', $para)) . '</p>'; $para = []; } };
    $flushL = function () use (&$list, &$h) { if ($list) { $h .= '<' . $list['t'] . '>' . implode('', array_map(function ($i) { return '<li>' . $i . '</li>'; }, $list['items'])) . '</' . $list['t'] . '>'; $list = null; } };
    $flushT = function () use (&$table, &$h) {
        if (!$table) return;
        $h .= '<div class="table-wrap"><table class="tbl compact" style="margin:8px 0 14px;border:1px solid var(--line)"><thead><tr>';
        foreach ($table[0] as $c) $h .= '<th>' . md_inline($c) . '</th>';
        $h .= '</tr></thead><tbody>';
        foreach (array_slice($table, 2) as $r) { $h .= '<tr>'; foreach ($r as $c) $h .= '<td>' . md_inline($c) . '</td>'; $h .= '</tr>'; }
        $h .= '</tbody></table></div>';
        $table = [];
    };
    foreach ($lines as $ln) {
        if (preg_match('/^\|(.+)\|\s*$/', $ln, $m)) { $flushP(); $flushL(); $table[] = array_map('trim', explode('|', $m[1])); continue; }
        $flushT();
        if (preg_match('/^(#{1,3})\s+(.*)$/', $ln, $m)) {
            $flushP(); $flushL();
            $lv = strlen($m[1]);
            $id = 's' . substr(md5($m[2]), 0, 8);
            if ($lv === 2) $toc[] = [$id, $m[2]];
            $h .= '<h' . ($lv + 1) . ' id="' . $id . '" style="margin-top:' . ($lv === 1 ? 0 : 22) . 'px">' . md_inline($m[2]) . '</h' . ($lv + 1) . '>';
        } elseif (preg_match('/^\s*(\d+)\.\s+(.*)$/', $ln, $m) || preg_match('/^\s*[-*]\s+(.*)$/', $ln, $m2)) {
            $flushP();
            $isOl = isset($m[2]) && $m;
            $t = $isOl ? 'ol' : 'ul';
            $txt = $isOl ? $m[2] : $m2[1];
            $indent = strlen($ln) - strlen(ltrim($ln));
            if ($indent >= 2 && $list) { $list['items'][count($list['items']) - 1] .= '<br><span class="muted">•</span> ' . md_inline($txt); }
            else { if ($list && $list['t'] !== $t) $flushL(); if (!$list) $list = ['t' => $t, 'items' => []]; $list['items'][] = md_inline($txt); }
        } elseif (trim($ln) === '') {
            $flushP();
            if ($list) { /* biarkan daftar berlanjut bila baris berikut masih item */ }
        } else {
            if ($list && preg_match('/^\s{2,}/', $ln)) { $list['items'][count($list['items']) - 1] .= ' ' . md_inline(trim($ln)); continue; }
            $flushL();
            $para[] = trim($ln);
        }
    }
    $flushP(); $flushL(); $flushT();
    return $h;
}

$toc = [];
$html = md_render(file_get_contents(BASE_PATH . '/docs/PANDUAN.md'), $toc);
$saran = [
    'superadmin' => 's' . substr(md5('4. Super Admin'), 0, 8),
    'admin_skpd' => 's' . substr(md5('5. Admin SKPD'), 0, 8),
    'admin' => 's' . substr(md5('6. Admin Unit dan Admin Sub Unit'), 0, 8),
    'operator' => 's' . substr(md5('7. Operator Unit dan Operator Sub Unit'), 0, 8),
][$u['role']];
$pageTitle = 'Panduan';
include BASE_PATH . '/views/header.php';
?>
<div class="page-head">
  <div><h1>Panduan Penggunaan</h1><p>Panduan lengkap seluruh peran. Bagian untuk peran Anda: <a href="#<?= e($saran) ?>"><?= e(role_label($u['role'], $u['unit_level'])) ?></a>.</p></div>
  <div class="actions"><button class="btn btn-light" onclick="window.print()"><?= icon('print') ?> Cetak</button></div>
</div>
<div class="grid" style="grid-template-columns:240px minmax(0,1fr);align-items:start">
  <nav class="card no-print" style="position:sticky;top:76px">
    <div class="card-body" style="padding:12px">
      <div class="small muted" style="font-weight:700;text-transform:uppercase;letter-spacing:.05em;margin:0 0 6px 6px">Daftar isi</div>
      <?php foreach ($toc as $t): ?><a href="#<?= e($t[0]) ?>" style="display:block;padding:5px 6px;border-radius:6px;font-size:13px;color:var(--ink-2)"><?= e($t[1]) ?></a><?php endforeach; ?>
    </div>
  </nav>
  <article class="card"><div class="card-body guide" style="max-width:900px;line-height:1.65"><?= $html ?></div></article>
</div>
<style>.guide h2{font-size:1.25rem;padding-top:6px;border-top:1px solid var(--line)}.guide h2:first-child{border:0}.guide ol,.guide ul{padding-left:22px}.guide li{margin-bottom:4px}@media(max-width:900px){.content .grid[style]{grid-template-columns:1fr!important}}</style>
<?php include BASE_PATH . '/views/footer.php'; ?>
