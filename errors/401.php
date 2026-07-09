<?php
http_response_code(401);
$requested_url = $_SERVER['REQUEST_URI'] ?? 'Unknown URL';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>401 - Unauthorized</title>
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
        .error-icon { font-size: 80px; color: #ef4444; margin-bottom: 20px; }
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
        .btn-danger { background: #ef4444; }
        .btn-danger:hover { background: #dc2626; }
        .login-box {
            background: #f8fafc;
            padding: 20px;
            border-radius: 16px;
            margin-top: 20px;
            text-align: left;
        }
        body.dark-mode .login-box { background: #0f172a; }
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
                <i class="bi bi-shield-lock-fill"></i>
            </div>
            <h1>401</h1>
            <h2>Unauthorized Access</h2>
            <p>You need to be logged in to access this page.<br>
            يرجى تسجيل الدخول للوصول إلى هذه الصفحة.</p>
            
            <a href="/admin/login.php" class="btn btn-danger">
                <i class="bi bi-box-arrow-in-right"></i> Login Now
            </a>
            <a href="javascript:history.back()" class="btn btn-secondary">
                <i class="bi bi-arrow-left"></i> Go Back
            </a>
            
            <div class="login-box">
                <strong><i class="bi bi-info-circle"></i> How to access:</strong><br>
                1. <a href="/admin/login.php">Login to your account</a><br>
                2. Use valid admin credentials<br>
                3. Contact system administrator if you don't have an account
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