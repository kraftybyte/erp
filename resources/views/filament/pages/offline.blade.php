<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Offline - ERP Krafty</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {

            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #6366f1 0%, #8b5cf6 100%);
            color: #fff;
            padding: 20px;
        }
        .container {
            text-align: center;
            max-width: 400px;
        }
        .icon {
            font-size: 80px;
            margin-bottom: 20px;
            opacity: 0.9;
        }
        h1 { font-size: 28px; margin-bottom: 12px; }
        p { font-size: 16px; opacity: 0.9; margin-bottom: 24px; line-height: 1.6; }
        .btn {
            display: inline-block;
            padding: 14px 28px;
            background: #fff;
            color: #6366f1;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-size: 16px;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.2);
        }
        .cached-list {
            margin-top: 30px;
            text-align: left;
            background: rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 20px;
        }
        .cached-list h3 {
            font-size: 14px;
            margin-bottom: 12px;
            opacity: 0.8;
        }
        .cached-list ul {
            list-style: none;
            font-size: 14px;
        }
        .cached-list li {
            padding: 8px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .cached-list li:last-child { border: none; }
        .status {
            display: inline-block;
            width: 10px;
            height: 10px;
            background: #10b981;
            border-radius: 50%;
            margin-right: 8px;
            animation: pulse 2s infinite;
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="icon">📡</div>
        <h1>Anda Sedang Offline</h1>
        <p>Koneksi internet terputus. Beberapa fitur mungkin tidak tersedia. Silakan periksa koneksi Anda dan coba lagi.</p>
        <a href="/admin" class="btn" onclick="return tryCache(event)">
            🔄 Coba Lagi
        </a>
        <div class="cached-list">
            <h3>📦 Halaman Tersimpan Offline</h3>
            <ul id="cached-pages">
                <li><span class="status"></span>Dashboard</li>
                <li><span class="status"></span>Projects</li>
                <li><span class="status"></span>Tasks</li>
            </ul>
        </div>
    </div>
    <script>
        async function tryCache(e) {
            e.preventDefault();
            try {
                const response = await fetch('/admin');
                if (response.ok) {
                    window.location.href = '/admin';
                } else {
                    alert('Still offline. Please check your connection.');
                }
            } catch {
                alert('Still offline. Please check your connection.');
            }
        }
    </script>
</body>
</html>