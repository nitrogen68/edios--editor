<?php
$directory = $_GET['directory'] ?? '';
$file = basename($_GET['file'] ?? '');

if (!$directory || !$file) {
    exit("Parameter tidak lengkap.");
}

// Jika directory diawali /home/... (absolut), pakai langsung
if (strpos($directory, $_SERVER['DOCUMENT_ROOT']) === 0) {
    $fullDir = realpath($directory);
} else {
    // Jika relatif, gabungkan dengan DOCUMENT_ROOT
    $fullDir = realpath($_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($directory, '/'));
}

$filePath = $fullDir . '/' . $file;

if ($fullDir && file_exists($filePath)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    exit;
} else {
    echo "Akses ditolak atau file tidak ditemukan.";
}