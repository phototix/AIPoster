<?php
date_default_timezone_set('Asia/Singapore');
// Error handling setup
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Custom error handler
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// Exception handler
set_exception_handler(function($e) {
    error_log("Uncaught Exception: " . $e->getMessage());
    displayErrorPage("Something went wrong. Our team has been notified. " . $e->getMessage());
});

// Display friendly error page
function displayErrorPage($message) {
    header('HTTP/1.1 500 Internal Server Error');
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Error</title>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
            .error-container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px; }
            .error-heading { color: #d9534f; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1 class="error-heading">Oops! Something went wrong</h1>
            <p><?= htmlspecialchars($message) ?></p>
            <p>Please try again later or contact support if the problem persists.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Display Custome Message page
function displayCustomPage() {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Auth</title>
        <!-- Bootstrap 5 CSS CDN -->
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <!-- Font Awesome for icons -->
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; padding: 20px; }
            .error-container { max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 5px; }
            .error-heading { color: #d9534f; }
        </style>
    </head>
    <body>
        <div class="error-container">
            <h1 class="error-heading">Provide password</h1>
            <p>
                <center>
                    <form method="get">
                          <div class="form-group">
                            <label for="passkey">Password</label>
                            <input type="password" class="form-control" id="passkey" name="passkey" placeholder="Password">
                        </div>
                        <input type="submit" class="btn btn-success">
                    </form>
                </center>
            </p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Ensure config directory is not web-accessible
define('CONFIG_DIR', __DIR__ . '/config/');
require_once CONFIG_DIR . 'config.php';
$config = include(CONFIG_DIR . 'config.php');

// Set up error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1); // Disable in production
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Secure session
session_start([
    'cookie_httponly' => true,
    'cookie_secure' => true,
    'cookie_samesite' => 'Strict'
]);

function writeLog($message, $type = 'INFO') {
    $logDir = __DIR__ . '/logs';
    $logFile = $logDir . '/error.log';

    if (!file_exists($logDir)) {
        mkdir($logDir, 0775, true); // Create logs directory if it doesn't exist
    }

    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp][$type] $message" . PHP_EOL;

    file_put_contents($logFile, $entry, FILE_APPEND);
}

// Load routine data with validation
function loadRoutineData($filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("Routine data file not found");
    }

    $data = json_decode(file_get_contents($filePath), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON data");
    }

    return $data['schedule'] ?? [];
}

try {
    $schedule = loadRoutineData($config['app']['routine_data']);
    $selectedActivity = $schedule[array_rand($schedule)];
} catch (Exception $e) {
    die("Error loading data: " . $e->getMessage());
}

// API Call with caching
function cachedApiCall($cacheKey, $callback) {
    global $config;
    
    $cacheFile = $config['app']['cache_dir'] . md5($cacheKey) . '.cache';
    
    // Return cached if exists and fresh (< 1 hour)
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 3600)) {
        return unserialize(file_get_contents($cacheFile));
    }
    
    $result = $callback();
    file_put_contents($cacheFile, serialize($result));
    
    writeLog("load api");

    return $result;
}

// Generate caption with OpenAI
function generateCaption($prompt) {
    global $config;

    return cachedApiCall("caption_" . md5($prompt), function() use ($prompt, $config) {
        $url = 'https://api.openai.com/v1/chat/completions';

        $data = [
            'model' => $config['openai']['caption_model'], // e.g. "gpt-4.1" or "gpt-3.5-turbo"
            'messages' => [
                [
                    'role' => 'developer',
                    'content' => "You are a Instagram post content creator as regular human."
                ],
                [
                    'role' => 'system',
                    'content' => "Write a short, catchy Instagram caption (max 20 words), Do not return time in output. output in plain text, not text decoration."
                ],
                [
                    'role' => 'user',
                    'content' => "Write a short, catchy Instagram caption (max 20 words), Do not return time in output. output in plain text, not text decoration. $prompt"
                ]
            ],
            'max_tokens' => 50,
            'temperature' => 0.7
        ];

        $options = [
            'http' => [
                'header' => [
                    "Content-Type: application/json",
                    "Authorization: Bearer {$config['openai']['api_key']}"
                ],
                'method' => 'POST',
                'content' => json_encode($data)
            ]
        ];

        $context = stream_context_create($options);
        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            $error = error_get_last();
            return "API call failed: " . ($error['message'] ?? 'Unknown error');
        }

        $result = json_decode($response, true);
        writeLog($result['choices'][0]['message']['content']);
        return trim($result['choices'][0]['message']['content'] ?? "Something inspiring!");
    });
}

