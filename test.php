<?php
session_start();
include 'config.php';
require_once 'functions.php';
$table_name = 'cooldown';
 
if (!isset($_SESSION['token']) && !isset($_SESSION['_userid'])) {
    header('Location: index.php');
    exit();
}
 
$error = '';
$success = '';
$captcha_code = '';
 
if (!isset($_SESSION['captcha_code'])) {
    generateCaptcha();
}
 
$captcha_code = $_SESSION['captcha_code'];
 
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
        $error = "Security validation failed. Please try again.";
    } else {
        $post_url = sanitize_input($_POST['post_url'] ?? '');
        $reaction_type = sanitize_input($_POST['reaction_type'] ?? '');
        $reaction_count = intval($_POST['reaction_count'] ?? 1);
        $user_captcha = sanitize_input($_POST['captcha'] ?? '');
 
        if (empty($post_url)) {
            $error = "Please enter a Facebook post URL";
        } elseif (!validate_facebook_url($post_url)) {
            $error = "Please enter a valid Facebook URL";
        } elseif (empty($reaction_type)) {
            $error = "Please select a reaction type";
        } elseif (empty($user_captcha)) {
            $error = "Please enter the CAPTCHA code";
        } elseif (strtoupper($user_captcha) !== $_SESSION['captcha_code']) {
            $error = "Invalid CAPTCHA code";
            generateCaptcha();
        } elseif ($reaction_count < 1 || $reaction_count > MAX_REACTIONS_PER_REQUEST) {
            $error = "Reaction count must be between 1 and " . MAX_REACTIONS_PER_REQUEST;
        } else {
            $user_id = $_SESSION['_userid'];
            if (!check_rate_limit($user_id, 'reaction', $connection)) {
                $error = "Rate limit exceeded. Please try again in an hour.";
            } else {
                $cooldown_info = getIdCooldownInfo($user_id, 'reaction_cooldown', $connection);
 
                if ($cooldown_info["in_cooldown"]) {
                    $error = "Please wait {$cooldown_info['remaining_time']} minute(s) before sending more reactions";
                } else {
                    $success_count = processReactions($post_url, $reaction_type, $reaction_count, $connection);
 
                    if ($success_count > 0) {
                        $success = "Successfully sent {$success_count} reaction(s) to the post";
                        setReactionCooldown($user_id, $connection);
                        generateCaptcha();
 
                        log_activity($user_id, 'reactions_sent', "Sent {$success_count} {$reaction_type} reactions", $connection);
                    } else {
                        $error = "Failed to send reactions. Please try again later.";
                    }
                }
            }
        }
    }
}
 
function generateCaptcha() {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $captcha = '';
    for ($i = 0; $i < 6; $i++) {
        $captcha .= $chars[rand(0, strlen($chars) - 1)];
    }
    $_SESSION['captcha_code'] = $captcha;
}
 
function processReactions($post_url, $reaction_type, $count, $connection) {
    $success_count = 0;
 
    $post_id = extract_ids($post_url);
    if (!$post_id) {
        return 0;
    }
 
    $query = "SELECT access_token FROM Likers WHERE activate = 0 ORDER BY RAND() LIMIT ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "i", $count);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
 
    $tokens = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $tokens[] = $row['access_token'];
    }
 
    foreach ($tokens as $token) {
        if (sendReaction($post_id, $reaction_type, $token)) {
            $success_count++;
            logReaction($_SESSION['_userid'], $post_url, $reaction_type, $connection);
        }
 
        usleep(500000);
    }
 
    return $success_count;
}
 
function sendReaction($post_id, $reaction_type, $access_token) {
    global $useragent;
 
    $reaction_url = "https://graph.facebook.com/{$post_id}/reactions";
 
    $params = [
        'access_token' => $access_token,
        'type' => $reaction_type
    ];
 
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $reaction_url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($params),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => $useragent,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 10
    ]);
 
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
 
    return $http_code === 200;
}
 
function setReactionCooldown($user_id, $connection) {
    $table_name = 'reaction_cooldown';
 
    $create_table = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
        id INT(6) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        id_value VARCHAR(30) NOT NULL,
        last_used TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user (id_value)
    )";
    mysqli_query($connection, $create_table);
 
    $sql = "INSERT INTO {$table_name} (id_value, last_used) VALUES (?, NOW()) 
            ON DUPLICATE KEY UPDATE last_used = NOW()";
    $stmt = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
    mysqli_stmt_execute($stmt);
}
 
