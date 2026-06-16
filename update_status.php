<?php
require_once 'config/config.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id'], $_POST['status'])) {
    $id = $_POST['id'];
    $status = $_POST['status'];

    try {
        $stmt = $db->prepare("UPDATE reservations SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $status, 'id' => $id]);

        if ($status == 'approved' || $status == 'rejected') {
            $userStmt = $db->prepare("SELECT email, name FROM reservations WHERE id = :id");
            $userStmt->execute(['id' => $id]);
            $user = $userStmt->fetch(PDO::FETCH_ASSOC);

            if ($user && !empty($user['email'])) {
                $mail = new PHPMailer(true);

                $mail->isSMTP();
                $mail->Host = 'smtp.gmail.com';
                $mail->SMTPAuth = true;
                $mail->Username = 'omerxeonn@gmail.com'; 
                $mail->Password = 'rtrn dhcx ipxk ifgt'; 
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port = 587;
                $mail->CharSet = 'UTF-8';

                $mail->setFrom('rezervasyon@smartpark.com', 'SmartPark İzmir');
                $mail->addAddress($user['email']);
                $mail->isHTML(true);

                $mail->Subject = ($status == 'approved') ? 'Rezervasyonunuz Onaylandı!' : 'Rezervasyon Bilgisi';
                $mail->Body = ($status == 'approved') 
                    ? "<h2>Onaylandı!</h2><p>Sayın " . $user['name'] . ", rezervasyonunuz onaylanmıştır.</p>" 
                    : "<h2>Bilgi</h2><p>Sayın " . $user['name'] . ", talebiniz hakkında güncelleme yapılmıştır.</p>";
                
                $mail->send();
            }
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
    exit;
}
?>