<?php
require_once 'config/config.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

try {
    $total_capacity = $db->query("SELECT SUM(total_capacity) FROM parking_lots")->fetchColumn() ?: 0;
    
    $active_v = $db->query("SELECT COUNT(*) FROM vehicles WHERE status = 'Inside'")->fetchColumn() ?: 0;
    
    $active_r = $db->query("
        SELECT COUNT(*) FROM reservations 
        WHERE status IN ('pending', 'approved')
        AND plate NOT IN (SELECT plate FROM vehicles WHERE status = 'Inside')
    ")->fetchColumn() ?: 0;

    $active_vehicles = $active_v + $active_r;

    $earnings_v = $db->query("SELECT IFNULL(SUM(price), 0) FROM vehicles WHERE status = 'Exited'")->fetchColumn() ?: 0;
    
    $earnings_r = 0;
    try {
        $earnings_r = $db->query("SELECT IFNULL(SUM(fee), 0) FROM reservations WHERE payment_status IN ('Ödendi', '3D Ödendi', 'Odenmedi')")->fetchColumn() ?: 0;
    } catch (Exception $e) {
        try {
            $earnings_r = $db->query("SELECT IFNULL(SUM(price), 0) FROM reservations WHERE payment_status IN ('Ödendi', '3D Ödendi', 'Odenmedi')")->fetchColumn() ?: 0;
        } catch (Exception $ex2) {
            $earnings_r = 0; 
        }
    }
    
    $total_earnings = $earnings_v + $earnings_r;

    echo json_encode([
        'active' => (int)$active_vehicles,
        'free' => (int)($total_capacity - $active_vehicles),
        'earnings' => number_format($total_earnings, 2)
    ]);

} catch (Exception $e) {
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
        'active' => 0,
        'free' => 0,
        'earnings' => "0.00"
    ]);
}
?>