function logReaction($user_id, $post_url, $reaction_type, $connection) {
    $table_name = 'user_reactions';
 
    $create_table = "CREATE TABLE IF NOT EXISTS `{$table_name}` (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL,
        post_url VARCHAR(500) NOT NULL,
        reaction_type VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX user_index (user_id)
    )";
    mysqli_query($connection, $create_table);
 
    $sql = "INSERT INTO {$table_name} (user_id, post_url, reaction_type) VALUES (?, ?, ?)";
    $stmt = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($stmt, "sss", $user_id, $post_url, $reaction_type);
    mysqli_stmt_execute($stmt);
}
 
$access_token = $_SESSION['token'];
$me = me($access_token);
 
$total_reactions_sent = getTotalReactionsSent($connection, $me['id']);
$cooldown_info = getIdCooldownInfo($me['id'], 'reaction_cooldown', $connection);
 
$reaction_types = [
    'LIKE' => '👍 Like',
    'LOVE' => '❤️ Love', 
    'HAHA' => '😄 Haha',
    'WOW' => '😯 Wow',
    'SAD' => '😢 Sad',
    'ANGRY' => '😠 Angry'
];
?>
 
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Auto Reactions - JRM Reacts</title>
    <meta name="referrer" content="default" id="meta_referrer" />
    <meta name="description" content="Automated reaction system for Facebook posts">
    <link rel="shortcut icon" href="img/favicon.png">
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="body">
    <div class="wrapper wrapper-navbar">
        <div id="block_58">
            <div class="block-wrapper">
                <div class="component_navbar">
                    <div class="component-navbar__wrapper">
                        <div class="sidebar-block__top component-navbar component-navbar__navbar-public editor__component-wrapper">
                            <div>
                                <nav class="navbar navbar-expand-lg navbar-light container-lg">
                                    <div class="navbar-public__header">
                                        <div class="sidebar-block__top-brand">
                                            <div class="component-navbar-brand component-navbar-public-brand">
                                                <a href="home.php" target="_self">
                                                    <span style="text-transform: uppercase; font-size: 24px; letter-spacing: 1.0px; line-height: 48px; font-weight: bold">
                                                        JRM Reacts
                                                    </span>
                                                </a>
                                            </div>
                                        </div>
                                        <div class="navbar-actions">
                                            <a href="home.php" class="btn" style="background: var(--primary); color: white;">
                                                <i class="fas fa-arrow-left"></i> Back to Home
                                            </a>
                                            <a href="logout.php" class="btn" style="background: var(--danger); color: white;">
                                                <i class="fas fa-sign-out-alt"></i> Logout
                                            </a>
                                        </div>
                                    </div>
                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
 
    <div class="wrapper-content">
        <div class="wrapper-content__header"></div>
        <div class="wrapper-content__body">
            <div id="block_77">
                <div class="totals">
                    <div class="bg"></div>
                    <div class="divider-top"></div>
                    <div class="divider-bottom"></div>
                    <div class="container-fluid">
                        <div class="row align-items-start justify-content-start">
                            <div class="col-lg-3 col-md-6 col-sm-12 mb-2 mt-2">
                                <div class="card h-100" style="padding-top: 24px; padding-bottom: 24px; border: none;">
                                    <div class="totals-block__card">
                                        <div class="totals-block__card-left">
                                            <h4 style="color: black;">Hello, <?php echo htmlspecialchars($me['name']); ?></h4>
                                            <img src="https://graph.facebook.com/<?php echo htmlspecialchars($me['id']); ?>/picture?width=1500&height=1500&access_token=1174099472704185|0722a7d5b5a4ac06b11450f7114eb2e9" alt="Profile" class="profile-avatar"/>
                                            <br/><span style="color: black;">Your Name:</span> <b style="color: black;"><?php echo htmlspecialchars($me['name']); ?></b></br>
                                            <span style="color: black;">Profile ID:</span> <b style="color: black;"><?php echo htmlspecialchars($me['id']); ?></b></br>
                                            <span style="color: black;"> Status: </span><span class="status-badge status-active">ACTIVE USER</span><br>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
 
                <div class="comment-stats">
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $total_reactions_sent; ?></div>
                        <div class="stat-label">Total Reactions Sent</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo $cooldown_info['in_cooldown'] ? $cooldown_info['remaining_time'] : '0'; ?></div>
                        <div class="stat-label">Cooldown (minutes)</div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-number"><?php echo MAX_REACTIONS_PER_REQUEST; ?></div>
                        <div class="stat-label">Max Reactions/Submit</div>
                    </div>
                </div>
 
                <?php if ($cooldown_info['in_cooldown']): ?>
                    <div class="cooldown-warning">
                        <i class="fas fa-clock"></i> 
                        You are in cooldown. Please wait <strong><?php echo $cooldown_info['remaining_time']; ?> minute(s)</strong> before sending more reactions.
                    </div>
                <?php endif; ?>
 
                <?php if ($error): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                    </div>
                <?php endif; ?>
 
                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                    </div>
                <?php endif; ?>
 
                <div class="wrapper-content">
                    <div class="wrapper-content__header"></div>
                    <div class="wrapper-content__body">
                        <div id="block_76">
                            <div class="sign-in">
                                <div class="bg"></div>
                                <div class="divider-top"></div>
                                <div class="divider-bottom"></div>
                                <div class="container">
                                    <div class="row sign-up-center-alignment">
                                        <div class="col-lg-8">
                                            <div class="component_card">
                                                <div class="card">
                                                    <form method="post" action="" id="reactionForm">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
 
                                                        <div class="component_form_group">
                                                            <div class="form-group">
                                                                <label for="post_url" class="control-label">Facebook Post URL</label>
                                                                <input type="url" class="form-control" id="post_url" name="post_url" 
                                                                       value="<?php echo htmlspecialchars($_POST['post_url'] ?? ''); ?>" 
                                                                       placeholder="https://facebook.com/username/posts/123456" required>
                                                                <small class="form-text text-muted">Enter the full URL of the Facebook post where you want to send reactions</small>
                                                            </div>
                                                        </div>
 
                                                        <div class="component_form_group">
                                                            <div class="form-group">
                                                                <label for="reaction_type" class="control-label">Reaction Type</label>
                                                                <div class="reaction-selector">
                                                                    <?php foreach ($reaction_types as $value => $label): ?>
                                                                        <label class="reaction-option">
                                                                            <input type="radio" name="reaction_type" value="<?php echo $value; ?>" 
                                                                                   <?php echo ($_POST['reaction_type'] ?? 'LIKE') == $value ? 'checked' : ''; ?> required>
                                                                            <span class="reaction-emoji"><?php echo explode(' ', $label)[0]; ?></span>
                                                                            <span class="reaction-label"><?php echo explode(' ', $label)[1]; ?></span>
                                                                        </label>
                                                                    <?php endforeach; ?>
                                                                </div>
                                                                <small class="form-text text-muted">Choose the type of reaction you want to send</small>
                                                            </div>
                                                        </div>
 
                                                        <div class="component_form_group">
                                                            <div class="form-group">
                                                                <label for="reaction_count" class="control-label">Number of Reactions</label>
                                                                <select class="form-control" id="reaction_count" name="reaction_count" required>
                                                                    <?php for ($i = 1; $i <= 10; $i++): ?>
                                                                        <option value="<?php echo $i; ?>" <?php echo ($_POST['reaction_count'] ?? 10) == $i ? 'selected' : ''; ?>>
                                                                            <?php echo $i; ?> Reaction<?php echo $i > 1 ? 's' : ''; ?>
                                                                        </option>
                                                                    <?php endfor; ?>
                                                                    <option value="20" <?php echo ($_POST['reaction_count'] ?? 10) == 20 ? 'selected' : ''; ?>>20 Reactions</option>
                                                                    <option value="30" <?php echo ($_POST['reaction_count'] ?? 10) == 30 ? 'selected' : ''; ?>>30 Reactions</option>
                                                                    <option value="50" <?php echo ($_POST['reaction_count'] ?? 10) == 50 ? 'selected' : ''; ?>>50 Reactions (Max)</option>
                                                                </select>
                                                                <small class="form-text text-muted">More reactions will be sent from different accounts</small>
                                                            </div>
                                                        </div>
 
                                                        <div class="component_form_group">
                                                            <div class="form-group">
                                                                <label class="control-label">Security Verification</label>
                                                                <div class="captcha-container">
                                                                    <p>Enter the code below to verify you're human:</p>
                                                                    <div class="captcha-code" id="captcha-code" title="Click to refresh">
                                                                        <?php echo htmlspecialchars($captcha_code); ?>
                                                                    </div>
                                                                    <input type="text" class="form-control" name="captcha" 
                                                                           placeholder="Enter CAPTCHA code" required 
                                                                           style="text-align: center; font-size: 18px; letter-spacing: 2px; text-transform: uppercase;">
                                                                    <small class="form-text" style="color: white;">Type the code exactly as shown above</small>
                                                                </div>
                                                            </div>
                                                        </div>
 
                                                        <div class="component_button_submit">
                                                            <div class="form-group">
                                                                <button type="submit" class="btn btn-block btn-big-primary" 
                                                                        <?php echo $cooldown_info['in_cooldown'] ? 'disabled' : ''; ?>>
                                                                    <i class="fas fa-heart"></i> 
                                                                    <?php echo $cooldown_info['in_cooldown'] ? 'In Cooldown' : 'Send Reactions'; ?>
                                                                </button>
                                                            </div>
                                                        </div>
                                                    </form>
 
                                                    <div class="reaction-guidelines mt-3">
                                                        <details>
                                                            <summary><i class="fas fa-info-circle"></i> Reaction Guidelines</summary>
                                                            <div class="guidelines-content">
                                                                <ul>
                                                                    <li>Reactions work best on public Facebook posts</li>
                                                                    <li>Ensure the post URL is correct and accessible</li>
                                                                    <li>Different reaction types may have varying success rates</li>
                                                                    <li>Reactions are sent from active Facebook accounts</li>
                                                                </ul>
                                                            </div>
                                                        </details>
                                                    </div>
 
                                                    <center>
                                                        <a href="home.php"><small><i>Go back to Home</i></small></a>
                                                    </center>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
 
    <div id="toast-container" class="toast-container"></div>
 
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="js/app.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const captchaElement = document.getElementById('captcha-code');
            if (captchaElement) {
                captchaElement.addEventListener('click', function() {
                    JRMReacts.refreshCaptcha();
                });
            }
 
            const reactionForm = document.getElementById('reactionForm');
            if (reactionForm) {
                JRMReacts.loadAutoSavedForm(reactionForm);
            }
 
            const reactionOptions = document.querySelectorAll('.reaction-option');
            reactionOptions.forEach(option => {
                option.addEventListener('click', function() {
                    reactionOptions.forEach(opt => opt.classList.remove('selected'));
                    this.classList.add('selected');
                });
            });
 
            const selectedReaction = document.querySelector('.reaction-option input:checked');
            if (selectedReaction) {
                selectedReaction.closest('.reaction-option').classList.add('selected');
            }
        });
    </script>
 
    <style>
        .reaction-selector {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(100px, 1fr));
            gap: 10px;
            margin: 10px 0;
        }

