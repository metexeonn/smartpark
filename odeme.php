<?php
require_once 'config/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id > 0) {
    $stmt = $db->prepare("SELECT * FROM reservations WHERE id = ?");
    $stmt->execute([$id]);
    $rezervasyon = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($rezervasyon) {
        $ucret = $rezervasyon['fee'];
        $plaka = $rezervasyon['plate'];
    } else {
        die("Rezervasyon bulunamadı.");
    }
} else {
    header("Location: index.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Güvenli Ödeme - SmartPark İzmir</title>
    <style>
        :root { 
            --bg: #0f172a; 
            --card: #1e293b; 
            --primary: #10b981; 
            --text: #f8fafc;
            --input-bg: #080e1a;
        }

        body { 
            background: var(--bg); font-family: 'Inter', system-ui, sans-serif; 
            display: flex; justify-content: center; align-items: center; 
            min-height: 100vh; margin: 0; color: var(--text); 
        }
        
        .wrapper { 
            background: var(--card); width: 100%; max-width: 400px; padding: 32px; 
            border-radius: 20px; border: 1px solid rgba(255,255,255,0.05); 
            box-shadow: 0 20px 60px rgba(0,0,0,0.6); position: relative; overflow: hidden;
        }

        .wrapper::before { content: ""; position: absolute; top: -50%; left: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(16, 185, 129, 0.05) 0%, transparent 70%); pointer-events: none; }

        .header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; z-index: 1; position: relative; }
        .logo { font-weight: 800; font-size: 1.2rem; }

        .amount-box { 
            text-align: center; background: #0f172a; padding: 20px; border-radius: 12px; 
            margin-bottom: 25px; border: 1px solid #334155; position: relative;
        }

        label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: #94a3b8; margin-bottom: 8px; display: block; font-weight: 700; }
        
        input { 
            width: 100%; padding: 15px; margin-bottom: 15px; border: 1px solid #334155; border-radius: 12px; 
            background: var(--input-bg); color: white; box-sizing: border-box; font-size: 16px; transition: 0.3s;
        }
        
        input:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2); outline: none; }
        
        .btn-pay { 
            background: linear-gradient(90deg, #10b981, #059669); color: white; 
            border: none; padding: 16px; width: 100%; border-radius: 12px; 
            font-weight: 700; cursor: pointer; transition: 0.4s; font-size: 16px;
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        .btn-pay:hover { transform: scale(1.02); box-shadow: 0 6px 20px rgba(16, 185, 129, 0.5); }
        
        .security { text-align: center; font-size: 0.75rem; color: #64748b; margin-top: 20px; }
    </style>
</head>
<body>

<div class="wrapper">
    <div class="header">
        <div class="logo">SmartPark Ödeme</div>
        <div style="font-size: 0.75rem; color: #64748b;">SSL Korumalı</div>
    </div>

    <div class="amount-box">
        <div style="font-size: 0.8rem; color: #10b981; margin-bottom: 5px;">Ödenecek Tutar</div>
        <div style="font-size: 1.8rem; font-weight: 800;"><?= htmlspecialchars($ucret) ?> TL</div>
        <div style="font-size: 0.8rem; opacity: 0.7; margin-top: 5px;">Plaka: <?= htmlspecialchars($plaka) ?></div>
    </div>

    <form action="odeme_islem.php" method="POST">
        <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
        <input type="hidden" name="plaka" value="<?= htmlspecialchars($plaka) ?>">
        
        <label>Kart Sahibi</label>
        <input type="text" placeholder="Ad Soyad" required oninput="this.value = this.value.replace(/[^a-zA-ZçğıöşüÇĞİÖŞÜ\s]/g, '')">
        
        <label>Kart Numarası</label>
        <input type="text" placeholder="0000 0000 0000 0000" maxlength="19" required 
               oninput="
                   let v = this.value.replace(/\D/g, ''); 
                   let matches = v.match(/\d{1,4}/g);
                   if (matches) {
                       this.value = matches.join(' ');
                   } else {
                       this.value = v;
                   }
               "
               onkeydown="
                   if(event.key === 'Backspace' && this.value.endsWith(' ')) {
                       event.preventDefault();
                       this.value = this.value.substring(0, this.value.length - 1);
                   }
               ">
        
        <div style="display: flex; gap: 15px;">
            <div style="flex: 2;">
                <label>Son Kullanma</label>
                <input type="text" placeholder="AA/YY" maxlength="5" required 
                       oninput="
                           let v = this.value.replace(/\D/g, ''); 
                           if (v.length >= 2) {
                               this.value = v.substring(0,2) + '/' + v.substring(2,4);
                           } else {
                               this.value = v;
                           }
                       "
                       onkeydown="
                           if(event.key === 'Backspace' && this.value.endsWith('/')) {
                               event.preventDefault();
                               this.value = this.value.substring(0, this.value.length - 1);
                           }
                       ">
            </div>
            <div style="flex: 1;">
                <label>CVV</label>
                <input type="password" placeholder="***" maxlength="3" required oninput="this.value = this.value.replace(/[^0-9]/g, '')">
            </div>
        </div>

        <button type="submit" name="odeme_tamamla" class="btn-pay">Ödemeyi Tamamla</button>
    </form>

    <div class="security">🔒 256-bit SSL ile şifrelenmiştir.</div>
</div>

</body>
</html>