
<?php
session_start();
require_once 'config.php';

// ==========================================
// VALIDASI LOGIN ADMIN
// ==========================================

if (!isset($_SESSION['admin_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: login.php");
    exit;
}

$namaAdmin = $_SESSION['nama'] ?? 'Administrator';

// ==========================================
// DATA STATISTIK
// ==========================================

$totalSiswa = 0;
$totalGuru = 0;
$totalAbsensi = 0;
$totalSurat = 0;
$totalHomeVisit = 0;

$rekapStatus = [];
$absensiTerbaru = [];
$errorDatabase = '';

try {

    // Total siswa
    $totalSiswa = (int) $pdo
        ->query("SELECT COUNT(*) FROM siswa")
        ->fetchColumn();

    // Total guru BK
    $totalGuru = (int) $pdo
        ->query("SELECT COUNT(*) FROM guru_bk")
        ->fetchColumn();

    // Total absensi hari ini
    $stmt = $pdo->query("
        SELECT COUNT(*)
        FROM absensi
        WHERE tanggal = CURDATE()
    ");

    $totalAbsensi = (int) $stmt->fetchColumn();

    // Total surat
    $totalSurat = (int) $pdo
        ->query("SELECT COUNT(*) FROM surat")
        ->fetchColumn();

   // Total Home Visit
$stmt = $pdo->query("
    SELECT COUNT(*)
    FROM surat
    WHERE jenis_surat = 'Home Visit'
");

$totalHomeVisit = (int) $stmt->fetchColumn();

    // Rekap status absensi hari ini
    $stmt = $pdo->query("
        SELECT status, COUNT(*) AS jumlah
        FROM absensi
        WHERE tanggal = CURDATE()
        GROUP BY status
    ");

    $rekapStatus = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Absensi terbaru
    $stmt = $pdo->query("
        SELECT
            a.id_absensi,
            a.tanggal,
            a.status,
            a.keterangan,
            s.nama_siswa,
            s.kelas
        FROM absensi a
        INNER JOIN siswa s
            ON a.id_siswa = s.id_siswa
        ORDER BY a.created_at DESC
        LIMIT 8
    ");

    $absensiTerbaru = $stmt->fetchAll(PDO::FETCH_ASSOC);

} catch (PDOException $e) {

    $errorDatabase = "Gagal mengambil data dashboard. Periksa struktur tabel database.";

}

// ==========================================
// FUNGSI FORMAT
// ==========================================

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function formatTanggal($tanggal) {
    return date('d/m/Y', strtotime($tanggal));
}

?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Dashboard Admin | AbsenSmart</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #FFF9F4;
            color: #493C40;
        }

        a {
            text-decoration: none;
            color: inherit;
        }

        /* =================================
           LAYOUT
        ================================= */

        .layout {
            display: flex;
            min-height: 100vh;
        }

        /* =================================
           SIDEBAR
        ================================= */

        .sidebar {
            width: 250px;
            min-width: 250px;

            background: #FFFFFF;

            border-right: 1px solid #F2E7E3;

            padding: 25px 17px;

            display: flex;
            flex-direction: column;

            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;

            z-index: 100;
            overflow-y: auto;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 5px 10px 30px;
        }

        .brand-icon {
            width: 43px;
            height: 43px;

            background: #FFCED8;

            border-radius: 13px;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 23px;
        }

        .brand h2 {
            font-size: 21px;
            color: #563C42;
        }

        .brand small {
            display: block;
            font-size: 11px;
            color: #999;
            margin-top: 4px;
        }

        .menu-title {
            font-size: 11px;
            font-weight: bold;
            color: #AAAAAA;

            padding: 12px 12px;

            letter-spacing: 1px;
        }

        .menu {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .menu a {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 13px 14px;

            border-radius: 11px;

            font-size: 14px;
            color: #777777;

            transition: 0.2s;
        }

        .menu a:hover {
            background: #FFF4DE;
            color: #563C42;
        }

        .menu a.active {
            background: #FFCED8;
            color: #563C42;
            font-weight: bold;
        }

        .menu-icon {
            font-size: 19px;
            width: 22px;
            text-align: center;
        }

        .sidebar-bottom {
            margin-top: auto;
            padding-top: 25px;
        }

        .logout-link {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 13px 14px;

            border-radius: 11px;

            color: #C75C66;
            font-size: 14px;
        }

        .logout-link:hover {
            background: #FFDDDD;
        }

        /* =================================
           MAIN CONTENT
        ================================= */

        .main {
            flex: 1;
            margin-left: 250px;
            min-width: 0;
        }

        /* =================================
           TOPBAR
        ================================= */

        .topbar {
            height: 75px;

            background: #FFFFFF;

            border-bottom: 1px solid #F2E7E3;

            display: flex;
            align-items: center;
            justify-content: space-between;

            padding: 0 35px;
        }

        .topbar h3 {
            font-size: 19px;
        }

        .admin-profile {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .avatar {
            width: 39px;
            height: 39px;

            background: #FFE8C4;

            border-radius: 50%;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 19px;
        }

        .profile-info strong {
            font-size: 13px;
            display: block;
        }

        .profile-info small {
            font-size: 11px;
            color: #999;
        }

        /* =================================
           CONTENT
        ================================= */

        .content {
            padding: 32px 35px;
        }

        .welcome {
            margin-bottom: 28px;
        }

        .welcome h1 {
            font-size: 26px;
            margin-bottom: 9px;
            color: #563C42;
        }

        .welcome p {
            color: #999;
            font-size: 14px;
        }

        .alert-error {
            padding: 15px;
            background: #FFDDDD;
            border-radius: 10px;
            margin-bottom: 20px;
            color: #813C48;
            font-size: 14px;
        }

        /* =================================
           STATISTICS
        ================================= */

        .stats-grid {
            display: grid;

            grid-template-columns:
                repeat(4, minmax(0, 1fr));

            gap: 18px;

            margin-bottom: 28px;
        }

        .stat-card {
            background: #FFFFFF;

            padding: 23px;

            border-radius: 17px;

            border: 1px solid #F1E7E2;

            box-shadow: 0 3px 12px
                rgba(80, 50, 50, 0.03);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;

            margin-bottom: 20px;
        }

        .stat-icon {
            width: 43px;
            height: 43px;

            display: flex;
            align-items: center;
            justify-content: center;

            border-radius: 12px;

            font-size: 21px;
        }

        .pink {
            background: #FFDDDD;
        }

        .cream {
            background: #FFF4DE;
        }

        .orange {
            background: #FFE8C4;
        }

        .soft-pink {
            background: #FFCED8;
        }

        .stat-card p {
            color: #999;
            font-size: 13px;
            margin-bottom: 9px;
        }

        .stat-card h2 {
            font-size: 29px;
            color: #563C42;
        }

        /* =================================
           PANEL
        ================================= */

        .panel-grid {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 20px;
        }

        .panel {
            background: #FFFFFF;

            border: 1px solid #F1E7E2;

            border-radius: 17px;

            padding: 23px;

            min-width: 0;
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;

            margin-bottom: 22px;
        }

        .panel-header h3 {
            font-size: 17px;
        }

        .panel-header span {
            font-size: 12px;
            color: #999;
        }

        /* =================================
           REKAP STATUS
        ================================= */

        .status-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }

        .status-item {
            display: flex;
            align-items: center;
            justify-content: space-between;

            padding-bottom: 13px;

            border-bottom: 1px solid #F4EEEE;

            font-size: 14px;
        }

        .status-item:last-child {
            border-bottom: none;
        }

        .status-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #FFCED8;
        }

        .status-count {
            font-weight: bold;
            color: #563C42;
        }

        .empty {
            text-align: center;
            padding: 25px 10px;
            color: #AAAAAA;
            font-size: 13px;
        }

        /* =================================
           TABLE
        ================================= */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;

            white-space: nowrap;
        }

        th {
            text-align: left;

            font-size: 12px;
            color: #999;

            padding: 13px 12px;

            background: #FFF9F4;
        }

        td {
            padding: 15px 12px;

            font-size: 13px;

            border-bottom: 1px solid #F4EEEE;
        }

        .badge-status {
            display: inline-block;

            padding: 6px 11px;

            border-radius: 20px;

            font-size: 11px;
            font-weight: bold;

            background: #FFF4DE;
            color: #80633E;
        }

        /* =================================
           FOOTER
        ================================= */

        .footer {
            text-align: center;

            padding: 25px;

            color: #AAAAAA;

            font-size: 12px;
        }

        /* =================================
           RESPONSIVE
        ================================= */

        @media (max-width: 1100px) {

            .stats-grid {
                grid-template-columns:
                    repeat(2, minmax(0, 1fr));
            }

            .panel-grid {
                grid-template-columns: 1fr;
            }

        }

        @media (max-width: 700px) {

            .sidebar {
                position: relative;

                width: 100%;
                min-width: 0;

                height: auto;

                padding: 12px;

                border-right: none;
                border-bottom: 1px solid #F2E7E3;
            }

            .layout {
                flex-direction: column;
            }

            .brand {
                padding: 5px 8px 15px;
            }

            .menu-title {
                display: none;
            }

            .menu {
                flex-direction: row;

                overflow-x: auto;
                padding-bottom: 8px;
            }

            .menu a {
                flex-shrink: 0;
                padding: 10px 13px;
                font-size: 12px;
            }

            .menu-icon {
                font-size: 16px;
            }

            .sidebar-bottom {
                padding-top: 8px;
            }

            .logout-link {
                padding: 10px;
                font-size: 12px;
            }

            .main {
                margin-left: 0;
            }

            .topbar {
                height: 65px;
                padding: 0 18px;
            }

            .topbar h3 {
                font-size: 16px;
            }

            .content {
                padding: 23px 16px;
            }

            .welcome h1 {
                font-size: 22px;
            }

        }

        @media (max-width: 420px) {

            .stats-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }

            .stat-card {
                padding: 18px;
            }

            .profile-info {
                display: none;
            }

        }

    </style>

</head>

<body>

<div class="layout">

    <!-- =================================
         SIDEBAR
    ================================== -->

    <aside class="sidebar">

        <div class="brand">

            <div class="brand-icon">📋</div>

            <div>
                <h2>AbsenSmart</h2>
                <small>Administrator Panel</small>
            </div>

        </div>

        <div class="menu-title">MENU UTAMA</div>

       

            
<nav class="menu">

    <a href="dashboard_admin.php" class="active">
        <span class="menu-icon">🏠</span>
        Dashboard
    </a>

    <a href="data_siswa.php">
        <span class="menu-icon">👨‍🎓</span>
        Data Siswa
    </a>

    <a href="guru_bk.php">
        <span class="menu-icon">👨‍🏫</span>
        Data Guru BK
    </a>

    <a href="admin.php">
        <span class="menu-icon">👤</span>
        Manajemen Admin
    </a>

    <a href="absensi.php">
        <span class="menu-icon">📅</span>
        Data Absensi
    </a>

    <a href="surat.php">
        <span class="menu-icon">📄</span>
        Surat Panggilan
    </a>

    <a href="/AbsenSmart/surat.php">
    <span>🏡</span>
    Home Visit
</a>

    <a href="rekap.php">
        <span class="menu-icon">📊</span>
        Rekap Absensi
    </a>

</nav>


       

        <div class="sidebar-bottom">

            <a href="logout.php" class="logout-link"
               onclick="return confirm('Yakin ingin keluar dari akun?')">

                <span class="menu-icon">🚪</span>
                Keluar

            </a>

        </div>

    </aside>


    <!-- =================================
         MAIN
    ================================== -->

    <main class="main">

        <!-- TOPBAR -->

        <header class="topbar">

            <h3>Dashboard</h3>

            <div class="admin-profile">

                <div class="avatar">👤</div>

                <div class="profile-info">

                    <strong><?= e($namaAdmin) ?></strong>

                    <small>Administrator</small>

                </div>

            </div>

        </header>


        <!-- CONTENT -->

        <section class="content">

            <?php if ($errorDatabase !== ''): ?>

                <div class="alert-error">
                    <?= e($errorDatabase) ?>
                </div>

            <?php endif; ?>


            <!-- WELCOME -->

            <div class="welcome">

                <h1>Selamat Datang, <?= e($namaAdmin) ?>! 👋</h1>

                <p>
                    Berikut ringkasan informasi sistem AbsenSmart.
                    Hari ini, <?= date('d/m/Y') ?>.
                </p>

            </div>


            <!-- STATISTICS -->

            <div class="stats-grid">

                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon pink">👨‍🎓</div>

                    </div>

                    <p>Total Siswa</p>

                    <h2><?= number_format($totalSiswa) ?></h2>

                </div>


                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon cream">👨‍🏫</div>

                    </div>

                    <p>Total Guru BK</p>

                    <h2><?= number_format($totalGuru) ?></h2>

                </div>


                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon orange">📅</div>

                    </div>

                    <p>Absensi Hari Ini</p>

                    <h2><?= number_format($totalAbsensi) ?></h2>

                </div>


                <div class="stat-card">

                    <div class="stat-top">

                        <div class="stat-icon soft-pink">📄</div>

                    </div>

                    <p>Total Surat</p>

                    <h2><?= number_format($totalSurat) ?></h2>

                </div>

            </div>


            <!-- PANELS -->

            <div class="panel-grid">

                <!-- REKAP ABSENSI -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>Rekap Absensi</h3>

                        <span>Hari ini</span>

                    </div>

                    <div class="status-list">

                        <?php if (empty($rekapStatus)): ?>

                            <div class="empty">
                                Belum ada data absensi hari ini.
                            </div>

                        <?php else: ?>

                            <?php foreach ($rekapStatus as $status): ?>

                                <div class="status-item">

                                    <div class="status-name">

                                        <span class="status-dot"></span>

                                        <?= e($status['status']) ?>

                                    </div>

                                    <span class="status-count">
                                        <?= number_format($status['jumlah']) ?>
                                    </span>

                                </div>

                            <?php endforeach; ?>

                        <?php endif; ?>

                    </div>

                </div>


                <!-- INFORMASI TAMBAHAN -->

                <div class="panel">

                    <div class="panel-header">

                        <h3>Informasi Sistem</h3>

                        <span>Ringkasan</span>

                    </div>

                    <div class="status-list">

                        <div class="status-item">

                            <span>Surat izin</span>

                            <strong><?= number_format($totalSurat) ?></strong>

                        </div>

                        <div class="status-item">

                            <span>Kunjungan rumah</span>

                            <strong><?= number_format($totalHomeVisit) ?></strong>

                        </div>

                        <div class="status-item">

                            <span>Total siswa terdaftar</span>

                            <strong><?= number_format($totalSiswa) ?></strong>

                        </div>

                        <div class="status-item">

                            <span>Total guru BK</span>

                            <strong><?= number_format($totalGuru) ?></strong>

                        </div>

                    </div>

                </div>

            </div>


            <!-- ABSENSI TERBARU -->

            <div class="panel" style="margin-top:22px;">

                <div class="panel-header">

                    <h3>Absensi Terbaru</h3>

                    <span>8 data terakhir</span>

                </div>

                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>
                                <th>No.</th>
                                <th>Nama Siswa</th>
                                <th>Kelas</th>
                                <th>Tanggal</th>
                                <th>Status</th>
                                <th>Keterangan</th>
                            </tr>

                        </thead>

                        <tbody>

                            <?php if (empty($absensiTerbaru)): ?>

                                <tr>

                                    <td colspan="6" class="empty">
                                        Belum ada data absensi.
                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php foreach ($absensiTerbaru as $i => $data): ?>

                                    <tr>

                                        <td><?= $i + 1 ?></td>

                                        <td>
                                            <?= e($data['nama_siswa']) ?>
                                        </td>

                                        <td>
                                            <?= e($data['kelas']) ?>
                                        </td>

                                        <td>
                                            <?= e(formatTanggal($data['tanggal'])) ?>
                                        </td>

                                        <td>

                                            <span class="badge-status">
                                                <?= e($data['status']) ?>
                                            </span>

                                        </td>

                                        <td>
                                            <?= e($data['keterangan'] ?: '-') ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </div>

        </section>


        <!-- FOOTER -->

        <footer class="footer">

            &copy; <?= date('Y') ?> AbsenSmart.
            Sistem Informasi Absensi Sekolah.

        </footer>

    </main>

</div>

</body>
</html>