.reaction-option.selected {
            border-color: var(--primary);
            background: var(--primary-light);
            transform: scale(1.05);
        }
 
        .reaction-option input {
            display: none;
        }
 
        .reaction-emoji {
            font-size: 2rem;
            margin-bottom: 5px;
        }
 
        .reaction-label {
            font-weight: 600;
            color: var(--dark);
        }
 
        .reaction-guidelines {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            overflow: hidden;
        }
 
        .reaction-guidelines summary {
            padding: 1rem;
            background: #f8f9fa;
            cursor: pointer;
            font-weight: 600;
        }
 
        .guidelines-content {
            padding: 1rem;
            background: white;
        }
 
        .guidelines-content ul {
            margin: 0;
            padding-left: 1.5rem;
        }
 
        .guidelines-content li {
            margin-bottom: 0.5rem;
            color: #666;
        }
 
        @media (max-width: 768px) {
            .reaction-selector {
                grid-template-columns: repeat(3, 1fr);
            }
 
            .reaction-emoji {
                font-size: 1.5rem;
            }
        }
    </style>
</body>
</html>
 
<?php
function getTotalReactionsSent($connection, $user_id) {
    $create_table = "CREATE TABLE IF NOT EXISTS `user_reactions` (
        id INT(11) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id VARCHAR(32) NOT NULL,
        post_url VARCHAR(500) NOT NULL,
        reaction_type VARCHAR(20) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX user_index (user_id)
    )";
    mysqli_query($connection, $create_table);
 
    $query = "SELECT COUNT(*) as total FROM user_reactions WHERE user_id = ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
 
    return $row['total'] ?? 0;
}
?>
 
        .reaction-option {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 15px;
            border: 2px solid #e4e6eb;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-align: center;
        }
 
        .reaction-option:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }
