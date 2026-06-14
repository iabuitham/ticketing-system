<?php
http_response_code(404);
$requested_url = $_SERVER['REQUEST_URI'] ?? 'Unknown URL';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>404 - Page Not Found</title>
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
        .error-icon { font-size: 80px; color: #667eea; margin-bottom: 20px; animation: bounce 1s ease; }
        @keyframes bounce {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-20px); }
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
        .suggestions {
            background: #f8fafc;
            padding: 15px;
            border-radius: 12px;
            margin-top: 20px;
            font-size: 13px;
            text-align: left;
        }
        body.dark-mode .suggestions { background: #0f172a; }
        .suggestions ul { margin-left: 20px; margin-top: 10px; }
        .suggestions li { margin: 5px 0; }
        .suggestions a { color: #667eea; text-decoration: none; }
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
                <i class="bi bi-compass"></i>
            </div>
            <h1>404</h1>
            <h2>Page Not Found</h2>
            <p>The page you are looking for doesn't exist or has been moved.<br>
            الصفحة التي تبحث عنها غير موجودة أو تم نقلها.</p>
            
            <a href="/admin/dashboard.php" class="btn">
                <i class="bi bi-house-door"></i> Go to Dashboard
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Go Back
            </a>
            
            <div class="suggestions">
                <strong><i class="bi bi-lightbulb"></i> You might be looking for:</strong>
                <ul>
                    <li><a href="/admin/dashboard.php">📊 Dashboard</a></li>
                    <li><a href="/admin/create_reservation.php">📝 New Reservation</a></li>
                    <li><a href="/admin/customers.php">👥 Customers</a></li>
                    <li><a href="/admin/manager_report.php">📈 Reports</a></li>
                </ul>
                <hr style="margin: 10px 0;">
                <small>Requested URL: <?php echo htmlspecialchars($requested_url); ?></small>
            </div>
        </div>
    </div>
    
    <button class="dark-mode-toggle" onclick="toggleDarkMode()">
        <i class="bi bi-moon-fill"></i>
    </button>
    
    <script>
        function toggleDarkMode() {
            document.body.classList.toggle('dark-mode');
            localStorage.setItem('darkMode', document.body.classList.contains('dark-mode'));
        }
        if (localStorage.getItem('darkMode') === 'true') document.body.classList.add('dark-mode');
    </script>
</body>
</html>