// Generate image with DALL-E
function generateImage($prompt, $outputFile = null) {
    global $config;

    $url = 'https://api.openai.com/v1/images/generations';

    $data = [
        'model' => $config['openai']['image_model'],
        'prompt' => "Realistic, Nikon D780, 70-200mm f/2.8. Closeup. ".$prompt,
        'n' => 1,
        'size' => '1024x1024'
        // removed response_format
    ];

    $headers = [
        "Content-Type: application/json",
        "Authorization: Bearer {$config['openai']['api_key']}"
    ];

    $options = [
        'http' => [
            'header'  => $headers,
            'method'  => 'POST',
            'content' => json_encode($data),
            'ignore_errors' => true
        ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($url, false, $context);

    if ($response === false) {
        $error = error_get_last();
        writeLog("Image generation failed: " . ($error['message'] ?? 'Unknown error'), 'ERROR');
        return false;
    }

    $result = json_decode($response, true);

    if (!isset($result['data'][0]['url'])) {
        writeLog("Unexpected API response: " . $response, 'ERROR');
        return false;
    }

    $imageUrl = $result['data'][0]['url'];

    // If outputFile provided, download the image and save locally
    if ($outputFile) {
        $imageData = @file_get_contents($imageUrl);
        if ($imageData === false) {
            writeLog("Failed to download generated image from URL: $imageUrl", 'ERROR');
            return false;
        }
        file_put_contents($outputFile, $imageData);
        writeLog("Image saved to $outputFile from URL: $imageUrl", 'INFO');
        return $outputFile;
    }

    // Otherwise just return the URL
    writeLog("Image generation succeeded. URL: $imageUrl", 'INFO');
    return $imageUrl;
}

// Directory to store generated content
define('GENERATED_DIR', __DIR__ . '/generated/');
if (!file_exists(GENERATED_DIR)) {
    mkdir(GENERATED_DIR, 0775, true);
}

// Helper to generate unique ID (you can use uniqid or random bytes)
function generateUniqueId() {
    return bin2hex(random_bytes(5)); // 10 hex chars
}

// Get id from query param
$id = $_GET['id'] ?? null;
$passkey = $_GET['passkey'] ?? null;

if (!$id) {
    // No id provided, generate new content

    if ($passkey&&$passkey==="Quidents64") {
        // Pick random activity as before
        try {
            $schedule = loadRoutineData($config['app']['routine_data']);
            $selectedActivity = $schedule[array_rand($schedule)];
        } catch (Exception $e) {
            die("Error loading data: " . $e->getMessage());
        }

        // Generate caption prompt string
        $captionPrompt = "At {$selectedActivity['time']}, I'm {$selectedActivity['activity']} ({$selectedActivity['actions']})";

        // Generate caption
        $caption = generateCaption($captionPrompt);

        // Generate image and save locally
        $id = date('Y-m-d')."-".generateUniqueId();
        $imageFilename = GENERATED_DIR . $id . '.jpg';
        $savedImage = generateImage($selectedActivity['activity'], $imageFilename);

        if (!$savedImage) {
            die("Failed to generate or save image.");
        }

        // Save JSON with caption and activity data
        $jsonData = [
            'caption' => $caption,
            'activity' => $selectedActivity,
            'create_date' => date('Y-m-d'),
            'create_time' => date('H:i:s'),
            'image_file' => $id . '.jpg'
        ];
        file_put_contents(GENERATED_DIR . $id . '.json', json_encode($jsonData, JSON_PRETTY_PRINT));

        // Redirect to ?id=...
        header("Location: ?id=$id");
        exit;
    }else{
        displayCustomPage();
        exit;
    }


} else {
    // id is provided, load existing data

    $jsonFile = GENERATED_DIR . $id . '.json';

    if (!file_exists($jsonFile)) {
        // If file not found, optionally generate new or show error
        die("Content not found for id: $id");
    }

    $jsonContent = file_get_contents($jsonFile);
    $data = json_decode($jsonContent, true);

    if (!$data) {
        die("Failed to read saved content.");
    }

    $caption = $data['caption'];
    $selectedActivity = $data['activity'];
    $imageUrl = '/generated/' . $data['image_file'];
    $pageUrl = "https://post.brandon.my/" . urlencode(strtolower(str_replace(' ', '-', $selectedActivity['activity'])));

    $ogTitle = "Brandon Chong's Activity: " . $selectedActivity['activity'];
    $ogDescription = $caption . " | Time: " . $selectedActivity['time'] . " | Type: " . $selectedActivity['type'];
    $ogImage = $imageUrl;
    $ogUrl = $pageUrl;

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brandon Chong's Activity</title>

    <?php if ($id) { ?>
    <!-- Open Graph / Facebook Meta Tags -->
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?php echo htmlspecialchars($ogUrl); ?>">
    <meta property="og:title" content="<?php echo htmlspecialchars($ogTitle); ?>">
    <meta property="og:description" content="<?php echo htmlspecialchars($ogDescription); ?>">
    <meta property="og:image" content="<?php echo htmlspecialchars($ogImage); ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    
    <!-- Twitter Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta property="twitter:domain" content="example.com">
    <meta property="twitter:url" content="<?php echo htmlspecialchars($ogUrl); ?>">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($ogTitle); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($ogDescription); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($ogImage); ?>">
    
    <!-- WhatsApp Specific -->
    <meta property="og:site_name" content="Brandon's Activity Tracker">
    <meta property="og:image:alt" content="<?php echo htmlspecialchars($selectedActivity['activity']); ?>">
    <?php } ?>

    <!-- Bootstrap 5 CSS CDN -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #fafafa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .instagram-post {
            max-width: 600px;
            margin: 30px auto;
            background: white;
            border: 1px solid #dbdbdb;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .post-header {
            display: flex;
            align-items: center;
            padding: 14px 16px;
            border-bottom: 1px solid #efefef;
        }
        .profile-pic {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: #007bff;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-right: 12px;
        }
        .username {
            font-weight: 600;
        }
        .post-image {
            width: 100%;
            height: auto;
            display: block;
        }
        .post-actions {
            padding: 10px 16px;
            font-size: 24px;
        }
        .action-icon {
            margin-right: 16px;
            cursor: pointer;
            transition: transform 0.2s;
        }
        .action-icon:hover {
            transform: scale(1.1);
        }
        .post-caption {
            padding: 0 16px 8px;
            font-size: 14px;
        }
        .caption-username {
            font-weight: 600;
            margin-right: 5px;
        }
        .post-time {
            padding: 0 16px 16px;
            color: #8e8e8e;
            font-size: 12px;
            text-transform: uppercase;
        }
        .activity-details {
            max-width: 600px;
            margin: 20px auto;
            background: white;
            border: 1px solid #dbdbdb;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .liked {
            color: #ed4956;
        }
        .bookmarked {
            color: #262626;
        }
    </style>
</head>
<body>
    <div class="container py-4">
        <a href="/">
            <h1>Journalog</h1>
        </a>
        <p>Journalog meets A.I</p>
        <div class="instagram-post">
            <div class="post-header">
                <div class="profile-pic">BC</div>
                <div class="username">brandon.chong</div>
            </div>
            <img src="<?php echo htmlspecialchars($imageUrl); ?>" alt="<?php echo htmlspecialchars($selectedActivity['activity']); ?>" class="post-image">
            <div class="post-actions">
                <span class="action-icon like-btn"><i class="far fa-heart"></i></span>
                <span class="action-icon" style="display:none;"><i class="far fa-comment"></i></span>
                <span class="action-icon" style="display:none;"><i class="far fa-paper-plane"></i></span>
                <span class="action-icon bookmark-btn ms-auto" style="display:none;"><i class="far fa-bookmark"></i></span>
            </div>
            <div class="post-caption">
                <span class="caption-username">brandon.chong</span>
                <?php echo htmlspecialchars(trim($caption)); ?>
            </div>
            <div class="post-time">
                <?php echo htmlspecialchars($selectedActivity['time']); ?> • Daily Routine
            </div>
        </div>
        
        <div class="activity-details" style="display:none;">
            <h3><i class="far fa-calendar-alt me-2"></i>Activity Details</h3>
            <div class="row">
                <div class="col-md-6">
                    <p><strong><i class="far fa-clock me-2"></i>Time:</strong> <?php echo htmlspecialchars($selectedActivity['time']); ?></p>
                    <p><strong><i class="fas fa-running me-2"></i>Activity:</strong> <?php echo htmlspecialchars($selectedActivity['activity']); ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong><i class="far fa-folder me-2"></i>Type:</strong> <?php echo htmlspecialchars($selectedActivity['type']); ?></p>
                    <p><strong><i class="fas fa-bolt me-2"></i>Action:</strong> <?php echo htmlspecialchars($selectedActivity['actions']); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Like button functionality
            const likeBtn = document.querySelector('.like-btn');
            likeBtn.addEventListener('click', function() {
                const icon = this.querySelector('i');
                icon.classList.toggle('far');
                icon.classList.toggle('fas');
                icon.classList.toggle('liked');
            });
            
            // Bookmark button functionality
            const bookmarkBtn = document.querySelector('.bookmark-btn');
            bookmarkBtn.addEventListener('click', function() {
                const icon = this.querySelector('i');
                icon.classList.toggle('far');
                icon.classList.toggle('fas');
                icon.classList.toggle('bookmarked');
            });
            
            // Double click to like the post
            const postImage = document.querySelector('.post-image');
            postImage.addEventListener('dblclick', function() {
                const icon = likeBtn.querySelector('i');
                icon.classList.remove('far');
                icon.classList.add('fas', 'liked');
                
                // Add a temporary heart animation
                const heart = document.createElement('div');
                heart.innerHTML = '<i class="fas fa-heart"></i>';
                heart.style.position = 'absolute';
                heart.style.fontSize = '80px';
                heart.style.color = 'white';
                heart.style.opacity = '0.8';
                heart.style.transform = 'translate(-50%, -50%)';
                heart.style.left = '50%';
                heart.style.top = '50%';
                heart.style.animation = 'heartBeat 0.6s ease-out forwards';
                
                this.parentNode.appendChild(heart);
                
                setTimeout(() => {
                    heart.remove();
                }, 1000);
            });
        });
    </script>
</body>
</html>
