
<?php
require_once 'config.php';

$pesan = '';
$berhasil = false;
$setupAllowed = false;

$nama = '';
$username = '';
$email = '';

// ========================================
// CEK ADMIN
// ========================================

try {
    $jumlahAdmin = (int) $pdo
        ->query("SELECT COUNT(*) FROM admin")
        ->fetchColumn();

    $setupAllowed = ($jumlahAdmin === 0);

} catch (PDOException $e) {
    die("Gagal memeriksa database admin.");
}

// ========================================
// PROSES PENDAFTARAN
// ========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $setupAllowed) {

    $nama = trim($_POST['nama_admin'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (
        $nama === '' ||
        $username === '' ||
        $email === '' ||
        $password === ''
    ) {
        $pesan = "Semua kolom wajib diisi.";

    } elseif (strlen($nama) > 100) {
        $pesan = "Nama maksimal 100 karakter.";

    } elseif (strlen($username) > 50) {
        $pesan = "Username maksimal 50 karakter.";

    } elseif (strlen($email) > 100 ||
              !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $pesan = "Format email tidak valid.";

    } elseif (strlen($password) < 8) {
        $pesan = "Password minimal 8 karakter.";

    } else {

        try {

            // Cek username dan email
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

                $pesan = "Username atau email sudah digunakan.";

            } else {

                // Cek ulang jumlah admin
                $jumlahAdmin = (int) $pdo
                    ->query("SELECT COUNT(*) FROM admin")
                    ->fetchColumn();

                if ($jumlahAdmin > 0) {

                    $setupAllowed = false;
                    $pesan = "Akun admin sudah tersedia.";

                } else {

                    // Enkripsi password
                    $hashPassword = password_hash(
                        $password,
                        PASSWORD_DEFAULT
                    );

                    // Simpan akun
                    $sql = "
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
                    ";

                    $stmt = $pdo->prepare($sql);

                    $stmt->execute([
                        ':nama' => $nama,
                        ':username' => $username,
                        ':password' => $hashPassword,
                        ':email' => $email
                    ]);

                    $berhasil = true;
                    $setupAllowed = false;

                    $pesan = "Akun administrator berhasil dibuat!";
                }
            }

        } catch (PDOException $e) {

            $pesan = "Terjadi kesalahan saat menyimpan akun.";
        }
    }
}

// ========================================
// KEAMANAN OUTPUT
// ========================================

function e($value) {
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES,
        'UTF-8'
    );
}
?>

<!DOCTYPE html>
<html lang="id">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width, initial-scale=1.0">

    <title>Buat Akun Admin | AbsenSmart</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;
            background: #FFF4DE;

            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            padding: 25px;
        }

        .container {
            width: 100%;
            max-width: 650px;

            background: #FFFFFF;

            padding: 40px;

            border-radius: 22px;

            box-shadow: 0 8px 30px rgba(0,0,0,0.07);
        }

        /* HEADER */

        .header {
            text-align: center;
            margin-bottom: 35px;
        }

        .header h1 {
            font-size: 38px;
            color: #563C42;
            margin-bottom: 12px;
        }

        .header p {
            font-size: 18px;
            color: #888888;
        }

        /* FORM */

        .form-group {
            margin-bottom: 24px;
        }

        .form-group label {
            display: block;
            font-size: 17px;
            color: #333333;
            margin-bottom: 10px;
        }

        .form-group input {
            width: 100%;
            height: 56px;

            padding: 0 17px;

            border: 1px solid #DDDDDD;
            border-radius: 13px;

            font-size: 16px;

            outline: none;
            transition: 0.2s;
        }

        .form-group input:focus {
            border-color: #FFCED8;

            box-shadow: 0 0 0 3px
                rgba(255,206,216,0.3);
        }

        /* PASSWORD */

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 60px;
        }

        .toggle-password {
            position: absolute;

            top: 50%;
            right: 10px;

            transform: translateY(-50%);

            width: 42px;
            height: 42px;

            border: none;
            border-radius: 50%;

            background: transparent;

            display: flex;
            align-items: center;
            justify-content: center;

            font-size: 21px;

            cursor: pointer;

            z-index: 5;
        }

        .toggle-password:hover {
            background: #FFF4DE;
        }

        /* TOMBOL DAFTAR */

        .btn-submit {
            width: 100%;
            height: 56px;

            background: #FFCED8;
            color: #333333;

            border: none;
            border-radius: 13px;

            font-size: 18px;
            font-weight: bold;

            cursor: pointer;
            transition: 0.2s;
        }

        .btn-submit:hover {
            background: #FFDDDD;
            transform: translateY(-2px);
        }

        /* NOTIFIKASI */

        .pesan {
            padding: 16px;
            margin-bottom: 25px;

            border-radius: 12px;

            background: #FFDDDD;
            color: #563C42;

            text-align: center;
            font-size: 15px;
        }

        .success {
            background: #FFF4DE;
        }

        /* RESPONSIVE */

        @media (max-width: 600px) {

            body {
                padding: 15px;
            }

            .container {
                padding: 28px 20px;
            }

            .header h1 {
                font-size: 30px;
            }

            .header p {
                font-size: 15px;
            }

            .form-group label {
                font-size: 15px;
            }

            .form-group input {
                height: 52px;
            }

            .btn-submit {
                height: 52px;
                font-size: 16px;
            }
        }

    </style>

