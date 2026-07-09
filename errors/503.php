<?php
http_response_code(503);
header('Retry-After: 3600'); // Suggest retry after 1 hour
$requested_url = $_SERVER['REQUEST_URI'] ?? 'Unknown URL';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>503 - Service Unavailable</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .error-container { max-width: 600px; width: 100%; text-align: center; }
        .error-card {
            background: white;
            border-radius: 24px;
            padding: 50px 40px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: fadeIn 0.5s ease;
        }
        body.dark-mode .error-card { background: #1e293b; }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .error-icon { font-size: 80px; color: #f59e0b; margin-bottom: 20px; animation: pulse 2s infinite; }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        h1 { font-size: 80px; color: #667eea; margin-bottom: 10px; }
        h2 { font-size: 24px; color: #333; margin-bottom: 15px; }
        body.dark-mode h2 { color: #e2e8f0; }
        p { color: #666; line-height: 1.6; margin-bottom: 30px; }
        body.dark-mode p { color: #94a3b8; }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 24px;
            background: #667eea;
            color: white;
            text-decoration: none;
            border-radius: 12px;
            font-weight: 600;
            transition: all 0.2s;
            margin: 5px;
        }
        .btn:hover { background: #5a67d8; transform: translateY(-2px); }
        .btn-secondary { background: #64748b; }
        .auto-refresh {
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 13px;
        }
        body.dark-mode .auto-refresh { background: #0f172a; }
        .countdown {
            font-size: 24px;
            font-weight: bold;
            color: #667eea;
            margin-top: 10px;
        }
        .dark-mode-toggle {
            position: fixed;
            bottom: 20px;
            right: 20px;
            background: rgba(0,0,0,0.7);
            color: white;
            border: none;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            cursor: pointer;
            font-size: 24px;
            z-index: 1000;
        }
        @media (max-width: 768px) {
            .error-card { padding: 30px 20px; }
            h1 { font-size: 60px; }
        }
    </style>
</head>
<body>
    <div class="error-container">
        <div class="error-card">
            <div class="error-icon">
                <i class="bi bi-server"></i>
            </div>
            <h1>503</h1>
            <h2>Service Unavailable</h2>
            <p>The server is temporarily unable to handle your request. Please try again later.<br>
            الخادم غير متاح مؤقتاً. يرجى المحاولة مرة أخرى لاحقاً.</p>
            
            <button onclick="location.reload()" class="btn">
                <i class="bi bi-arrow-repeat"></i> Try Again
            </button>
            <a href="/admin/dashboard.php" class="btn btn-secondary">
                <i class="bi bi-house-door"></i> Go to Dashboard
            </a>
            
            <div class="auto-refresh">
                <strong><i class="bi bi-hourglass-split"></i> Auto-refresh in:</strong>
                <div class="countdown" id="countdown">30</div>
                <small>Page will automatically refresh when countdown reaches 0</small>
            </div>
        </div>
    </div>
    
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="bi bi-moon-fill"></i>
    </button>
    
    <script>
        let countdown = 30;
        const countdownEl = document.getElementById('countdown');
        
        const timer = setInterval(function() {
            countdown--;
            countdownEl.textContent = countdown;
            
            if (countdown <= 0) {
                clearInterval(timer);
                location.reload();
            }
        }, 1000);
        
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
        }
        if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
    </script>
</body>
</html>