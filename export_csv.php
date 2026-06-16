<?php
require_once 'config/config.php';
check_admin(); 

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=otopark_raporu_'.date('d-m-Y').'.csv');

$output = fopen('php://output', 'w');

fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

fputcsv($output, array('Plaka', 'Giriş Zamanı', 'Çıkış Zamanı', 'Ücret (TL)'));

$rows = $db->query("SELECT plate, entry_time, exit_time, price FROM vehicles WHERE status = 'Exited' ORDER BY exit_time DESC")->fetchAll(PDO::FETCH_ASSOC);

foreach ($rows as $row) {
    fputcsv($output, $row);
}
fclose($output);
?>