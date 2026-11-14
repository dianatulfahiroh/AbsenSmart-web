
<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Koneksi database tidak ditemukan.');
}

$role = $_SESSION['role'] ?? '';

if (!in_array($role, ['admin', 'guru'], true)) {
    header('Location: login.php');
    exit;
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

// FILTER
$bulan = $_GET['bulan'] ?? date('Y-m');
$semester = $_GET['semester'] ?? 'semua';
$cari = trim($_GET['cari'] ?? '');
$kelasFilter = trim($_GET['kelas'] ?? '');

if (!preg_match('/^\d{4}-\d{2}$/', $bulan)) {
    $bulan = date('Y-m');
}

$tahun = (int) substr($bulan, 0, 4);

$awalPeriode = $bulan . '-01';
$akhirPeriode = date('Y-m-t', strtotime($awalPeriode));

if ($semester === 'ganjil') {
    $awalPeriode = $tahun . '-07-01';
    $akhirPeriode = $tahun . '-12-31';
} elseif ($semester === 'genap') {
    $awalPeriode = $tahun . '-01-01';
    $akhirPeriode = $tahun . '-06-30';
} else {
    $semester = 'semua';
}

// DAFTAR KELAS
$stmtKelas = $pdo->query("
    SELECT DISTINCT kelas
    FROM siswa
    WHERE kelas IS NOT NULL AND kelas <> ''
    ORDER BY kelas ASC
");

$daftarKelas = $stmtKelas->fetchAll(PDO::FETCH_COLUMN);

// QUERY REKAP SELURUH SISWA
$sql = "
    SELECT
        s.id_siswa,
        s.nama_siswa,
        s.nisn,
        s.kelas,

        COALESCE(SUM(
            CASE WHEN a.status = 'Hadir' THEN 1 ELSE 0 END
        ), 0) AS hadir,

        COALESCE(SUM(
            CASE WHEN a.status = 'Sakit' THEN 1 ELSE 0 END
        ), 0) AS sakit,

        COALESCE(SUM(
            CASE WHEN a.status = 'Izin' THEN 1 ELSE 0 END
        ), 0) AS izin,

        COALESCE(SUM(
            CASE WHEN a.status = 'Alfa' THEN 1 ELSE 0 END
        ), 0) AS alfa,

        COALESCE(SUM(
            CASE WHEN a.status = 'Dispensasi' THEN 1 ELSE 0 END
        ), 0) AS dispensasi,

        COUNT(a.id_absensi) AS total

    FROM siswa s

    LEFT JOIN absensi a
        ON a.id_siswa = s.id_siswa
        AND a.tanggal BETWEEN :awal AND :akhir

    WHERE 1 = 1
";

$params = [
    'awal' => $awalPeriode,
    'akhir' => $akhirPeriode
];

if ($kelasFilter !== '') {
    $sql .= " AND TRIM(s.kelas) = :kelas";
    $params['kelas'] = trim($kelasFilter);
}

if ($cari !== '') {
    $sql .= " AND LOWER(TRIM(s.nama_siswa)) LIKE LOWER(:cari)";
    $params['cari'] = '%' . trim($cari) . '%';
}

$sql .= "
    GROUP BY
        s.id_siswa,
        s.nama_siswa,
        s.nisn,
        s.kelas

    ORDER BY
        s.kelas ASC,
        s.nama_siswa ASC
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rekap = $stmt->fetchAll(PDO::FETCH_ASSOC);

// RINGKASAN SELURUH HASIL FILTER
$totalStatus = [
    'Hadir' => 0,
    'Sakit' => 0,
    'Izin' => 0,
    'Alfa' => 0,
    'Dispensasi' => 0
];

foreach ($rekap as $r) {
    $totalStatus['Hadir'] += (int)$r['hadir'];
    $totalStatus['Sakit'] += (int)$r['sakit'];
    $totalStatus['Izin'] += (int)$r['izin'];
    $totalStatus['Alfa'] += (int)$r['alfa'];
    $totalStatus['Dispensasi'] += (int)$r['dispensasi'];
}

$totalSemua = array_sum($totalStatus);

$rekapJson = json_encode(
    $rekap,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);


$namaPeriode = $semester === 'ganjil'
    ? 'Semester Ganjil'
    : ($semester === 'genap' ? 'Semester Genap' : 'Bulan ' . date('F Y', strtotime($awalPeriode)));

// EXPORT DATA KE EXCEL (FORMAT CSV)

// EXPORT DATA KE EXCEL
if (isset($_GET['export']) && $_GET['export'] === 'excel') {

    $namaFile = 'rekap-absensi-' . date('Y-m-d') . '.xls';

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');

    echo "\xEF\xBB\xBF";

    echo '<html>
    <head>
        <meta charset="UTF-8">
    </head>
    <body>
    <table border="1">
        <thead>
            <tr>
                <th>No</th>
                <th>Nama Siswa</th>
                <th>NISN</th>
                <th>Kelas</th>
                <th>Hadir</th>
                <th>% Hadir</th>
                <th>Sakit</th>
                <th>% Sakit</th>
                <th>Izin</th>
                <th>% Izin</th>
                <th>Alfa</th>
                <th>% Alfa</th>
                <th>Dispensasi</th>
                <th>% Dispensasi</th>
                <th>Total</th>
            </tr>
        </thead>
        <tbody>';

    foreach ($rekap as $i => $r) {

        $total = (int)$r['total'];

        $hadir = (int)$r['hadir'];
        $sakit = (int)$r['sakit'];
        $izin = (int)$r['izin'];
        $alfa = (int)$r['alfa'];
        $dispensasi = (int)$r['dispensasi'];

        $pHadir = $total ? round($hadir / $total * 100, 1) : 0;
        $pSakit = $total ? round($sakit / $total * 100, 1) : 0;
        $pIzin = $total ? round($izin / $total * 100, 1) : 0;
        $pAlfa = $total ? round($alfa / $total * 100, 1) : 0;
        $pDispensasi = $total ? round($dispensasi / $total * 100, 1) : 0;

        echo '<tr>
            <td>' . ($i + 1) . '</td>
            <td>' . e($r['nama_siswa']) . '</td>
            <td>' . e($r['nisn']) . '</td>
            <td>' . e($r['kelas']) . '</td>
            <td>' . $hadir . '</td>
            <td>' . $pHadir . '%</td>
            <td>' . $sakit . '</td>
            <td>' . $pSakit . '%</td>
            <td>' . $izin . '</td>
            <td>' . $pIzin . '%</td>
            <td>' . $alfa . '</td>
            <td>' . $pAlfa . '%</td>
            <td>' . $dispensasi . '</td>
            <td>' . $pDispensasi . '%</td>
            <td>' . $total . '</td>
        </tr>';
    }

    echo '</tbody>
    </table>
    </body>
    </html>';

    exit;
}


?>


<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Rekap Absensi | AbsenSmart</title>

<style>
:root {
    --pink: #FFCED8;
    --pink2: #FFDDDD;
    --cream: #FFF4DE;
    --peach: #FFE8C4;
    --ink: #493b3b;
    --muted: #897979;
    --line: #f0e4e1;
}

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    background: #fffaf8;
    color: var(--ink);
    font-family: Arial, Helvetica, sans-serif;
}

.layout {
    display: flex;
    min-height: 100vh;
}

.sidebar {
    width: 235px;
    background: white;
    border-right: 1px solid var(--line);
    padding: 28px 18px;
    flex-shrink: 0;
}

.brand {
    font-size: 23px;
    font-weight: 800;
    margin-bottom: 7px;
}

.brand span {
    color: #d78395;
}

.subbrand {
    font-size: 12px;
    color: var(--muted);
    margin-bottom: 35px;
}

.nav {
    display: block;
    padding: 12px 14px;
    border-radius: 12px;
    color: var(--ink);
    text-decoration: none;
    margin: 7px 0;
    font-size: 14px;
}

.nav:hover,
.nav.active {
    background: var(--pink2);
}

.main {
    flex: 1;
    min-width: 0;
    padding: 30px;
    max-width: 1600px;
    margin: 0 auto;
    width: 100%;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 15px;
    margin-bottom: 25px;
}

.top h1 {
    font-size: 27px;
    margin: 0 0 6px;
}

.muted {
    color: var(--muted);
    font-size: 13px;
}

.panel {
    background: white;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 5px 20px #6d41400a;
}

.section-title {
    font-size: 18px;
    margin: 0 0 8px;
}

.toolbar {
    display: flex;
    align-items: end;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 20px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.field label {
    font-size: 12px;
    color: var(--muted);
    font-weight: 700;
}

input, select {
    font: inherit;
    border: 1px solid #e9dedd;
    border-radius: 10px;
    padding: 10px 12px;
    min-height: 40px;
    background: white;
    color: var(--ink);
}

button, .btn {
    border: 0;
    border-radius: 10px;
    padding: 11px 16px;
    background: #d9899a;
    color: white;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn.secondary {
    background: var(--cream);
    color: var(--ink);
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(110px, 1fr));
    gap: 12px;
    margin: 20px 0;
}

.stat {
    padding: 16px;
    border-radius: 14px;
    background: var(--cream);
}

.stat:nth-child(2) { background: #e9f4ff; }
.stat:nth-child(3) { background: #fce9ef; }
.stat:nth-child(4) { background: #ffe9e4; }
.stat:nth-child(5) { background: #eee9ff; }

.stat b {
    display: block;
    font-size: 23px;
    margin-bottom: 5px;
}

.stat small {
    color: var(--muted);
}

.stat-percent {
    margin-top: 8px;
    font-size: 13px;
    font-weight: bold;
}

.table-wrap {
    width: 100%;
    overflow-x: auto;
    margin-top: 20px;
    border: 1px solid var(--line);
    border-radius: 12px;
}

table {
    width: 100%;
    min-width: 1150px;
    border-collapse: collapse;
}

th, td {
    padding: 12px 10px;
    text-align: left;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    white-space: nowrap;
}

th {
    background: #fff5f3;
    color: #725e5e;
    font-size: 12px;
}

.student-name {
    color: #9d5365;
    font-weight: bold;
    cursor: pointer;
    text-decoration: underline;
}

.empty {
    text-align: center;
    padding: 25px;
    color: var(--muted);
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 20;
    background: #251a1acc;
    padding: 20px;
    overflow: auto;
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-box {
    width: min(700px, 100%);
    background: white;
    border-radius: 18px;
    padding: 24px;
}

.modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.close {
    background: #eee;
    color: var(--ink);
}

.detail-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    margin: 20px 0;
}

.detail-stats div {
    padding: 12px;
    background: var(--cream);
    border-radius: 10px;
    text-align: center;
}

.detail-stats b {
    display: block;
    font-size: 20px;
}

.signature {
    margin: 40px 25px 10px auto;
    width: 300px;
    text-align: center;
    line-height: 1.7;
}

.signature .sign-name {
    margin-top: 70px;
    font-weight: bold;
    text-decoration: underline;
}

@media(max-width: 850px) {
    .sidebar {
        display: none;
    }

    .main {
        padding: 16px;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

    .detail-stats {
        grid-template-columns: repeat(2, 1fr);
    }
}

@page {
    size: A4 landscape;
    margin: 5mm;
}

@media print {
    html,
    body {
        width: 100%;
        height: auto;
        margin: 0;
        padding: 0;
        background: white !important;
    }

    .sidebar,
    .top,
    .no-print,
    .modal {
        display: none !important;
    }

    .main > section.panel:first-of-type .section-title,
    .main > section.panel:first-of-type > .muted:first-of-type {
        display: none !important;
    }

    .main {
        width: 125% !important;
        max-width: none !important;
        margin: 0 !important;
        padding: 0 !important;
        zoom: 0.8;
    }

    .panel {
        width: 100%;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
        box-shadow: none !important;
    }

    #hasilRekap {
        width: 100%;
        margin: 0 !important;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .table-wrap {
        width: 100%;
        overflow: visible !important;
        margin-top: 4px !important;
        border: none !important;
    }

    table {
        width: 100% !important;
        min-width: 0 !important;
        table-layout: fixed;
        border-collapse: collapse;
    }

    thead {
        display: table-header-group;
    }

    tr {
        break-inside: avoid;
        page-break-inside: avoid;
    }

    th,
    td {
        white-space: normal !important;
        font-size: 6.5px !important;
        padding: 2px 2px !important;
        line-height: 1.05 !important;
    }

    th {
        font-size: 6px !important;
    }

    .stats {
        grid-template-columns: repeat(5, 1fr) !important;
        gap: 4px !important;
        margin: 5px 0 !important;
    }

    .stat {
        padding: 4px !important;
    }

    .stat b {
        font-size: 13px !important;
        margin-bottom: 1px !important;
    }

    .stat-percent {
        margin-top: 2px !important;
        font-size: 8px !important;
    }

    .signature {
        font-size: 8px !important;
        line-height: 1.1 !important;
        margin: 5px 10px 0 auto !important;
    }

    .signature .sign-name {
        margin-top: 18px !important;
    }
}


</style>
</head>

<body>

<div class="layout">

    <aside class="sidebar no-print">
        <div class="brand">Absen<span>Smart</span></div>
        <div class="subbrand">Sistem Informasi Absensi Siswa</div>

        <a class="nav"
           href="dashboard_<?= $role === 'admin' ? 'admin' : 'guru' ?>.php">
            Dashboard
        </a>

        <a class="nav" href="absensi.php">
            Absensi Siswa
        </a>

        <a class="nav active" href="rekap.php">
            Rekap Absensi
        </a>
    </aside>

    <main class="main">

        <div class="top no-print">
            <div>
                <h1>Rekap Absensi</h1>
                <div class="muted">
                    Rekap kehadiran seluruh siswa AbsenSmart.
                </div>
            </div>

            <a class="btn secondary"
               href="dashboard_<?= $role === 'admin' ? 'admin' : 'guru' ?>.php">
                Kembali
            </a>
        </div>

        <section class="panel">

            <h2 class="section-title">Filter Rekap</h2>
            <div class="muted">
                Pilih periode dan kelas untuk melihat data kehadiran.
            </div>

            
<form method="get" class="toolbar no-print">

    <div class="field">
        <label for="bulan">Bulan acuan</label>
        <input type="month"
               id="bulan"
               name="bulan"
               value="<?= e($bulan) ?>">
    </div>

    <div class="field">
        <label for="semester">Periode</label>
        <select name="semester" id="semester">
            <option value="semua"
                <?= $semester === 'semua' ? 'selected' : '' ?>>
                Bulan terpilih
            </option>

            <option value="ganjil"
                <?= $semester === 'ganjil' ? 'selected' : '' ?>>
                Semester Ganjil
            </option>

            <option value="genap"
                <?= $semester === 'genap' ? 'selected' : '' ?>>
                Semester Genap
            </option>
        </select>
    </div>

    <div class="field">
        <label for="kelas">Kelas</label>
        <select name="kelas" id="kelas">
            <option value="">Semua kelas</option>

            <?php foreach ($daftarKelas as $k): ?>
                <option value="<?= e($k) ?>"
                    <?= $kelasFilter === $k ? 'selected' : '' ?>>
                    <?= e($k) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="field">
        <label for="cari">Cari nama siswa</label>
        <input type="text"
               name="cari"
               id="cari"
               value="<?= e($cari) ?>"
               placeholder="Nama siswa">
    </div>

    <button type="submit">Terapkan</button>

    <a href="rekap.php" class="btn secondary">
        Reset
    </a>

</form>


            <div class="muted" style="margin-top:15px">
                Periode: <?= e($awalPeriode) ?> sampai <?= e($akhirPeriode) ?>
                · <?= count($rekap) ?> siswa
            </div>

        </section>

        <section class="panel" id="hasilRekap">

            <h2 class="section-title">Ringkasan Kehadiran</h2>
            <div class="muted"><?= e($namaPeriode) ?></div>

            <div class="stats">

                <?php foreach ($totalStatus as $st => $jumlah): ?>

                    <?php
                    $persen = $totalSemua > 0
                        ? round(($jumlah / $totalSemua) * 100, 1)
                        : 0;
                    ?>

                    <div class="stat">
                        <b><?= $jumlah ?></b>
                        <small><?= e($st) ?></small>
                        <div class="stat-percent">
                            <?= $persen ?>%
                        </div>
                    </div>

                <?php endforeach; ?>

            </div>

            <div class="table-wrap">

                <table>
                    <thead>
                        <tr>
                            <th>No.</th>
                            <th>Nama Siswa</th>
                            <th>NISN</th>
                            <th>Kelas</th>
                            <th>Hadir</th>
                            <th>% Hadir</th>
                            <th>Sakit</th>
                            <th>% Sakit</th>
                            <th>Izin</th>
                            <th>% Izin</th>
                            <th>Alfa</th>
                            <th>% Alfa</th>
                            <th>Dispensasi</th>
                            <th>% Dispensasi</th>
                            <th>Total</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php if (!$rekap): ?>

                        <tr>
                            <td colspan="15" class="empty">
                                Tidak ada data siswa.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($rekap as $i => $r): ?>

                            <?php
                            $total = (int)$r['total'];

                            $hadir = (int)$r['hadir'];
                            $sakit = (int)$r['sakit'];
                            $izin = (int)$r['izin'];
                            $alfa = (int)$r['alfa'];
                            $dispensasi = (int)$r['dispensasi'];

                            $pHadir = $total ? round($hadir / $total * 100, 1) : 0;
                            $pSakit = $total ? round($sakit / $total * 100, 1) : 0;
                            $pIzin = $total ? round($izin / $total * 100, 1) : 0;
                            $pAlfa = $total ? round($alfa / $total * 100, 1) : 0;
                            $pDispensasi = $total ? round($dispensasi / $total * 100, 1) : 0;
                            ?>

                            <tr>
                                <td><?= $i + 1 ?></td>

                                <td>
                                    <span class="student-name"
                                          onclick="bukaDetail(<?= (int)$r['id_siswa'] ?>)">
                                        <?= e($r['nama_siswa']) ?>
                                    </span>
                                </td>

                                <td><?= e($r['nisn'] ?: '-') ?></td>
                                <td><?= e($r['kelas']) ?></td>

                                <td><?= $hadir ?></td>
                                <td><?= $pHadir ?>%</td>

                                <td><?= $sakit ?></td>
                                <td><?= $pSakit ?>%</td>

                                <td><?= $izin ?></td>
                                <td><?= $pIzin ?>%</td>

                                <td><?= $alfa ?></td>
                                <td><?= $pAlfa ?>%</td>

                                <td><?= $dispensasi ?></td>
                                <td><?= $pDispensasi ?>%</td>

                                <td><?= $total ?></td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>

            </div>

            <div class="signature">
                <div>Bangkalan, <?= date('d-m-Y') ?></div>
                <div>Mengetahui,</div>
                <div>Kepala Sekolah</div>

                <div class="sign-name">
                    Qurrotuainy, S.T., M.Pd.
                </div>
            </div>

            <div class="no-print" style="margin-top:20px; display:flex; gap:10px; flex-wrap:wrap;">

    <button type="button" onclick="window.print()">
        Cetak Rekap
    </button>

    <a class="btn"
       href="rekap.php?<?= e(http_build_query(array_merge($_GET, ['export' => 'excel']))) ?>"
       style="background:#4b9b70;">
        Export Excel
    </a>

</div>

        </section>

    </main>

</div>

<!-- MODAL DETAIL SISWA -->
<div class="modal" id="modalDetail">

    <div class="modal-box">

        <div class="modal-head">
            <div>
                <h2 class="section-title">Detail Kehadiran</h2>
                <div class="muted" id="detailNama"></div>
            </div>

            <button class="close" onclick="tutupDetail()">
                Tutup
            </button>
        </div>

        <div class="detail-stats">

            <div>
                <b id="dHadir">0</b>
                <small>Hadir</small>
                <div id="dpHadir">0%</div>
            </div>

            <div>
                <b id="dSakit">0</b>
                <small>Sakit</small>
                <div id="dpSakit">0%</div>
            </div>

            <div>
                <b id="dIzin">0</b>
                <small>Izin</small>
                <div id="dpIzin">0%</div>
            </div>

            <div>
                <b id="dAlfa">0</b>
                <small>Alfa</small>
                <div id="dpAlfa">0%</div>
            </div>

            <div>
                <b id="dDispensasi">0</b>
                <small>Dispensasi</small>
                <div id="dpDispensasi">0%</div>
            </div>

        </div>

        <div class="muted" id="detailTotal"></div>

    </div>

</div>

<script>
const dataRekap = <?= $rekapJson ?: '[]' ?>;

function bukaDetail(id) {
    const siswa = dataRekap.find(
        item => Number(item.id_siswa) === Number(id)
    );

    if (!siswa) return;

    const total = Number(siswa.total) || 0;

    const status = [
        ['Hadir', 'hadir'],
        ['Sakit', 'sakit'],
        ['Izin', 'izin'],
        ['Alfa', 'alfa'],
        ['Dispensasi', 'dispensasi']
    ];

    document.getElementById('detailNama').textContent =
        siswa.nama_siswa + ' - ' + siswa.kelas;

    status.forEach(([nama, key]) => {
        const jumlah = Number(siswa[key]) || 0;
        const persen = total > 0
            ? (jumlah / total * 100).toFixed(1) + '%'
            : '0%';

        const idKey = nama === 'Dispensasi'
            ? 'Dispensasi'
            : nama;

        document.getElementById(
            'd' + idKey
        ).textContent = jumlah;

        document.getElementById(
            'dp' + idKey
        ).textContent = persen;
    });

    document.getElementById('detailTotal').textContent =
        'Total catatan absensi: ' + total;

    document.getElementById('modalDetail').classList.add('show');
}

function tutupDetail() {
    document.getElementById('modalDetail').classList.remove('show');
}

document.getElementById('modalDetail').addEventListener('click', function(event) {
    if (event.target === this) {
        tutupDetail();
    }
});

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        tutupDetail();
    }
});
</script>

</body>
</html>
