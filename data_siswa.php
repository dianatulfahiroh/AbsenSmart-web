
<?php
session_start();
require_once __DIR__ . '/config.php';

if (!isset($pdo) || !($pdo instanceof PDO)) {
    die('Koneksi database tidak ditemukan.');
}

function e($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

$role = $_SESSION['role'] ?? '';

$validLogin =
    ($role === 'admin' && !empty($_SESSION['admin_id'])) ||
    ($role === 'guru' && !empty($_SESSION['guru_id']));

if (!$validLogin) {
    header('Location: login.php');
    exit;
}

$isAdmin = $role === 'admin';

if (empty($_SESSION['csrf_siswa'])) {
    $_SESSION['csrf_siswa'] = bin2hex(random_bytes(32));
}

$pesan = '';
$error = '';

$cari = trim($_GET['cari'] ?? '');
$filterKelas = trim($_GET['kelas'] ?? '');
$filterJurusan = trim($_GET['jurusan'] ?? '');

$kelasPilihan = [
    'X TITL', 'X RPL', 'X TKR',
    'XI TITL', 'XI RPL',
    'XII TITL', 'XII RPL'
];

$jurusanPilihan = [
    'TITL', 'RPL', 'TKR'
];

$editSiswa = null;

try {

  // PROSES TAMBAH, EDIT, DAN HAPUS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $aksi = $_POST['aksi'] ?? '';
    $idSiswaDikirim = (int)($_POST['id_siswa'] ?? 0);

   

        if (
            !isset($_POST['csrf_token']) ||
            !hash_equals($_SESSION['csrf_siswa'], $_POST['csrf_token'])
        ) {
            throw new RuntimeException('Permintaan tidak valid. Silakan muat ulang halaman.');
        }

        $aksi = $_POST['aksi'] ?? '';

        // TAMBAH DAN EDIT SISWA
        if ($aksi === 'simpan') {

            $id = (int)($_POST['id_siswa'] ?? 0);
            $nisn = trim($_POST['nisn'] ?? '');
            $nama = trim($_POST['nama_siswa'] ?? '');
            $jk = trim($_POST['jenis_kelamin'] ?? '');
            $kelas = trim($_POST['kelas'] ?? '');
            $jurusan = trim($_POST['jurusan'] ?? '');
            $alamat = trim($_POST['alamat'] ?? '');
            $telepon = trim($_POST['no_telepon_wali'] ?? '');

            if ($nama === '') {
                throw new RuntimeException('Nama siswa wajib diisi.');
            }

            if (!in_array($jk, ['Laki-laki', 'Perempuan'], true)) {
                throw new RuntimeException('Pilih jenis kelamin yang benar.');
            }

            if (!in_array($kelas, $kelasPilihan, true)) {
                throw new RuntimeException('Pilih kelas yang tersedia.');
            }

            if (!in_array($jurusan, $jurusanPilihan, true)) {
                throw new RuntimeException('Pilih jurusan yang tersedia.');
            }

            if (strlen($nama) > 120) {
                throw new RuntimeException('Nama siswa maksimal 120 karakter.');
            }

            if ($nisn !== '' && !preg_match('/^[0-9]{1,20}$/', $nisn)) {
                throw new RuntimeException('NISN harus berupa angka maksimal 20 digit.');
            }

            if ($telepon !== '' && strlen($telepon) > 20) {
                throw new RuntimeException('Nomor telepon wali maksimal 20 karakter.');
            }

            // NISN kosong disimpan sebagai NULL
            $nisn = $nisn === '' ? null : $nisn;
            $alamat = $alamat === '' ? null : $alamat;
            $telepon = $telepon === '' ? null : $telepon;

            if ($id > 0) {

                $stmt = $pdo->prepare("
                    UPDATE siswa SET
                        nisn = :nisn,
                        nama_siswa = :nama,
                        jenis_kelamin = :jk,
                        kelas = :kelas,
                        jurusan = :jurusan,
                        alamat = :alamat,
                        no_telepon_wali = :telepon
                    WHERE id_siswa = :id
                ");

                $stmt->execute([
                    'nisn' => $nisn,
                    'nama' => $nama,
                    'jk' => $jk,
                    'kelas' => $kelas,
                    'jurusan' => $jurusan,
                    'alamat' => $alamat,
                    'telepon' => $telepon,
                    'id' => $id
                ]);

                header('Location: data_siswa.php?pesan=edit');
                exit;

            } else {

                $stmt = $pdo->prepare("
                    INSERT INTO siswa (
                        nisn,
                        nama_siswa,
                        jenis_kelamin,
                        kelas,
                        jurusan,
                        alamat,
                        no_telepon_wali
                    ) VALUES (
                        :nisn,
                        :nama,
                        :jk,
                        :kelas,
                        :jurusan,
                        :alamat,
                        :telepon
                    )
                ");

                $stmt->execute([
                    'nisn' => $nisn,
                    'nama' => $nama,
                    'jk' => $jk,
                    'kelas' => $kelas,
                    'jurusan' => $jurusan,
                    'alamat' => $alamat,
                    'telepon' => $telepon
                ]);

                header('Location: data_siswa.php?pesan=tambah');
                exit;
            }
        }

        // HAPUS SISWA
        if ($aksi === 'hapus') {

            $id = (int)($_POST['id_siswa'] ?? 0);

            if ($id <= 0) {
                throw new RuntimeException('Data siswa tidak valid.');
            }

            $stmt = $pdo->prepare("
                DELETE FROM siswa
                WHERE id_siswa = :id
            ");

            $stmt->execute(['id' => $id]);

            header('Location: data_siswa.php?pesan=hapus');
            exit;
        }
    }

    // PESAN BERHASIL
    if (isset($_GET['pesan'])) {
        $pesan = match ($_GET['pesan']) {
            'tambah' => 'Data siswa berhasil ditambahkan.',
            'edit' => 'Data siswa berhasil diperbarui.',
            'hapus' => 'Data siswa berhasil dihapus.',
            default => ''
        };
    }

   // AMBIL DATA UNTUK EDIT
if (($isAdmin || $role === 'guru') && isset($_GET['edit'])) {

        $idEdit = (int)$_GET['edit'];

        $stmt = $pdo->prepare("
            SELECT *
            FROM siswa
            WHERE id_siswa = :id
            LIMIT 1
        ");

        $stmt->execute(['id' => $idEdit]);
        $editSiswa = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$editSiswa) {
            throw new RuntimeException('Data siswa tidak ditemukan.');
        }
    }

    // DAFTAR KELAS
    $daftarKelas = $pdo->query("
        SELECT DISTINCT kelas
        FROM siswa
        WHERE kelas IS NOT NULL AND kelas <> ''
        ORDER BY kelas
    ")->fetchAll(PDO::FETCH_COLUMN);

    // DAFTAR JURUSAN
    $daftarJurusan = $pdo->query("
        SELECT DISTINCT jurusan
        FROM siswa
        WHERE jurusan IS NOT NULL AND jurusan <> ''
        ORDER BY jurusan
    ")->fetchAll(PDO::FETCH_COLUMN);

    // PENCARIAN DAN FILTER
    $sql = "
        SELECT *
        FROM siswa
        WHERE 1=1
    ";

    $params = [];

    if ($cari !== '') {
        $sql .= "
    AND (
        LOWER(TRIM(nama_siswa)) LIKE LOWER(:nama)
        OR TRIM(nisn) LIKE :nisn
    )
";

$params['nama'] = '%' . trim($cari) . '%';
$params['nisn'] = '%' . trim($cari) . '%';
    }

    if ($filterKelas !== '') {
        $sql .= " AND kelas = :kelas";
        $params['kelas'] = $filterKelas;
    }

    if ($filterJurusan !== '') {
        $sql .= " AND jurusan = :jurusan";
        $params['jurusan'] = $filterJurusan;
    }

    $sql .= "
        ORDER BY kelas ASC, nama_siswa ASC
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $daftarSiswa = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // STATISTIK
    $totalSiswa = (int)$pdo->query("
        SELECT COUNT(*) FROM siswa
    ")->fetchColumn();

    $totalKelas = (int)$pdo->query("
        SELECT COUNT(DISTINCT kelas)
        FROM siswa
    ")->fetchColumn();

    $totalJurusan = (int)$pdo->query("
        SELECT COUNT(DISTINCT jurusan)
        FROM siswa
        WHERE jurusan IS NOT NULL AND jurusan <> ''
    ")->fetchColumn();

} catch (Throwable $ex) {

    $error = $ex->getMessage();

    if ($ex instanceof PDOException && $ex->getCode() === '23000') {
        $error = 'NISN sudah digunakan atau data siswa masih terhubung dengan riwayat absensi/surat sehingga tidak dapat dihapus.';
    }
}

$dashboard = $isAdmin
    ? 'dashboard_admin.php'
    : 'dashboard_guru.php';

?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Data Siswa | AbsenSmart</title>

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
    margin: 0 0 7px;
    font-size: 27px;
}

.muted {
    color: var(--muted);
    font-size: 13px;
}

.panel {
    background: white;
    border: 1px solid var(--line);
    border-radius: 16px;
    padding: 22px;
    margin-bottom: 22px;
    box-shadow: 0 5px 18px rgba(80, 50, 50, .04);
}

.section-title {
    font-size: 18px;
    margin: 0 0 18px;
}

.stats {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin-bottom: 22px;
}

.stat-card {
    background: white;
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 19px;
}

.stat-card span {
    color: var(--muted);
    font-size: 13px;
}

.stat-card strong {
    display: block;
    font-size: 27px;
    margin-top: 8px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 7px;
}

.field.full {
    grid-column: 1 / -1;
}

label {
    font-size: 13px;
    font-weight: bold;
}

input,
select,
textarea {
    width: 100%;
    padding: 11px 12px;
    border: 1px solid #e8dada;
    border-radius: 9px;
    font: inherit;
    color: var(--ink);
    background: white;
}

textarea {
    resize: vertical;
    min-height: 80px;
}

input:focus,
select:focus,
textarea:focus {
    outline: none;
    border-color: #d78395;
    box-shadow: 0 0 0 3px rgba(215, 131, 149, .12);
}

.btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 10px 16px;
    border: none;
    border-radius: 9px;
    background: #d78395;
    color: white;
    font-size: 13px;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
}

.btn.secondary {
    background: #fff;
    color: var(--ink);
    border: 1px solid var(--line);
}

.btn.danger {
    background: #c85d67;
}

.btn.small {
    padding: 7px 10px;
    font-size: 12px;
}

.actions {
    display: flex;
    gap: 7px;
    flex-wrap: wrap;
}

.notice {
    padding: 13px 15px;
    border-radius: 10px;
    background: #eaf7ed;
    color: #28633b;
    margin-bottom: 17px;
    font-size: 13px;
}

.notice.error {
    background: #fff0f0;
    color: #a13c3c;
}

.toolbar {
    display: flex;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}

.toolbar input {
    flex: 1;
    min-width: 180px;
}

.toolbar select {
    width: auto;
    min-width: 145px;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th,
td {
    text-align: left;
    padding: 13px 11px;
    border-bottom: 1px solid var(--line);
    font-size: 13px;
    vertical-align: middle;
}

th {
    background: #fff7f5;
    font-size: 12px;
    color: #756464;
}

.empty {
    text-align: center;
    padding: 35px;
    color: var(--muted);
}

.form-footer {
    display: flex;
    gap: 9px;
    margin-top: 18px;
}

@media(max-width: 850px) {
    .sidebar {
        width: 190px;
    }

    .main {
        padding: 20px;
    }
}

@media(max-width: 650px) {
    .layout {
        display: block;
    }

    .sidebar {
        width: 100%;
        padding: 15px;
    }

    .subbrand {
        margin-bottom: 12px;
    }

    .main {
        padding: 15px;
    }

    .stats {
        grid-template-columns: 1fr;
    }

    .form-grid {
        grid-template-columns: 1fr;
    }

    .field.full {
        grid-column: auto;
    }

    .top {
        align-items: flex-start;
    }
}
</style>
</head>

<body>

<div class="layout">

<aside class="sidebar">
    <div class="brand">Absen<span>Smart</span></div>
    <div class="subbrand">Sistem Informasi Absensi Siswa</div>

    <a class="nav" href="<?= e($dashboard) ?>">Dashboard</a>
    <a class="nav" href="absensi.php">Absensi Siswa</a>
    <a class="nav active" href="data_siswa.php">Data Siswa</a>
    <a class="nav" href="surat.php">Surat</a>
</aside>

<main class="main">

<div class="top">
    <div>
        <h1>Data Siswa</h1>
        <div class="muted">
            Pengelolaan data peserta didik SMK Negeri 2 Bangkalan.
        </div>
    </div>

    <a href="<?= e($dashboard) ?>" class="btn secondary">
        Kembali
    </a>
</div>

<?php if ($pesan): ?>
    <div class="notice"><?= e($pesan) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="notice error"><?= e($error) ?></div>
<?php endif; ?>

<!-- STATISTIK -->

<div class="stats">

    <div class="stat-card">
        <span>Total Siswa</span>
        <strong><?= $totalSiswa ?></strong>
    </div>

    <div class="stat-card">
        <span>Total Kelas</span>
        <strong><?= $totalKelas ?></strong>
    </div>

    <div class="stat-card">
        <span>Total Jurusan</span>
        <strong><?= $totalJurusan ?></strong>
    </div>

</div>

<!-- FORM TAMBAH / EDIT -->

<?php if ($isAdmin || $role === 'guru'): ?>

<section class="panel">

    <h2 class="section-title">
        <?= $editSiswa ? 'Edit Data Siswa' : 'Tambah Siswa Baru' ?>
    </h2>

    <form method="post" action="data_siswa.php">

        <input type="hidden" name="aksi" value="simpan">

        <input type="hidden" name="csrf_token"
               value="<?= e($_SESSION['csrf_siswa']) ?>">

        <input type="hidden" name="id_siswa"
               value="<?= (int)($editSiswa['id_siswa'] ?? 0) ?>">

        <div class="form-grid">

            <div class="field">
                <label>NISN</label>
                <input type="text"
                       name="nisn"
                       maxlength="20"
                       inputmode="numeric"
                       value="<?= e($editSiswa['nisn'] ?? '') ?>"
                       placeholder="Masukkan NISN jika tersedia">
            </div>

            <div class="field">
                <label>Nama Lengkap Siswa *</label>
                <input type="text"
                       name="nama_siswa"
                       maxlength="120"
                       required
                       value="<?= e($editSiswa['nama_siswa'] ?? '') ?>"
                       placeholder="Nama lengkap siswa">
            </div>

            <div class="field">
                <label>Jenis Kelamin *</label>
                <select name="jenis_kelamin" required>
                    <option value="">Pilih jenis kelamin</option>
                    <option value="Laki-laki"
                        <?= ($editSiswa['jenis_kelamin'] ?? '') === 'Laki-laki' ? 'selected' : '' ?>>
                        Laki-laki
                    </option>
                    <option value="Perempuan"
                        <?= ($editSiswa['jenis_kelamin'] ?? '') === 'Perempuan' ? 'selected' : '' ?>>
                        Perempuan
                    </option>
                </select>
            </div>

            <div class="field">
                <label>Kelas *</label>
                <select name="kelas" required>
                    <option value="">Pilih kelas</option>

                    <?php foreach ($kelasPilihan as $kelas): ?>
                        <option value="<?= e($kelas) ?>"
                            <?= ($editSiswa['kelas'] ?? '') === $kelas ? 'selected' : '' ?>>
                            <?= e($kelas) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="field">
                <label>Jurusan *</label>
                <select name="jurusan" required>
                    <option value="">Pilih jurusan</option>

                    <?php foreach ($jurusanPilihan as $jurusan): ?>
                        <option value="<?= e($jurusan) ?>"
                            <?= ($editSiswa['jurusan'] ?? '') === $jurusan ? 'selected' : '' ?>>
                            <?= e($jurusan) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="field">
                <label>Nomor Telepon Wali</label>
                <input type="text"
                       name="no_telepon_wali"
                       maxlength="20"
                       value="<?= e($editSiswa['no_telepon_wali'] ?? '') ?>"
                       placeholder="Nomor telepon wali siswa">
            </div>

            <div class="field full">
                <label>Alamat</label>
                <textarea name="alamat"
                          placeholder="Alamat lengkap siswa"><?= e($editSiswa['alamat'] ?? '') ?></textarea>
            </div>

        </div>

        <div class="form-footer">

            <button type="submit" class="btn">
                <?= $editSiswa ? 'Simpan Perubahan' : 'Tambah Siswa' ?>
            </button>

            <?php if ($editSiswa): ?>
                <a href="data_siswa.php" class="btn secondary">
                    Batal
                </a>
            <?php endif; ?>

        </div>

    </form>

</section>

<?php endif; ?>

<!-- DAFTAR SISWA -->

<section class="panel">

    <h2 class="section-title">Daftar Siswa</h2>

    <form method="get" action="data_siswa.php" class="toolbar">

        <input type="text"
               name="cari"
               placeholder="Cari nama atau NISN..."
               value="<?= e($cari) ?>">

        <select name="kelas">
            <option value="">Semua Kelas</option>

            <?php foreach ($daftarKelas as $kelas): ?>
                <option value="<?= e($kelas) ?>"
                    <?= $filterKelas === $kelas ? 'selected' : '' ?>>
                    <?= e($kelas) ?>
                </option>
            <?php endforeach; ?>

        </select>

        <select name="jurusan">
            <option value="">Semua Jurusan</option>

            <?php foreach ($daftarJurusan as $jurusan): ?>
                <option value="<?= e($jurusan) ?>"
                    <?= $filterJurusan === $jurusan ? 'selected' : '' ?>>
                    <?= e($jurusan) ?>
                </option>
            <?php endforeach; ?>

        </select>

        <button type="submit" class="btn">Cari</button>

        <a href="data_siswa.php" class="btn secondary">Reset</a>

    </form>

    <div class="muted" style="margin-bottom:14px">
        Menampilkan <?= count($daftarSiswa) ?> siswa.
    </div>

    <div class="table-wrap">

        <table>

            <thead>
                <tr>
                    <th>No.</th>
                    <th>NISN</th>
                    <th>Nama Siswa</th>
                    <th>Jenis Kelamin</th>
                    <th>Kelas</th>
                    <th>Jurusan</th>
                    <th>Telepon Wali</th>

                    <?php if ($isAdmin || $role === 'guru'): ?>
                        <th>Aksi</th>
                    <?php endif; ?>
                </tr>
            </thead>

            <tbody>

            <?php if (empty($daftarSiswa)): ?>

                <tr>
                    <td colspan="<?= $isAdmin ? 8 : 7 ?>" class="empty">
                        Data siswa tidak ditemukan.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($daftarSiswa as $i => $siswa): ?>

                    <tr>
                        <td><?= $i + 1 ?></td>

                        <td><?= e($siswa['nisn'] ?: '-') ?></td>

                        <td><strong><?= e($siswa['nama_siswa']) ?></strong></td>

                        <td><?= e($siswa['jenis_kelamin']) ?></td>

                        <td><?= e($siswa['kelas']) ?></td>

                        <td><?= e($siswa['jurusan']) ?></td>

                        <td><?= e($siswa['no_telepon_wali'] ?: '-') ?></td>

                        <?php if ($isAdmin || $role === 'guru'): ?>
    <td>
        <div class="actions">

                                    <a class="btn small"
                                       href="data_siswa.php?edit=<?= (int)$siswa['id_siswa'] ?>">
                                        Edit
                                    </a>

                                    <form method="post"
                                          action="data_siswa.php"
                                          onsubmit="return confirm('Yakin ingin menghapus data siswa ini?')">

                                        <input type="hidden" name="aksi" value="hapus">

                                        <input type="hidden" name="csrf_token"
                                               value="<?= e($_SESSION['csrf_siswa']) ?>">

                                        <input type="hidden" name="id_siswa"
                                               value="<?= (int)$siswa['id_siswa'] ?>">

                                        <button type="submit" class="btn small danger">
                                            Hapus
                                        </button>

                                    </form>

                                </div>
                            </td>
                        <?php endif; ?>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

            </tbody>

        </table>

    </div>

</section>

</main>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const params = new URLSearchParams(window.location.search);

    // Jika tombol Edit diklik
    if (params.has('edit')) {
        const formEdit = document.querySelector('.panel form');

        if (formEdit) {
            formEdit.closest('.panel').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }

    // Jika tombol Cari digunakan
    else if (
        params.has('cari') ||
        params.has('kelas') ||
        params.has('jurusan')
    ) {
        const daftar = document.querySelector('.table-wrap');

        if (daftar) {
            daftar.closest('.panel').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        }
    }
});
</script>


</body>
</html>
