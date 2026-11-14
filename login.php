
<?php
session_start();
require_once 'config.php';

// ==========================================
// JIKA SUDAH LOGIN
// ==========================================

if (isset($_SESSION['admin_id'])) {
    header("Location: dashboard_admin.php");
    exit;
}

if (isset($_SESSION['guru_id'])) {
    header("Location: dashboard_guru.php");
    exit;
}

// ==========================================
// VARIABEL
// ==========================================

$pesan = '';
$identity = '';

// ==========================================
// PROSES LOGIN
// ==========================================

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $identity = trim($_POST['identity'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($identity === '' || $password === '') {

        $pesan = "Username/email dan password wajib diisi.";

    } else {

        try {

            $loginBerhasil = false;

            // ==================================
            // CEK AKUN ADMIN
            // ==================================

            $queryAdmin = $pdo->prepare("
                SELECT *
                FROM admin
                WHERE (username = :username OR email = :email)
                AND auth_provider = 'local'
                LIMIT 1
            ");

            $queryAdmin->execute([
                ':username' => $identity,
                ':email' => $identity
            ]);

            $admin = $queryAdmin->fetch(PDO::FETCH_ASSOC);

            if (
                $admin &&
                password_verify($password, $admin['password']) &&
                $admin['status_akun'] === 'aktif'
            ) {

                session_regenerate_id(true);

                $_SESSION['admin_id'] = $admin['id_admin'];
                $_SESSION['nama'] = $admin['nama_admin'];
                $_SESSION['username'] = $admin['username'];
                $_SESSION['email'] = $admin['email'];
                $_SESSION['role'] = 'admin';

                header("Location: dashboard_admin.php");
                exit;
            }

            // ==================================
            // CEK AKUN GURU BK
            // ==================================

            $queryGuru = $pdo->prepare("
                SELECT *
                FROM guru_bk
                WHERE username = :username
                OR email = :email
                LIMIT 1
            ");

            $queryGuru->execute([
                ':username' => $identity,
                ':email' => $identity
            ]);

            $guru = $queryGuru->fetch(PDO::FETCH_ASSOC);

            if (
                $guru &&
                password_verify($password, $guru['password']) &&
                $guru['status_akun'] === 'aktif'
            ) {

                $_SESSION = [];

session_regenerate_id(true);

$_SESSION['guru_id'] = $guru['id_guru_bk'];
                $_SESSION['nama'] = $guru['nama_guru'];
                $_SESSION['username'] = $guru['username'];
                $_SESSION['email'] = $guru['email'];
                $_SESSION['role'] = 'guru';

                header("Location: dashboard_guru.php");
                exit;
            }

            // ==================================
            // LOGIN GAGAL
            // ==================================

            $pesan = "Username/email atau password salah, atau akun tidak aktif.";

        } catch (PDOException $e) {

            $pesan = "Terjadi kesalahan pada sistem. Silakan coba kembali.";

        }
    }
}

// ==========================================
// FUNGSI ESCAPE
// ==========================================

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

    <title>Login | AbsenSmart</title>

    <style>

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, sans-serif;

            min-height: 100vh;

            display: flex;
            align-items: center;
            justify-content: center;

            background: #FFF4DE;

            padding: 20px;
        }

        .login-container {
            width: 100%;
            max-width: 480px;

            background: #FFFFFF;

            padding: 45px 40px;

            border-radius: 24px;

            box-shadow: 0 10px 35px
                rgba(80, 50, 50, 0.08);
        }

        /* HEADER */

        .header {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo {
            width: 75px;
            height: 75px;

            background: #FFCED8;

            border-radius: 22px;

            display: flex;
            align-items: center;
            justify-content: center;

            margin: 0 auto 18px;

            font-size: 35px;
        }

        .header h1 {
            font-size: 35px;
            color: #563C42;

            margin-bottom: 10px;
        }

        .header p {
            color: #888888;
            font-size: 15px;
            line-height: 1.5;
        }

        /* FORM */

        .form-group {
            margin-bottom: 23px;
        }

        .form-group label {
            display: block;

            margin-bottom: 10px;

            font-size: 15px;
            font-weight: 600;

            color: #444444;
        }

        .form-group input {
            width: 100%;
            height: 54px;

            padding: 0 16px;

            border: 1px solid #DDDDDD;
            border-radius: 12px;

            outline: none;

            font-size: 15px;

            transition: 0.2s;
        }

        .form-group input:focus {
            border-color: #FFCED8;

            box-shadow: 0 0 0 3px
                rgba(255, 206, 216, 0.3);
        }

        /* PASSWORD */

        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .password-wrapper input {
            padding-right: 55px;
        }

        .toggle-password {
            position: absolute;

            right: 8px;
            top: 50%;

            transform: translateY(-50%);

            width: 40px;
            height: 40px;

            display: flex;
            align-items: center;
            justify-content: center;

            background: transparent;

            border: none;
            border-radius: 50%;

            font-size: 20px;

            cursor: pointer;
            z-index: 5;
        }

        .toggle-password:hover {
            background: #FFF4DE;
        }

        /* BUTTON */

        .btn-login {
            width: 100%;
            height: 55px;

            background: #FFCED8;

            border: none;
            border-radius: 12px;

            font-size: 17px;
            font-weight: bold;

            color: #563C42;

            cursor: pointer;

            transition: 0.2s;
        }

        .btn-login:hover {
            background: #FFDDDD;
            transform: translateY(-2px);
        }

        /* ERROR */

        .alert {
            background: #FFDDDD;

            color: #813C48;

            padding: 14px;

            border-radius: 10px;

            margin-bottom: 22px;

            font-size: 14px;
            line-height: 1.5;

            text-align: center;
        }

        /* FOOTER */

        .footer {
            text-align: center;

            margin-top: 28px;

            font-size: 13px;
            color: #999999;
        }

        /* RESPONSIVE */

        @media (max-width: 480px) {

            .login-container {
                padding: 35px 23px;
            }

            .header h1 {
                font-size: 30px;
            }

            .logo {
                width: 65px;
                height: 65px;
                font-size: 30px;
            }

        }

    </style>

</head>

<body>

    <div class="login-container">

        <!-- HEADER -->

        <div class="header">

            <div class="logo">
                📋
            </div>

            <h1>AbsenSmart</h1>

            <p>
                Sistem Informasi Absensi Sekolah
                <br>
                Silakan masuk ke akun Anda
            </p>

        </div>


        <!-- PESAN ERROR -->

        <?php if ($pesan !== ''): ?>

            <div class="alert">
                <?= e($pesan) ?>
            </div>

        <?php endif; ?>


        <!-- FORM LOGIN -->

        <form method="POST" action="" autocomplete="on">

            <div class="form-group">

                <label for="identity">
                    Username atau Email
                </label>

                <input
                    type="text"
                    id="identity"
                    name="identity"
                    placeholder="Masukkan username atau email"
                    value="<?= e($identity) ?>"
                    autocomplete="username"
                    required
                >

            </div>


            <div class="form-group">

                <label for="password">
                    Password
                </label>

                <div class="password-wrapper">

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Masukkan password"
                        autocomplete="current-password"
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


            <button type="submit" class="btn-login">
                Masuk
            </button>

        </form>


        <div class="footer">

            &copy; <?= date('Y') ?> AbsenSmart.
            All Rights Reserved.

        </div>

    </div>


    <!-- JAVASCRIPT -->

    <script>

        document.addEventListener('DOMContentLoaded', function() {

            const password =
                document.getElementById('password');

            const tombol =
                document.getElementById('togglePassword');

            tombol.addEventListener('click', function() {

                const tersembunyi =
                    password.type === 'password';

                password.type =
                    tersembunyi ? 'text' : 'password';

                tombol.textContent =
                    tersembunyi ? '🙈' : '👁️';

                tombol.setAttribute(
                    'aria-label',
                    tersembunyi
                        ? 'Sembunyikan password'
                        : 'Tampilkan password'
                );

            });

        });

    </script>

</body>
</html>
