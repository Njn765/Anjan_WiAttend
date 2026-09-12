<?php
session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if ($username === 'admin' && $password === 'admin123') {
        $_SESSION['admin_id'] = 1;
        $_SESSION['admin_username'] = 'admin';
        header('Location: index.php');
        exit;
    } else {
        $error = 'Invalid username or password.';
    }
}

if (!empty($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Wi-Attend</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0b0f1a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .login-box {
            background: #1a2235;
            border: 1px solid rgba(0, 212, 170, 0.3);
            border-radius: 16px;
            width: 100%;
            max-width: 420px;
            padding: 48px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        }
        
        h1 {
            color: #00d4aa;
            font-size: 32px;
            margin-bottom: 8px;
            text-align: center;
            font-weight: 900;
        }
        
        .tagline {
            text-align: center;
            color: #6b7a99;
            font-size: 13px;
            margin-bottom: 30px;
        }
        
        .error {
            background: rgba(244, 63, 94, 0.1);
            color: #ff6b7a;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 13px;
            border-left: 4px solid #ff6b7a;
        }
        
        .form-group { margin-bottom: 18px; }
        
        label {
            display: block;
            font-size: 11px;
            color: #6b7a99;
            margin-bottom: 8px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        input {
            width: 100%;
            padding: 12px 14px;
            background: rgba(15, 23, 42, 0.6);
            border: 1.5px solid rgba(0, 212, 170, 0.2);
            border-radius: 8px;
            color: #dce4f0;
            font-size: 14px;
            transition: all 0.3s ease;
        }
        
        input::placeholder {
            color: #6b7a99;
        }
        
        input:focus {
            outline: none;
            border-color: #00d4aa;
            background: rgba(0, 212, 170, 0.08);
            box-shadow: 0 0 20px rgba(0, 212, 170, 0.15);
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            margin-top: 8px;
            background: #00d4aa;
            color: #0b0f1a;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
            text-transform: uppercase;
            transition: all 0.3s ease;
        }
        
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(0, 212, 170, 0.4);
        }
        
        .hint {
            text-align: center;
            margin-top: 24px;
            font-size: 12px;
            color: #6b7a99;
            line-height: 1.8;
        }
        
        .hint strong {
            color: #00d4aa;
            font-weight: 700;
        }
    </style>
</head>
<body>
    <div class="login-box">
        <h1>Wi-Attend</h1>
        <p class="tagline">Wi-Fi Based Attendance System</p>

        <?php if ($error ?? false): ?>
            <div class="error">⚠ <?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="admin" autofocus required>
            </div>
            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" placeholder="admin123" required>
            </div>
            <button type="submit" class="btn">Sign In →</button>
        </form>

        <div class="hint">
            <strong>Username:</strong> admin<br>
            <strong>Password:</strong> admin123
        </div>
    </div>
</body>
</html>