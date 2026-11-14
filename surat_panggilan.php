
<?php
require_once 'config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_id']) && !isset($_SESSION['guru_id'])) {
    header('Location: login.php');
    exit;
}

$idSurat = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$idSurat) {
    exit('ID surat tidak valid.');
}

$stmt = $pdo->prepare("
    SELECT
        s.*,
        sw.nama_siswa,
        sw.kelas
    FROM surat s
    JOIN siswa sw ON sw.id_siswa = s.id_siswa
    WHERE s.id_surat = ?
    LIMIT 1
");
$stmt->execute([$idSurat]);
$surat = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$surat) {
    exit('Data surat tidak ditemukan.');
}

function e($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tanggalIndonesia($tanggal) {
    $bulan = [
        1 => 'Januari', 'Februari', 'Maret', 'April',
        'Mei', 'Juni', 'Juli', 'Agustus',
        'September', 'Oktober', 'November', 'Desember'
    ];

    $waktu = strtotime($tanggal);

    return date('d', $waktu) . ' ' .
        $bulan[(int)date('n', $waktu)] . ' ' .
        date('Y', $waktu);
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Surat Panggilan</title>

<style>
* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    background: #eee;
    font-family: "Times New Roman", serif;
    color: #000;
}

.print-area {
    width: 210mm;
    min-height: 297mm;
    margin: auto;
    padding: 18mm 20mm;
    background: white;
    font-size: 12pt;
}

.kop-surat {
    display: grid;
    grid-template-columns: 90px 1fr 90px;
    align-items: center;
    text-align: center;
    padding-bottom: 12px;
    border-bottom: 4px double #000;
}

.kop-logo img {
    width: 80px;
    height: 90px;
    object-fit: contain;
}

.kop-identitas div {
    margin: 3px 0;
}

.kop-dinas {
    font-size: 13pt;
}

.kop-kabupaten {
    font-size: 15pt;
    font-weight: bold;
}

.kop-sekolah {
    font-size: 19pt;
    font-weight: bold;
}

.kop-alamat,
.kop-npsn,
.kop-email {
    font-size: 9pt;
}

.nomor-surat {
    margin-top: 25px;
    text-align: center;
    font-weight: bold;
    text-decoration: underline;
}

.isi-surat {
    margin-top: 30px;
    line-height: 1.7;
    text-align: justify;
}

.data-siswa {
    margin: 15px 0 15px 35px;
    border-collapse: collapse;
}

.data-siswa td {
    padding: 3px 8px;
    vertical-align: top;
}

.print-signature {
    width: 250px;
    margin: 45px 0 0 auto;
    text-align: center;
    line-height: 1.5;
}

.tombol-cetak {
    display: block;
    margin: 20px auto;
    padding: 12px 25px;
    border: 0;
    border-radius: 8px;
    background: #2563eb;
    color: white;
    cursor: pointer;
}

@media print {
    @page {
        size: A4;
        margin: 0;
    }

    body {
        padding: 0;
        background: white;
    }

    .print-area {
        width: 210mm;
        min-height: 297mm;
        margin: 0;
        padding: 18mm 20mm;
    }

    .tombol-cetak {
        display: none;
    }
}
</style>
</head>

<body>

<button class="tombol-cetak" onclick="window.print()">
    Cetak Surat
</button>

<div class="print-area">

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
            <div class="kop-email">Email: smkn2bangkalan301@gmail.com</div>
        </div>

        <div></div>
    </div>

    <div class="nomor-surat">
        SURAT PANGGILAN
    </div>

    <p>Nomor: <?= e($surat['nomor_surat']) ?></p>

    <div class="isi-surat">
        <p>Yth. Bapak/Ibu Orang Tua/Wali dari:</p>

        <table class="data-siswa">
            <tr>
                <td>Nama</td>
                <td>:</td>
                <td><?= e($surat['nama_siswa']) ?></td>
            </tr>
            <tr>
                <td>Kelas</td>
                <td>:</td>
                <td><?= e($surat['kelas']) ?></td>
            </tr>
            <tr>
                <td>NISN</td>
                <td>:</td>
                <td>........................................</td>
            </tr>
        </table>

        <p>
            Dengan hormat,
        </p>

        <p>
            Sehubungan dengan <?= e($surat['alasan']) ?>,
            kami mengharapkan kehadiran Bapak/Ibu Orang Tua/Wali
            siswa tersebut untuk datang ke SMK Negeri 2 Bangkalan
            guna membicarakan dan menyelesaikan permasalahan
            yang berkaitan dengan siswa.
        </p>

        <p>
            Demikian surat panggilan ini kami sampaikan.
            Atas perhatian dan kerja sama Bapak/Ibu, kami
            mengucapkan terima kasih.
        </p>
    </div>

    <div class="print-signature">
        <p>Bangkalan, <?= tanggalIndonesia($surat['tanggal_surat']) ?></p>
        <p>Hormat Kami,</p>
        <br><br><br>
        <p><strong>Kepala SMK Negeri 2 Bangkalan</strong></p>
    </div>

</div>

</body>
</html>
