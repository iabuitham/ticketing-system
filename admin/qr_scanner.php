<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit();
}

$conn = getConnection();
$scan_result = '';
$scan_status = '';

// Handle manual ticket entry
if (isset($_POST['scanned_ticket'])) {
    $ticket_code = sanitizeInput($_POST['ticket_code']);
    
    // Find ticket
    $stmt = $conn->prepare("SELECT t.*, r.name, r.phone, r.table_id, r.reservation_id 
                            FROM ticket_codes t 
                            JOIN reservations r ON t.reservation_id = r.reservation_id 
                            WHERE t.ticket_code = ?");
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
        } else {
            // Mark as scanned
            $update = $conn->prepare("UPDATE ticket_codes SET is_scanned = 1, scanned_at = NOW() WHERE id = ?");
            $update->bind_param("i", $ticket['id']);
            $update->execute();
            $update->close();
            
            $scan_status = 'success';
            $scan_result = "✅ Ticket Valid!<br>
                           Ticket Type: " . ucfirst($ticket['guest_type']) . "<br>
                           Customer: " . htmlspecialchars($ticket['name']) . "<br>
                           Table: " . htmlspecialchars($ticket['table_id']) . "<br>
                           Reservation: " . htmlspecialchars($ticket['reservation_id']);
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
    <title>QR Code Scanner - Ticketing System</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- Include html5-qrcode library for camera scanning -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        .container { max-width: 700px; margin: 0 auto; }
        .card {
            background: white;
            border-radius: 24px;
            padding: 30px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .header {
            text-align: center;
            margin-bottom: 25px;
        }
        .header h1 { color: #4f46e5; margin-bottom: 10px; }
        .header p { color: #64748b; font-size: 14px; }
        
        /* Camera Scanner */
        .scanner-container {
            background: #0f172a;
            border-radius: 16px;
            overflow: hidden;
            margin-bottom: 20px;
            position: relative;
        }
        #reader {
            width: 100%;
            border: none;
        }
        #reader video {
            border-radius: 16px;
            width: 100%;
        }
        .scanner-overlay {
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
            width: 80%;
            height: 80%;
            border: 2px solid rgba(255,255,255,0.5);
            border-radius: 20px;
            box-shadow: 0 0 0 1000px rgba(0,0,0,0.3);
        }
        
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 10px;
        }
        .tab-btn {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            color: #64748b;
            transition: all 0.2s;
            border-radius: 8px;
        }
        .tab-btn.active {
            color: #4f46e5;
            background: #e0e7ff;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .manual-area {
            text-align: center;
            padding: 30px;
            background: #f8fafc;
            border-radius: 16px;
        }
        .manual-icon { font-size: 60px; color: #4f46e5; margin-bottom: 20px; }
        input {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            border: 2px solid #e2e8f0;
            border-radius: 12px;
            text-align: center;
            font-family: monospace;
        }
        input:focus {
            outline: none;
            border-color: #4f46e5;
        }
        .btn {
            padding: 12px 24px;
            border-radius: 12px;
            border: none;
            cursor: pointer;
            font-weight: 600;
            margin-top: 15px;
            transition: all 0.2s;
        }
        .btn-primary { background: #4f46e5; color: white; width: 100%; }
        .btn-primary:hover { transform: translateY(-2px); }
        .result {
            margin-top: 20px;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            animation: slideIn 0.3s ease;
        }
        @keyframes slideIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .result.success { background: #d1fae5; color: #065f46; border-left: 4px solid #10b981; }
        .result.error { background: #fee2e2; color: #991b1b; border-left: 4px solid #ef4444; }
        .result-info {
            margin-top: 10px;
            font-size: 14px;
        }
        .btn-back {
            display: inline-block;
            margin-top: 20px;
            text-decoration: none;
            color: #4f46e5;
        }
        .camera-controls {
            display: flex;
            gap: 10px;
            margin-top: 10px;
            justify-content: center;
        }
        .camera-controls .btn {
            margin-top: 0;
            padding: 8px 16px;
            font-size: 14px;
        }
        .btn-secondary {
            background: #64748b;
            color: white;
        }
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            margin-top: 10px;
        }
        @media (max-width: 768px) {
            .container { padding: 0; }
            .card { padding: 20px; }
            .scan-frame { width: 90%; height: 70%; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <h1><i class="bi bi-upc-scan"></i> QR Code Scanner</h1>
                <p>Scan QR code to validate tickets at the entrance</p>
            </div>
            
            <div class="tabs">
                <button class="tab-btn active" onclick="switchTab('camera')">
                    <i class="bi bi-camera"></i> Camera Scan
                </button>
                <button class="tab-btn" onclick="switchTab('manual')">
                    <i class="bi bi-keyboard"></i> Manual Entry
                </button>
            </div>
            
            <!-- Camera Scanner Tab -->
            <div id="cameraTab" class="tab-content active">
                <div class="scanner-container">
                    <div id="reader"></div>
                    <div class="scanner-overlay">
                        <div class="scan-frame"></div>
                    </div>
                </div>
                <div class="camera-controls">
                    <button onclick="startCamera()" class="btn btn-secondary" id="startCameraBtn">
                        <i class="bi bi-play-fill"></i> Start Camera
                    </button>
                    <button onclick="stopCamera()" class="btn btn-danger" id="stopCameraBtn" style="display: none;">
                        <i class="bi bi-stop-fill"></i> Stop Camera
                    </button>
                </div>
                <div class="demo-note" style="margin-top: 15px; background: #e0e7ff; padding: 10px; border-radius: 12px; font-size: 12px; text-align: center;">
                    <i class="bi bi-info-circle"></i> 
                    Position the QR code in front of the camera. The ticket will be validated automatically.
                </div>
            </div>
            
            <!-- Manual Entry Tab -->
            <div id="manualTab" class="tab-content">
                <form method="POST" id="manualForm">
                    <div class="manual-area">
                        <div class="manual-icon">
                            <i class="bi bi-upc-scan"></i>
                        </div>
                        <input type="text" name="ticket_code" id="ticket_code" placeholder="Enter ticket ID" autocomplete="off">
                        <button type="submit" name="scanned_ticket" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Validate Ticket
                        </button>
                    </div>
                </form>
            </div>
            
            <?php if ($scan_result): ?>
                <div class="result <?php echo $scan_status; ?>">
                    <?php echo $scan_result; ?>
                    <?php if ($scan_status == 'success'): ?>
                        <div class="status-badge" style="background: #d1fae5; color: #065f46;">
                            <i class="bi bi-check-circle"></i> Entry Granted
                        </div>
                    <?php else: ?>
                        <div class="status-badge" style="background: #fee2e2; color: #991b1b;">
                            <i class="bi bi-x-circle"></i> Entry Denied
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back to Dashboard</a>
            </div>
        </div>
    </div>
    
    <script>
        let html5QrCode = null;
        let isScanning = false;
        
        // Tab switching
        function switchTab(tab) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            
            if (tab === 'camera') {
                document.getElementById('cameraTab').classList.add('active');
                document.querySelector('.tab-btn').classList.add('active');
                // Ask for camera permission when switching to camera tab
                if (!isScanning) {
                    startCamera();
                }
            } else {
                document.getElementById('manualTab').classList.add('active');
                document.querySelectorAll('.tab-btn')[1].classList.add('active');
                stopCamera();
            }
        }
        
        // Start camera scanner
        function startCamera() {
            if (isScanning) return;
            
            const startBtn = document.getElementById('startCameraBtn');
            const stopBtn = document.getElementById('stopCameraBtn');
            
            html5QrCode = new Html5Qrcode("reader");
            
            const qrCodeSuccessCallback = (decodedText, decodedResult) => {
                // Stop scanning after successful scan
                stopCamera();
                
                // Process the scanned ticket
                processScannedTicket(decodedText);
            };
            
            const config = {
                fps: 10,
                qrbox: { width: 250, height: 250 },
                aspectRatio: 1.0
            };
            
            html5QrCode.start(
                { facingMode: "environment" }, // Use back camera
                config,
                qrCodeSuccessCallback,
                (errorMessage) => {
                    // console.log(errorMessage);
                }
            ).then(() => {
                isScanning = true;
                startBtn.style.display = 'none';
                stopBtn.style.display = 'inline-flex';
            }).catch((err) => {
                console.error("Unable to start scanning", err);
                alert("Unable to access camera. Please check permissions or try manual entry.");
                startBtn.style.display = 'inline-flex';
                stopBtn.style.display = 'none';
            });
        }
        
        // Stop camera scanner
        function stopCamera() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().then(() => {
                    isScanning = false;
                    const startBtn = document.getElementById('startCameraBtn');
                    const stopBtn = document.getElementById('stopCameraBtn');
                    startBtn.style.display = 'inline-flex';
                    stopBtn.style.display = 'none';
                }).catch((err) => {
                    console.error("Unable to stop scanning", err);
                });
            }
        }
        
        // Process scanned ticket via AJAX
        async function processScannedTicket(ticketCode) {
            // Show loading
            const resultDiv = document.querySelector('.result');
            if (resultDiv) {
                resultDiv.remove();
            }
            
            // Create loading indicator
            const loadingDiv = document.createElement('div');
            loadingDiv.className = 'result';
            loadingDiv.style.background = '#e0e7ff';
            loadingDiv.style.color = '#3730a3';
            loadingDiv.innerHTML = '<i class="bi bi-hourglass-split"></i> Processing ticket...';
            document.querySelector('.card').appendChild(loadingDiv);
            
            try {
                const response = await fetch('ajax_validate_ticket.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({ ticket_code: ticketCode })
                });
                
                const data = await response.json();
                loadingDiv.remove();
                
                // Display result
                const resultHtml = `
                    <div class="result ${data.status}">
                        ${data.message}
                        ${data.status === 'success' ? 
                            '<div class="status-badge" style="background: #d1fae5; color: #065f46;"><i class="bi bi-check-circle"></i> Entry Granted</div>' : 
                            '<div class="status-badge" style="background: #fee2e2; color: #991b1b;"><i class="bi bi-x-circle"></i> Entry Denied</div>'
                        }
                        ${data.details ? `<div class="result-info">${data.details}</div>` : ''}
                    </div>
                `;
                document.querySelector('.card').insertAdjacentHTML('beforeend', resultHtml);
                
                // Play beep sound on successful scan
                if (data.status === 'success') {
                    playBeep();
                }
                
                // Auto restart camera after 2 seconds
                setTimeout(() => {
                    if (!isScanning) {
                        startCamera();
                    }
                }, 2000);
                
            } catch (error) {
                loadingDiv.remove();
                const errorHtml = `
                    <div class="result error">
                        ❌ Error processing ticket: ${error.message}
                    </div>
                `;
                document.querySelector('.card').insertAdjacentHTML('beforeend', errorHtml);
                
                // Restart camera
                setTimeout(() => {
                    if (!isScanning) {
                        startCamera();
                    }
                }, 2000);
            }
        }
        
        // Play beep sound
        function playBeep() {
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
        
        // Auto-start camera on page load (for mobile)
        document.addEventListener('DOMContentLoaded', function() {
            // Check if on mobile device
            const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            if (isMobile) {
                startCamera();
            }
            
            // Focus on manual input
            document.getElementById('ticket_code')?.focus();
        });
        
        // Clear manual input after submission
        const manualForm = document.getElementById('manualForm');
        if (manualForm) {
            manualForm.addEventListener('submit', function() {
                setTimeout(() => {
                    document.getElementById('ticket_code').value = '';
                    document.getElementById('ticket_code').focus();
                }, 100);
            });
        }
        
        // Cleanup on page unload
        window.addEventListener('beforeunload', function() {
            if (html5QrCode && isScanning) {
                html5QrCode.stop().catch((err) => {});
            }
        });
    </script>
</body>
</html>