<?php
require_once 'config/config.php';
check_admin();
error_reporting(E_ALL);
ini_set('display_errors', 1);

$id = $_GET['id'] ?? '';

if ($id) {
    try {
        $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
        $stmt->execute([$id]);
        $rez = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($rez) {
            $full_name = trim(($rez['name'] ?? '') . ' ' . ($rez['surname'] ?? ''));
            $owner_name = !empty($full_name) ? $full_name : 'Belirtilmemiş';
            $slot_name = !empty($rez['slot_name']) ? $rez['slot_name'] : 'Belirtilmemiş';
            $parking_id = !empty($rez['parking_id']) ? $rez['parking_id'] : 1;
            $cleanPlate = str_replace(' ', '', $rez['plate']);
            $calculatedFee = isset($rez['fee']) ? floatval($rez['fee']) : 50.00;

            $check = $db->prepare("SELECT id FROM vehicles WHERE REPLACE(plate, ' ', '') = ? AND status = 'Inside'");
            $check->execute([$cleanPlate]);
            
            if (!$check->fetch()) {
                $sql = "INSERT INTO vehicles (plate, owner_name, parking_id, slot_name, status, entry_time) 
                        VALUES (:plate, :owner_name, :parking_id, :slot_name, 'Inside', NOW())";
                $insert = $db->prepare($sql);
                $insert->execute([
                    ':plate'       => $rez['plate'],
                    ':owner_name'  => $owner_name,
                    ':parking_id'  => $parking_id,
                    ':slot_name'   => $slot_name
                ]);
            }

            $stmt_exit = $db->prepare("UPDATE vehicles SET status = 'Exited', exit_time = NOW(), price = ? WHERE REPLACE(plate, ' ', '') = ? AND status = 'Inside'");
            $stmt_exit->execute([$calculatedFee, $cleanPlate]);

            try {
                $db->prepare("UPDATE reservations SET payment_status = 'Ödendi', status = 'Tamamlandı', park_status = 'Tamamlandı' WHERE id = ?")->execute([$id]);
            } catch (Exception $e) {
                $db->prepare("UPDATE reservations SET payment_status = 'Ödendi', status = 'Tamamlandı' WHERE id = ?")->execute([$id]);
            }

            try {
                $insertLog = $db->prepare("INSERT INTO vehicle_logs (plate, parking_name, action, date) VALUES (?, ?, 'Exit', NOW())");
                $insertLog->execute([$rez['plate'], 'SmartPark Otoparkı']);
            } catch (Exception $log_e) {}

            echo "<script>alert('Nakit Ödeme Onaylandı!'); window.location.href='admin.php';</script>";
        } else {
            echo "<script>alert('Rezervasyon bulunamadı!'); window.history.back();</script>";
        }
    } catch (Exception $e) {
        echo "<script>alert('İşlem sırasında hata: " . $e->getMessage() . "'); window.history.back();</script>";
    }
} else {
    echo "<script>alert('Geçersiz işlem!');</script>";
}
?>