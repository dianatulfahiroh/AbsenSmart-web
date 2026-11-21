<?php
date_default_timezone_set('Asia/Jakarta');
session_start();

require_once __DIR__ . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Koneksi database tidak ditemukan. Periksa config.php.');
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role'] ?? '';

$idAdmin = $_SESSION['admin_id'] ?? null;
$idGuru = $_SESSION['guru_id'] ?? null;

$validLogin =
    ($role === 'admin' && !empty($idAdmin)) ||
    ($role === 'guru' && !empty($idGuru));

if (!$validLogin) {
    header('Location: login.php');
    exit;
}

$waliKelas = [
    'XI TITL'  => 'ARUM AISYA, SPd.,Gr',
    'XII TITL' => 'SIHABUDDIN,S.Ag.M.Pdi',
    'XI RPL'   => 'FAUZIATUS KAMARIYA',
    'XII RPL'  => 'RINA YULIANI,S.Pd',
    'X TITL'   => 'BURHANUDDIN HAMKA',
    'X RPL'    => 'ELFAINI SALAM',
    'X TKR'    => 'RIBUT RAHMAT JAYA'
];

$pesan = '';
$error = '';

// TOKEN KEAMANAN CSRF
if (empty($_SESSION['csrf_absensi'])) {
    $_SESSION['csrf_absensi'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['csrf_absensi'];

$kelasDipilih = trim($_GET['kelas'] ?? '');

$tanggalHariIni = date('Y-m-d');
$tanggal = !empty($_GET['tanggal'])
    ? $_GET['tanggal']
    : $tanggalHariIni;

$bulan = $_GET['bulan'] ?? date('Y-m');
$semester = $_GET['semester'] ?? 'semua';
$cari = trim($_GET['cari'] ?? '');

$statusValid = ['Hadir', 'Sakit', 'Izin', 'Alfa', 'Dispensasi'];

$kelasList = [];
$siswaKelas = [];
$rekap = [];
$riwayat = [];
$absensiTanggal = [];

$punyaGuruBk = false;
$punyaKeterangan = false;
$punyaAdmin = false;
$jumlahSiswaKelas = 0;

try {

    // Memeriksa struktur kolom tabel absensi.
    $kolomAbsensi = $pdo
        ->query("SHOW COLUMNS FROM absensi")
        ->fetchAll(PDO::FETCH_COLUMN);

    $punyaGuruBk = in_array('id_guru_bk', $kolomAbsensi, true);
    $punyaKeterangan = in_array('keterangan', $kolomAbsensi, true);
    $punyaAdmin = in_array('id_admin', $kolomAbsensi, true);

    // Mengambil daftar kelas.
    $kelasList = $pdo->query("
        SELECT kelas, COUNT(*) AS jumlah
        FROM siswa
        WHERE kelas IS NOT NULL AND kelas <> ''
        GROUP BY kelas
        ORDER BY
            CASE kelas
                WHEN 'X TITL' THEN 1
                WHEN 'X RPL' THEN 2
                WHEN 'X TKR' THEN 3
                WHEN 'XI TITL' THEN 4
                WHEN 'XI RPL' THEN 5
                WHEN 'XII TITL' THEN 6
                WHEN 'XII RPL' THEN 7
                ELSE 8
            END, kelas
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($kelasDipilih !== '') {

        // Mengambil siswa berdasarkan kelas.
        $stmt = $pdo->prepare("
            SELECT id_siswa, nisn, nama_siswa, kelas, jurusan
            FROM siswa
            WHERE kelas = :kelas
            ORDER BY nama_siswa ASC
        ");

        $stmt->execute(['kelas' => $kelasDipilih]);
        $siswaKelas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $jumlahSiswaKelas = count($siswaKelas);

        // Menentukan periode rekap.
        $awalPeriode = $bulan . '-01';
        $akhirPeriode = date('Y-m-t', strtotime($awalPeriode));

        if ($semester === 'ganjil') {
            $tahun = (int) substr($bulan, 0, 4);
            $awalPeriode = $tahun . '-07-01';
            $akhirPeriode = $tahun . '-12-31';

        } elseif ($semester === 'genap') {
            $tahun = (int) substr($bulan, 0, 4);
            $awalPeriode = $tahun . '-01-01';
            $akhirPeriode = $tahun . '-06-30';
        }

       
      
// REKAP SEMUA STATUS KEHADIRAN.
$sqlRekap = "
    SELECT
        s.id_siswa,
        s.nama_siswa,
        s.nisn,
        s.kelas,

        COALESCE(SUM(a.status = 'Hadir'), 0) AS hadir,
        COALESCE(SUM(a.status = 'Sakit'), 0) AS sakit,
        COALESCE(SUM(a.status = 'Izin'), 0) AS izin,
        COALESCE(SUM(a.status = 'Alfa'), 0) AS alfa,
        COALESCE(SUM(a.status = 'Dispensasi'), 0) AS dispensasi,

        COUNT(a.id_absensi) AS total

    FROM siswa s

    LEFT JOIN absensi a
        ON a.id_siswa = s.id_siswa
        AND a.tanggal BETWEEN :awal AND :akhir
";

$params = [
    'awal' => $awalPeriode,
    'akhir' => $akhirPeriode
];

// Filter pencarian nama siswa.
if ($cari !== '') {
    $sqlRekap .= " WHERE s.nama_siswa LIKE :cari";
    $params['cari'] = '%' . $cari . '%';
}

$sqlRekap .= "
    GROUP BY
        s.id_siswa,
        s.nama_siswa,
        s.nisn,
        s.kelas

    ORDER BY
        s.kelas ASC,
        s.nama_siswa ASC
";

$stmt = $pdo->prepare($sqlRekap);
$stmt->execute($params);
$rekap = $stmt->fetchAll(PDO::FETCH_ASSOC);


        // Mengambil riwayat absensi.
        $stmtRiwayat = $pdo->prepare("
            SELECT
                a.id_absensi,
                a.tanggal,
                a.status
                " . ($punyaKeterangan ? ", a.keterangan" : ", '' AS keterangan") . "
            FROM absensi a
            INNER JOIN siswa s
                ON s.id_siswa = a.id_siswa
            WHERE s.kelas = :kelas
                AND a.tanggal BETWEEN :awal AND :akhir
            ORDER BY a.tanggal DESC
        ");

        $stmtRiwayat->execute([
            'kelas' => $kelasDipilih,
            'awal' => $awalPeriode,
            'akhir' => $akhirPeriode
        ]);

        $riwayat = $stmtRiwayat->fetchAll(PDO::FETCH_ASSOC);
    }

    // MENYIMPAN ABSENSI HARIAN.
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['aksi'] ?? '') === 'simpan_absensi'
    ) {

    if (
    !isset($_POST['csrf_token']) ||
    !hash_equals($csrfToken, $_POST['csrf_token'])
) {
    throw new RuntimeException(
        'Token keamanan tidak valid. Silakan muat ulang halaman.'
    );
}

        $kelasPost = trim($_POST['kelas'] ?? '');
        $tanggalPost = $_POST['tanggal'] ?? date('Y-m-d');

        $statusPost = $_POST['status'] ?? [];
        $ketPost = $_POST['keterangan'] ?? [];

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggalPost)) {
            throw new RuntimeException('Format tanggal tidak valid.');
        }

        $pdo->beginTransaction();

        foreach ($statusPost as $idSiswa => $status) {
            $idSiswa = (int) $idSiswa;

            if (!in_array($status, $statusValid, true)) {
                continue;
            }

            $cekSiswa = $pdo->prepare("
                SELECT id_siswa
                FROM siswa
                WHERE id_siswa = :id
                AND kelas = :kelas
            ");

            $cekSiswa->execute([
                'id' => $idSiswa,
                'kelas' => $kelasPost
            ]);

            if (!$cekSiswa->fetchColumn()) {
                continue;
            }

            $cek = $pdo->prepare("
                SELECT id_absensi
                FROM absensi
                WHERE id_siswa = :id_siswa
                AND tanggal = :tanggal
                LIMIT 1
            ");

            $cek->execute([
                'id_siswa' => $idSiswa,
                'tanggal' => $tanggalPost
            ]);

            $idAbsensi = $cek->fetchColumn();
            $keterangan = trim($ketPost[$idSiswa] ?? '');

            if ($idAbsensi) {

                $sql = "
                    UPDATE absensi
                    SET status = :status
                    " . ($punyaKeterangan ? ", keterangan = :keterangan" : "") . "
                    WHERE id_absensi = :id_absensi
                ";

                $data = [
                    'status' => $status,
                    'id_absensi' => $idAbsensi
                ];

                if ($punyaKeterangan) {
                    $data['keterangan'] = $keterangan;
                }

                $upd = $pdo->prepare($sql);
                $upd->execute($data);

            } else {

                $fields = ['id_siswa', 'tanggal', 'status'];
                $values = [':id_siswa', ':tanggal', ':status'];

                $data = [
                    'id_siswa' => $idSiswa,
                    'tanggal' => $tanggalPost,
                    'status' => $status
                ];

                if ($punyaKeterangan) {
                    $fields[] = 'keterangan';
                    $values[] = ':keterangan';
                    $data['keterangan'] = $keterangan;
                }

                if ($punyaAdmin) {
                    $fields[] = 'id_admin';
                    $values[] = ':id_admin';
                    $data['id_admin'] = $role === 'admin' ? $idAdmin : null;
                }

                if ($punyaGuruBk) {
                    $fields[] = 'id_guru_bk';
                    $values[] = ':id_guru_bk';
                    $data['id_guru_bk'] = $role === 'guru' ? $idGuru : null;
                }

                $sql = "
                    INSERT INTO absensi (
                        " . implode(',', $fields) . "
                    )
                    VALUES (
                        " . implode(',', $values) . "
                    )
                ";

                $ins = $pdo->prepare($sql);
                $ins->execute($data);
            }
        }

        $pdo->commit();

        header(
    'Location: absensi.php?kelas=' . urlencode($kelasPost)
    . '&tanggal=' . urlencode($tanggalPost)
    . '&bulan=' . urlencode(substr($tanggalPost, 0, 7))
    . '&terhapus=1'
);

        exit;
    }

    // MENGHAPUS CATATAN ABSENSI.
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST'
        && ($_POST['aksi'] ?? '') === 'hapus_absensi'
    ) {

        if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, $_POST['csrf_token'])
    ) {
        throw new RuntimeException(
            'Token keamanan tidak valid. Silakan muat ulang halaman.'
        );
    }
        $idAbsensi = (int) ($_POST['id_absensi'] ?? 0);
        $kelasPost = trim($_POST['kelas'] ?? '');

        $stmt = $pdo->prepare("
            DELETE a
            FROM absensi a
            INNER JOIN siswa s
                ON s.id_siswa = a.id_siswa
            WHERE a.id_absensi = :id
            AND s.kelas = :kelas
        ");

        $stmt->execute([
            'id' => $idAbsensi,
            'kelas' => $kelasPost
        ]);

        header(
            'Location: absensi.php?kelas=' . urlencode($kelasPost)
            . '&bulan=' . urlencode($bulan)
            . '&terhapus=1'
        );

        exit;
    }

    if (isset($_GET['sukses'])) {
        $pesan = 'Absensi berhasil disimpan.';
    }

    if (isset($_GET['terhapus'])) {
        $pesan = 'Catatan absensi berhasil dihapus.';
    }

} catch (Throwable $ex) {

    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $error = $ex->getMessage();
}

// ABSENSI PADA TANGGAL TERPILIH.
if ($kelasDipilih !== '') {
    $stmt = $pdo->prepare("
        SELECT
            a.id_absensi,
            a.id_siswa,
            a.status
            " . ($punyaKeterangan ? ", a.keterangan" : ", '' AS keterangan") . "
        FROM absensi a
        INNER JOIN siswa s
            ON s.id_siswa = a.id_siswa
        WHERE s.kelas = :kelas
            AND a.tanggal = :tanggal
    ");

    $stmt->execute([
        'kelas' => $kelasDipilih,
        'tanggal' => $tanggal
    ]);

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $absensiTanggal[$row['id_siswa']] = $row;
    }
}

$namaWali = $waliKelas[$kelasDipilih] ?? 'Belum ditentukan';

$rekapJson = json_encode(
    $rekap,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
);
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Absensi Siswa | AbsenSmart</title>

<style>
:root {
    --pink: #FFCED8;
    --pink2: #FFDDDD;
    --cream: #FFF4DE;
    --peach: #FFE8C4;
    --ink: #493b3b;
    --muted: #897979;
    --line: #f0e4e1;
    --white: #fff;
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
    background: #fff;
    border-right: 1px solid var(--line);
    padding: 28px 18px;
    flex-shrink: 0;
}

.brand {
    font-size: 23px;
    font-weight: 800;
    margin: 0 0 7px;
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

.nav.active,
.nav:hover {
    background: var(--pink2);
}

.main {
    flex: 1;
    min-width: 0;
    padding: 30px;
    max-width: 1500px;
    margin: 0 auto;
    width: 100%;
}

.top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 16px;
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
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 5px 20px #6d41400a;
}

.section-title {
    font-size: 18px;
    font-weight: 750;
    margin: 0 0 6px;
}

.class-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.class-card {
    display: block;
    text-decoration: none;
    color: var(--ink);
    padding: 21px;
    border: 1px solid var(--line);
    border-radius: 17px;
    background: linear-gradient(135deg, #fff, var(--cream));
    transition: .15s;
}

.class-card:hover,
.class-card.selected {
    border-color: #df9aa8;
    transform: translateY(-2px);
    box-shadow: 0 7px 18px #d98d9c20;
}

.class-card strong {
    font-size: 19px;
    display: block;
    margin-bottom: 8px;
}

.class-card small {
    color: var(--muted);
}

.toolbar {
    display: flex;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
    margin-top: 18px;
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

input,
select {
    font: inherit;
    border: 1px solid #e9dedd;
    border-radius: 10px;
    padding: 10px 12px;
    background: #fff;
    color: var(--ink);
    min-height: 40px;
}

button,
.btn {
    border: 0;
    border-radius: 10px;
    padding: 11px 16px;
    background: #d9899a;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
}

.btn.secondary,
button.secondary {
    background: var(--cream);
    color: var(--ink);
}

button.danger {
    background: #b85b67;
    padding: 7px 10px;
    font-size: 12px;
}

.wali {
    background: var(--cream);
    border: 1px solid #f1dfbd;
    border-radius: 13px;
    padding: 14px 17px;
    margin: 16px 0 20px;
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.wali b,
.wali span {
    font-size: 14px;
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
    border-collapse: collapse;
    min-width: 1250px;
}

th,
td {
    padding: 12px 11px;
    text-align: left;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    white-space: nowrap;
}

th {
    background: #fff5f3;
    color: #725e5e;
    font-size: 12px;
    font-weight: 700;
}

thead th {
    position: sticky;
    top: 0;
    z-index: 1;
}

.status-select {
    min-width: 130px;
}

.notice {
    padding: 12px 15px;
    border-radius: 11px;
    margin-bottom: 16px;
    background: #e9f7ec;
    color: #27633b;
}

.notice.error {
    background: #fff0ef;
    color: #a23e3e;
}

.stats {
    display: grid;
    grid-template-columns: repeat(5, minmax(100px, 1fr));
    gap: 12px;
    margin: 20px 0 24px;
}

.stat {
    padding: 15px;
    border-radius: 14px;
    background: var(--cream);
}

.stat:nth-child(2) {
    background: #e9f4ff;
}

.stat:nth-child(3) {
    background: #fce9ef;
}

.stat:nth-child(4) {
    background: #ffe9e4;
}

.stat:nth-child(5) {
    background: #eee9ff;
}

.stat b {
    display: block;
    font-size: 22px;
    margin-bottom: 5px;
}

.stat small {
    display: block;
    color: var(--muted);
}

.stat-percent {
    font-size: 13px;
    font-weight: 700;
    margin-top: 8px;
    color: #8c5361;
}

.student-name {
    color: #9d5365;
    font-weight: 700;
    cursor: pointer;
    text-decoration: underline;
    text-underline-offset: 3px;
}

.signature {
    margin: 48px 0 8px auto;
    width: min(350px, 100%);
    padding: 15px 10px;
    text-align: left;
    line-height: 1.7;
    page-break-inside: avoid;
}

.signature .city {
    text-align: center;
    margin-bottom: 36px;
}

.signature .sign-name {
    font-weight: 700;
    text-decoration: underline;
    margin-top: 55px;
}

.modal {
    display: none;
    position: fixed;
    inset: 0;
    background: #251a1acc;
    z-index: 20;
    padding: 20px;
    overflow: auto;
}

.modal.show {
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-box {
    background: #fff;
    border-radius: 18px;
    padding: 23px;
    width: min(760px, 100%);
    max-height: 90vh;
    overflow: auto;
}

.modal-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
}

.close {
    background: #eee;
    color: #493b3b;
    padding: 7px 12px;
}

.detail-stats {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 8px;
    margin: 15px 0;
}

.detail-stats div {
    background: #fff4de;
    border-radius: 10px;
    padding: 10px;
    text-align: center;
}

.detail-stats b {
    display: block;
    font-size: 19px;
}

.empty {
    padding: 25px;
    text-align: center;
    color: var(--muted);
}

/* Perapian bagian rekap */
.recap-heading {
    margin-bottom: 8px;
}

.recap-description {
    margin: 7px 0 18px;
}

.recap-toolbar {
    margin: 18px 0 20px;
}

#rekap .table-wrap {
    margin-top: 20px;
    overflow-x: auto;
}

#rekap table {
    min-width: 1180px;
}

@media (max-width: 800px) {
    .sidebar {
        display: none;
    }

    .main {
        padding: 17px;
    }

    .top h1 {
        font-size: 23px;
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
    margin: 6mm;
}

@media print {
    body {
        background: #fff;
        width: 100%;
        margin: 0;
        padding: 0;
    }

    .sidebar,
    .top,
    .no-print,
    .class-grid,
    .toolbar,
    .modal {
        display: none !important;
    }

    .main {
        width: 100%;
        max-width: none;
        padding: 0;
        margin: 0;
    }

    .panel {
        width: 100%;
        padding: 0;
        margin: 0;
        border: none;
        box-shadow: none;
    }

    #rekap .section-title {
        font-size: 16px;
        margin-bottom: 3px;
    }

    #rekap .recap-description {
        margin-bottom: 8px;
    }

    #rekap .stats {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 5px;
        margin: 8px 0;
    }

    #rekap .stat {
        padding: 7px;
    }

    #rekap .stat b {
        font-size: 14px;
    }

    #rekap .stat small,
    #rekap .stat-percent {
        font-size: 9px;
    }

    #rekap .table-wrap {
        width: 100%;
        margin-top: 8px;
        overflow: visible;
        border: none;
    }

    #rekap table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
    }

    #rekap th,
    #rekap td {
        white-space: normal;
        font-size: 7px;
        padding: 3px 2px;
    }

    #rekap thead th {
        position: static;
    }

    .wali {
        border: 1px solid #aaa;
        background: #fff;
    }

    .signature {
        margin-top: 12px;
        margin-right: 25px;
        padding: 5px;
        font-size: 10px;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .signature .city {
        margin-bottom: 10px;
    }

    .signature .sign-name {
        margin-top: 18px;
    }
}

