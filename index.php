<?php
/**
 * Complete Contract Management System - Single File Version
 * Install and run this single file on your cPanel hosting
 */

session_start();

// ==================== CONFIGURATION ====================
// UPDATE THESE VALUES BEFORE INSTALLING!
define('DB_HOST', 'localhost');
define('DB_NAME', 'your_database_name');      // CHANGE THIS
define('DB_USER', 'your_database_user');      // CHANGE THIS
define('DB_PASS', 'your_database_password');  // CHANGE THIS
define('SITE_URL', 'https://yourdomain.com/contract-system'); // CHANGE THIS
define('ADMIN_USER', 'admin');                 // CHANGE THIS
define('ADMIN_PASS', 'your_secure_password');  // CHANGE THIS - Make it strong!
define('SITE_NAME', 'Your Business Name');     // CHANGE THIS

// ==================== DATABASE CONNECTION ====================
try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("SET NAMES utf8");
} catch(PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

// ==================== FUNCTIONS ====================
function generateUniqueLink() {
    return bin2hex(random_bytes(32));
}

function isAdminLoggedIn() {
    return isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;
}

function sanitize($input) {
    return htmlspecialchars(strip_tags(trim($input)));
}

function getBusinessSettings($pdo) {
    $stmt = $pdo->query("SELECT * FROM settings WHERE id = 1");
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getClient($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getContract($pdo, $id) {
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getContractByLink($pdo, $link) {
    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE unique_link = ?");
    $stmt->execute([$link]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function isContractSigned($pdo, $contract_id) {
    $stmt = $pdo->prepare("SELECT * FROM signatures WHERE contract_id = ?");
    $stmt->execute([$contract_id]);
    return $stmt->rowCount() > 0;
}

function saveSignature($pdo, $contract_id, $signature_data) {
    $upload_dir = __DIR__ . '/signatures/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }
    
    $signature_path = 'signatures/sig_' . $contract_id . '_' . time() . '.png';
    $full_path = __DIR__ . '/' . $signature_path;
    
    $signature_data = str_replace('data:image/png;base64,', '', $signature_data);
    $signature_data = str_replace(' ', '+', $signature_data);
    $image_data = base64_decode($signature_data);
    file_put_contents($full_path, $image_data);
    
    $stmt = $pdo->prepare("INSERT INTO signatures (contract_id, signature_path, signed_date) VALUES (?, ?, NOW())");
    $stmt->execute([$contract_id, $signature_path]);
    
    $stmt = $pdo->prepare("UPDATE contracts SET status = 'signed', signed_date = NOW() WHERE id = ?");
    $stmt->execute([$contract_id]);
    
    return true;
}

// ==================== INSTALLATION ====================
function installSystem($pdo) {
    $tables = [
        "CREATE TABLE IF NOT EXISTS settings (
            id INT PRIMARY KEY AUTO_INCREMENT,
            business_name VARCHAR(255),
            business_address TEXT,
            business_phone VARCHAR(50),
            business_email VARCHAR(255),
            business_logo VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS clients (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            email VARCHAR(255) NOT NULL,
            phone VARCHAR(50),
            address TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )",
        
        "CREATE TABLE IF NOT EXISTS contracts (
            id INT PRIMARY KEY AUTO_INCREMENT,
            client_id INT NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NOT NULL,
            unique_link VARCHAR(64) UNIQUE NOT NULL,
            status ENUM('pending', 'signed') DEFAULT 'pending',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            signed_date TIMESTAMP NULL,
            FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
        )",
        
        "CREATE TABLE IF NOT EXISTS signatures (
            id INT PRIMARY KEY AUTO_INCREMENT,
            contract_id INT NOT NULL,
            signature_path VARCHAR(255) NOT NULL,
            signed_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
        )"
    ];
    
    foreach($tables as $table) {
        $pdo->exec($table);
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) FROM settings");
    if($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO settings (business_name, business_address, business_phone, business_email) 
                    VALUES ('".SITE_NAME."', 'Your Business Address', '+27 XX XXX XXXX', 'info@yourbusiness.com')");
    }
    
    return true;
}

// ==================== PDF GENERATION ====================
function generatePDF($pdo, $contract_id, $download = true) {
    $contract = getContract($pdo, $contract_id);
    if(!$contract) die('Contract not found');
    
    $client = getClient($pdo, $contract['client_id']);
    $settings = getBusinessSettings($pdo);
    
    $stmt = $pdo->prepare("SELECT * FROM signatures WHERE contract_id = ?");
    $stmt->execute([$contract_id]);
    $signature = $stmt->fetch();
    
    if(!$signature) die('Contract not signed yet');
    
    // Create PDF using HTML/CSS approach (built into browsers)
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Contract: ' . htmlspecialchars($contract['title']) . '</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
            .header { text-align: right; margin-bottom: 30px; }
            .logo { max-width: 150px; max-height: 80px; }
            .title { text-align: center; font-size: 24px; margin: 20px 0; color: #dc2626; }
            .client-info { background: #f5f5f5; padding: 15px; margin: 20px 0; border-radius: 5px; }
            .contract-content { margin: 30px 0; }
            .signature-section { margin-top: 50px; border-top: 2px solid #ddd; padding-top: 30px; }
            .signature-img { max-width: 300px; max-height: 100px; margin: 20px 0; }
            .footer { margin-top: 50px; font-size: 10px; text-align: center; color: #999; }
            @media print {
                body { margin: 0; }
                .no-print { display: none; }
            }
        </style>
    </head>
    <body>
        <div class="header">
            ' . (!empty($settings['business_logo']) && file_exists($settings['business_logo']) ? 
                '<img src="' . $settings['business_logo'] . '" class="logo"><br>' : '') . '
            <strong>' . htmlspecialchars($settings['business_name']) . '</strong><br>
            ' . nl2br(htmlspecialchars($settings['business_address'])) . '<br>
            ' . ($settings['business_phone'] ? 'Tel: ' . htmlspecialchars($settings['business_phone']) . '<br>' : '') . '
            ' . ($settings['business_email'] ? 'Email: ' . htmlspecialchars($settings['business_email']) : '') . '
        </div>
        
        <div class="title">' . htmlspecialchars($contract['title']) . '</div>
        
        <div class="client-info">
            <strong>Client Information</strong><br>
            Name: ' . htmlspecialchars($client['name']) . '<br>
            Email: ' . htmlspecialchars($client['email']) . '<br>
            ' . ($client['phone'] ? 'Phone: ' . htmlspecialchars($client['phone']) . '<br>' : '') . '
            ' . ($client['address'] ? 'Address: ' . nl2br(htmlspecialchars($client['address'])) : '') . '
        </div>
        
        <div class="contract-content">
            <strong>Contract Details</strong><br><br>
            ' . nl2br(htmlspecialchars($contract['content'])) . '
        </div>
        
        <div class="signature-section">
            <strong>Signature</strong><br>
            ' . (file_exists($signature['signature_path']) ? 
                '<img src="' . $signature['signature_path'] . '" class="signature-img"><br>' : '') . '
            Signed by: ' . htmlspecialchars($client['name']) . '<br>
            Date signed: ' . date('F j, Y \a\t H:i', strtotime($signature['signed_date'])) . '
        </div>
        
        <div class="footer">
            This is a legally binding document. Generated on ' . date('Y-m-d H:i:s') . '
        </div>
        
        <div class="no-print" style="text-align: center; margin-top: 30px;">
            <button onclick="window.print()" style="padding: 10px 20px; background: #dc2626; color: white; border: none; border-radius: 5px; cursor: pointer;">Save as PDF</button>
            <button onclick="window.close()" style="padding: 10px 20px; margin-left: 10px; cursor: pointer;">Close</button>
        </div>
        
        <script>
            window.onload = function() {
                setTimeout(function() {
                    window.print();
                }, 500);
            };
        </script>
    </body>
    </html>';
    
    echo $html;
    exit;
}

// ==================== ROUTING ====================
$action = $_GET['action'] ?? 'home';
$subaction = $_GET['subaction'] ?? '';

// Handle installation
if($action == 'install') {
    try {
        installSystem($pdo);
        echo "<h2>Installation Complete!</h2>";
        echo "<p>Database tables created successfully.</p>";
        echo "<p><a href='?'>Go to Home</a></p>";
        echo "<p style='color: red;'><strong>IMPORTANT:</strong> Remove the install option from your code or add a password check for security!</p>";
    } catch(Exception $e) {
        echo "<h2>Installation Error</h2>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "<p>Please check your database credentials in the configuration section.</p>";
    }
    exit;
}

// Handle PDF generation
if($action == 'pdf') {
    $contract_id = $_GET['contract'] ?? 0;
    generatePDF($pdo, $contract_id);
    exit;
}

// Handle public contract signing
if($action == 'sign') {
    $contract_link = $_GET['contract'] ?? '';
    $contract = getContractByLink($pdo, $contract_link);
    
    if(!$contract) {
        die('Invalid contract link. Please contact the sender.');
    }
    
    $client = getClient($pdo, $contract['client_id']);
    $settings = getBusinessSettings($pdo);
    
    if(isContractSigned($pdo, $contract['id'])) {
        // Show already signed page
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Contract Already Signed</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
                .container { max-width: 600px; background: white; border-radius: 10px; padding: 40px; text-align: center; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
                .success-icon { font-size: 64px; color: #10b981; margin-bottom: 20px; }
                button { background: #dc2626; color: white; padding: 12px 30px; border: none; border-radius: 5px; cursor: pointer; margin: 10px; }
                button:hover { background: #b91c1c; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="success-icon">✓</div>
                <h1>Contract Already Signed</h1>
                <p>This contract was signed on <?php echo date('F j, Y', strtotime($contract['signed_date'])); ?></p>
                <button onclick="window.location.href='?action=pdf&contract=<?php echo $contract['id']; ?>'">View Signed Contract</button>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    if($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['signature'])) {
        saveSignature($pdo, $contract['id'], $_POST['signature']);
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Contract Signed Successfully</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
                .container { max-width: 600px; background: white; border-radius: 10px; padding: 40px; text-align: center; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
                .success-icon { font-size: 64px; color: #10b981; margin-bottom: 20px; }
                button { background: #dc2626; color: white; padding: 12px 30px; border: none; border-radius: 5px; cursor: pointer; margin: 10px; }
                .btn-secondary { background: #666; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="success-icon">✓</div>
                <h1>Thank You!</h1>
                <p>Your contract has been signed successfully.</p>
                <button onclick="window.location.href='?action=pdf&contract=<?php echo $contract['id']; ?>'">View/Download PDF</button>
                <button class="btn-secondary" onclick="window.close()">Close</button>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    // Show signing page
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
        <title>Sign Contract - <?php echo htmlspecialchars($contract['title']); ?></title>
        <script src="https://cdn.jsdelivr.net/npm/signature_pad@4.0.0/dist/signature_pad.umd.min.js"></script>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 900px; margin: 0 auto; background: white; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: #dc2626; color: white; padding: 20px; text-align: center; }
            .logo { max-width: 150px; max-height: 80px; margin-bottom: 15px; }
            .content { padding: 30px; }
            .client-info { background: #f0f0f0; padding: 15px; border-radius: 8px; margin-bottom: 30px; }
            .contract-body { background: #f9f9f9; padding: 20px; border-radius: 8px; margin-bottom: 30px; line-height: 1.6; }
            .signature-section { border-top: 2px solid #ddd; padding-top: 30px; margin-top: 20px; }
            .signature-pad-container { border: 2px solid #ddd; border-radius: 8px; margin: 20px 0; background: white; }
            .signature-pad { width: 100%; height: 200px; background: white; }
            .signature-actions { display: flex; gap: 10px; margin: 15px 0; }
            button { background: #dc2626; color: white; padding: 12px 24px; border: none; border-radius: 5px; cursor: pointer; font-size: 16px; }
            button:hover { background: #b91c1c; }
            .btn-secondary { background: #666; }
            .btn-clear { background: #999; }
            .error-message { background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 5px; margin: 10px 0; display: none; }
            @media (max-width: 768px) { .content { padding: 20px; } button { padding: 10px 20px; font-size: 14px; } }
        </style>
    </head>
    <body>
        <div class="container">
            <div class="header">
                <?php if(!empty($settings['business_logo']) && file_exists($settings['business_logo'])): ?>
                <img src="<?php echo $settings['business_logo']; ?>" class="logo">
                <?php endif; ?>
                <h1><?php echo htmlspecialchars($contract['title']); ?></h1>
                <p>Please review and sign below</p>
            </div>
            
            <div class="content">
                <div class="client-info">
                    <strong>Client: <?php echo htmlspecialchars($client['name']); ?></strong><br>
                    Email: <?php echo htmlspecialchars($client['email']); ?><br>
                    Phone: <?php echo htmlspecialchars($client['phone']); ?>
                </div>
                
                <div class="contract-body">
                    <?php echo nl2br(htmlspecialchars($contract['content'])); ?>
                </div>
                
                <div class="signature-section">
                    <h3>Sign Here</h3>
                    <p style="color: #666; margin-bottom: 15px;">Please sign using your finger or mouse below:</p>
                    
                    <div class="signature-pad-container">
                        <canvas id="signature-pad" class="signature-pad" width="800" height="200"></canvas>
                    </div>
                    
                    <div class="signature-actions">
                        <button type="button" class="btn-clear" onclick="clearSignature()">Clear</button>
                        <button type="button" class="btn-secondary" onclick="undoSignature()">Undo</button>
                    </div>
                    
                    <div id="error-message" class="error-message">
                        Please provide your signature before submitting.
                    </div>
                    
                    <form id="sign-form" method="POST" action="">
                        <input type="hidden" name="signature" id="signature-data">
                        <button type="submit">Sign Contract</button>
                    </form>
                    
                    <p style="margin-top: 20px; font-size: 12px; color: #999; text-align: center;">
                        By signing, you agree to the terms and conditions of this contract.
                        This is a legally binding document.
                    </p>
                </div>
            </div>
        </div>
        
        <script>
            let signaturePad;
            let signatureHistory = [];
            
            window.onload = function() {
                const canvas = document.getElementById('signature-pad');
                signaturePad = new SignaturePad(canvas, {
                    backgroundColor: 'rgb(255, 255, 255)',
                    penColor: 'rgb(0, 0, 0)',
                    minWidth: 1,
                    maxWidth: 2
                });
                
                function resizeCanvas() {
                    const ratio = Math.max(window.devicePixelRatio || 1, 1);
                    canvas.width = canvas.offsetWidth * ratio;
                    canvas.height = canvas.offsetHeight * ratio;
                    canvas.getContext('2d').scale(ratio, ratio);
                    signaturePad.clear();
                }
                
                window.addEventListener('resize', resizeCanvas);
                resizeCanvas();
            };
            
            function clearSignature() {
                signaturePad.clear();
                signatureHistory = [];
            }
            
            function undoSignature() {
                const data = signaturePad.toData();
                if (data) {
                    signatureHistory.push(data);
                    signaturePad.clear();
                    if (signatureHistory.length > 0) {
                        const previousData = signatureHistory.pop();
                        signaturePad.fromData(previousData);
                    }
                }
            }
            
            document.getElementById('sign-form').onsubmit = function(e) {
                if (signaturePad.isEmpty()) {
                    document.getElementById('error-message').style.display = 'block';
                    e.preventDefault();
                    return false;
                }
                document.getElementById('signature-data').value = signaturePad.toDataURL();
                return true;
            };
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==================== ADMIN AREA ====================
// Handle admin login
if($action == 'login' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if($username === ADMIN_USER && $password === ADMIN_PASS) {
        $_SESSION['admin_logged_in'] = true;
        header('Location: ?action=admin');
        exit;
    } else {
        $error = "Invalid username or password!";
    }
}

// Handle admin logout
if($action == 'logout') {
    session_destroy();
    header('Location: ?');
    exit;
}

// Admin CRUD Operations
if($action == 'admin' || (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'])) {
    if(!isAdminLoggedIn()) {
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Admin Login</title>
            <style>
                body { font-family: Arial, sans-serif; background: #f5f5f5; margin: 0; padding: 20px; display: flex; justify-content: center; align-items: center; min-height: 100vh; }
                .login-box { max-width: 400px; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 5px 20px rgba(0,0,0,0.1); }
                h2 { text-align: center; color: #dc2626; margin-bottom: 30px; }
                input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 5px; }
                button { width: 100%; padding: 12px; background: #dc2626; color: white; border: none; border-radius: 5px; cursor: pointer; }
                .error { background: #fee2e2; color: #dc2626; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
            </style>
        </head>
        <body>
            <div class="login-box">
                <h2>Contract Management System</h2>
                <h3 style="text-align: center;">Admin Login</h3>
                <?php if(isset($error)): ?>
                    <div class="error"><?php echo $error; ?></div>
                <?php endif; ?>
                <form method="POST" action="?action=login">
                    <input type="text" name="username" placeholder="Username" required>
                    <input type="password" name="password" placeholder="Password" required>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    // Handle admin actions
    $admin_action = $_GET['admin_action'] ?? 'dashboard';
    
    // Delete client
    if($admin_action == 'delete_client' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM clients WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header('Location: ?action=admin&admin_action=clients');
        exit;
    }
    
    // Delete contract
    if($admin_action == 'delete_contract' && isset($_GET['id'])) {
        $stmt = $pdo->prepare("DELETE FROM contracts WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        header('Location: ?action=admin&admin_action=contracts');
        exit;
    }
    
    // Add/Edit client
    if($admin_action == 'save_client' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $id = $_POST['id'] ?? 0;
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);
        
        if($id) {
            $stmt = $pdo->prepare("UPDATE clients SET name=?, email=?, phone=?, address=? WHERE id=?");
            $stmt->execute([$name, $email, $phone, $address, $id]);
        } else {
            $stmt = $pdo->prepare("INSERT INTO clients (name, email, phone, address) VALUES (?, ?, ?, ?)");
            $stmt->execute([$name, $email, $phone, $address]);
        }
        header('Location: ?action=admin&admin_action=clients');
        exit;
    }
    
    // Add/Edit contract
    if($admin_action == 'save_contract' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $id = $_POST['id'] ?? 0;
        $client_id = $_POST['client_id'];
        $title = sanitize($_POST['title']);
        $content = $_POST['content'];
        
        if($id) {
            $stmt = $pdo->prepare("UPDATE contracts SET client_id=?, title=?, content=? WHERE id=?");
            $stmt->execute([$client_id, $title, $content, $id]);
        } else {
            $unique_link = generateUniqueLink();
            $stmt = $pdo->prepare("INSERT INTO contracts (client_id, title, content, unique_link) VALUES (?, ?, ?, ?)");
            $stmt->execute([$client_id, $title, $content, $unique_link]);
        }
        header('Location: ?action=admin&admin_action=contracts');
        exit;
    }
    
    // Save settings
    if($admin_action == 'save_settings' && $_SERVER['REQUEST_METHOD'] == 'POST') {
        $business_name = sanitize($_POST['business_name']);
        $business_address = sanitize($_POST['business_address']);
        $business_phone = sanitize($_POST['business_phone']);
        $business_email = sanitize($_POST['business_email']);
        
        $logo_path = '';
        if(isset($_FILES['business_logo']) && $_FILES['business_logo']['error'] == 0) {
            $upload_dir = __DIR__ . '/uploads/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }
            $ext = strtolower(pathinfo($_FILES['business_logo']['name'], PATHINFO_EXTENSION));
            $new_filename = 'logo_' . time() . '.' . $ext;
            $upload_path = $upload_dir . $new_filename;
            
            if(move_uploaded_file($_FILES['business_logo']['tmp_name'], $upload_path)) {
                $logo_path = 'uploads/' . $new_filename;
            }
        }
        
        if($logo_path) {
            $stmt = $pdo->prepare("UPDATE settings SET business_name=?, business_address=?, business_phone=?, business_email=?, business_logo=? WHERE id=1");
            $stmt->execute([$business_name, $business_address, $business_phone, $business_email, $logo_path]);
        } else {
            $stmt = $pdo->prepare("UPDATE settings SET business_name=?, business_address=?, business_phone=?, business_email=? WHERE id=1");
            $stmt->execute([$business_name, $business_address, $business_phone, $business_email]);
        }
        
        header('Location: ?action=admin&admin_action=settings&success=1');
        exit;
    }
    
    $settings = getBusinessSettings($pdo);
    $clients = $pdo->query("SELECT * FROM clients ORDER BY name")->fetchAll();
    $contracts = $pdo->query("SELECT c.*, cl.name as client_name FROM contracts c LEFT JOIN clients cl ON c.client_id = cl.id ORDER BY c.created_at DESC")->fetchAll();
    $total_contracts = $pdo->query("SELECT COUNT(*) FROM contracts")->fetchColumn();
    $signed_contracts = $pdo->query("SELECT COUNT(*) FROM contracts WHERE status = 'signed'")->fetchColumn();
    $total_clients = $pdo->query("SELECT COUNT(*) FROM clients")->fetchColumn();
    
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Dashboard - Contract Management System</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; }
            .sidebar { background: #1f2937; color: white; width: 250px; height: 100vh; position: fixed; left: 0; top: 0; overflow-y: auto; }
            .sidebar h2 { padding: 20px; text-align: center; color: #dc2626; }
            .sidebar a { display: block; padding: 12px 20px; color: white; text-decoration: none; transition: 0.3s; }
            .sidebar a:hover, .sidebar a.active { background: #dc2626; }
            .content { margin-left: 250px; padding: 20px; }
            .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px; }
            .stat-card { background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
            .stat-number { font-size: 36px; font-weight: bold; color: #dc2626; margin: 10px 0; }
            .card { background: white; border-radius: 10px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            table { width: 100%; border-collapse: collapse; }
            th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background: #f8f9fa; }
            .status-badge { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
            .status-pending { background: #fed7aa; color: #92400e; }
            .status-signed { background: #d1fae5; color: #065f46; }
            .btn { display: inline-block; padding: 8px 16px; background: #dc2626; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; font-size: 14px; }
            .btn-sm { padding: 4px 8px; font-size: 12px; }
            .btn-secondary { background: #666; }
            .form-group { margin-bottom: 15px; }
            .form-group label { display: block; margin-bottom: 5px; font-weight: 500; }
            .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px; }
            .form-group textarea { min-height: 200px; }
            .success { background: #d1fae5; color: #065f46; padding: 10px; border-radius: 5px; margin-bottom: 20px; }
            .copy-link { background: #f0f0f0; padding: 5px; border-radius: 3px; font-size: 12px; width: 200px; }
            @media (max-width: 768px) { .sidebar { width: 100%; height: auto; position: relative; } .content { margin-left: 0; } table { font-size: 12px; } }
        </style>
    </head>
    <body>
        <div class="sidebar">
            <h2>Contract Manager</h2>
            <a href="?action=admin&admin_action=dashboard" class="<?php echo $admin_action == 'dashboard' ? 'active' : ''; ?>">Dashboard</a>
            <a href="?action=admin&admin_action=contracts" class="<?php echo $admin_action == 'contracts' ? 'active' : ''; ?>">Contracts</a>
            <a href="?action=admin&admin_action=clients" class="<?php echo $admin_action == 'clients' ? 'active' : ''; ?>">Clients</a>
            <a href="?action=admin&admin_action=settings" class="<?php echo $admin_action == 'settings' ? 'active' : ''; ?>">Settings</a>
            <a href="?action=logout">Logout</a>
        </div>
        
        <div class="content">
            <?php if($admin_action == 'dashboard'): ?>
                <div class="stats-grid">
                    <div class="stat-card"><div class="stat-label">Total Contracts</div><div class="stat-number"><?php echo $total_contracts; ?></div></div>
                    <div class="stat-card"><div class="stat-label">Signed Contracts</div><div class="stat-number"><?php echo $signed_contracts; ?></div></div>
                    <div class="stat-card"><div class="stat-label">Pending Contracts</div><div class="stat-number"><?php echo $total_contracts - $signed_contracts; ?></div></div>
                    <div class="stat-card"><div class="stat-label">Total Clients</div><div class="stat-number"><?php echo $total_clients; ?></div></div>
                </div>
                
                <div class="card">
                    <h3>Recent Contracts</h3>
                    <table>
                        <thead><tr><th>Title</th><th>Client</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach(array_slice($contracts, 0, 5) as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['title']); ?></td>
                                <td><?php echo htmlspecialchars($c['client_name']); ?></td>
                                <td><span class="status-badge status-<?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                                <td><?php echo date('Y-m-d', strtotime($c['created_at'])); ?></td>
                                <td><a href="?action=admin&admin_action=view_contract&id=<?php echo $c['id']; ?>" class="btn btn-sm">View</a></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            
            <?php elseif($admin_action == 'contracts'): 
                $edit_id = $_GET['edit'] ?? 0;
                $contract_data = null;
                if($edit_id) {
                    $stmt = $pdo->prepare("SELECT * FROM contracts WHERE id = ?");
                    $stmt->execute([$edit_id]);
                    $contract_data = $stmt->fetch();
                }
            ?>
                <div class="card">
                    <h2><?php echo $edit_id ? 'Edit Contract' : 'Create New Contract'; ?></h2>
                    <form method="POST" action="?action=admin&admin_action=save_contract">
                        <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
                        <div class="form-group">
                            <label>Select Client *</label>
                            <select name="client_id" required>
                                <option value="">-- Select Client --</option>
                                <?php foreach($clients as $client): ?>
                                <option value="<?php echo $client['id']; ?>" <?php echo ($contract_data && $contract_data['client_id'] == $client['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($client['name']); ?> - <?php echo htmlspecialchars($client['email']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Contract Title *</label>
                            <input type="text" name="title" value="<?php echo $contract_data['title'] ?? ''; ?>" required>
                        </div>
                        <div class="form-group">
                            <label>Contract Content *</label>
                            <textarea name="content" required><?php echo $contract_data['content'] ?? ''; ?></textarea>
                        </div>
                        <button type="submit" class="btn"><?php echo $edit_id ? 'Update Contract' : 'Create Contract'; ?></button>
                        <?php if($edit_id): ?>
                        <a href="?action=admin&admin_action=contracts" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="card">
                    <h2>All Contracts</h2>
                    <table>
                        <thead><tr><th>Title</th><th>Client</th><th>Status</th><th>Created</th><th>Contract Link</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach($contracts as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['title']); ?></td>
                                <td><?php echo htmlspecialchars($c['client_name']); ?></td>
                                <td><span class="status-badge status-<?php echo $c['status']; ?>"><?php echo ucfirst($c['status']); ?></span></td>
                                <td><?php echo date('Y-m-d', strtotime($c['created_at'])); ?></td>
                                <td><input type="text" class="copy-link" value="<?php echo SITE_URL; ?>/?action=sign&contract=<?php echo $c['unique_link']; ?>" readonly size="30"><button onclick="copyToClipboard(this)">Copy</button></td>
                                <td>
                                    <a href="?action=admin&admin_action=contracts&edit=<?php echo $c['id']; ?>" class="btn btn-sm">Edit</a>
                                    <a href="?action=admin&admin_action=delete_contract&id=<?php echo $c['id']; ?>" onclick="return confirm('Delete this contract?')" class="btn btn-sm btn-secondary">Delete</a>
                                    <a href="?action=pdf&contract=<?php echo $c['id']; ?>" target="_blank" class="btn btn-sm">PDF</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            
            <?php elseif($admin_action == 'clients'): 
                $edit_id = $_GET['edit'] ?? 0;
                $client_data = null;
                if($edit_id) {
                    $stmt = $pdo->prepare("SELECT * FROM clients WHERE id = ?");
                    $stmt->execute([$edit_id]);
                    $client_data = $stmt->fetch();
                }
            ?>
                <div class="card">
                    <h2><?php echo $edit_id ? 'Edit Client' : 'Add New Client'; ?></h2>
                    <form method="POST" action="?action=admin&admin_action=save_client">
                        <input type="hidden" name="id" value="<?php echo $edit_id; ?>">
                        <div class="form-group"><label>Full Name *</label><input type="text" name="name" value="<?php echo $client_data['name'] ?? ''; ?>" required></div>
                        <div class="form-group"><label>Email Address *</label><input type="email" name="email" value="<?php echo $client_data['email'] ?? ''; ?>" required></div>
                        <div class="form-group"><label>Phone Number</label><input type="text" name="phone" value="<?php echo $client_data['phone'] ?? ''; ?>"></div>
                        <div class="form-group"><label>Address</label><textarea name="address" rows="3"><?php echo $client_data['address'] ?? ''; ?></textarea></div>
                        <button type="submit" class="btn"><?php echo $edit_id ? 'Update Client' : 'Add Client'; ?></button>
                        <?php if($edit_id): ?>
                        <a href="?action=admin&admin_action=clients" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </form>
                </div>
                
                <div class="card">
                    <h2>All Clients</h2>
                    <table>
                        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Created</th><th>Actions</th></tr></thead>
                        <tbody>
                            <?php foreach($clients as $c): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($c['name']); ?></td>
                                <td><?php echo htmlspecialchars($c['email']); ?></td>
                                <td><?php echo htmlspecialchars($c['phone']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($c['created_at'])); ?></td>
                                <td>
                                    <a href="?action=admin&admin_action=clients&edit=<?php echo $c['id']; ?>" class="btn btn-sm">Edit</a>
                                    <a href="?action=admin&admin_action=delete_client&id=<?php echo $c['id']; ?>" onclick="return confirm('Delete this client?')" class="btn btn-sm btn-secondary">Delete</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            
            <?php elseif($admin_action == 'settings'): ?>
                <div class="card">
                    <h2>Business Settings</h2>
                    <?php if(isset($_GET['success'])): ?>
                    <div class="success">Settings saved successfully!</div>
                    <?php endif; ?>
                    <form method="POST" action="?action=admin&admin_action=save_settings" enctype="multipart/form-data">
                        <div class="form-group"><label>Business Name</label><input type="text" name="business_name" value="<?php echo htmlspecialchars($settings['business_name'] ?? ''); ?>" required></div>
                        <div class="form-group"><label>Business Address</label><textarea name="business_address" rows="3"><?php echo htmlspecialchars($settings['business_address'] ?? ''); ?></textarea></div>
                        <div class="form-group"><label>Phone Number</label><input type="text" name="business_phone" value="<?php echo htmlspecialchars($settings['business_phone'] ?? ''); ?>"></div>
                        <div class="form-group"><label>Email Address</label><input type="email" name="business_email" value="<?php echo htmlspecialchars($settings['business_email'] ?? ''); ?>"></div>
                        <div class="form-group">
                            <label>Business Logo</label>
                            <?php if(!empty($settings['business_logo']) && file_exists($settings['business_logo'])): ?>
                            <div><img src="<?php echo $settings['business_logo']; ?>" style="max-width: 150px; margin-bottom: 10px;"></div>
                            <?php endif; ?>
                            <input type="file" name="business_logo" accept="image/*">
                        </div>
                        <button type="submit" class="btn">Save Settings</button>
                    </form>
                </div>
            
            <?php elseif($admin_action == 'view_contract'): 
                $contract_id = $_GET['id'] ?? 0;
                $contract = getContract($pdo, $contract_id);
                if($contract):
                    $client = getClient($pdo, $contract['client_id']);
            ?>
                <div class="card">
                    <h2><?php echo htmlspecialchars($contract['title']); ?></h2>
                    <div style="margin: 20px 0; padding: 15px; background: #f5f5f5; border-radius: 5px;">
                        <strong>Client:</strong> <?php echo htmlspecialchars($client['name']); ?><br>
                        <strong>Email:</strong> <?php echo htmlspecialchars($client['email']); ?><br>
                        <strong>Status:</strong> <span class="status-badge status-<?php echo $contract['status']; ?>"><?php echo ucfirst($contract['status']); ?></span><br>
                        <?php if($contract['status'] == 'signed'): ?>
                        <strong>Signed Date:</strong> <?php echo date('F j, Y H:i', strtotime($contract['signed_date'])); ?><br>
                        <?php endif; ?>
                    </div>
                    <div style="background: #fafafa; padding: 20px; border-radius: 5px; margin: 20px 0;">
                        <?php echo nl2br(htmlspecialchars($contract['content'])); ?>
                    </div>
                    <div style="margin-top: 20px;">
                        <a href="?action=pdf&contract=<?php echo $contract['id']; ?>" target="_blank" class="btn">Download PDF</a>
                        <a href="?action=admin&admin_action=contracts" class="btn btn-secondary">Back</a>
                    </div>
                </div>
            <?php endif; endif; ?>
        </div>
        
        <script>
        function copyToClipboard(button) {
            var input = button.previousElementSibling;
            input.select();
            document.execCommand('copy');
            button.textContent = 'Copied!';
            setTimeout(function() { button.textContent = 'Copy'; }, 2000);
        }
        </script>
    </body>
    </html>
    <?php
    exit;
}

// ==================== HOME PAGE ====================
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contract Management System</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            margin: 0;
            padding: 0;
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .container {
            text-align: center;
            background: white;
            padding: 50px;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 500px;
            margin: 20px;
        }
        h1 {
            color: #dc2626;
            margin-bottom: 20px;
        }
        .buttons {
            margin-top: 30px;
        }
        .btn {
            display: inline-block;
            padding: 12px 30px;
            margin: 10px;
            background: #dc2626;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            transition: 0.3s;
        }
        .btn:hover {
            background: #b91c1c;
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #666;
        }
        .btn-secondary:hover {
            background: #555;
        }
        .features {
            text-align: left;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #eee;
        }
        .features li {
            margin: 10px 0;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Contract Management System</h1>
        <p>Professional contract signing solution for your business</p>
        
        <div class="buttons">
            <a href="?action=admin" class="btn">Admin Login</a>
            <a href="?action=install" class="btn btn-secondary" onclick="return confirm('Run installation? This will create database tables.')">Install Database</a>
        </div>
        
        <div class="features">
            <h3>Features:</h3>
            <ul>
                <li>✓ Create and manage contracts</li>
                <li>✓ Client management</li>
                <li>✓ Digital signatures (finger/mouse)</li>
                <li>✓ PDF generation</li>
                <li>✓ Secure unique links</li>
                <li>✓ Mobile-friendly interface</li>
                <li>✓ Track signed/pending status</li>
            </ul>
        </div>
        
        <p style="margin-top: 20px; font-size: 12px; color: #999;">
            © <?php echo date('Y'); ?> Contract Management System
        </p>
    </div>
</body>
</html>
