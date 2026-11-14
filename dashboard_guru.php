
<?php
session_start();
require_once __DIR__ . '/config.php';

// =============================================
// 1. VALIDASI SESSION GURU BK
// =============================================

if (
    !isset($_SESSION['role']) ||
    $_SESSION['role'] !== 'guru' ||
    empty($_SESSION['guru_id'])
) {
    header('Location: login.php');
    exit;
}

$idGuru = (int) $_SESSION['guru_id'];

$namaGuru = $_SESSION['nama'] ?? 'Guru BK';

// =============================================
// 2. FUNGSI FORMAT
// =============================================

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatTanggal($tanggal) {
    if (!$tanggal) {
        return '-';
    }

    return date('d/m/Y', strtotime($tanggal));
}

// =============================================
// 3. AMBIL DATA DASHBOARD
// =============================================

try {

    // Periksa akun Guru BK
    $stmtGuru = $pdo->prepare("
        SELECT
            id_guru_bk,
            nama_guru,
            username,
            email,
            status_akun
        FROM guru_bk
        WHERE id_guru_bk = :id
        LIMIT 1
    ");

    $stmtGuru->execute([
        ':id' => $idGuru
    ]);

    $guru = $stmtGuru->fetch(PDO::FETCH_ASSOC);

    // Jangan redirect berulang jika akun bermasalah
    if (!$guru) {
        http_response_code(403);
        exit('Akun Guru BK tidak ditemukan. Periksa data guru_bk.');
    }

    if ($guru['status_akun'] !== 'aktif') {
        http_response_code(403);
        exit('Akun Guru BK tidak aktif. Hubungi Administrator.');
    }

    $namaGuru = $guru['nama_guru'];

    $_SESSION['nama'] = $namaGuru;

    // Total siswa
    $totalSiswa = (int) $pdo->query("
        SELECT COUNT(*)
        FROM siswa
    ")->fetchColumn();

    // Total absensi hari ini
    $stmtHariIni = $pdo->prepare("
        SELECT COUNT(*)
        FROM absensi
        WHERE tanggal = CURDATE()
    ");

    $stmtHariIni->execute();

    $absensiHariIni = (int) $stmtHariIni->fetchColumn();

    // Rekap status absensi hari ini
    $stmtRekap = $pdo->prepare("
        SELECT
            status,
            COUNT(*) AS jumlah
        FROM absensi
        WHERE tanggal = CURDATE()
        GROUP BY status
    ");

    $stmtRekap->execute();

    $hasilRekap = $stmtRekap->fetchAll(PDO::FETCH_ASSOC);

    // Siapkan kategori status
    $rekap = [
        'Hadir' => 0,
        'Izin' => 0,
        'Sakit' => 0,
        'Alpa' => 0
    ];

    foreach ($hasilRekap as $item) {
        $status = ucfirst(strtolower(trim($item['status'])));

        if (array_key_exists($status, $rekap)) {
            $rekap[$status] = (int) $item['jumlah'];
        }
    }

    // Riwayat absensi terbaru
    $stmtRiwayat = $pdo->query("
        SELECT
            a.id_absensi,
            a.tanggal,
            a.status,
            a.keterangan,
            s.nama_siswa,
            s.kelas
        FROM absensi a
        LEFT JOIN siswa s
            ON a.id_siswa = s.id_siswa
        ORDER BY
            a.tanggal DESC,
            a.id_absensi DESC
        LIMIT 10
    ");

    $riwayat = $stmtRiwayat->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $error) {

    http_response_code(500);

    exit(
        'Terjadi kesalahan database: ' .
        e($error->getMessage())
    );
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Dashboard Guru BK | AbsenSmart</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: Arial, sans-serif;
        }

        body {
            background: #fff8f4;
            color: #493b3b;
        }

        /* SIDEBAR */

        .sidebar {
            width: 270px;
            height: 100vh;
            position: fixed;
            top: 0;
            left: 0;
            background: white;
            border-right: 1px solid #eee;
            padding: 25px 18px;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 5px 10px;
            margin-bottom: 45px;
        }

        .brand-icon {
            width: 55px;
            height: 55px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #ffd0dc;
            border-radius: 15px;
            font-size: 27px;
        }

        .brand h2 {
            font-size: 23px;
        }

        .brand p {
            color: #999;
            font-size: 13px;
            margin-top: 5px;
        }

        .menu-title {
            font-size: 12px;
            font-weight: bold;
            color: #aaa;
            margin: 20px 15px 15px;
            letter-spacing: 1px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 15px;
            padding: 17px 18px;
            margin-bottom: 8px;
            border-radius: 13px;
            text-decoration: none;
            color: #777;
            transition: .2s;
        }

        .menu a:hover,
        .menu a.active {
            background: #ffd0dc;
            color: #493b3b;
        }

        .menu a span {
            font-size: 20px;
        }

        .menu .logout {
            color: #d9534f;
            margin-top: 25px;
        }

        /* MAIN */

        .main {
            margin-left: 270px;
            min-height: 100vh;
        }

        .topbar {
            height: 95px;
            background: white;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0 35px;
        }

        .topbar h2 {
            font-size: 23px;
        }

        .profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .profile-icon {
            width: 48px;
            height: 48px;
            background: #ffe7c4;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 22px;
        }

        .profile small {
            display: block;
            color: #999;
            margin-top: 5px;
        }

        /* CONTENT */

        .content {
            padding: 40px 35px;
        }

        .welcome {
            margin-bottom: 35px;
        }

        .welcome h1 {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .welcome p {
            color: #999;
        }

        /* CARDS */

        .cards {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 22px;
            margin-bottom: 30px;
        }

        .card {
            background: white;
            border: 1px solid #f0e5df;
            border-radius: 22px;
            padding: 27px;
            box-shadow: 0 5px 18px rgba(0,0,0,.02);
        }

        .card-icon {
            width: 55px;
            height: 55px;
            border-radius: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 25px;
            margin-bottom: 22px;
        }

        .pink {
            background: #ffdce1;
        }

        .yellow {
            background: #fff1d8;
        }

        .orange {
            background: #ffe5c5;
        }

        .card p {
            color: #999;
            margin-bottom: 10px;
        }

        .card h2 {
            font-size: 34px;
        }

        /* PANEL */

        .panel {
            background: white;
            border: 1px solid #f0e5df;
            border-radius: 20px;
            padding: 28px;
            margin-bottom: 25px;
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .panel-header small {
            color: #999;
        }

        /* REKAP */

        .rekap {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
        }

        .rekap-item {
            background: #fff8f4;
            border-radius: 12px;
            padding: 18px;
        }

        .rekap-item p {
            color: #999;
            margin-bottom: 8px;
        }

        .rekap-item h3 {
            font-size: 25px;
        }

        /* TABLE */

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }

        th {
            color: #999;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .empty {
            text-align: center;
            color: #999;
            padding: 30px;
        }

        /* BUTTON */

        .action {
            display: inline-block;
            background: #ffd0dc;
            color: #493b3b;
            padding: 13px 20px;
            border-radius: 12px;
            text-decoration: none;
            font-weight: bold;
            transition: .2s;
        }

        .action:hover {
            background: #ffbdce;
        }

        /* RESPONSIVE */

        @media(max-width: 1000px) {

            .cards {
                grid-template-columns: repeat(2, 1fr);
            }

            .rekap {
                grid-template-columns: repeat(2, 1fr);
            }

        }

        @media(max-width: 700px) {

            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }

            .main {
                margin-left: 0;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 25px 18px;
            }

            .topbar {
                padding: 0 20px;
            }

        }

    </style>

</head>

<body>

    <!-- SIDEBAR -->

    <aside class="sidebar">

        <div class="brand">

            <div class="brand-icon">
                📋
            </div>

            <div>
                <h2>AbsenSmart</h2>
                <p>Guru BK Panel</p>
            </div>

        </div>

        <p class="menu-title">MENU UTAMA</p>

        <nav class="menu">

            <a href="dashboard_guru.php" class="active">
                <span>🏠</span>
                Dashboard
            </a>

            <a href="data_siswa.php">
                <span>👨‍🎓</span>
                Data Siswa
            </a>

            <a href="absensi.php">
                <span>📅</span>
                Kelola Absensi
            </a>

            <a href="surat.php">
                <span>📄</span>
                Surat Panggilan
            </a>

            <a href="/AbsenSmart/surat.php">
    <span>🏡</span>
    Home Visit
</a>

            <a href="rekap.php">
                <span>📊</span>
                Rekap Absensi
            </a>

            <a href="logout.php" class="logout"
               onclick="return confirm('Yakin ingin logout?')">
                <span>🚪</span>
                Logout
            </a>

        </nav>

    </aside>

    <!-- MAIN CONTENT -->

    <main class="main">

        <header class="topbar">

            <h2>Dashboard Guru BK</h2>

            <div class="profile">

                <div class="profile-icon">
                    👤
                </div>

                <div>

                    <strong><?= e($namaGuru) ?></strong>

                    <small>Guru BK</small>

                </div>

            </div>

        </header>

        <section class="content">

            <!-- WELCOME -->

            <div class="welcome">

                <h1>
                    Selamat Datang,
                    <?= e($namaGuru) ?>! 👋
                </h1>

                <p>
                    Berikut ringkasan informasi sistem AbsenSmart.
                    Hari ini, <?= date('d/m/Y') ?>.
                </p>

            </div>

            <!-- STATISTIK -->

            <div class="cards">

                <div class="card">

                    <div class="card-icon pink">
                        🎓
                    </div>

                    <p>Total Siswa</p>

                    <h2>
                        <?= number_format($totalSiswa) ?>
                    </h2>

                </div>

                <div class="card">

                    <div class="card-icon yellow">
                        📅
                    </div>

                    <p>Absensi Hari Ini</p>

                    <h2>
                        <?= number_format($absensiHariIni) ?>
                    </h2>

                </div>

                <div class="card">

                    <div class="card-icon orange">
                        📊
                    </div>

                    <p>Jenis Status Absensi</p>

                    <h2>
                        <?= count($hasilRekap) ?>
                    </h2>

                </div>

            </div>

            <!-- REKAP ABSENSI -->

            <div class="panel">

                <div class="panel-header">

                    <h3>Rekap Absensi Hari Ini</h3>

                    <small><?= date('d/m/Y') ?></small>

                </div>

                <div class="rekap">

                    <?php foreach ($rekap as $status => $jumlah): ?>

                        <div class="rekap-item">

                            <p><?= e($status) ?></p>

                            <h3><?= number_format($jumlah) ?></h3>

                        </div>

                    <?php endforeach; ?>

                </div>

            </div>

            <!-- RIWAYAT ABSENSI -->

            <div class="panel">

                <div class="panel-header">

                    <h3>Riwayat Absensi Terbaru</h3>

                    <small>10 data terakhir</small>

                </div>

                <div class="table-wrap">

                    <table>

                        <thead>

                            <tr>
                                <th>No</th>
                                <th>Nama Siswa</th>
                                <th>Kelas</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th>Keterangan</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (count($riwayat) > 0): ?>

                                <?php foreach ($riwayat as $index => $data): ?>

                                    <tr>

                                        <td>
                                            <?= $index + 1 ?>
                                        </td>

                                        <td>
                                            <?= e($data['nama_siswa'] ?? '-') ?>
                                        </td>

                                        <td>
                                            <?= e($data['kelas'] ?? '-') ?>
                                        </td>

                                        <td>
                                            <?= formatTanggal($data['tanggal']) ?>
                                        </td>

                                        <td>
                                            <?= e($data['status']) ?>
                                        </td>

                                        <td>
                                            <?= e($data['keterangan'] ?? '-') ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="6" class="empty">
                                        Belum ada data absensi.
                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

            <!-- KELOLA ABSENSI -->

            <div class="panel">

                <div class="panel-header">

                    <h3>Pengelolaan Absensi</h3>

                </div>

                <p style="color:#999; margin-bottom:20px;">

                    Guru BK dapat menambah, mengedit,
                    menghapus, dan mengelola data absensi siswa.

                </p>

                <a href="absensi.php" class="action">
                    Kelola Absensi Siswa →
                </a>

            </div>

        </section>

    </main>

</body>

</html>
