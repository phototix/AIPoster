<?php
header('Content-Type: application/json');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 0;
$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;

$postsDir = '/var/www/post.brandon.my/generated/';
$postFiles = glob($postsDir . '*.json');

// Sort files by creation time (newest first)
usort($postFiles, function($a, $b) {
    return filemtime($b) - filemtime($a);
});

$totalPosts = count($postFiles);
$start = $page * $perPage;
$end = $start + $perPage;
$posts = [];

for ($i = $start; $i < $end && $i < $totalPosts; $i++) {
    $file = $postFiles[$i];
    $jsonContent = file_get_contents($file);
    $postData = json_decode($jsonContent, true);
    
    if ($postData) {
        $posts[] = $postData;
    }
}

echo json_encode([
    'success' => true,
    'posts' => $posts,
    'page' => $page,
    'per_page' => $perPage,
    'total' => $totalPosts
]);
?>