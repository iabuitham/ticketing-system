<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();

// Force connection to use correct collation
$conn->set_charset("utf8mb4");
$conn->query("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");

$scan_result = '';
$scan_status = '';
$last_scan = '';

// Get today's statistics - FIXED collation issue
$todayScans = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM scan_logs WHERE DATE(scanned_at) = CURDATE()");
if ($result) {
    $todayScans = $result->fetch_assoc()['cnt'];
}

$totalTickets = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM ticket_codes WHERE is_scanned = 0 AND is_active = 1");
if ($result) {
    $totalTickets = $result->fetch_assoc()['cnt'];
}

$totalScanned = 0;
$result = $conn->query("SELECT COUNT(*) as cnt FROM ticket_codes WHERE is_scanned = 1");
if ($result) {
    $totalScanned = $result->fetch_assoc()['cnt'];
}

// Get recent scans - FIXED with explicit collation
$recentScans = [];
$recentQuery = "
    SELECT sl.*, r.name, t.guest_type, t.guest_number
    FROM scan_logs sl
    JOIN reservations r ON CAST(sl.reservation_id AS CHAR) = CAST(r.reservation_id AS CHAR)
    JOIN ticket_codes t ON CAST(sl.ticket_code AS CHAR) = CAST(t.ticket_code AS CHAR)
    ORDER BY sl.scanned_at DESC 
    LIMIT 10
";
$recentResult = $conn->query($recentQuery);
if ($recentResult && $recentResult->num_rows > 0) {
    while ($row = $recentResult->fetch_assoc()) {
        $recentScans[] = $row;
    }
}

