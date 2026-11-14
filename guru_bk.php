
<?php
session_start();
require_once 'config.php';

// ========================================
// CEK HAK AKSES ADMIN
// ========================================

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

// ========================================
// FUNGSI KEAMANAN
// ========================================

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_guru_bk'])) {
    $_SESSION['csrf_guru_bk'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_guru_bk'];
$pesan = $_SESSION['flash_guru_bk'] ?? '';
$jenisPesan = $_SESSION['flash_jenis_guru_bk'] ?? 'success';

unset($_SESSION['flash_guru_bk'], $_SESSION['flash_jenis_guru_bk']);

function redirectGuruBK($pesan, $jenis = 'success') {
    $_SESSION['flash_guru_bk'] = $pesan;
    $_SESSION['flash_jenis_guru_bk'] = $jenis;
    header("Location: guru_bk.php");
    exit;
}

// ========================================
// PROSES TAMBAH, EDIT, HAPUS
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        redirectGuruBK('Permintaan tidak valid. Silakan coba lagi.', 'error');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {

        // ====================================
        // TAMBAH GURU BK
        // ====================================

        if ($aksi === 'tambah') {

            $nama = trim($_POST['nama_guru'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $noTelepon = trim($_POST['no_telepon'] ?? '');
            $password = $_POST['password'] ?? '';

            if (
                $nama === '' ||
                $username === '' ||
                $email === '' ||
                $password === ''
            ) {
                redirectGuruBK('Nama, username, email, dan password wajib diisi.', 'error');
            }

            if (strlen($nama) > 120 || strlen($username) > 50) {
                redirectGuruBK('Nama atau username melebihi batas karakter.', 'error');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                redirectGuruBK('Format email tidak valid.', 'error');
            }

            if (strlen($password) < 8) {
                redirectGuruBK('Password minimal 8 karakter.', 'error');
            }

            if (strlen($noTelepon) > 20) {
                redirectGuruBK('Nomor telepon maksimal 20 karakter.', 'error');
            }

            $cek = $pdo->prepare("
                SELECT id_guru_bk
                FROM guru_bk
                WHERE username = :username OR email = :email
            ");

            $cek->execute([
                ':username' => $username,
                ':email' => $email
            ]);

            if ($cek->fetch()) {
                redirectGuruBK('Username atau email sudah digunakan.', 'error');
            }

            $hashPassword = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO guru_bk
                (nama_guru, username, password, email, no_telepon, status_akun)
                VALUES
                (:nama, :username, :password, :email, :telepon, 'aktif')
            ");

            $stmt->execute([
                ':nama' => $nama,
                ':username' => $username,
                ':password' => $hashPassword,
                ':email' => $email,
                ':telepon' => $noTelepon !== '' ? $noTelepon : null
            ]);

            redirectGuruBK('Akun Guru BK berhasil ditambahkan.');
        }

        // ====================================
        // EDIT GURU BK
        // ====================================

        elseif ($aksi === 'edit') {

            $id = (int) ($_POST['id_guru_bk'] ?? 0);
            $nama = trim($_POST['nama_guru'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $noTelepon = trim($_POST['no_telepon'] ?? '');
            $password = $_POST['password'] ?? '';
            $status = $_POST['status_akun'] ?? '';

            if (
                $id <= 0 ||
                $nama === '' ||
                $username === '' ||
                $email === ''
            ) {
                redirectGuruBK('Data Guru BK belum lengkap.', 'error');
            }

            if (strlen($nama) > 120 || strlen($username) > 50) {
                redirectGuruBK('Nama atau username melebihi batas karakter.', 'error');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
                redirectGuruBK('Format email tidak valid.', 'error');
            }

            if (strlen($noTelepon) > 20) {
                redirectGuruBK('Nomor telepon maksimal 20 karakter.', 'error');
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                redirectGuruBK('Status akun tidak valid.', 'error');
            }

            if ($password !== '' && strlen($password) < 8) {
                redirectGuruBK('Password baru minimal 8 karakter.', 'error');
            }

            $cek = $pdo->prepare("
                SELECT id_guru_bk
                FROM guru_bk
                WHERE (username = :username OR email = :email)
                AND id_guru_bk != :id
            ");

            $cek->execute([
                ':username' => $username,
                ':email' => $email,
                ':id' => $id
            ]);

            if ($cek->fetch()) {
                redirectGuruBK('Username atau email sudah digunakan akun lain.', 'error');
            }

            if ($password !== '') {

                $hashPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    UPDATE guru_bk SET
                        nama_guru = :nama,
                        username = :username,
                        password = :password,
                        email = :email,
                        no_telepon = :telepon,
                        status_akun = :status
                    WHERE id_guru_bk = :id
                ");

                $stmt->execute([
                    ':nama' => $nama,
                    ':username' => $username,
                    ':password' => $hashPassword,
                    ':email' => $email,
                    ':telepon' => $noTelepon !== '' ? $noTelepon : null,
                    ':status' => $status,
                    ':id' => $id
                ]);

            } else {

                $stmt = $pdo->prepare("
                    UPDATE guru_bk SET
                        nama_guru = :nama,
                        username = :username,
                        email = :email,
                        no_telepon = :telepon,
                        status_akun = :status
                    WHERE id_guru_bk = :id
                ");

                $stmt->execute([
                    ':nama' => $nama,
                    ':username' => $username,
                    ':email' => $email,
                    ':telepon' => $noTelepon !== '' ? $noTelepon : null,
                    ':status' => $status,
                    ':id' => $id
                ]);
            }

            redirectGuruBK('Data Guru BK berhasil diperbarui.');
        }

        // ====================================
        // HAPUS GURU BK
        // ====================================

        elseif ($aksi === 'hapus') {

            $id = (int) ($_POST['id_guru_bk'] ?? 0);

            if ($id <= 0) {
                redirectGuruBK('ID Guru BK tidak valid.', 'error');
            }

            $stmt = $pdo->prepare("
                DELETE FROM guru_bk
                WHERE id_guru_bk = :id
            ");

            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                redirectGuruBK('Akun Guru BK berhasil dihapus.');
            }

            redirectGuruBK('Data Guru BK tidak ditemukan.', 'error');
        }

    } catch (PDOException $e) {
        redirectGuruBK('Terjadi kesalahan database. Periksa kembali data yang dimasukkan.', 'error');
    }
}

// ========================================
// AMBIL DATA UNTUK FORM EDIT
// ========================================

$dataEdit = null;
$idEdit = (int) ($_GET['edit'] ?? 0);

if ($idEdit > 0) {
    $stmt = $pdo->prepare("
        SELECT id_guru_bk, nama_guru, username, email,
               no_telepon, status_akun
        FROM guru_bk
        WHERE id_guru_bk = :id
    ");

    $stmt->execute([':id' => $idEdit]);
    $dataEdit = $stmt->fetch();

    if (!$dataEdit) {
        redirectGuruBK('Data Guru BK tidak ditemukan.', 'error');
    }
}

// ========================================
// PENCARIAN DATA
// ========================================

$cari = trim($_GET['cari'] ?? '');

$sql = "
    SELECT id_guru_bk, nama_guru, username, email,
           no_telepon, status_akun, created_at
    FROM guru_bk
";

$params = [];

if ($cari !== '') {
    $sql .= "
        WHERE nama_guru LIKE :nama
        OR username LIKE :username
        OR email LIKE :email
    ";

    $keyword = '%' . $cari . '%';

    $params = [
        ':nama' => $keyword,
        ':username' => $keyword,
        ':email' => $keyword
    ];
}

$sql .= " ORDER BY created_at DESC, id_guru_bk DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$daftarGuru = $stmt->fetchAll();

// ========================================
// STATISTIK
// ========================================

$totalGuru = count($daftarGuru);

$totalAktif = 0;
$totalNonaktif = 0;

foreach ($daftarGuru as $guru) {
    if ($guru['status_akun'] === 'aktif') {
        $totalAktif++;
    } else {
        $totalNonaktif++;
    }
}

?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Data Guru BK | AbsenSmart</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #FFF4DE;
            color: #333;
            padding: 30px;
        }

        .container {
            max-width: 1200px;
            margin: auto;
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            margin-bottom: 25px;
            flex-wrap: wrap;
        }

        .header h1 {
            color: #563C42;
            font-size: 28px;
            margin-bottom: 7px;
        }

        .header p {
            color: #888;
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            padding: 11px 17px;
            border: none;
            border-radius: 9px;
            background: #FFCED8;
            color: #563C42;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn:hover {
            background: #FFBFCB;
        }

        .btn-danger {
            background: #F8D7DA;
            color: #842029;
        }

        .btn-danger:hover {
            background: #F1BFC5;
        }

        .btn-secondary {
            background: #EEEEEE;
            color: #444;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: #FFF;
            padding: 22px;
            border-radius: 15px;
            box-shadow: 0 4px 15px rgba(0,0,0,.04);
        }

        .stat-card p {
            color: #888;
            font-size: 14px;
            margin-bottom: 10px;
        }

        .stat-card h2 {
            color: #563C42;
            font-size: 27px;
        }

        .panel {
            background: #FFF;
            border-radius: 16px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 4px 15px rgba(0,0,0,.04);
        }

        .panel h2 {
            color: #563C42;
            font-size: 20px;
            margin-bottom: 22px;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 18px;
        }

        .form-group {
            margin-bottom: 5px;
        }

        .form-group label {
            display: block;
            font-size: 14px;
            font-weight: bold;
            margin-bottom: 8px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid #DDD;
            border-radius: 9px;
            font-size: 14px;
            outline: none;
        }

        .form-group input:focus,
        .form-group select:focus {
            border-color: #FFCED8;
            box-shadow: 0 0 0 3px rgba(255,206,216,.25);
        }

        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .info {
            color: #888;
            font-size: 12px;
            margin-top: 7px;
        }

        .alert {
            padding: 14px 17px;
            border-radius: 10px;
            margin-bottom: 20px;
            background: #D1E7DD;
            color: #0F5132;
        }

        .alert.error {
            background: #F8D7DA;
            color: #842029;
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-form input {
            flex: 1;
            min-width: 0;
            padding: 12px 15px;
            border: 1px solid #DDD;
            border-radius: 9px;
            font-size: 14px;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        th, td {
            padding: 14px 12px;
            border-bottom: 1px solid #EEE;
            text-align: left;
            font-size: 13px;
        }

        th {
            background: #FFF4DE;
            color: #563C42;
            font-weight: bold;
        }

        tr:hover td {
            background: #FFFCF7;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
        }

        .aktif {
            background: #D1E7DD;
            color: #0F5132;
        }

        .nonaktif {
            background: #F8D7DA;
            color: #842029;
        }

        .aksi {
            display: flex;
            align-items: center;
            gap: 7px;
        }

        .aksi .btn {
            padding: 8px 11px;
            font-size: 12px;
        }

        .empty {
            text-align: center;
            color: #888;
            padding: 30px;
        }

        @media (max-width: 700px) {
            body {
                padding: 15px;
            }

            .stats {
                grid-template-columns: 1fr;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .panel {
                padding: 18px;
            }

            .header h1 {
                font-size: 23px;
            }

            .search-form {
                flex-wrap: wrap;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <div>
            <h1>Data Guru BK</h1>
            <p>Pengelolaan akun Guru Bimbingan Konseling</p>
        </div>

        <a href="dashboard_admin.php" class="btn btn-secondary">
            ← Kembali ke Dashboard
        </a>
    </div>

    <?php if ($pesan !== ''): ?>
        <div class="alert <?= $jenisPesan === 'error' ? 'error' : '' ?>">
            <?= e($pesan) ?>
        </div>
    <?php endif; ?>

    <div class="stats">

        <div class="stat-card">
            <p>Total Guru BK</p>
            <h2><?= $totalGuru ?></h2>
        </div>

        <div class="stat-card">
            <p>Akun Aktif</p>
            <h2><?= $totalAktif ?></h2>
        </div>

        <div class="stat-card">
            <p>Akun Nonaktif</p>
            <h2><?= $totalNonaktif ?></h2>
        </div>

    </div>

    <!-- FORM TAMBAH / EDIT -->

    <div class="panel" id="form-guru">

        <h2>
            <?= $dataEdit ? 'Edit Data Guru BK' : 'Tambah Guru BK' ?>
        </h2>

        <form method="POST" action="guru_bk.php">

            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <input
                type="hidden"
                name="aksi"
                value="<?= $dataEdit ? 'edit' : 'tambah' ?>"
            >

            <?php if ($dataEdit): ?>
                <input
                    type="hidden"
                    name="id_guru_bk"
                    value="<?= (int) $dataEdit['id_guru_bk'] ?>"
                >
            <?php endif; ?>

            <div class="form-grid">

                <div class="form-group">
                    <label>Nama Guru BK</label>
                    <input
                        type="text"
                        name="nama_guru"
                        maxlength="120"
                        value="<?= e($dataEdit['nama_guru'] ?? '') ?>"
                        placeholder="Masukkan nama lengkap"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Username</label>
                    <input
                        type="text"
                        name="username"
                        maxlength="50"
                        value="<?= e($dataEdit['username'] ?? '') ?>"
                        placeholder="Masukkan username"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Email</label>
                    <input
                        type="email"
                        name="email"
                        maxlength="100"
                        value="<?= e($dataEdit['email'] ?? '') ?>"
                        placeholder="Masukkan email"
                        required
                    >
                </div>

                <div class="form-group">
                    <label>Nomor Telepon</label>
                    <input
                        type="text"
                        name="no_telepon"
                        maxlength="20"
                        value="<?= e($dataEdit['no_telepon'] ?? '') ?>"
                        placeholder="Masukkan nomor telepon"
                    >
                </div>

                <div class="form-group">
                    <label>
                        Password
                        <?= $dataEdit ? '(Opsional)' : '' ?>
                    </label>

                    <input
                        type="password"
                        name="password"
                        minlength="8"
                        placeholder="<?= $dataEdit ? 'Kosongkan jika tidak diganti' : 'Minimal 8 karakter' ?>"
                        <?= $dataEdit ? '' : 'required' ?>
                        autocomplete="new-password"
                    >

                    <?php if ($dataEdit): ?>
                        <p class="info">
                            Isi hanya jika ingin mengganti password.
                        </p>
                    <?php endif; ?>
                </div>

                <?php if ($dataEdit): ?>
                    <div class="form-group">
                        <label>Status Akun</label>

                        <select name="status_akun" required>
                            <option
                                value="aktif"
                                <?= $dataEdit['status_akun'] === 'aktif' ? 'selected' : '' ?>
                            >
                                Aktif
                            </option>

                            <option
                                value="nonaktif"
                                <?= $dataEdit['status_akun'] === 'nonaktif' ? 'selected' : '' ?>
                            >
                                Nonaktif
                            </option>
                        </select>
                    </div>
                <?php endif; ?>

            </div>

            <div class="form-actions">

                <button type="submit" class="btn">
                    <?= $dataEdit ? 'Simpan Perubahan' : 'Tambah Guru BK' ?>
                </button>

                <?php if ($dataEdit): ?>
                    <a href="guru_bk.php" class="btn btn-secondary">
                        Batal
                    </a>
                <?php endif; ?>

            </div>

        </form>

    </div>

    <!-- DAFTAR GURU BK -->

    <div class="panel">

        <h2>Daftar Guru BK</h2>

        <form method="GET" action="guru_bk.php" class="search-form">

            <input
                type="text"
                name="cari"
                placeholder="Cari nama, username, atau email..."
                value="<?= e($cari) ?>"
            >

            <button type="submit" class="btn">
                Cari
            </button>

            <?php if ($cari !== ''): ?>
                <a href="guru_bk.php" class="btn btn-secondary">
                    Reset
                </a>
            <?php endif; ?>

        </form>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Guru BK</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>No. Telepon</th>
                        <th>Status</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($daftarGuru) > 0): ?>

                    <?php foreach ($daftarGuru as $index => $guru): ?>

                        <tr>

                            <td><?= $index + 1 ?></td>

                            <td><?= e($guru['nama_guru']) ?></td>

                            <td><?= e($guru['username']) ?></td>

                            <td><?= e($guru['email']) ?></td>

                            <td><?= e($guru['no_telepon'] ?: '-') ?></td>

                            <td>
                                <span class="badge <?= $guru['status_akun'] === 'aktif' ? 'aktif' : 'nonaktif' ?>">
                                    <?= e(ucfirst($guru['status_akun'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= e($guru['created_at'] ? date('d-m-Y', strtotime($guru['created_at'])) : '-') ?>
                            </td>

                            <td>
                                <div class="aksi">

                                    <a
                                        href="guru_bk.php?edit=<?= (int) $guru['id_guru_bk'] ?>"
                                        class="btn"
                                    >
                                        Edit
                                    </a>

                                    <form
                                        method="POST"
                                        action="guru_bk.php"
                                        onsubmit="return confirm('Yakin ingin menghapus akun Guru BK ini?')"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($csrf) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="aksi"
                                            value="hapus"
                                        >

                                        <input
                                            type="hidden"
                                            name="id_guru_bk"
                                            value="<?= (int) $guru['id_guru_bk'] ?>"
                                        >

                                        <button type="submit" class="btn btn-danger">
                                            Hapus
                                        </button>

                                    </form>

                                </div>
                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            Belum ada data Guru BK.
                        </td>
                    </tr>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </div>

</div>

<?php if ($dataEdit): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('#form-guru');

    if (form) {
        form.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }
});
</script>
<?php endif; ?>

</body>
</html>
