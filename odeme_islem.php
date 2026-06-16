<?php
require_once 'config/config.php';

$id = $_POST['id'] ?? null;

if (!$id) {
    echo "<script>alert('Hata: ID bilgisi formdan gelmedi! POST verisini kontrol et.'); window.location.href='index.php';</script>";
    exit;
}

try {
    $stmt_res = $db->prepare("SELECT id, plate, fee FROM reservations WHERE id = ?");
    $stmt_res->execute([$id]);
    $res = $stmt_res->fetch(PDO::FETCH_ASSOC);

    if ($res) {
        $plaka = $res['plate'];
        $ucret = $res['fee'];

        $db->beginTransaction();

        $stmt = $db->prepare("UPDATE reservations SET payment_status = '3D Ödendi', park_status = 'Tamamlandı' WHERE id = ?");
        $stmt->execute([$id]);

        $stmt_exit = $db->prepare("UPDATE vehicles SET status = 'Exited', exit_time = NOW(), total_fee = ?, price = ? WHERE plate = ? AND status = 'Inside'");
        $stmt_exit->execute([$ucret, $ucret, $plaka]);

        $db->commit();

        echo "<script>alert('Ödeme başarılı! Çıkışınız yapıldı.'); window.location.href='index.php';</script>";
    } else {
        echo "<script>alert('Hata: ID ($id) ile eşleşen rezervasyon bulunamadı.'); window.location.href='index.php';</script>";
    }
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo "<script>alert('Sistem Hatası: " . $e->getMessage() . "'); window.location.href='index.php';</script>";
}
?>