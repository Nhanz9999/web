<?php
session_start();
header('Content-Type: text/html; charset=utf-8');

// Cấu hình
define('DATA_FILE', 'keys.txt');
define('ADMIN_PASSWORD', 'admin123');
define('MEMBER_KEYS_FILE', 'member_keys.txt');

// API ENDPOINT - Lấy tất cả keys
if (isset($_GET['api']) && $_GET['api'] === 'keys') {
    header('Content-Type: text/plain; charset=utf-8');
    echo file_exists(DATA_FILE) ? file_get_contents(DATA_FILE) : '';
    exit;
}

// API - Kiểm tra key cụ thể
if (isset($_GET['api']) && $_GET['api'] === 'check' && isset($_GET['name'])) {
    header('Content-Type: text/plain; charset=utf-8');
    $searchName = trim($_GET['name']);
    $keys = readKeys();
    
    foreach ($keys as $key) {
        if ($key['name'] === $searchName) {
            $parts = explode('/', $key['date']);
            if (count($parts) === 3) {
                $expiry = strtotime($parts[2] . '-' . $parts[1] . '-' . $parts[0]);
                $is_valid = $expiry >= time();
                
                echo "name=" . $key['name'] . "\n";
                echo "date=" . $key['date'] . "\n";
                echo "valid=" . ($is_valid ? 'true' : 'false') . "\n";
                echo "remaining_days=" . max(0, floor(($expiry - time()) / 86400)) . "\n";
            }
            exit;
        }
    }
    echo "error=Key not found";
    exit;
}

// Xử lý đăng nhập
if (isset($_POST['login'])) {
    $password = $_POST['password'];
    
    if ($password === ADMIN_PASSWORD) {
        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'admin';
    } else {
        $memberKeys = readMemberKeys();
        foreach ($memberKeys as $mk) {
            if ($mk['key'] === $password) {
                $_SESSION['logged_in'] = true;
                $_SESSION['role'] = 'member';
                $_SESSION['member_key'] = $password;
                $_SESSION['allowed_days'] = $mk['days'];
                break;
            }
        }
    }
}

// Xử lý đăng xuất
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
$is_admin = isset($_SESSION['role']) && $_SESSION['role'] === 'admin';

// Hàm xử lý keys
function readKeys() {
    if (!file_exists(DATA_FILE)) return [];
    
    $content = file_get_contents(DATA_FILE);
    $lines = explode("\n", trim($content));
    $keys = [];
    
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $parts = explode(';', $line);
        if (count($parts) === 2) {
            $keys[] = ['name' => $parts[0], 'date' => $parts[1]];
        }
    }
    return $keys;
}

function writeKeys($keys) {
    $content = '';
    foreach ($keys as $key) {
        $content .= $key['name'] . ';' . $key['date'] . "\n";
    }
    file_put_contents(DATA_FILE, $content);
}

function readMemberKeys() {
    if (!file_exists(MEMBER_KEYS_FILE)) return [];
    
    $content = file_get_contents(MEMBER_KEYS_FILE);
    $lines = explode("\n", trim($content));
    $keys = [];
    
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $parts = explode(';', $line);
        if (count($parts) === 2) {
            $keys[] = ['key' => $parts[0], 'days' => intval($parts[1])];
        }
    }
    return $keys;
}

function writeMemberKeys($keys) {
    $content = '';
    foreach ($keys as $key) {
        $content .= $key['key'] . ';' . $key['days'] . "\n";
    }
    file_put_contents(MEMBER_KEYS_FILE, $content);
}