// Handle manual ticket entry
if (isset($_POST['scanned_ticket'])) {
    $ticket_code = sanitizeInput($_POST['ticket_code']);
    
    $stmt = $conn->prepare("
        SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id, e.event_name
        FROM ticket_codes t 
        JOIN reservations r ON CAST(t.reservation_id AS CHAR) = CAST(r.reservation_id AS CHAR)
        LEFT JOIN event_settings e ON r.event_id = e.id
        WHERE CAST(t.ticket_code AS CHAR) = ?
    ");
    $stmt->bind_param("s", $ticket_code);
    $stmt->execute();
    $ticket = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    if ($ticket) {
        if ($ticket['is_scanned'] == 1) {
            $scan_status = 'error';
            $scan_result = "❌ Ticket already used!<br>
                           Used at: " . date('M d, Y H:i:s', strtotime($ticket['scanned_at'])) . "<br>
                           Customer: " . htmlspecialchars($ticket['name']);
        } elseif ($ticket['is_active'] == 0) {
            $scan_status = 'error';
            $scan_result = "❌ Ticket has been deactivated!<br>
                           Customer: " . htmlspecialchars($ticket['name']);
        } else {
            $update = $conn->prepare("UPDATE ticket_codes SET is_scanned = 1, scanned_at = NOW() WHERE id = ?");
            $update->bind_param("i", $ticket['id']);
            $update->execute();
            $update->close();
            
            // Check if scan_logs table exists, create if not
            $tableCheck = $conn->query("SHOW TABLES LIKE 'scan_logs'");
            if ($tableCheck->num_rows == 0) {
                $conn->query("
                    CREATE TABLE IF NOT EXISTS scan_logs (
                        id INT(11) AUTO_INCREMENT PRIMARY KEY,
                        ticket_code VARCHAR(50) NOT NULL,
                        reservation_id VARCHAR(50) NOT NULL,
                        scanned_by VARCHAR(100) NOT NULL,
                        scanned_at DATETIME NOT NULL,
                        INDEX idx_ticket (ticket_code),
                        INDEX idx_reservation (reservation_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            $logStmt = $conn->prepare("INSERT INTO scan_logs (ticket_code, reservation_id, scanned_by, scanned_at) VALUES (?, ?, ?, NOW())");
            $logStmt->bind_param("sss", $ticket_code, $ticket['reservation_id'], $_SESSION['admin_username']);
            $logStmt->execute();
            $logStmt->close();
            
            $scan_status = 'success';
            $scan_result = "✅ Ticket Valid!<br>
                           🎫 Type: " . ucfirst($ticket['guest_type']) . " Ticket #" . str_pad($ticket['guest_number'], 3, '0', STR_PAD_LEFT) . "<br>
                           👤 Customer: " . htmlspecialchars($ticket['name']) . "<br>
                           🍽️ Table: " . htmlspecialchars($ticket['table_id'] ?: 'Not assigned') . "<br>
                           📋 Reservation: " . htmlspecialchars($ticket['reservation_id']);
            
            $last_scan = $ticket;
        }
    } else {
        $scan_status = 'error';
        $scan_result = "❌ Invalid Ticket ID!";
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>QR Ticket Scanner - Event Check-in</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        .stat-card {
            background: white;
            border-radius: 20px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .stat-number { font-size: 32px; font-weight: bold; color: #4f46e5; }
        .stat-label { font-size: 13px; color: #64748b; margin-top: 5px; }
        .stat-icon { font-size: 24px; margin-bottom: 10px; color: #4f46e5; }
        
        /* Main Layout */
        .scanner-layout {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 24px;
        }
        
        .card {
            background: white;
            border-radius: 24px;
            padding: 24px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.2);
        }
        
        .header {
            text-align: center;
            margin-bottom: 20px;
        }
        .header h1 { color: #4f46e5; margin-bottom: 5px; }
        .header p { color: #64748b; font-size: 14px; }
        
        /* Scanner */
        .scanner-container {
            background: #0f172a;
            border-radius: 20px;
            overflow: hidden;
            position: relative;
        }
        #reader {
            width: 100%;
        }
        #reader video {
            width: 100%;
            height: auto;
            min-height: 400px;
            object-fit: cover;
        }
        .scan-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            pointer-events: none;
        }
        .scan-frame {
            width: 70%;
            height: 70%;
            border: 3px solid rgba(79, 70, 229, 0.8);
            border-radius: 20px;
            box-shadow: 0 0 0 1000px rgba(0,0,0,0.4);
            animation: pulse 1.5s infinite;
        }
        @keyframes pulse {
            0% { border-color: rgba(79, 70, 229, 0.8); transform: scale(1); }
            50% { border-color: rgba(79, 70, 229, 1); transform: scale(1.02); }
            100% { border-color: rgba(79, 70, 229, 0.8); transform: scale(1); }
        }
        
        /* Camera Controls */
        .camera-controls {
            display: flex;
            gap: 10px;
            margin-top: 15px;
            justify-content: center;
        }
        .btn {
            padding: 10px 20px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s;
        }
        .btn-primary { background: #4f46e5; color: white; }
        .btn-primary:hover { transform: translateY(-2px); background: #4338ca; }
        .btn-success { background: #10b981; color: white; }
        .btn-success:hover { transform: translateY(-2px); background: #059669; }
        .btn-danger { background: #ef4444; color: white; }
        .btn-secondary { background: #64748b; color: white; }
        
        /* Result Panel */
        .result-panel {
            background: #f8fafc;
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .result-success { background: linear-gradient(135deg, #d1fae5, #a7f3d0); border-left: 4px solid #10b981; }
        .result-error { background: linear-gradient(135deg, #fee2e2, #fecaca); border-left: 4px solid #ef4444; }
        .result-pending { background: linear-gradient(135deg, #fef3c7, #fde68a); border-left: 4px solid #f59e0b; }
        
        .scan-animation {
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        /* Recent Scans */
        .recent-scans {
            max-height: 300px;
            overflow-y: auto;
        }
        .scan-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        .scan-icon { font-size: 20px; }
        .scan-info { flex: 1; }
        .scan-name { font-weight: 600; font-size: 14px; }
        .scan-time { font-size: 11px; color: #64748b; }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .tab-btn {
            padding: 8px 16px;
            background: none;
            border: none;
            cursor: pointer;
            font-weight: 600;
            color: #64748b;
            border-radius: 8px;
            transition: all 0.2s;
        }
        .tab-btn.active {
            color: #4f46e5;
            background: #e0e7ff;
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s ease; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .manual-area {
            text-align: center;
            padding: 30px;
        }
        .manual-input {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            font-family: monospace;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            text-align: center;
        }
        .manual-input:focus {
            outline: none;
            border-color: #4f46e5;
        }
        
        @media (max-width: 1024px) {
            .scanner-layout { grid-template-columns: 1fr; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: 1fr; }
            .card { padding: 16px; }
        }
        
        .sound-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: #1e293b;
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            cursor: pointer;
            z-index: 1000;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
    </style>
</head>
<body>
<div class="container">
    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div class="stat-number" id="remainingCount"><?php echo $totalTickets; ?></div>
            <div class="stat-label">Remaining Tickets</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-check-circle"></i></div>
            <div class="stat-number" id="scannedCount"><?php echo $totalScanned; ?></div>
            <div class="stat-label">Total Scanned</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div class="stat-number" id="scannedTodayCount"><?php echo $todayScans; ?></div>
            <div class="stat-label">Today's Check-ins</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon"><i class="bi bi-percent"></i></div>
            <div class="stat-number" id="percentageCount">0%</div>
            <div class="stat-label">Check-in Rate</div>
        </div>
    </div>
    
    <div class="scanner-layout">
        <!-- Scanner Section -->
        <div class="card">
            <div class="header">
                <h1><i class="bi bi-upc-scan"></i> QR Ticket Scanner</h1>
                <p>Position QR code in the frame to validate ticket</p>
            </div>
            
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('camera')"><i class="bi bi-camera"></i> Camera Scan</button>
                <button class="tab-btn" onclick="switchTab('manual')"><i class="bi bi-keyboard"></i> Manual Entry</button>
            </div>
            
            <!-- Camera Tab -->
            <div id="cameraTab" class="tab-content active">
                <div class="scanner-container">
                    <div id="reader"></div>
                    <div class="scan-overlay">
                        <div class="scan-frame"></div>
                    </div>
                </div>
                <div class="camera-controls">
                    <button onclick="startCamera()" class="btn btn-primary" id="startCameraBtn">
                        <i class="bi bi-play-fill"></i> Start Camera
                    </button>
                    <button onclick="stopCamera()" class="btn btn-danger" id="stopCameraBtn" style="display: none;">
                        <i class="bi bi-stop-fill"></i> Stop Camera
                    </button>
                </div>
            </div>
            
            <!-- Manual Tab -->
            <div id="manualTab" class="tab-content">
                <form method="POST" id="manualForm">
                    <div class="manual-area">
                        <i class="bi bi-upc-scan" style="font-size: 48px; color: #4f46e5;"></i>
                        <p style="margin: 15px 0; color: #64748b;">Enter ticket code manually</p>
                        <input type="text" name="ticket_code" id="ticket_code" class="manual-input" placeholder="Enter ticket ID" autocomplete="off">
                        <button type="submit" name="scanned_ticket" class="btn btn-primary" style="width: 100%; margin-top: 15px;">
                            <i class="bi bi-check-circle"></i> Validate Ticket
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- Results Section -->
        <div>
            <!-- Scan Result -->
            <div class="card" id="resultCard" style="<?php echo !$scan_result ? 'display: none;' : ''; ?>">
                <div class="result-panel <?php echo $scan_status == 'success' ? 'result-success' : ($scan_status == 'error' ? 'result-error' : 'result-pending'); ?> scan-animation" id="resultPanel">
                    <?php if ($scan_result): ?>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <i class="bi bi-<?php echo $scan_status == 'success' ? 'check-circle-fill' : 'x-circle-fill'; ?>" style="font-size: 40px;"></i>
                            <div style="flex: 1;">
                                <?php echo $scan_result; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Recent Scans -->
            <div class="card">
                <h3 style="margin-bottom: 15px;"><i class="bi bi-clock-history"></i> Recent Check-ins</h3>
                <div class="recent-scans" id="recentScansList">
                    <?php foreach ($recentScans as $scan): ?>
                    <div class="scan-item">
                        <div class="scan-icon"><i class="bi bi-check-circle-fill" style="color: #10b981;"></i></div>
                        <div class="scan-info">
                            <div class="scan-name"><?php echo htmlspecialchars($scan['name']); ?></div>
                            <div class="scan-time"><?php echo date('H:i:s', strtotime($scan['scanned_at'])); ?> - <?php echo ucfirst($scan['guest_type']); ?> Ticket</div>
                        </div>
                        <div><i class="bi bi-chevron-right"></i></div>
                    </div>
                    <?php endforeach; ?>
                    <?php if (empty($recentScans)): ?>
                        <div style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="bi bi-inbox" style="font-size: 48px;"></i>
                            <p>No scans yet</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<button class="sound-toggle" id="soundToggle" onclick="toggleSound()">
    <i class="bi bi-volume-up-fill"></i>
</button>

<script>
    let html5QrCode = null;
    let isScanning = false;
    let soundEnabled = localStorage.getItem('scannerSound') !== 'false';
    
    function playBeep() {
        if (!soundEnabled) return;
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.frequency.value = 880;
            gainNode.gain.value = 0.3;
            oscillator.start();
            gainNode.gain.exponentialRampToValueAtTime(0.00001, audioContext.currentTime + 0.3);
            oscillator.stop(audioContext.currentTime + 0.3);
            if (audioContext.state === 'suspended') audioContext.resume();
        } catch(e) {}
    }
    
    function playErrorBeep() {
        if (!soundEnabled) return;
        try {
            const audioContext = new (window.AudioContext || window.webkitAudioContext)();
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            oscillator.frequency.value = 440;
            gainNode.gain.value = 0.3;
            oscillator.start();
            gainNode.gain.exponentialRampToValueAtTime(0.00001, audioContext.currentTime + 0.5);
            oscillator.stop(audioContext.currentTime + 0.5);
            if (audioContext.state === 'suspended') audioContext.resume();
        } catch(e) {}
    }
    
    function toggleSound() {
        soundEnabled = !soundEnabled;
        localStorage.setItem('scannerSound', soundEnabled);
        const soundBtn = document.getElementById('soundToggle');
        soundBtn.innerHTML = soundEnabled ? '<i class="bi bi-volume-up-fill"></i>' : '<i class="bi bi-volume-mute-fill"></i>';
    }
    
    function switchTab(tab) {
        document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        
        if (tab === 'camera') {
            document.getElementById('cameraTab').classList.add('active');
            document.querySelector('.tab-btn').classList.add('active');
            if (!isScanning) startCamera();
        } else {
            document.getElementById('manualTab').classList.add('active');
            document.querySelectorAll('.tab-btn')[1].classList.add('active');
            stopCamera();
            document.getElementById('ticket_code')?.focus();
        }
    }
    
    async function startCamera() {
        if (isScanning) return;
        
        const startBtn = document.getElementById('startCameraBtn');
        const stopBtn = document.getElementById('stopCameraBtn');
        
        html5QrCode = new Html5Qrcode("reader");
        
        const qrCodeSuccessCallback = (decodedText) => {
            stopCamera();
            processTicket(decodedText);
        };
        
        const config = { fps: 10, qrbox: { width: 300, height: 300 }, aspectRatio: 1.0 };
        
        try {
            await html5QrCode.start({ facingMode: "environment" }, config, qrCodeSuccessCallback);
            isScanning = true;
            startBtn.style.display = 'none';
            stopBtn.style.display = 'inline-flex';
        } catch (err) {
            alert("Unable to access camera. Please check permissions.");
            startBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
        }
    }
    
    function stopCamera() {
        if (html5QrCode && isScanning) {
            html5QrCode.stop().then(() => {
                isScanning = false;
                document.getElementById('startCameraBtn').style.display = 'inline-flex';
                document.getElementById('stopCameraBtn').style.display = 'none';
            }).catch(err => console.log(err));
        }
    }
    
    async function processTicket(ticketCode) {
        const resultCard = document.getElementById('resultCard');
        const resultPanel = document.getElementById('resultPanel');
        
        resultCard.style.display = 'block';
        resultPanel.innerHTML = '<div style="text-align: center; padding: 20px;"><i class="bi bi-hourglass-split"></i> Processing ticket...</div>';
        resultPanel.className = 'result-panel result-pending scan-animation';
        
        try {
            const response = await fetch('ajax_validate_ticket.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ ticket_code: ticketCode })
            });
            const data = await response.json();
            
            if (data.status === 'success') {
                playBeep();
                resultPanel.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <i class="bi bi-check-circle-fill" style="font-size: 48px; color: #10b981;"></i>
                        <div style="flex: 1;">
                            <strong style="font-size: 18px;">✅ Entry Granted!</strong><br>
                            ${data.details.replace(/\n/g, '<br>')}
                        </div>
                    </div>
                    <hr style="margin: 15px 0;">
                    <div style="display: flex; gap: 10px; justify-content: center;">
                        <button onclick="location.reload()" class="btn btn-primary btn-sm">
                            <i class="bi bi-camera"></i> Scan Next
                        </button>
                    </div>
                `;
                resultPanel.className = 'result-panel result-success scan-animation';
                updateStats();
                addToRecentScans(data);
            } else {
                playErrorBeep();
                resultPanel.innerHTML = `
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <i class="bi bi-x-circle-fill" style="font-size: 48px; color: #ef4444;"></i>
                        <div style="flex: 1;">
                            <strong style="font-size: 18px;">${data.message}</strong><br>
                            ${data.details || ''}
                        </div>
                    </div>
                    <hr style="margin: 15px 0;">
                    <div style="display: flex; gap: 10px; justify-content: center;">
                        <button onclick="startCamera()" class="btn btn-primary btn-sm">
                            <i class="bi bi-camera"></i> Try Again
                        </button>
                    </div>
                `;
                resultPanel.className = 'result-panel result-error scan-animation';
            }
        } catch (error) {
            playErrorBeep();
            resultPanel.innerHTML = `
                <div style="text-align: center;">
                    <i class="bi bi-exclamation-triangle-fill" style="font-size: 48px; color: #ef4444;"></i>
                    <p style="margin-top: 10px;">Error: ${error.message}</p>
                    <button onclick="startCamera()" class="btn btn-primary" style="margin-top: 15px;">Try Again</button>
                </div>
            `;
            resultPanel.className = 'result-panel result-error scan-animation';
        }
        
        setTimeout(() => {
            if (!isScanning && document.getElementById('cameraTab').classList.contains('active')) {
                startCamera();
            }
        }, 3000);
    }
    
    function updateStats() {
        fetch('ajax_get_scanner_stats.php')
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('remainingCount').innerText = data.remaining;
                    document.getElementById('scannedCount').innerText = data.total_scanned;
                    document.getElementById('scannedTodayCount').innerText = data.scanned_today;
                    const total = data.remaining + data.total_scanned;
                    const percentage = total > 0 ? Math.round((data.scanned_today / total) * 100) : 0;
                    document.getElementById('percentageCount').innerText = percentage + '%';
                }
            })
            .catch(err => console.log(err));
    }
    
    function addToRecentScans(ticket) {
        const container = document.getElementById('recentScansList');
        const newItem = document.createElement('div');
        newItem.className = 'scan-item';
        newItem.innerHTML = `
            <div class="scan-icon"><i class="bi bi-check-circle-fill" style="color: #10b981;"></i></div>
            <div class="scan-info">
                <div class="scan-name">${ticket.customer || 'Customer'}</div>
                <div class="scan-time">Just now - ${ticket.ticket_type || 'Ticket'} Ticket</div>
            </div>
            <div><i class="bi bi-chevron-right"></i></div>
        `;
        if (container.firstChild) {
            container.insertBefore(newItem, container.firstChild);
        } else {
            container.appendChild(newItem);
        }
        if (container.children.length > 10) container.removeChild(container.lastChild);
    }
    
    document.getElementById('manualForm')?.addEventListener('submit', function() {
        setTimeout(() => {
            document.getElementById('ticket_code').value = '';
            document.getElementById('ticket_code').focus();
        }, 100);
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
        if (isMobile) startCamera();
        document.getElementById('ticket_code')?.focus();
        
        const soundBtn = document.getElementById('soundToggle');
        soundBtn.innerHTML = soundEnabled ? '<i class="bi bi-volume-up-fill"></i>' : '<i class="bi bi-volume-mute-fill"></i>';
        
        const total = parseInt(document.getElementById('remainingCount')?.innerText || 0) + parseInt(document.getElementById('scannedCount')?.innerText || 0);
        const scanned = parseInt(document.getElementById('scannedTodayCount')?.innerText || 0);
        const percentage = total > 0 ? Math.round((scanned / total) * 100) : 0;
        document.getElementById('percentageCount').innerText = percentage + '%';
    });
    
    window.addEventListener('beforeunload', () => {
        if (html5QrCode && isScanning) html5QrCode.stop();
    });
</script>
</body>
</html>