</head>

<body>

    <div class="container">

        <!-- HEADER -->

        <div class="header">

            <h1>AbsenSmart</h1>

            <p>Pembuatan Akun Administrator</p>

        </div>


        <!-- NOTIFIKASI -->

        <?php if ($pesan !== ''): ?>

            <div class="pesan <?= $berhasil ? 'success' : '' ?>">

                <?= e($pesan) ?>

            </div>

        <?php endif; ?>


        <?php if ($berhasil): ?>

            <p style="text-align:center;">
                Akun administrator berhasil dibuat.
                Silakan lanjut ke halaman login.
            </p>

        <?php elseif (!$setupAllowed): ?>

            <p style="text-align:center;">
                Akun administrator sudah tersedia.
                Halaman pendaftaran admin pertama telah ditutup.
            </p>

        <?php else: ?>

            <form method="POST" autocomplete="off">

                <!-- NAMA -->

                <div class="form-group">

                    <label for="nama_admin">
                        Nama
                    </label>

                    <input
                        type="text"
                        id="nama_admin"
                        name="nama_admin"
                        placeholder="Masukkan nama"
                        maxlength="100"
                        value="<?= e($nama) ?>"
                        required
                    >

                </div>


                <!-- USERNAME -->

                <div class="form-group">

                    <label for="username">
                        Username
                    </label>

                    <input
                        type="text"
                        id="username"
                        name="username"
                        placeholder="Buat username"
                        maxlength="50"
                        value="<?= e($username) ?>"
                        autocomplete="off"
                        required
                    >

                </div>


                <!-- EMAIL -->

                <div class="form-group">

                    <label for="email">
                        Email
                    </label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        placeholder="Masukkan email"
                        maxlength="100"
                        value="<?= e($email) ?>"
                        required
                    >

                </div>


                <!-- PASSWORD -->

                <div class="form-group">

                    <label for="password">
                        Password
                    </label>

                    <div class="password-wrapper">

                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="Minimal 8 karakter"
                            minlength="8"
                            autocomplete="new-password"
                            required
                        >

                        <button
                            type="button"
                            class="toggle-password"
                            id="togglePassword"
                            aria-label="Tampilkan password"
                        >
                            👁️
                        </button>

                    </div>

                </div>


                <!-- SUBMIT -->

                <button
                    type="submit"
                    class="btn-submit"
                >
                    Buat Akun Admin
                </button>

            </form>

        <?php endif; ?>

    </div>


    <!-- JAVASCRIPT -->

    <script>

        document.addEventListener('DOMContentLoaded', function() {

            const password =
                document.getElementById('password');

            const tombol =
                document.getElementById('togglePassword');

            if (password && tombol) {

                tombol.addEventListener('click', function() {

                    const sedangTersembunyi =
                        password.type === 'password';

                    password.type =
                        sedangTersembunyi ? 'text' : 'password';

                    tombol.textContent =
                        sedangTersembunyi ? '🙈' : '👁️';

                    tombol.setAttribute(
                        'aria-label',
                        sedangTersembunyi
                            ? 'Sembunyikan password'
                            : 'Tampilkan password'
                    );

                });

            }

        });

    </script>

</body>
</html>
