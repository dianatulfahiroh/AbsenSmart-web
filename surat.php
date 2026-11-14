<?php
session_start();
if (empty($_SESSION['csrf_surat'])) {
    $_SESSION['csrf_surat'] = bin2hex(random_bytes(32));
}
require_once __DIR__ . '/config.php';

/* =========================================
   AUTENTIKASI
========================================= */

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Koneksi database tidak tersedia.');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

$role = $_SESSION['role'] ?? '';
$idAdmin = $_SESSION['admin_id'] ?? null;
$namaLogin = $_SESSION['nama'] ?? 'Pengguna';

if (!in_array($role, ['admin', 'guru'], true)) {
    header('Location: login.php');
    exit;
}

$dashboard = $role === 'admin'
    ? 'dashboard_admin.php'
    : 'dashboard_guru.php';

$pesan = '';
$error = '';
$daftarSiswa = [];
$riwayatSurat = [];
$suratCetak = null;

function surat_e($value): string
{
    return htmlspecialchars(
        (string)($value ?? ''),
        ENT_QUOTES,
        'UTF-8'
    );
}

function tanggalIndonesia($tanggal): string
{
    if (empty($tanggal)) {
        return '-';
    }

    $timestamp = strtotime($tanggal);

    if ($timestamp === false) {
        return '-';
    }

    $bulan = [
        1 => 'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];

    return date('d', $timestamp) . ' ' .
        $bulan[(int)date('n', $timestamp)] . ' ' .
        date('Y', $timestamp);
}

function tanggalValid($tanggal): bool
{
    if (!is_string($tanggal)) {
        return false;
    }

    $date = DateTime::createFromFormat('!Y-m-d', $tanggal);

    return $date !== false &&
        $date->format('Y-m-d') === $tanggal;
}

/* =========================================
   PROSES UTAMA
========================================= */

try {

    // PROSES HAPUS SURAT
    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'hapus_surat'
    ) {
        if (!in_array($role, ['admin', 'guru'], true)) {
            throw new RuntimeException('Tidak memiliki akses menghapus surat.');
        }

        $token = $_POST['csrf_surat'] ?? '';

        if (
            !is_string($token) ||
            !hash_equals($_SESSION['csrf_surat'], $token)
        ) {
            throw new RuntimeException('Token keamanan tidak valid.');
        }

        $idSurat = (int)($_POST['id_surat'] ?? 0);

        if ($idSurat <= 0) {
            throw new RuntimeException('ID surat tidak valid.');
        }

        $stmtHapus = $pdo->prepare(
            "DELETE FROM surat WHERE id_surat = :id"
        );

        $stmtHapus->execute([':id' => $idSurat]);

        if ($stmtHapus->rowCount() === 0) {
            throw new RuntimeException('Surat tidak ditemukan.');
        }

        header('Location: surat.php?terhapus=1');
        exit;
    }

    /* Ambil data siswa */

    $stmtSiswa = $pdo->query("
        SELECT
            id_siswa,
            nisn,
            nama_siswa,
            kelas,
            alamat,
            no_telepon_wali
        FROM siswa
        ORDER BY kelas ASC, nama_siswa ASC
    ");

    $daftarSiswa = $stmtSiswa->fetchAll(PDO::FETCH_ASSOC);

    /* =====================================
       SIMPAN SURAT
    ===================================== */

    if (
        $_SERVER['REQUEST_METHOD'] === 'POST' &&
        ($_POST['aksi'] ?? '') === 'buat_surat'
    ) {

        if (!in_array($role, ['admin', 'guru'], true)) {
    throw new RuntimeException(
        'Kamu tidak memiliki akses untuk membuat surat.'
    );
}

        $jenis = trim($_POST['jenis_surat'] ?? '');
        $idSiswa = (int)($_POST['id_siswa'] ?? 0);
        $tanggalSurat = trim($_POST['tanggal_surat'] ?? '');
        $tanggalKejadian = trim($_POST['tanggal_kejadian'] ?? '');

        $alasan = trim($_POST['alasan'] ?? '');
        $tujuan = trim($_POST['tujuan_surat'] ?? '');
        $alamatTujuan = trim($_POST['alamat_tujuan'] ?? '');
        $catatan = trim($_POST['catatan'] ?? '');

        $tanggalKunjungan = trim($_POST['tanggal_kunjungan'] ?? '');
        $waktuKunjungan = trim($_POST['waktu_kunjungan'] ?? '');

        $petugasKunjungan = $_POST['petugas_kunjungan'] ?? [];

        if (!is_array($petugasKunjungan)) {
            $petugasKunjungan = [];
        }

        $petugasKunjungan = array_values(
            array_filter(
                array_map(
                    static fn($nama) => trim((string)$nama),
                    $petugasKunjungan
                ),
                static fn($nama) => $nama !== ''
            )
        );

        /* Validasi umum */

        if (!in_array($jenis, ['Panggilan', 'Home Visit'], true)) {
            throw new RuntimeException('Jenis surat tidak valid.');
        }

        if ($idSiswa <= 0) {
            throw new RuntimeException('Silakan pilih siswa.');
        }

        if (!tanggalValid($tanggalSurat)) {
            throw new RuntimeException('Tanggal surat tidak valid.');
        }

        if (
            $tanggalKejadian !== '' &&
            !tanggalValid($tanggalKejadian)
        ) {
            throw new RuntimeException('Tanggal kejadian tidak valid.');
        }

        if ($alasan === '') {
            throw new RuntimeException('Alasan penerbitan surat wajib diisi.');
        }

        /* Validasi khusus Home Visit */

        if ($jenis === 'Home Visit') {

            if (!tanggalValid($tanggalKunjungan)) {
                throw new RuntimeException(
                    'Tanggal kunjungan Home Visit wajib diisi.'
                );
            }

            if (
                !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $waktuKunjungan)
            ) {
                throw new RuntimeException(
                    'Waktu kunjungan Home Visit tidak valid.'
                );
            }

            if (empty($petugasKunjungan)) {
                throw new RuntimeException(
                    'Minimal satu petugas Home Visit harus diisi.'
                );
            }
        } else {
            $tanggalKunjungan = null;
            $waktuKunjungan = null;
            $petugasKunjungan = [];
        }

        /* Pastikan siswa tersedia */

        $cekSiswa = $pdo->prepare("
            SELECT id_siswa
            FROM siswa
            WHERE id_siswa = :id_siswa
            LIMIT 1
        ");

        $cekSiswa->execute([
            ':id_siswa' => $idSiswa
        ]);

        if (!$cekSiswa->fetchColumn()) {
            throw new RuntimeException('Data siswa tidak ditemukan.');
        }

        /* Buat nomor surat unik */

        $nomorSurat = '';

        for ($i = 0; $i < 10; $i++) {

            $nomorSurat =
                'SP/ABS/' .
                date('Y') . '/' .
                date('m') . '/' .
                strtoupper(bin2hex(random_bytes(3)));

            $cekNomor = $pdo->prepare("
                SELECT COUNT(*)
                FROM surat
                WHERE nomor_surat = :nomor
            ");

            $cekNomor->execute([
                ':nomor' => $nomorSurat
            ]);

            if ((int)$cekNomor->fetchColumn() === 0) {
                break;
            }

            $nomorSurat = '';
        }

        if ($nomorSurat === '') {
            throw new RuntimeException(
                'Gagal membuat nomor surat. Silakan ulangi.'
            );
        }

        /* Simpan surat */

        $sqlInsert = "
            INSERT INTO surat (
                nomor_surat,
                jenis_surat,
                id_siswa,
                tanggal_surat,
                tanggal_kejadian,
                alasan,
                tujuan_surat,
                alamat_tujuan,
                catatan,
                tanggal_kunjungan,
                waktu_kunjungan,
                petugas_kunjungan,
                id_admin,
                created_at
            ) VALUES (
                :nomor,
                :jenis,
                :siswa,
                :tanggal,
                :kejadian,
                :alasan,
                :tujuan,
                :alamat,
                :catatan,
                :tanggal_kunjungan,
                :waktu_kunjungan,
                :petugas_kunjungan,
                :admin,
                NOW()
            )
        ";

        $stmtInsert = $pdo->prepare($sqlInsert);

        $stmtInsert->execute([
            ':nomor' => $nomorSurat,
            ':jenis' => $jenis,
            ':siswa' => $idSiswa,
            ':tanggal' => $tanggalSurat,
            ':kejadian' => $tanggalKejadian !== ''
                ? $tanggalKejadian
                : null,
            ':alasan' => $alasan,
            ':tujuan' => $tujuan !== ''
                ? $tujuan
                : null,
            ':alamat' => $alamatTujuan !== ''
                ? $alamatTujuan
                : null,
            ':catatan' => $catatan !== ''
                ? $catatan
                : null,
            ':tanggal_kunjungan' => $tanggalKunjungan,
            ':waktu_kunjungan' => $waktuKunjungan,
            ':petugas_kunjungan' => !empty($petugasKunjungan)
                ? implode("\n", $petugasKunjungan)
                : null,
            ':admin' => $idAdmin
        ]);

        header('Location: surat.php?sukses=1');
        exit;
    }

    /* =====================================
       FILTER DAN RIWAYAT SURAT
    ===================================== */

    $cari = trim($_GET['cari'] ?? '');
    $filterJenis = trim($_GET['jenis'] ?? '');

    $sqlRiwayat = "
        SELECT
            sr.*,
            s.nama_siswa,
            s.nisn,
            s.kelas
        FROM surat sr
        INNER JOIN siswa s
            ON s.id_siswa = sr.id_siswa
        WHERE 1 = 1
    ";

    $params = [];

    if ($cari !== '') {
        $sqlRiwayat .= "
            AND (
                s.nama_siswa LIKE :cari_nama
                OR sr.nomor_surat LIKE :cari_nomor
                OR s.nisn LIKE :cari_nisn
            )
        ";

        $kataCari = '%' . $cari . '%';

        $params[':cari_nama'] = $kataCari;
        $params[':cari_nomor'] = $kataCari;
        $params[':cari_nisn'] = $kataCari;
    }

    if (in_array($filterJenis, ['Panggilan', 'Home Visit'], true)) {
        $sqlRiwayat .= "
            AND sr.jenis_surat = :filter_jenis
        ";

        $params[':filter_jenis'] = $filterJenis;
    }

    $sqlRiwayat .= "
        ORDER BY sr.tanggal_surat DESC, sr.id_surat DESC
    ";

    $stmtRiwayat = $pdo->prepare($sqlRiwayat);
    $stmtRiwayat->execute($params);

    $riwayatSurat = $stmtRiwayat->fetchAll(PDO::FETCH_ASSOC);

    /* =====================================
       AMBIL DATA SURAT UNTUK CETAK
    ===================================== */

    if (isset($_GET['cetak'])) {

        $idCetak = (int)$_GET['cetak'];

        if ($idCetak <= 0) {
            throw new RuntimeException('ID surat tidak valid.');
        }

        $stmtCetak = $pdo->prepare("
            SELECT
                sr.*,
                s.nama_siswa,
                s.nisn,
                s.kelas,
                s.alamat,
                s.no_telepon_wali
            FROM surat sr
            INNER JOIN siswa s
                ON s.id_siswa = sr.id_siswa
            WHERE sr.id_surat = :id_surat
            LIMIT 1
        ");

        $stmtCetak->execute([
            ':id_surat' => $idCetak
        ]);

        $suratCetak = $stmtCetak->fetch(PDO::FETCH_ASSOC);

        if (!$suratCetak) {
            throw new RuntimeException('Surat tidak ditemukan.');
        }
    }

    if (isset($_GET['sukses'])) {
        $pesan = 'Surat berhasil dibuat dan disimpan.';
    }
    if (isset($_GET['terhapus'])) {
    $pesan = 'Surat berhasil dihapus.';
}

} catch (Throwable $ex) {
    $error = $ex->getMessage();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Surat | AbsenSmart</title>

<style>
:root {
    --pink: #FFCED8;
    --pink2: #FFDDDD;
    --cream: #FFF4DE;
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
    background: #fff;
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
    background: #fff;
    border: 1px solid var(--line);
    border-radius: 18px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 5px 20px #6d41400a;
}

.section-title {
    font-size: 19px;
    margin: 0 0 7px;
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
    flex: 1;
    min-width: 180px;
}

.field label {
    font-size: 13px;
    color: var(--muted);
    font-weight: 700;
}

input,
select,
textarea {
    width: 100%;
    font: inherit;
    border: 1px solid #e9dedd;
    border-radius: 10px;
    padding: 11px 12px;
    background: #fff;
    color: var(--ink);
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: #d9899a;
    box-shadow: 0 0 0 3px #ffced855;
}

textarea {
    resize: vertical;
    min-height: 90px;
}

button,
.btn {
    border: 0;
    border-radius: 10px;
    padding: 12px 17px;
    background: #d9899a;
    color: #fff;
    font-weight: 700;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    font-size: 13px;
}

button:hover,
.btn:hover {
    opacity: .88;
}

.btn.secondary {
    background: var(--cream);
    color: var(--ink);
}

.btn-danger {
    background: #dc6b6b;
}

.notice {
    padding: 13px 16px;
    border-radius: 11px;
    margin-bottom: 16px;
    background: #e9f7ec;
    color: #27633b;
}

.notice.error {
    background: #fff0ef;
    color: #a23e3e;
}

.table-wrap {
    overflow-x: auto;
    margin-top: 17px;
}

table {
    width: 100%;
    min-width: 850px;
    border-collapse: collapse;
}

th,
td {
    padding: 13px 11px;
    text-align: left;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
}

th {
    background: #fff5f3;
    color: #725e5e;
    font-size: 12px;
}

.badge {
    display: inline-block;
    padding: 6px 10px;
    border-radius: 20px;
    background: #fff0f2;
    color: #a45368;
    font-size: 11px;
    font-weight: bold;
}

.badge.home {
    background: #fff4de;
    color: #9a7134;
}

.empty {
    text-align: center;
    padding: 25px;
    color: var(--muted);
}

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.btn-print {
    padding: 8px 12px;
    font-size: 12px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 16px;
    margin-top: 20px;
}

.full {
    grid-column: 1 / -1;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 18px;
}

.student-search {
    display: flex;
    flex-direction: column;
    gap: 10px;
    width: 100%;
}

.student-search-controls {
    display: flex;
    gap: 10px;
    width: 100%;
}

.student-search-controls input {
    flex: 1;
    min-width: 0;
}

#hasilCariSiswa {
    color: #887b7b;
    font-size: 12px;
}

#homeVisitFields {
    background: #fff9f5;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 18px;
}

.petugas-item {
    display: flex;
    gap: 10px;
    margin-bottom: 10px;
}

.petugas-item input {
    flex: 1;
}

.print-area {
    display: none;
}

/* CETAK */

@media print {
    @page {
        size: A4 portrait;
        margin: 12mm;
    }

    body {
        background: #fff;
    }

    .sidebar,
    .top,
    .no-print,
    .panel {
        display: none !important;
    }

    .layout,
    .main {
        display: block;
        width: 100%;
        padding: 0;
        margin: 0;
    }

    .print-area {
        display: block !important;
        color: #000;
        font-family: "Times New Roman", serif;
        font-size: 11pt;
        line-height: 1.4;
    }

    .kop-surat {
        display: grid;
        grid-template-columns: 100px 1fr 100px;
        align-items: center;
        width: 100%;
        padding-bottom: 10px;
        margin-bottom: 18px;
        border-bottom: 4px double #000;
    }

    .kop-logo {
        display: flex;
        justify-content: center;
        align-items: center;
    }

    .kop-logo img {
        width: 85px;
        height: 95px;
        object-fit: contain;
    }

    .kop-identitas {
        text-align: center;
    }

    .kop-dinas,
    .kop-kabupaten {
        font-size: 15px;
        font-weight: bold;
    }

    .kop-sekolah {
        font-size: 20px;
        font-weight: bold;
    }

    .kop-alamat {
        font-size: 10px;
        margin-top: 4px;
    }

    .kop-npsn,
    .kop-email {
        font-size: 10px;
        font-weight: bold;
    }

    .print-area table {
        min-width: 0;
        margin: 15px 0;
    }

    .print-area td {
        border: none;
        padding: 3px 5px;
        vertical-align: top;
    }

    .print-signature {
        width: 260px;
        margin: 30px 0 0 auto;
        text-align: center;
        break-inside: avoid;
    }

    .print-signature .name {
        margin-top: 65px;
        font-weight: bold;
        text-decoration: underline;
    }
}

@media(max-width:800px) {
    .sidebar {
        display: none;
    }

    .main {
        padding: 17px;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .full {
        grid-column: auto;
    }

    .top {
        align-items: flex-start;
    }

    .petugas-item {
        flex-wrap: wrap;
    }
}

/* Tambahan ruang tanda tangan kepala sekolah */
.print-signature {
    margin-top: 30px;
}

.signature-space {
    height: 100px;
    display: flex;
    align-items: flex-end;
    justify-content: center;
}

.signature-space::after {
    content: "";
    display: block;
    width: 180px;
    border-bottom: 1px solid #000;
}
</style>
</head>

<body>
<div class="layout">

<aside class="sidebar no-print">
    <div class="brand">Absen<span>Smart</span></div>
    <div class="subbrand">Sistem Informasi Absensi Siswa</div>

    <a class="nav" href="<?= surat_e($dashboard) ?>">Dashboard</a>
    <a class="nav" href="absensi.php">Absensi Siswa</a>
    <a class="nav active" href="surat.php">Surat</a>
</aside>

<main class="main">

<div class="top no-print">
    <div>
        <h1>Surat</h1>
        <div class="muted">
            Pengelolaan surat panggilan dan Home Visit siswa.
        </div>
    </div>

    <a href="<?= surat_e($dashboard) ?>" class="btn secondary">
        Kembali
    </a>
</div>

<?php if ($pesan): ?>
    <div class="notice no-print">
        <?= surat_e($pesan) ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="notice error no-print">
        <?= surat_e($error) ?>
    </div>
<?php endif; ?>

<?php if (!empty($suratCetak)): ?>

<!-- =====================================
     HALAMAN CETAK SURAT
===================================== -->

<section class="print-area">

    <div class="kop-surat">
        <div class="kop-logo">
            <img src="assets/logo-smkn2.png" alt="Logo Sekolah">
        </div>

        <div class="kop-identitas">
            <div class="kop-dinas">DINAS PENDIDIKAN</div>
            <div class="kop-kabupaten">KABUPATEN BANGKALAN</div>
            <div class="kop-sekolah">SMK NEGERI 2 BANGKALAN</div>

            <div class="kop-alamat">
                Jl. Halim Perdana Kusuma (Ring Road), Mlajah,
                Kec. Bangkalan, Kabupaten Bangkalan, Jawa Timur 69116
            </div>

            <div class="kop-npsn">NPSN: 20531223</div>
            <div class="kop-email">
                Email: smkn2bangkalan301@gmail.com
            </div>
        </div>

        <div></div>
    </div>

    <p style="text-align:center;font-weight:bold;text-decoration:underline;">
        <?= $suratCetak['jenis_surat'] === 'Home Visit'
            ? 'SURAT TUGAS HOME VISIT'
            : 'SURAT PANGGILAN ORANG TUA / WALI' ?>
    </p>

    <p>
        Nomor: <?= surat_e($suratCetak['nomor_surat']) ?>
    </p>

    <p style="text-align:right;">
        Bangkalan, <?= tanggalIndonesia($suratCetak['tanggal_surat']) ?>
    </p>

    <?php if ($suratCetak['jenis_surat'] === 'Panggilan'): ?>

        <p>Kepada Yth.</p>
        <p>
            <?= surat_e($suratCetak['tujuan_surat'] ?: 'Orang Tua / Wali Siswa') ?>
        </p>

        <?php if (!empty($suratCetak['alamat_tujuan'])): ?>
            <p><?= nl2br(surat_e($suratCetak['alamat_tujuan'])) ?></p>
        <?php endif; ?>

        <p>Dengan hormat,</p>

        <p>
            Sehubungan dengan keperluan pembinaan dan pemantauan
            peserta didik, kami mengundang Bapak/Ibu untuk hadir
            ke sekolah terkait perkembangan peserta didik berikut:
        </p>

    <?php else: ?>

        <p>
            Dalam rangka pembinaan dan pemantauan perkembangan peserta
            didik, sekolah menugaskan petugas untuk melaksanakan
            kunjungan ke rumah orang tua/wali siswa.
        </p>

        <p>Adapun pelaksanaan kunjungan adalah sebagai berikut:</p>

    <?php endif; ?>

    <table>
        <tr>
            <td width="150">Nama siswa</td>
            <td width="15">:</td>
            <td><?= surat_e($suratCetak['nama_siswa']) ?></td>
        </tr>
        <tr>
            <td>NISN</td>
            <td>:</td>
            <td><?= surat_e($suratCetak['nisn']) ?></td>
        </tr>
        <tr>
            <td>Kelas</td>
            <td>:</td>
            <td><?= surat_e($suratCetak['kelas']) ?></td>
        </tr>

        <?php if (!empty($suratCetak['tanggal_kejadian'])): ?>
        <tr>
            <td>Tanggal kejadian</td>
            <td>:</td>
            <td><?= tanggalIndonesia($suratCetak['tanggal_kejadian']) ?></td>
        </tr>
        <?php endif; ?>

        <?php if ($suratCetak['jenis_surat'] === 'Home Visit'): ?>
        <tr>
            <td>Tanggal kunjungan</td>
            <td>:</td>
            <td><?= tanggalIndonesia($suratCetak['tanggal_kunjungan']) ?></td>
        </tr>
        <tr>
            <td>Waktu kunjungan</td>
            <td>:</td>
            <td>
                <?= surat_e(
                    $suratCetak['waktu_kunjungan']
                        ? substr($suratCetak['waktu_kunjungan'], 0, 5)
                        : '-'
                ) ?> WIB
            </td>
        </tr>
        <tr>
            <td>Petugas</td>
            <td>:</td>
            <td>
                <?= nl2br(surat_e($suratCetak['petugas_kunjungan'])) ?>
            </td>
        </tr>
        <?php endif; ?>

        <tr>
            <td>Alasan</td>
            <td>:</td>
            <td><?= nl2br(surat_e($suratCetak['alasan'])) ?></td>
        </tr>
    </table>

    <?php if (!empty($suratCetak['alamat_tujuan'])): ?>
        <p>
            Alamat tujuan:
            <?= nl2br(surat_e($suratCetak['alamat_tujuan'])) ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($suratCetak['catatan'])): ?>
        <p>
            Catatan:
            <?= nl2br(surat_e($suratCetak['catatan'])) ?>
        </p>
    <?php endif; ?>

    <?php if ($suratCetak['jenis_surat'] === 'Panggilan'): ?>
        <p>
            Demikian surat panggilan ini kami sampaikan.
            Atas perhatian dan kerja sama Bapak/Ibu,
            kami mengucapkan terima kasih.
        </p>
    <?php else: ?>
        <p>
            Demikian surat tugas ini dibuat untuk dilaksanakan
            dengan penuh tanggung jawab.
        </p>
    <?php endif; ?>

    <div class="print-signature">
    <p>Bangkalan, <?= tanggalIndonesia($suratCetak['tanggal_surat']) ?></p>
    <p>Kepala SMK Negeri 2 Bangkalan,</p>

    <div class="signature-space"></div>

    <p><strong>Qurrotuainy, S.T., M.Pd.</strong></p>
</div>

</section>

<div class="panel no-print">
    <h2 class="section-title">Pratinjau Surat</h2>
    <p class="muted">
        Surat <?= surat_e($suratCetak['jenis_surat']) ?>
        untuk <?= surat_e($suratCetak['nama_siswa']) ?>.
    </p>

    <button type="button" onclick="window.print()">
        Cetak Surat
    </button>

    <a href="surat.php" class="btn secondary">
        Kembali ke Riwayat
    </a>
</div>

<?php else: ?>

<!-- =====================================
     FORM PEMBUATAN SURAT
===================================== -->

<?php if (in_array($role, ['admin', 'guru'], true)): ?>

<section class="panel no-print">

    <h2 class="section-title">Buat Surat Baru</h2>

    <div class="muted">
        Isi data berikut untuk membuat surat panggilan atau Home Visit.
    </div>

    <form method="post" action="surat.php" id="formSurat">

        <input type="hidden" name="aksi" value="buat_surat">

        <div class="form-grid">

            <div class="field">
                <label>Jenis Surat *</label>
                <select name="jenis_surat" id="jenisSurat" required>
                    <option value="">Pilih jenis surat</option>
                    <option value="Panggilan">Surat Panggilan</option>
                    <option value="Home Visit">Surat Home Visit</option>
                </select>
            </div>

            <div class="field">
                <label>Tanggal Surat *</label>
                <input
                    type="date"
                    name="tanggal_surat"
                    value="<?= date('Y-m-d') ?>"
                    required
                >
            </div>

            <div class="field full">
                <label>Nama Siswa *</label>

                <?php
                $siswaPerKelas = [];

                foreach ($daftarSiswa as $s) {
                    $kelas = trim($s['kelas'] ?? 'Tanpa Kelas');
                    $siswaPerKelas[$kelas][] = $s;
                }

                ksort($siswaPerKelas);
                ?>

                <div class="student-search">

                    <div class="student-search-controls">
                        <input
                            type="text"
                            id="cariSiswaSurat"
                            placeholder="Ketik nama siswa, NISN, atau kelas..."
                        >

                        <button type="button" id="tombolCariSiswa">
                            Cari
                        </button>
                    </div>

                    <select name="id_siswa" id="pilihSiswaSurat" required>
                        <option value="">Pilih siswa</option>

                        <?php foreach ($siswaPerKelas as $kelas => $siswas): ?>
                            <optgroup label="<?= surat_e($kelas) ?>">

                                <?php foreach ($siswas as $s): ?>
                                    <option
                                        value="<?= (int)$s['id_siswa'] ?>"
                                        data-search="<?= surat_e(
                                            strtolower(
                                                ($s['nama_siswa'] ?? '') . ' ' .
                                                $kelas . ' ' .
                                                ($s['nisn'] ?? '')
                                            )
                                        ) ?>"
                                    >
                                        <?= surat_e($s['nama_siswa']) ?>
                                        - <?= surat_e($kelas) ?>
                                        (<?= surat_e($s['nisn'] ?? '-') ?>)
                                    </option>
                                <?php endforeach; ?>

                            </optgroup>
                        <?php endforeach; ?>

                    </select>

                    <small id="hasilCariSiswa">
                        Pilih siswa berdasarkan kelas atau gunakan pencarian.
                    </small>

                </div>
            </div>

            <div class="field">
                <label>Tanggal Kejadian</label>
                <input type="date" name="tanggal_kejadian">
            </div>

            <!-- HOME VISIT -->

            <div class="field full" id="homeVisitFields" style="display:none;">

                <h3>Jadwal Home Visit</h3>

                <div class="form-grid">

                    <div class="field">
                        <label>Tanggal Kunjungan *</label>
                        <input
                            type="date"
                            name="tanggal_kunjungan"
                            id="tanggalKunjungan"
                        >
                    </div>

                    <div class="field">
                        <label>Waktu Kunjungan *</label>
                        <input
                            type="time"
                            name="waktu_kunjungan"
                            id="waktuKunjungan"
                        >
                    </div>

                    <div class="field full">
                        <label>Petugas yang Ditugaskan *</label>

                        <div id="daftarPetugas">
                            <div class="petugas-item">
                                <input
                                    type="text"
                                    name="petugas_kunjungan[]"
                                    placeholder="Nama petugas"
                                >

                                <button
                                    type="button"
                                    class="btn-danger"
                                    onclick="hapusPetugas(this)"
                                >
                                    Hapus
                                </button>
                            </div>
                        </div>

                        <button type="button" onclick="tambahPetugas()">
                            + Tambah Petugas
                        </button>
                    </div>

                </div>
            </div>

            <div class="field">
                <label>Nama Tujuan Surat</label>
                <input
                    type="text"
                    name="tujuan_surat"
                    placeholder="Contoh: Orang Tua / Wali Siswa"
                >
            </div>

            <div class="field full">
                <label>Alamat Tujuan</label>
                <textarea
                    name="alamat_tujuan"
                    placeholder="Masukkan alamat orang tua/wali siswa"
                ></textarea>
            </div>

            <div class="field full">
                <label>Alasan Penerbitan Surat *</label>
                <textarea
                    name="alasan"
                    placeholder="Tuliskan alasan pembuatan surat..."
                    required
                ></textarea>
            </div>

            <div class="field full">
                <label>Catatan Tambahan</label>
                <textarea
                    name="catatan"
                    placeholder="Catatan tambahan (opsional)"
                ></textarea>
            </div>

        </div>

        <div class="form-actions">
            <button type="submit">Simpan Surat</button>

            <button type="reset" class="btn secondary">
                Reset
            </button>
        </div>

    </form>

</section>

<?php endif; ?>

<!-- =====================================
     RIWAYAT SURAT
===================================== -->

<section class="panel no-print">

    <h2 class="section-title">Riwayat Surat</h2>

    <div class="muted">
        Daftar surat yang telah dibuat dan disimpan dalam database.
    </div>

    
<form method="get" action="surat.php" class="toolbar">

    <div class="field">
        <label>Cari surat atau nama siswa</label>
        <input
            type="text"
            name="cari"
            value="<?= surat_e($cari ?? '') ?>"
            placeholder="Nama siswa, NISN, atau nomor surat"
        >
    </div>

    <div class="field">
        <label>Jenis Surat</label>
        <select name="jenis">
            <option value="">Semua jenis</option>
            <option value="Panggilan" <?= ($filterJenis ?? '') === 'Panggilan' ? 'selected' : '' ?>>
                Panggilan
            </option>
            <option value="Home Visit" <?= ($filterJenis ?? '') === 'Home Visit' ? 'selected' : '' ?>>
                Home Visit
            </option>
        </select>
    </div>

    <button type="submit">Terapkan</button>

<button
    type="button"
    class="btn secondary"
    onclick="window.location.href='/AbsenSmart/surat.php';"
>
    Reset
</button>

</form>


    <div class="table-wrap">

        <table>
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Nomor Surat</th>
                    <th>Jenis</th>
                    <th>Nama Siswa</th>
                    <th>Kelas</th>
                    <th>Tanggal Surat</th>
                    <th>Aksi</th>
                </tr>
            </thead>

            <tbody>

                <?php if (empty($riwayatSurat)): ?>

                    <tr>
                        <td colspan="7" class="empty">
                            Belum ada riwayat surat.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($riwayatSurat as $i => $s): ?>

                        <tr>
                            <td><?= $i + 1 ?></td>

                            <td><?= surat_e($s['nomor_surat']) ?></td>

                            <td>
                                <span class="badge <?= $s['jenis_surat'] === 'Home Visit' ? 'home' : '' ?>">
                                    <?= surat_e($s['jenis_surat']) ?>
                                </span>
                            </td>

                            <td><?= surat_e($s['nama_siswa']) ?></td>

                            <td><?= surat_e($s['kelas']) ?></td>

                            <td>
                                <?= tanggalIndonesia($s['tanggal_surat']) ?>
                            </td>

                            

<td>
    <div class="actions">

        <a
            class="btn btn-print"
            href="surat.php?cetak=<?= (int)$s['id_surat'] ?>"
            target="_blank"
        >
            Lihat / Cetak
        </a>

        <form method="post" action="surat.php"
              onsubmit="return confirm('Yakin ingin menghapus surat ini?');">

            <input type="hidden" name="aksi" value="hapus_surat">

            <input
                type="hidden"
                name="id_surat"
                value="<?= (int)$s['id_surat'] ?>"
            >

            <input
                type="hidden"
                name="csrf_surat"
                value="<?= surat_e($_SESSION['csrf_surat']) ?>"
            >

            <button type="submit" class="btn-danger">
                Hapus
            </button>

        </form>

    </div>
</td>


                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

            </tbody>
        </table>

    </div>

</section>

<?php endif; ?>

</main>
</div>

<script>

/* =====================================
   PENCARIAN SISWA
===================================== */

document.addEventListener('DOMContentLoaded', function () {

    const input = document.getElementById('cariSiswaSurat');
    const tombol = document.getElementById('tombolCariSiswa');
    const select = document.getElementById('pilihSiswaSurat');
    const info = document.getElementById('hasilCariSiswa');

    if (input && tombol && select && info) {

        const semuaOpsi = Array.from(
            select.querySelectorAll('option')
        ).filter(option => option.value !== '');

        const semuaGrup = Array.from(
            select.querySelectorAll('optgroup')
        );

        function cariSiswa() {

            const kata = input.value.trim().toLowerCase();
            let jumlah = 0;

            semuaOpsi.forEach(option => {

                const teks = option.dataset.search ||
                    option.textContent.toLowerCase();

                const cocok = teks.includes(kata);

                option.hidden = !cocok;

                if (cocok) {
                    jumlah++;
                }
            });

            semuaGrup.forEach(grup => {

                const adaHasil = Array.from(
                    grup.querySelectorAll('option')
                ).some(option => !option.hidden);

                grup.hidden = !adaHasil;
            });

            info.textContent = kata
                ? jumlah + ' siswa ditemukan.'
                : 'Menampilkan semua siswa berdasarkan kelas.';

            if (
                select.selectedOptions.length &&
                select.selectedOptions[0].hidden
            ) {
                select.value = '';
            }
        }

        tombol.addEventListener('click', cariSiswa);
        input.addEventListener('input', cariSiswa);

        input.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                cariSiswa();
            }
        });
    }

    /* =====================================
       FORM HOME VISIT
    ===================================== */

    const jenisSurat = document.getElementById('jenisSurat');
    const homeVisitFields = document.getElementById('homeVisitFields');
    const tanggalKunjungan = document.getElementById('tanggalKunjungan');
    const waktuKunjungan = document.getElementById('waktuKunjungan');

    function aturFormHomeVisit() {

        if (!jenisSurat || !homeVisitFields) {
            return;
        }

        const aktif = jenisSurat.value === 'Home Visit';

        homeVisitFields.style.display = aktif ? 'block' : 'none';

        tanggalKunjungan.required = aktif;
        waktuKunjungan.required = aktif;

        document.querySelectorAll('[name="petugas_kunjungan[]"]')
            .forEach(input => {
                input.required = aktif;
            });
    }

    if (jenisSurat) {
        jenisSurat.addEventListener('change', aturFormHomeVisit);
        aturFormHomeVisit();
    }

    /* =====================================
       TAMBAH PETUGAS
    ===================================== */

    window.tambahPetugas = function () {

        const daftar = document.getElementById('daftarPetugas');

        if (!daftar) {
            return;
        }

        const item = document.createElement('div');
        item.className = 'petugas-item';

        const input = document.createElement('input');
        input.type = 'text';
        input.name = 'petugas_kunjungan[]';
        input.placeholder = 'Nama petugas';
        input.required = jenisSurat &&
            jenisSurat.value === 'Home Visit';

        const tombol = document.createElement('button');
        tombol.type = 'button';
        tombol.className = 'btn-danger';
        tombol.textContent = 'Hapus';
        tombol.addEventListener('click', function () {
            hapusPetugas(tombol);
        });

        item.appendChild(input);
        item.appendChild(tombol);

        daftar.appendChild(item);
    };

    /* =====================================
       HAPUS PETUGAS
    ===================================== */

    window.hapusPetugas = function (tombol) {

        const daftar = document.getElementById('daftarPetugas');

        if (!daftar || daftar.children.length <= 1) {
            alert('Minimal harus ada satu kolom petugas.');
            return;
        }

        tombol.closest('.petugas-item').remove();
    };

    /* =====================================
       RESET FORM
    ===================================== */

    const formSurat = document.getElementById('formSurat');

    if (formSurat) {
        formSurat.addEventListener('reset', function () {
            setTimeout(aturFormHomeVisit, 0);
        });
    }

});

</script>

</body>
</html>