@media print {
    body {
        background: #fff;
        width: 100%;
        margin: 0;
        padding: 0;
    }

    .sidebar,
    .top,
    .no-print,
    .class-grid,
    .toolbar,
    .modal {
        display: none !important;
    }

    .main {
        width: 100%;
        max-width: none;
        padding: 0;
        margin: 0;
    }

    .panel {
        width: 100%;
        padding: 0;
        margin: 0;
        border: none;
        box-shadow: none;
    }

    #rekap .section-title {
        font-size: 16px;
        margin-bottom: 3px;
    }

    #rekap .recap-description {
        margin-bottom: 8px;
    }

    #rekap .stats {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 5px;
        margin: 8px 0;
    }

    #rekap .stat {
        padding: 7px;
    }

    #rekap .stat b {
        font-size: 14px;
    }

    #rekap .stat small,
    #rekap .stat-percent {
        font-size: 9px;
    }

    #rekap .table-wrap {
        width: 100%;
        margin-top: 8px;
        overflow: visible;
        border: none;
    }

    #rekap table {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
    }

    #rekap th,
    #rekap td {
        white-space: normal;
        font-size: 7px;
        padding: 3px 2px;
    }

    #rekap thead th {
        position: static;
    }

    .wali {
        border: 1px solid #aaa;
        background: #fff;
    }

    .signature {
        margin-top: 12px;
        margin-right: 25px;
        padding: 5px;
        font-size: 10px;
        break-inside: avoid;
        page-break-inside: avoid;
    }

    .signature .city {
        margin-bottom: 10px;
    }

    .signature .sign-name {
        margin-top: 18px;
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

        <a class="nav active" href="absensi.php">
            Absensi Siswa
        </a>
    </aside>

    <main class="main">

        <div class="top no-print">
            <div>
                <h1>Absensi Siswa</h1>
                <div class="muted">
                    Kelola kehadiran dan rekap absensi setiap kelas.
                </div>
            </div>

            <a class="btn secondary"
               href="dashboard_<?= $role === 'admin' ? 'admin' : 'guru' ?>.php">
                Kembali
            </a>
        </div>

        <?php if ($pesan): ?>
            <div class="notice no-print">
                <?= e($pesan) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="notice error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($kelasDipilih === ''): ?>

            <!-- PILIH KELAS -->
            <section class="panel">

                <h2 class="section-title">Pilih Kelas</h2>

                <div class="muted">
                    Pilih kelas untuk membuka halaman absensi dan rekap siswa.
                </div>

                <div class="class-grid">

                    <?php foreach ($kelasList as $item): ?>

                        <a class="class-card"
                           href="absensi.php?kelas=<?= urlencode($item['kelas']) ?>">

                            <strong>
                                <?= e($item['kelas']) ?>
                            </strong>

                            <small>
                                <?= (int)$item['jumlah'] ?> siswa
                            </small>

                        </a>

                    <?php endforeach; ?>

                </div>

            </section>

        <?php else: ?>

            <div class="no-print" style="margin-bottom:14px">
                <a class="btn secondary" href="absensi.php">
                    ← Kembali ke daftar kelas
                </a>
            </div>

            <!-- ABSENSI HARIAN -->
            <section class="panel">

                <h2 class="section-title">
                    Absensi Kelas <?= e($kelasDipilih) ?>
                </h2>

                <div class="muted">
                    <?= (int)$jumlahSiswaKelas ?> siswa terdaftar di kelas ini.
                </div>

                <div class="wali">
                    <b>Wali Kelas:</b>
                    <span><?= e($namaWali) ?></span>
                </div>

                

            <form method="GET" action="absensi.php" class="toolbar no-print">

    <input
        type="hidden"
        name="kelas"
        value="<?= e($kelasDipilih) ?>"
    >

    <div class="field">
        <label for="tanggal">Tanggal absensi</label>

        <input
            type="date"
            id="tanggal"
            name="tanggal"
            value="<?= e($tanggal) ?>"
            required
        >
    </div>

    <button type="submit">Tampilkan</button>

</form>

                <form method="post" class="no-print">

                    <input type="hidden"
                           name="aksi"
                           value="simpan_absensi">

                           <input type="hidden"
       name="csrf_token"
       value="<?= e($csrfToken) ?>">

                    <input type="hidden"
                           name="kelas"
                           value="<?= e($kelasDipilih) ?>">

                    <input type="hidden"
                           name="tanggal"
                           value="<?= e($tanggal) ?>">

                    <div class="table-wrap">

                        <table>

                            <thead>
                                <tr>
                                    <th>No.</th>
                                    <th>Nama Siswa</th>
                                    <th>NISN</th>
                                    <th>Status Kehadiran</th>
                                    <th>Keterangan</th>
                                    <th>Aksi</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (!$siswaKelas): ?>

                                    <tr>
                                       <td colspan="6" class="empty">
                                            Belum ada siswa di kelas ini.
                                        </td>
                                    </tr>

                                <?php else: ?>

                                    <?php foreach ($siswaKelas as $i => $siswa):

                                        $existing =
                                            $absensiTanggal[$siswa['id_siswa']] ?? [];

                                    ?>

                                        <tr>

                                            <td><?= $i + 1 ?></td>

                                            <td>
                                                <?= e($siswa['nama_siswa']) ?>
                                            </td>

                                            <td>
                                                <?= e($siswa['nisn'] ?: '-') ?>
                                            </td>

                                            <td>

                                                <select
                                                    class="status-select"
                                                    name="status[<?= (int)$siswa['id_siswa'] ?>]">

                                                    <?php
                                                   $selectedStatus =
    $existing['status'] ?? '';
                                                    ?>

                                                    <option value="" disabled <?= $selectedStatus === '' ? 'selected' : '' ?>>
    Pilih Status
</option>

                                                    <?php foreach ($statusValid as $st): ?>

                                                        <option
                                                            value="<?= e($st) ?>"
                                                            <?= $selectedStatus === $st ? 'selected' : '' ?>>

                                                            <?= e($st) ?>

                                                        </option>

                                                    <?php endforeach; ?>

                                                </select>

                                            </td>

                                            <td>

                                                <input
                                                    type="text"
                                                    name="keterangan[<?= (int)$siswa['id_siswa'] ?>]"
                                                    value="<?= e($existing['keterangan'] ?? '') ?>"
                                                    placeholder="Opsional">

                                            </td>
                                                       
                                                        
                                                        <td>
    <?php if (!empty($existing['id_absensi'])): ?>

        <button
            type="button"
            style="background:#dc6b78;"
            onclick="hapusAbsensi(
                <?= (int)$existing['id_absensi'] ?>,
                <?= htmlspecialchars(json_encode($kelasDipilih), ENT_QUOTES, 'UTF-8') ?>,
                <?= htmlspecialchars(json_encode($tanggal), ENT_QUOTES, 'UTF-8') ?>
            )">
            Hapus
        </button>

    <?php else: ?>
        -
    <?php endif; ?>
