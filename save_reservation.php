<?php
require_once 'config/config.php';

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $parking_id   = $_POST['parking_id'] ?? null;
    $name         = $_POST['name'] ?? null;
    $surname      = $_POST['surname'] ?? null;
    $plate        = strtoupper(str_replace(' ', '', trim($_POST['plate'] ?? '')));
    $slot_name    = strtoupper(str_replace(' ', '', trim($_POST['slot_name'] ?? ''))); 
    $start_time   = $_POST['start_time'] ?? null;
    $end_time     = $_POST['end_time'] ?? null;
    $email        = $_POST['email'] ?? null;

    $eksik_alanlar = [];
    
    if (empty($parking_id) && $parking_id !== '0') $eksik_alanlar[] = 'Otopark ID (parking_id)';
    if (empty(trim($name ?? '')))                   $eksik_alanlar[] = 'Ad (name)';
    if (empty(trim($surname ?? '')))                $eksik_alanlar[] = 'Soyad (surname)';
    if (empty(trim($plate ?? '')))                  $eksik_alanlar[] = 'Plaka (plate)';
    if (empty(trim($slot_name ?? '')))              $eksik_alanlar[] = 'Slot/Blok (slot_name)';
    if (empty(trim($start_time ?? '')))             $eksik_alanlar[] = 'Giriş Tarihi (start_time)';
    if (empty(trim($end_time ?? '')))               $eksik_alanlar[] = 'Çıkış Tarihi (end_time)';
    if (empty(trim($email ?? '')))                  $eksik_alanlar[] = 'E-posta (email)';

    if (!empty($eksik_alanlar)) {
        $liste = implode(', ', $eksik_alanlar);
        echo json_encode([
            'status' => 'error', 
            'message' => 'Lütfen tüm alanları doldurun. Eksik olanlar: ' . $liste,
            'eksik_alanlar' => $eksik_alanlar
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (preg_match('/\d/', $name) || preg_match('/\d/', $surname)) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Adınız veya soyadınız rakam içeremez!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Geçersiz e-posta adresi girdiniz. Lütfen e-postanızı (örnek: isim@domain.com) doğru formatta yazın.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $current_timestamp = time();
    $start_timestamp   = strtotime($start_time);
    $end_timestamp     = strtotime($end_time);

    if ($start_timestamp < ($current_timestamp - 300)) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Giriş tarihi geçmiş bir zaman olamaz! Lütfen şu anki zamanı veya ileri bir tarihi seçin.'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($end_timestamp < ($start_timestamp + 3600)) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Çıkış saati, giriş saatinden en az 1 saat sonra olmalıdır!'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $check_query = "SELECT COUNT(*) FROM reservations 
                        WHERE parking_id = ? 
                        AND slot_name = ? 
                        AND status IN ('pending', 'approved') 
                        AND start_time < ? 
                        AND end_time > ?";
        
        $check_stmt = $db->prepare($check_query);
        $check_stmt->execute([$parking_id, $slot_name, $end_time, $start_time]);
        
        if ($check_stmt->fetchColumn() > 0) {
            echo json_encode([
                'status' => 'error', 
                'message' => 'Seçtiğiniz slot belirtilen tarih ve saat aralığında zaten dolu veya rezerve edilmiş.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }

        $query = "INSERT INTO reservations (parking_id, name, surname, plate, slot_name, start_time, end_time, status, email) 
                  VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?)";
                  
        $stmt = $db->prepare($query);
        $stmt->execute([
            $parking_id, 
            $name, 
            $surname, 
            $plate, 
            $slot_name, 
            $start_time, 
            $end_time, 
            $email
        ]);
        
        echo json_encode([
            'status' => 'success', 
            'message' => 'Rezervasyon talebiniz başarıyla alındı!'
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error', 
            'message' => 'Veritabanı Hatası: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
} else {
    echo json_encode([
        'status' => 'error', 
        'message' => 'Geçersiz istek yöntemi.'
    ], JSON_UNESCAPED_UNICODE);
}
?>