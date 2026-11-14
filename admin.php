
<?php
session_start();
require_once 'config.php';

// ========================================
// CEK HAK AKSES
// ========================================

if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php");
    exit;
}

$adminLogin = (int) $_SESSION['admin_id'];

// ========================================
// FUNGSI KEAMANAN
// ========================================

function e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

if (empty($_SESSION['csrf_admin'])) {
    $_SESSION['csrf_admin'] = bin2hex(random_bytes(32));
}

$csrf = $_SESSION['csrf_admin'];

function redirectAdmin($pesan, $jenis = 'success') {
    $_SESSION['flash_admin'] = $pesan;
    $_SESSION['flash_admin_jenis'] = $jenis;
    header("Location: admin.php");
    exit;
}

$pesan = $_SESSION['flash_admin'] ?? '';
$jenisPesan = $_SESSION['flash_admin_jenis'] ?? 'success';

unset($_SESSION['flash_admin'], $_SESSION['flash_admin_jenis']);

// ========================================
// PROSES FORM
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!hash_equals($csrf, $_POST['csrf_token'] ?? '')) {
        redirectAdmin('Permintaan tidak valid.', 'error');
    }

    $aksi = $_POST['aksi'] ?? '';

    try {

        // ====================================
        // TAMBAH ADMIN
        // ====================================

        if ($aksi === 'tambah') {

            $nama = trim($_POST['nama_admin'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';

            if ($nama === '' || $username === '' ||
                $email === '' || $password === '') {
                redirectAdmin('Semua kolom wajib diisi.', 'error');
            }

            if (strlen($nama) > 100 || strlen($username) > 50) {
                redirectAdmin('Nama atau username terlalu panjang.', 'error');
            }

            if (strlen($email) > 100 ||
                !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirectAdmin('Format email tidak valid.', 'error');
            }

            if (strlen($password) < 8) {
                redirectAdmin('Password minimal 8 karakter.', 'error');
            }

            $cek = $pdo->prepare("
                SELECT id_admin
                FROM admin
                WHERE username = :username
                OR email = :email
            ");

            $cek->execute([
                ':username' => $username,
                ':email' => $email
            ]);

            if ($cek->fetch()) {
                redirectAdmin('Username atau email sudah digunakan.', 'error');
            }

            $hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = $pdo->prepare("
                INSERT INTO admin (
                    nama_admin,
                    username,
                    password,
                    email,
                    auth_provider,
                    status_akun
                ) VALUES (
                    :nama,
                    :username,
                    :password,
                    :email,
                    'local',
                    'aktif'
                )
            ");

            $stmt->execute([
                ':nama' => $nama,
                ':username' => $username,
                ':password' => $hash,
                ':email' => $email
            ]);

            redirectAdmin('Akun administrator berhasil ditambahkan.');
        }

        // ====================================
        // EDIT ADMIN
        // ====================================

        elseif ($aksi === 'edit') {

            $id = (int) ($_POST['id_admin'] ?? 0);
            $nama = trim($_POST['nama_admin'] ?? '');
            $username = trim($_POST['username'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            $status = $_POST['status_akun'] ?? '';

            if ($id <= 0 || $nama === '' ||
                $username === '' || $email === '') {
                redirectAdmin('Data administrator belum lengkap.', 'error');
            }

            if (strlen($nama) > 100 || strlen($username) > 50) {
                redirectAdmin('Nama atau username terlalu panjang.', 'error');
            }

            if (strlen($email) > 100 ||
                !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                redirectAdmin('Format email tidak valid.', 'error');
            }

            if (!in_array($status, ['aktif', 'nonaktif'], true)) {
                redirectAdmin('Status akun tidak valid.', 'error');
            }

            if ($id === $adminLogin && $status !== 'aktif') {
                redirectAdmin('Kamu tidak bisa menonaktifkan akun sendiri.', 'error');
            }

            if ($password !== '' && strlen($password) < 8) {
                redirectAdmin('Password minimal 8 karakter.', 'error');
            }

            $cek = $pdo->prepare("
                SELECT id_admin
                FROM admin
                WHERE (username = :username OR email = :email)
                AND id_admin != :id
            ");

            $cek->execute([
                ':username' => $username,
                ':email' => $email,
                ':id' => $id
            ]);

            if ($cek->fetch()) {
                redirectAdmin('Username atau email sudah digunakan.', 'error');
            }

            // Periksa metode autentikasi akun
            $cekAkun = $pdo->prepare("
                SELECT auth_provider
                FROM admin
                WHERE id_admin = :id
            ");

            $cekAkun->execute([':id' => $id]);
            $akun = $cekAkun->fetch();

            if (!$akun) {
                redirectAdmin('Akun administrator tidak ditemukan.', 'error');
            }

            if ($password !== '' && $akun['auth_provider'] !== 'local') {
                redirectAdmin('Password akun Google tidak dapat diubah di sini.', 'error');
            }

            if ($password !== '') {

                $hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("
                    UPDATE admin SET
                        nama_admin = :nama,
                        username = :username,
                        email = :email,
                        password = :password,
                        status_akun = :status
                    WHERE id_admin = :id
                ");

                $stmt->execute([
                    ':nama' => $nama,
                    ':username' => $username,
                    ':email' => $email,
                    ':password' => $hash,
                    ':status' => $status,
                    ':id' => $id
                ]);

            } else {

                $stmt = $pdo->prepare("
                    UPDATE admin SET
                        nama_admin = :nama,
                        username = :username,
                        email = :email,
                        status_akun = :status
                    WHERE id_admin = :id
                ");

                $stmt->execute([
                    ':nama' => $nama,
                    ':username' => $username,
                    ':email' => $email,
                    ':status' => $status,
                    ':id' => $id
                ]);
            }

            redirectAdmin('Data administrator berhasil diperbarui.');
        }

        // ====================================
        // HAPUS ADMIN
        // ====================================

        elseif ($aksi === 'hapus') {

            $id = (int) ($_POST['id_admin'] ?? 0);

            if ($id <= 0) {
                redirectAdmin('ID administrator tidak valid.', 'error');
            }

            if ($id === $adminLogin) {
                redirectAdmin('Kamu tidak bisa menghapus akun sendiri.', 'error');
            }

            $stmt = $pdo->prepare("
                DELETE FROM admin
                WHERE id_admin = :id
            ");

            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                redirectAdmin('Akun administrator berhasil dihapus.');
            }

            redirectAdmin('Akun administrator tidak ditemukan.', 'error');
        }

    } catch (PDOException $e) {
        redirectAdmin('Terjadi kesalahan database. Periksa kembali data.', 'error');
    }
}

// ========================================
// DATA EDIT
// ========================================

$dataEdit = null;
$idEdit = (int) ($_GET['edit'] ?? 0);

if ($idEdit > 0) {

    $stmt = $pdo->prepare("
        SELECT id_admin, nama_admin, username, email,
               auth_provider, status_akun
        FROM admin
        WHERE id_admin = :id
    ");

    $stmt->execute([':id' => $idEdit]);
    $dataEdit = $stmt->fetch();

    if (!$dataEdit) {
        redirectAdmin('Data administrator tidak ditemukan.', 'error');
    }
}

// ========================================
// PENCARIAN
// ========================================

$cari = trim($_GET['cari'] ?? '');

$sql = "
    SELECT id_admin, nama_admin, username, email,
           auth_provider, status_akun, created_at
    FROM admin
";

$params = [];

if ($cari !== '') {

    $sql .= "
        WHERE nama_admin LIKE :nama
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

$sql .= " ORDER BY created_at DESC, id_admin DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$daftarAdmin = $stmt->fetchAll();

// ========================================
// STATISTIK
// ========================================

$totalAdmin = count($daftarAdmin);
$totalAktif = 0;
$totalNonaktif = 0;

foreach ($daftarAdmin as $admin) {
    if ($admin['status_akun'] === 'aktif') {
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

<title>Manajemen Admin | AbsenSmart</title>

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
    flex-wrap: wrap;
    margin-bottom: 25px;
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
    padding: 11px 16px;
    border: none;
    border-radius: 9px;
    background: #FFCED8;
    color: #563C42;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
    font-size: 13px;
}

.btn:hover {
    background: #FFBFCB;
}

.btn-danger {
    background: #F8D7DA;
    color: #842029;
}

.btn-secondary {
    background: #EEE;
    color: #444;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 18px;
    margin-bottom: 25px;
}

.stat-card,
.panel {
    background: #FFF;
    border-radius: 15px;
    padding: 23px;
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
    margin-bottom: 25px;
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

.info {
    color: #888;
    font-size: 12px;
    margin-top: 7px;
}

.form-actions {
    display: flex;
    gap: 10px;
    margin-top: 20px;
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
    min-width: 850px;
    border-collapse: collapse;
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
    gap: 7px;
    align-items: center;
}

.aksi .btn {
    padding: 8px 10px;
    font-size: 12px;
}

.empty {
    text-align: center;
    color: #888;
    padding: 30px;
}

@media(max-width:700px) {
    body {
        padding: 15px;
    }

    .stats,
    .form-grid {
        grid-template-columns: 1fr;
    }

    .panel {
        padding: 18px;
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
            <h1>Manajemen Admin</h1>
            <p>Pengelolaan akun administrator AbsenSmart</p>
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
            <p>Total Administrator</p>
            <h2><?= $totalAdmin ?></h2>
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

    <!-- FORM ADMIN -->

    <div class="panel" id="form-admin">

        <h2>
            <?= $dataEdit ? 'Edit Administrator' : 'Tambah Administrator' ?>
        </h2>

        <form method="POST" action="admin.php">

            <input type="hidden" name="csrf_token" value="<?= e($csrf) ?>">

            <input
                type="hidden"
                name="aksi"
                value="<?= $dataEdit ? 'edit' : 'tambah' ?>"
            >

            <?php if ($dataEdit): ?>
                <input
                    type="hidden"
                    name="id_admin"
                    value="<?= (int) $dataEdit['id_admin'] ?>"
                >
            <?php endif; ?>

            <div class="form-grid">

                <div class="form-group">
                    <label>Nama Admin</label>
                    <input
                        type="text"
                        name="nama_admin"
                        maxlength="100"
                        value="<?= e($dataEdit['nama_admin'] ?? '') ?>"
                        placeholder="Masukkan nama admin"
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
                    <label>
                        Password <?= $dataEdit ? '(Opsional)' : '' ?>
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
                            Kosongkan jika tidak ingin mengganti password.
                            Password akun Google tidak dapat diubah di sini.
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
                            >Aktif</option>

                            <option
                                value="nonaktif"
                                <?= $dataEdit['status_akun'] === 'nonaktif' ? 'selected' : '' ?>
                            >Nonaktif</option>
                        </select>
                    </div>
                <?php endif; ?>

            </div>

            <div class="form-actions">
                <button type="submit" class="btn">
                    <?= $dataEdit ? 'Simpan Perubahan' : 'Tambah Admin' ?>
                </button>

                <?php if ($dataEdit): ?>
                    <a href="admin.php" class="btn btn-secondary">Batal</a>
                <?php endif; ?>
            </div>

        </form>
    </div>

    <!-- DAFTAR ADMIN -->

    <div class="panel">

        <h2>Daftar Administrator</h2>

        <form method="GET" action="admin.php" class="search-form">

            <input
                type="text"
                name="cari"
                placeholder="Cari nama, username, atau email..."
                value="<?= e($cari) ?>"
            >

            <button type="submit" class="btn">Cari</button>

            <?php if ($cari !== ''): ?>
                <a href="admin.php" class="btn btn-secondary">Reset</a>
            <?php endif; ?>

        </form>

        <div class="table-wrap">

            <table>
                <thead>
                    <tr>
                        <th>No.</th>
                        <th>Nama Admin</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Login Melalui</th>
                        <th>Status</th>
                        <th>Tanggal Dibuat</th>
                        <th>Aksi</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (count($daftarAdmin) > 0): ?>

                    <?php foreach ($daftarAdmin as $index => $admin): ?>
                        <tr>
                            <td><?= $index + 1 ?></td>

                            <td><?= e($admin['nama_admin']) ?></td>

                            <td><?= e($admin['username'] ?: '-') ?></td>

                            <td><?= e($admin['email']) ?></td>

                            <td><?= e(ucfirst($admin['auth_provider'])) ?></td>

                            <td>
                                <span class="badge <?= $admin['status_akun'] === 'aktif' ? 'aktif' : 'nonaktif' ?>">
                                    <?= e(ucfirst($admin['status_akun'])) ?>
                                </span>
                            </td>

                            <td>
                                <?= e($admin['created_at'] ? date('d-m-Y', strtotime($admin['created_at'])) : '-') ?>
                            </td>

                            <td>
                                <div class="aksi">

                                    <a
                                        href="admin.php?edit=<?= (int) $admin['id_admin'] ?>"
                                        class="btn"
                                    >
                                        Edit
                                    </a>

                                    <?php if ((int) $admin['id_admin'] !== $adminLogin): ?>

                                        <form
                                            method="POST"
                                            action="admin.php"
                                            onsubmit="return confirm('Yakin ingin menghapus akun admin ini?')"
                                        >

                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= e($csrf) ?>"
                                            >

                                            <input type="hidden" name="aksi" value="hapus">

                                            <input
                                                type="hidden"
                                                name="id_admin"
                                                value="<?= (int) $admin['id_admin'] ?>"
                                            >

                                            <button type="submit" class="btn btn-danger">
                                                Hapus
                                            </button>

                                        </form>

                                    <?php else: ?>

                                        <span class="info">Akun sendiri</span>

                                    <?php endif; ?>

                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                <?php else: ?>

                    <tr>
                        <td colspan="8" class="empty">
                            Belum ada data administrator.
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
    const form = document.querySelector('#form-admin');

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
