<?php
include 'config/config.php';
$plate = $_GET['plate'];
header('Content-Type: application/json');

$plate = $_GET['plate'] ?? '';
if(empty($plate)) {
    echo json_encode(['status' => 'Normal_Giris']);
    exit;
}

$stmt = $db->prepare("SELECT park_status, payment_status FROM reservations WHERE REPLACE(plate, ' ', '') = REPLACE(?, ' ', '') ORDER BY id DESC LIMIT 1");
$stmt->execute([$plate]);
$res = $stmt->fetch(PDO::FETCH_ASSOC);

if ($res) {
    if ($res['park_status'] === 'Nakit Onayı Bekliyor') {
        echo json_encode(['status' => 'Nakit_Bekliyor']);
    } elseif (($res['payment_status'] === 'Ödendi' || $res['payment_status'] === '3D Ödendi') && $res['park_status'] === 'Park Halinde') {
        echo json_encode(['status' => 'Odedi_Cikis_Yapabilir']);
    } else {
        echo json_encode(['status' => 'Normal_Giris']);
    }
} else {
    echo json_encode(['status' => 'Normal_Giris']);
}
exit;
?>