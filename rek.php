<?php
if (!function_exists('me')) {

include 'config.php';

class FacebookAPI {
    private $useragent;
    
    public function __construct() {
        global $useragent;
        $this->useragent = $useragent;
    }
    
    public function extract_post_id($url, $token = null) {
        $patterns = [
            '/\/posts\/([\w\d]+)\//',
            '/groups\/(\d+)\/permalink\/(\d+)\//',
            '/story\.php\?story_fbid=([0-9]+)/',
            '/photo\.php\?fbid=([0-9]+)/',
            '/permalink\.php\?story_fbid=([0-9]+)/',
            '/\/videos\/([0-9a-zA-Z]+)/',
            '/fbid=([0-9]+)/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        
        $url_parts = parse_url($url);
        $path = $url_parts['path'] ?? '';
        
        if (preg_match('/(\d+)_(\d+)/', $path, $matches)) {
            return $matches[0];
        }
        
        if (preg_match('/(\d+)/', $path, $matches)) {
            return $matches[1];
        }
        
        return null;
    }
    
    public function extract_profile_id($url) {
        $patterns = [
            '/facebook\.com\/(?:profile\.php\?id=)?(\d+)/',
            '/fbid=(\d+)/',
            '/facebook\.com\/([^\/?]+)/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    public function validate_token($access_token) {
        $url = 'https://graph.facebook.com/me?fields=id,name&access_token=' . $access_token;
        $response = $this->_req($url);
        $data = json_decode($response, true);
        
        return isset($data['id']) ? $data : null;
    }
    
    public function get_user_info($access_token) {
        $url = 'https://graph.facebook.com/me?fields=id,name,first_name,last_name,picture&access_token=' . $access_token;
        return json_decode($this->_req($url), true);
    }
    
    public function post_comment($post_id, $comment_text, $access_token) {
        $comment_url = "https://graph.facebook.com/{$post_id}/comments";
        
        $params = [
            'access_token' => $access_token,
            'message' => $comment_text
        ];
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $comment_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        $response_data = json_decode($response, true);
        
        return ($http_code >= 200 && $http_code < 300) && isset($response_data['id']);
    }
    
    public function send_reaction($post_id, $reaction_type, $access_token) {
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
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
    
    public function follow_user($user_id, $access_token) {
        $follow_url = "https://graph.facebook.com/{$user_id}/subscribers";
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $follow_url,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return $http_code === 200;
    }
    
    private function _req($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT => $this->useragent,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true
        ]);
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result;
    }
}

$fb_api = new FacebookAPI();

function me($access_token) {
    global $fb_api;
    return $fb_api->validate_token($access_token);
}

function extract_ids($url, $token = null) {
    global $fb_api;
    return $fb_api->extract_post_id($url, $token);
}

function extract_ids_for_follow($url) {
    global $fb_api;
    return $fb_api->extract_profile_id($url);
}

function getRemainingCooldown($last_used_time) {
    $cooldown_duration = 10 * 60;
    $current_time = time();
    $elapsed_time = $current_time - $last_used_time;
    $remaining_time = $cooldown_duration - $elapsed_time;
    
    return max(0, $remaining_time);
}

function getIdCooldownInfo($id, $table_name, $connection) {
    $sql = "SELECT UNIX_TIMESTAMP(last_used) AS last_used_timestamp FROM $table_name WHERE id_value = ?";
    $stmt = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($stmt, "s", $id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) > 0) {
        $row = mysqli_fetch_assoc($result);
        $last_used_time = $row['last_used_timestamp'];
        $remaining_seconds = getRemainingCooldown($last_used_time);
        
        if ($remaining_seconds > 0) {
            $remaining_minutes = ceil($remaining_seconds / 60);
            return ["in_cooldown" => true, "remaining_time" => $remaining_minutes];
        }
    }
    
    return ["in_cooldown" => false, "remaining_time" => 0];
}

function checkCooldown($id, $table_name, $connection) {
    $sql = "INSERT INTO $table_name (id_value, last_used) VALUES (?, NOW()) 
            ON DUPLICATE KEY UPDATE last_used = NOW()";
    $stmt = mysqli_prepare($connection, $sql);
    mysqli_stmt_bind_param($stmt, "s", $id);
    mysqli_stmt_execute($stmt);
}

function generate_csrf_token() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validate_csrf_token($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

function displaySweetAlert($icon, $title, $message) {
    echo '<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>';
    echo '<script>';
    echo '    document.addEventListener("DOMContentLoaded", function() {';
    echo '        Swal.fire({';
    echo '            icon: "'.$icon.'",';
    echo '            title: "'.$title.'",';
    echo '            text: '.json_encode($message).',';
    echo '            confirmButtonColor: "#667eea",';
    echo '            background: "#ffffff",';
    echo '            color: "#333333"';
    echo '        });';
    echo '    });';
    echo '</script>';
}

function invalidToken() {
    echo '
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Invalid Token - JRM Reacts</title>
    </head>
    <body>
        <div>
            <div>⚠️</div>
            <h1>Invalid Token</h1>
            <p>
                Your Facebook access token has expired or is invalid. 
                Please login again to continue using our services.
            </p>
            <a href="logout.php">
                Logout & Re-login
            </a>
        </div>
    </body>
    </html>';
    exit();
}

function validate_facebook_url($url) {
    $patterns = [
        '/facebook\.com\/.+/',
        '/fb\.com\/.+/',
        '/web\.facebook\.com\/.+/'
    ];
    
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url)) {
            return true;
        }
    }
    
    return false;
}

function getUserStatistics($user_id, $connection) {
    $stats = [
        'total_comments' => 0,
        'total_reactions' => 0,
        'total_reports' => 0,
        'total_follows' => 0
    ];
    
    $query = "SELECT COUNT(*) as total FROM user_comments WHERE user_id = ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_comments'] = $row['total'];
    }
    
    $query = "SELECT COUNT(*) as total FROM user_reactions WHERE user_id = ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_reactions'] = $row['total'];
    }
    
    $query = "SELECT SUM(reports_sent) as total FROM user_reports WHERE user_id = ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_reports'] = $row['total'] ?? 0;
    }
    
    $query = "SELECT COUNT(*) as total FROM user_follows WHERE user_id = ?";
    $stmt = mysqli_prepare($connection, $query);
    mysqli_stmt_bind_param($stmt, "s", $user_id);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if ($row = mysqli_fetch_assoc($result)) {
        $stats['total_follows'] = $row['total'];
    }
    
    return $stats;
}

}
?>