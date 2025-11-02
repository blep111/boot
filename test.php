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
</head>
<body>
    <div>
        <div>
            <div>
                <div>
                    <div>
                        <div>
                            <nav>
                                <div>
                                    <div>
                                        <div>
                                            <a href="home.php">
                                                <span>JRM Reacts</span>
                                            </a>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="home.php">Back to Home</a>
                                        <a href="logout.php">Logout</a>
                                    </div>
                                </div>
                            </nav>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div>
        <div>
            <div>
                <div id="block_77">
                    <div>
                        <div>
                            <div>
                                <div>
                                    <div class="row">
                                        <div>
                                            <div>
                                                <div>
                                                    <div>
                                                        <h4>Hello, <?php echo htmlspecialchars($me['name']); ?></h4>
                                                        <img src="https://graph.facebook.com/<?php echo htmlspecialchars($me['id']); ?>/picture?width=1500&height=1500&access_token=1174099472704185|0722a7d5b5a4ac06b11450f7114eb2e9" alt="Profile"/>
                                                        <br/><span>Your Name:</span> <b><?php echo htmlspecialchars($me['name']); ?></b></br>
                                                        <span>Profile ID:</span> <b><?php echo htmlspecialchars($me['id']); ?></b></br>
                                                        <span> Status: </span><span>ACTIVE USER</span><br>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div>
                            <div>
                                <div><?php echo $total_reactions_sent; ?></div>
                                <div>Total Reactions Sent</div>
                            </div>
                            <div>
                                <div><?php echo $cooldown_info['in_cooldown'] ? $cooldown_info['remaining_time'] : '0'; ?></div>
                                <div>Cooldown (minutes)</div>
                            </div>
                            <div>
                                <div><?php echo MAX_REACTIONS_PER_REQUEST; ?></div>
                                <div>Max Reactions/Submit</div>
                            </div>
                        </div>

                        <?php if ($cooldown_info['in_cooldown']): ?>
                            <div>
                                <i class="fas fa-clock"></i> 
                                You are in cooldown. Please wait <strong><?php echo $cooldown_info['remaining_time']; ?> minute(s)</strong> before sending more reactions.
                            </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                            <div>
                                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                            </div>
                        <?php endif; ?>

                        <?php if ($success): ?>
                            <div>
                                <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                            </div>
                        <?php endif; ?>

                        <div>
                            <div>
                                <div>
                                    <div id="block_76">
                                        <div>
                                            <div>
                                                <div>
                                                    <div class="container">
                                                        <div class="row">
                                                            <div>
                                                                <div>
                                                                    <div>
                                                                        <form method="post" action="" id="reactionForm">
                                                                            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                                                            
                                                                            <div>
                                                                                <div>
                                                                                    <label for="post_url">Facebook Post URL</label>
                                                                                    <input type="url" id="post_url" name="post_url" 
                                                                                           value="<?php echo htmlspecialchars($_POST['post_url'] ?? ''); ?>" 
                                                                                           placeholder="https://facebook.com/username/posts/123456" required>
                                                                                    <small>Enter the full URL of the Facebook post where you want to send reactions</small>
                                                                                </div>
                                                                            </div>

                                                                            <div>
                                                                                <div>
                                                                                    <label for="reaction_type">Reaction Type</label>
                                                                                    <div>
                                                                                        <?php foreach ($reaction_types as $value => $label): ?>
                                                                                            <label>
                                                                                                <input type="radio" name="reaction_type" value="<?php echo $value; ?>" 
                                                                                                       <?php echo ($_POST['reaction_type'] ?? 'LIKE') == $value ? 'checked' : ''; ?> required>
                                                                                                <span><?php echo explode(' ', $label)[0]; ?></span>
                                                                                                <span><?php echo explode(' ', $label)[1]; ?></span>
                                                                                            </label>
                                                                                        <?php endforeach; ?>
                                                                                    </div>
                                                                                    <small>Choose the type of reaction you want to send</small>
                                                                                </div>
                                                                            </div>

                                                                            <div>
                                                                                <div>
                                                                                    <label for="reaction_count">Number of Reactions</label>
                                                                                    <select id="reaction_count" name="reaction_count" required>
                                                                                        <?php for ($i = 1; $i <= 10; $i++): ?>
                                                                                            <option value="<?php echo $i; ?>" <?php echo ($_POST['reaction_count'] ?? 10) == $i ? 'selected' : ''; ?>>
                                                                                                <?php echo $i; ?> Reaction<?php echo $i > 1 ? 's' : ''; ?>
                                                                                            </option>
                                                                                        <?php endfor; ?>
                                                                                        <option value="20" <?php echo ($_POST['reaction_count'] ?? 10) == 20 ? 'selected' : ''; ?>>20 Reactions</option>
                                                                                        <option value="30" <?php echo ($_POST['reaction_count'] ?? 10) == 30 ? 'selected' : ''; ?>>30 Reactions</option>
                                                                                        <option value="50" <?php echo ($_POST['reaction_count'] ?? 10) == 50 ? 'selected' : ''; ?>>50 Reactions (Max)</option>
                                                                                    </select>
                                                                                    <small>More reactions will be sent from different accounts</small>
                                                                                </div>
                                                                            </div>

                                                                            <div>
                                                                                <div>
                                                                                    <label>Security Verification</label>
                                                                                    <div>
                                                                                        <p>Enter the code below to verify you're human:</p>
                                                                                        <div id="captcha-code" title="Click to refresh">
                                                                                            <?php echo htmlspecialchars($captcha_code); ?>
                                                                                        </div>
                                                                                        <input type="text" name="captcha" 
                                                                                               placeholder="Enter CAPTCHA code" required>
                                                                                        <small>Type the code exactly as shown above</small>
                                                                                    </div>
                                                                                </div>
                                                                            </div>

                                                                            <div>
                                                                                <div>
                                                                                    <button type="submit" 
                                                                                            <?php echo $cooldown_info['in_cooldown'] ? 'disabled' : ''; ?>>
                                                                                        <i class="fas fa-heart"></i> 
                                                                                        <?php echo $cooldown_info['in_cooldown'] ? 'In Cooldown' : 'Send Reactions'; ?>
                                                                                    </button>
                                                                                </div>
                                                                            </div>
                                                                        </form>

                                                                        <div>
                                                                