</td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                    <?php if ($siswaKelas): ?>

                        <div style="margin-top:16px">
                            <button type="submit">
                                Simpan Absensi
                            </button>
                        </div>

                    <?php endif; ?>

                </form>

                <form method="post" id="formHapusAbsensi" class="no-print">

    <input type="hidden" name="aksi" value="hapus_absensi">

    <input type="hidden" name="csrf_token"
           value="<?= e($csrfToken) ?>">

    <input type="hidden" name="id_absensi" id="hapusIdAbsensi">

    <input type="hidden" name="kelas" id="hapusKelas">

    <input type="hidden" name="tanggal" id="hapusTanggal">

</form>

            </section>

           
        <?php endif; ?>

    </main>

</div>

<!-- MODAL DETAIL SISWA -->
<div class="modal" id="modalDetail">

    <div class="modal-box">

        <div class="modal-head">
            <div>
                <h2 class="section-title">Detail Kehadiran Siswa</h2>
                <div class="muted" id="detailNama">
                    Statistik absensi siswa
                </div>
            </div>

            <button type="button"
                    class="close"
                    onclick="tutupDetail()">
                Tutup
            </button>
        </div>

        <div class="detail-stats">

            <div>
                <b id="detailHadir">0</b>
                <small>Hadir</small>
                <div id="detailPersenHadir">0%</div>
            </div>

            <div>
                <b id="detailSakit">0</b>
                <small>Sakit</small>
                <div id="detailPersenSakit">0%</div>
            </div>

            <div>
                <b id="detailIzin">0</b>
                <small>Izin</small>
                <div id="detailPersenIzin">0%</div>
            </div>

            <div>
                <b id="detailAlfa">0</b>
                <small>Alfa</small>
                <div id="detailPersenAlfa">0%</div>
            </div>

            <div>
                <b id="detailDispensasi">0</b>
                <small>Dispensasi</small>
                <div id="detailPersenDispensasi">0%</div>
            </div>

        </div>

        <div class="muted" id="detailTotal">
            Total absensi: 0
        </div>

    </div>

</div>

<script>
const dataRekap = <?= $rekapJson ?: '[]' ?>;

function formatPersen(jumlah, total) {
    if (total === 0) {
        return '0%';
    }

    return (jumlah / total * 100).toFixed(1) + '%';
}

function bukaDetail(idSiswa) {

    const siswa = dataRekap.find(function(item) {
        return Number(item.id_siswa) === Number(idSiswa);
    });

    if (!siswa) {
        alert('Data siswa tidak ditemukan.');
        return;
    }

    const total = Number(siswa.total) || 0;

    const hadir = Number(siswa.hadir) || 0;
    const sakit = Number(siswa.sakit) || 0;
    const izin = Number(siswa.izin) || 0;
    const alfa = Number(siswa.alfa) || 0;
    const dispensasi = Number(siswa.dispensasi) || 0;

    document.getElementById('detailNama').textContent =
        siswa.nama_siswa;

    document.getElementById('detailHadir').textContent = hadir;
    document.getElementById('detailSakit').textContent = sakit;
    document.getElementById('detailIzin').textContent = izin;
    document.getElementById('detailAlfa').textContent = alfa;
    document.getElementById('detailDispensasi').textContent = dispensasi;

    document.getElementById('detailPersenHadir').textContent =
        formatPersen(hadir, total);

    document.getElementById('detailPersenSakit').textContent =
        formatPersen(sakit, total);

    document.getElementById('detailPersenIzin').textContent =
        formatPersen(izin, total);

    document.getElementById('detailPersenAlfa').textContent =
        formatPersen(alfa, total);

    document.getElementById('detailPersenDispensasi').textContent =
        formatPersen(dispensasi, total);

    document.getElementById('detailTotal').textContent =
        'Total absensi: ' + total;

    document.getElementById('modalDetail').classList.add('show');
}

function tutupDetail() {
    document.getElementById('modalDetail').classList.remove('show');
}

document.getElementById('modalDetail').addEventListener(
    'click',
    function(event) {
        if (event.target === this) {
            tutupDetail();
        }
    }
);

document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
        tutupDetail();
    }
});

function hapusAbsensi(id, kelas, tanggal) {
    if (!confirm('Yakin ingin menghapus catatan absensi ini?')) {
        return;
    }

    document.getElementById('hapusIdAbsensi').value = id;
    document.getElementById('hapusKelas').value = kelas;
    document.getElementById('hapusTanggal').value = tanggal;

    document.getElementById('formHapusAbsensi').submit();
}
</script>

</body>
</html>