// ADMIN: Tạo member key
if ($is_admin && isset($_POST['create_member_key'])) {
    $member_key = trim($_POST['member_key']);
    $days = intval($_POST['member_days']);
    
    if (!empty($member_key) && $days > 0) {
        $memberKeys = readMemberKeys();
        $memberKeys[] = ['key' => $member_key, 'days' => $days];
        writeMemberKeys($memberKeys);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ADMIN: Xóa member key
if ($is_admin && isset($_GET['delete_member'])) {
    $index = intval($_GET['delete_member']);
    $memberKeys = readMemberKeys();
    
    if (isset($memberKeys[$index])) {
        array_splice($memberKeys, $index, 1);
        writeMemberKeys($memberKeys);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ADMIN: Thêm key
if ($is_admin && isset($_POST['add_key'])) {
    $name = trim($_POST['key_name']);
    $date = trim($_POST['key_date']);
    
    if (!empty($name) && !empty($date)) {
        $keys = readKeys();
        $keys[] = ['name' => $name, 'date' => $date];
        writeKeys($keys);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// MEMBER: Thêm key (chỉ 1 lần)
if (!$is_admin && $logged_in && isset($_POST['add_key_member'])) {
    $name = trim($_POST['key_name']);
    $keys = readKeys();
    
    $member_key = $_SESSION['member_key'];
    $already_added = false;
    
    foreach ($keys as $key) {
        if (strpos($key['name'], '[' . $member_key . ']') !== false) {
            $already_added = true;
            break;
        }
    }
    
    if (!$already_added && !empty($name)) {
        $days = $_SESSION['allowed_days'];
        $expiry_date = date('d/m/Y', strtotime('+' . $days . ' days'));
        
        $keys[] = [
            'name' => $name . ' [' . $member_key . ']',
            'date' => $expiry_date
        ];
        writeKeys($keys);
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// ADMIN: Xóa key
if ($is_admin && isset($_GET['delete'])) {
    $index = intval($_GET['delete']);
    $keys = readKeys();
    
    if (isset($keys[$index])) {
        array_splice($keys, $index, 1);
        writeKeys($keys);
    }
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

// ADMIN: Import
if ($is_admin && isset($_POST['import'])) {
    $import_text = trim($_POST['import_text']);
    $lines = explode("\n", $import_text);
    $keys = [];
    
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        $parts = explode(';', $line);
        if (count($parts) === 2) {
            $keys[] = ['name' => trim($parts[0]), 'date' => trim($parts[1])];
        }
    }
    writeKeys($keys);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit;
}

$keys = readKeys();
$memberKeys = readMemberKeys();
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://{$_SERVER['HTTP_HOST']}{$_SERVER['PHP_SELF']}";
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🎅 Key Manager - Christmas</title>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap');
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;
            background: linear-gradient(135deg, #c94b4b 0%, #4b134f 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .snowflake {
            position: fixed;
            top: -10px;
            color: white;
            font-size: 1em;
            pointer-events: none;
            z-index: 1000;
            animation: fall linear infinite;
        }
        
        @keyframes fall {
            to { transform: translateY(100vh) rotate(360deg); }
        }
        
        .music-control {
            position: fixed;
            top: 20px;
            right: 20px;
            background: linear-gradient(135deg, #ff6b6b 0%, #c92a2a 100%);
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            cursor: pointer;
            z-index: 1001;
            color: white;
            font-size: 24px;
            transition: all 0.3s;
            box-shadow: 0 5px 20px rgba(255, 107, 107, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .music-control:hover {
            transform: scale(1.1) rotate(10deg);
            box-shadow: 0 8px 30px rgba(255, 107, 107, 0.7);
        }
        
        .music-control.playing {
            animation: pulse 1.5s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
        
        .container {
            max-width: 1000px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        .header {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        h1 {
            background: linear-gradient(135deg, #c94b4b 0%, #4b134f 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-size: 28px;
            font-weight: 700;
        }
        
        h2 {
            color: #c94b4b;
            font-size: 20px;
            margin-bottom: 15px;
        }
        
        .role-badge {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
        }
        
        .logout-btn {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            margin-left: 10px;
        }
        
        .logout-btn:hover {
            transform: translateY(-2px);
        }
        
        .login-form {
            background: rgba(255, 255, 255, 0.95);
            padding: 40px;
            border-radius: 20px;
            max-width: 400px;
            margin: 50px auto;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #c94b4b;
            font-weight: 600;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="password"],
        input[type="number"],
        textarea {
            width: 100%;
            padding: 12px;
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid rgba(201, 75, 75, 0.3);
            border-radius: 10px;
            font-family: 'Poppins', sans-serif;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #c94b4b;
            background: white;
        }
        
        textarea {
            min-height: 120px;
            font-family: 'Courier New', monospace;
            resize: vertical;
        }
        
        button {
            background: linear-gradient(135deg, #c94b4b 0%, #4b134f 100%);
            color: white;
            border: none;
            padding: 12px 25px;
            border-radius: 50px;
            cursor: pointer;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
            font-size: 14px;
        }
        
        button:hover {
            transform: translateY(-2px);
        }
        
        .api-section, .add-form, .key-list, .import-section, .member-section {
            background: rgba(255, 255, 255, 0.95);
            padding: 25px;
            border-radius: 20px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }
        
        .api-url {
            background: #f5f5f5;
            padding: 12px;
            border-radius: 10px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            word-break: break-all;
            margin: 10px 0;
        }
        
        .copy-btn {
            padding: 8px 15px;
            font-size: 12px;
            margin-top: 5px;
            width: auto;
        }
        
        .key-item {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s;
        }
        
        .key-item:hover {
            transform: translateX(5px);
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .key-name {
            font-weight: 700;
            color: #c94b4b;
            margin-bottom: 3px;
        }
        
        .key-date {
            color: #666;
            font-size: 13px;
        }
        
        .delete-btn {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 50px;
            cursor: pointer;
            text-decoration: none;
            font-size: 12px;
            font-weight: 600;
        }
        
        .expired { color: #f5576c; font-weight: 600; }
        .active { color: #4facfe; font-weight: 600; }
        
        .warning {
            background: #fff3cd;
            border-left: 4px solid #ffc107;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 15px;
            color: #856404;
        }
        
        .member-key-item {
            background: #e8f5e9;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        @media (max-width: 768px) {
            .music-control {
                width: 50px;
                height: 50px;
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <iframe id="bgMusic" width="0" height="0" src="https://www.youtube.com/embed/YjQRfudC96g?autoplay=0&loop=1&playlist=YjQRfudC96g" 
            frameborder="0" allow="autoplay; encrypted-media" allowfullscreen style="display:none;"></iframe>
    
    <button class="music-control" id="musicBtn" onclick="toggleMusic()">
        <span id="musicIcon">🔇</span>
    </button>
    
    <div class="container">
        <?php if (!$logged_in): ?>
            <div class="login-form">
                <h1 style="text-align: center; margin-bottom: 25px;">🎅 Key Manager</h1>
                <form method="POST">
                    <div class="form-group">
                        <label>🔑 Mật khẩu / Member Key:</label>
                        <input type="password" name="password" required placeholder="Nhập mật khẩu...">
                    </div>
                    <button type="submit" name="login">🚀 Đăng nhập</button>
                </form>
            </div>
        <?php else: ?>
            <div class="header">
                <div style="display: flex; align-items: center; gap: 15px;">
                    <h1>🎅 Key Manager</h1>
                    <span class="role-badge"><?php echo $is_admin ? '👮 ADMIN' : '👤 MEMBER'; ?></span>
                </div>
                <a href="?logout" class="logout-btn">🚪 Đăng xuất</a>
            </div>
            
            <?php if ($is_admin): ?>
                <div class="member-section">
                    <h2>👥 Quản lý Member Keys</h2>
                    <form method="POST">
                        <div class="form-group">
                            <label>🏷️ Member Key:</label>
                            <input type="text" name="member_key" required placeholder="Ví dụ: member123">
                        </div>
                        <div class="form-group">
                            <label>📅 Số ngày cho phép:</label>
                            <input type="number" name="member_days" required placeholder="Ví dụ: 30">
                        </div>
                        <button type="submit" name="create_member_key">✨ Tạo Member Key</button>
                    </form>
                    
                    <?php if (!empty($memberKeys)): ?>
                        <h2 style="margin-top: 25px;">Danh sách Member Keys (<?php echo count($memberKeys); ?>)</h2>
                        <?php foreach ($memberKeys as $index => $mk): ?>
                            <div class="member-key-item">
                                <div>
                                    <div class="key-name">🔑 <?php echo htmlspecialchars($mk['key']); ?></div>
                                    <div class="key-date">Cho phép thêm key: <?php echo $mk['days']; ?> ngày</div>
                                </div>
                                <a href="?delete_member=<?php echo $index; ?>" class="delete-btn" onclick="return confirm('Xóa member key này?')">🗑️ Xóa</a>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <div class="api-section">
                    <h2>🔗 API URLs</h2>
                    <div class="api-url"><?php echo $current_url; ?>?api=keys</div>
                    <button class="copy-btn" onclick="copyToClipboard('<?php echo $current_url; ?>?api=keys')">📋 Copy</button>
                    
                    <div class="api-url" style="margin-top: 15px;"><?php echo $current_url; ?>?api=check&name=TEN_KEY</div>
                    <button class="copy-btn" onclick="copyToClipboard('<?php echo $current_url; ?>?api=check&name=')">📋 Copy</button>
                </div>
                
                <div class="import-section">
                    <h2>📥 Import Keys</h2>
                    <form method="POST">
                        <div class="form-group">
                            <textarea name="import_text" placeholder="Arthur_Rome;29/10/2025&#10;Nhanz_Ssvip;19/01/2026"></textarea>
                        </div>
                        <button type="submit" name="import">📥 Import</button>
                    </form>
                </div>
                
                <div class="add-form">
                    <h2>➕ Thêm Key</h2>
                    <form method="POST">
                        <div class="form-group">
                            <label>📝 Tên:</label>
                            <input type="text" name="key_name" required>
                        </div>
                        <div class="form-group">
                            <label>📅 Hết hạn (dd/mm/yyyy):</label>
                            <input type="text" name="key_date" placeholder="29/10/2025" required>
                        </div>
                        <button type="submit" name="add_key">✨ Thêm</button>
                    </form>
                </div>
            <?php else: ?>
                <?php
                $member_key = $_SESSION['member_key'];
                $already_added = false;
                foreach ($keys as $key) {
                    if (strpos($key['name'], '[' . $member_key . ']') !== false) {
                        $already_added = true;
                        break;
                    }
                }
                ?>
                
                <?php if (!$already_added): ?>
                    <div class="add-form">
                        <h2>➕ Thêm Key của bạn</h2>
                        <div class="warning">
                            ⚠️ Bạn chỉ được thêm 1 key duy nhất với thời hạn <?php echo $_SESSION['allowed_days']; ?> ngày!
                        </div>
                        <form method="POST">
                            <div class="form-group">
                                <label>📝 Tên Key:</label>
                                <input type="text" name="key_name" required placeholder="Nhập tên key của bạn">
                            </div>
                            <button type="submit" name="add_key_member">✨ Thêm Key (<?php echo $_SESSION['allowed_days']; ?> ngày)</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="warning">
                        ✅ Bạn đã thêm key của mình rồi! Member chỉ được thêm 1 key duy nhất.
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            
            <div class="key-list">
                <h2>📋 Danh sách Keys (<?php echo count($keys); ?>)</h2>
                
                <?php if (empty($keys)): ?>
                    <p style="text-align: center; color: #999; padding: 20px;">Chưa có key nào</p>
                <?php else: ?>
                    <?php foreach ($keys as $index => $key): ?>
                        <?php
                        $parts = explode('/', $key['date']);
                        if (count($parts) === 3) {
                            $expiry = strtotime($parts[2] . '-' . $parts[1] . '-' . $parts[0]);
                            $is_expired = $expiry < time();
                            $days_left = floor(($expiry - time()) / 86400);
                        }
                        ?>
                        <div class="key-item">
                            <div>
                                <div class="key-name">👤 <?php echo htmlspecialchars($key['name']); ?></div>
                                <div class="key-date <?php echo $is_expired ? 'expired' : 'active'; ?>">
                                    📅 <?php echo htmlspecialchars($key['date']); ?>
                                    <?php echo $is_expired ? ' - ❌ Hết hạn' : ' - ✅ Còn ' . $days_left . ' ngày'; ?>
                                </div>
                            </div>
                            <?php if ($is_admin): ?>
                                <a href="?delete=<?php echo $index; ?>" class="delete-btn" onclick="return confirm('Xóa key này?')">🗑️ Xóa</a>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        function createSnowflake() {
            const snowflake = document.createElement('div');
            snowflake.classList.add('snowflake');
            snowflake.innerHTML = '❄️'; 
            snowflake.style.left = Math.random() * 100 + '%';
            snowflake.style.animationDuration = Math.random() * 3 + 5 + 's';
            snowflake.style.opacity = Math.random();
            snowflake.style.fontSize = Math.random() * 10 + 10 + 'px';
            document.body.appendChild(snowflake);
            setTimeout(() => snowflake.remove(), 8000);
        }
        
        setInterval(createSnowflake, 200);
        
        let musicPlaying = false;
        const musicBtn = document.getElementById('musicBtn');
        const musicIcon = document.getElementById('musicIcon');
        const musicFrame = document.getElementById('bgMusic');
        
        function toggleMusic() {
            if (musicPlaying) {
                musicFrame.src = musicFrame.src.replace('autoplay=1', 'autoplay=0');
                musicIcon.textContent = '🔇';
                musicBtn.classList.remove('playing');
                musicPlaying = false;
            } else {
                musicFrame.src = musicFrame.src.replace('autoplay=0', 'autoplay=1');
                musicIcon.textContent = '🔊';
                musicBtn.classList.add('playing');
                musicPlaying = true;
            }
        }
        
        function copyToClipboard(text) {
            navigator.clipboard.writeText(text).then(() => {
                alert('✅ Đã copy!');
            });
        }
    </script>
</body>
</html>
