<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Brandon's Posts</title>
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #fafafa;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .post-container {
            max-width: 614px;
            margin: 0 auto;
            padding: 20px 0;
        }
        .post-card {
            background: #fff;
            border: 1px solid #dbdbdb;
            border-radius: 8px;
            margin-bottom: 24px;
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        .post-header {
            padding: 14px 16px;
            display: flex;
            align-items: center;
            border-bottom: 1px solid #efefef;
        }
        .post-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            margin-right: 12px;
            background-color: #f0f0f0;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #999;
        }
        .post-username {
            font-weight: 600;
            font-size: 14px;
            color: #262626;
        }
        .post-time {
            margin-left: auto;
            font-size: 12px;
            color: #8e8e8e;
        }
        .post-image {
            width: 100%;
            display: block;
        }
        .post-actions {
            padding: 6px 16px;
            font-size: 24px;
        }
        .post-action {
            margin-right: 16px;
            cursor: pointer;
        }
        .post-likes {
            padding: 0 16px;
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .post-caption {
            padding: 0 16px;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .post-comments {
            padding: 0 16px 8px;
            color: #8e8e8e;
            font-size: 14px;
        }
        .post-date {
            padding: 0 16px 12px;
            color: #8e8e8e;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.2px;
        }
        .loading {
            text-align: center;
            padding: 20px;
            color: #999;
            display: none;
        }
        .activity-badge {
            background-color: #f0f8ff;
            color: #1e90ff;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            margin-left: 8px;
        }
    </style>
</head>
<body>
    <div class="container-fluid p-0">
        <div class="post-container" id="postContainer">
            <!-- Posts will be loaded here -->
        </div>
        <div class="loading" id="loadingIndicator">
            <div class="spinner-border text-secondary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
        </div>
    </div>

    <!-- Bootstrap JS and dependencies -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <script>
        $(document).ready(function() {
            let loading = false;
            let allPostsLoaded = false;
            let currentPage = 0;
            const postsPerPage = 10;

            // Load initial posts
            loadPosts();

            // Infinite scroll
            $(window).scroll(function() {
                if ($(window).scrollTop() + $(window).height() > $(document).height() - 100) {
                    if (!loading && !allPostsLoaded) {
                        loadPosts();
                    }
                }
            });

            function loadPosts() {
                if (loading) return;
                
                loading = true;
                $('#loadingIndicator').show();

                $.ajax({
                    url: 'index.php/listPost',
                    type: 'GET',
                    data: {
                        page: currentPage,
                        per_page: postsPerPage
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.posts.length > 0) {
                                response.posts.forEach(function(post) {
                                    $('#postContainer').append(createPostElement(post));
                                });
                                currentPage++;
                            } else {
                                allPostsLoaded = true;
                            }
                        }
                    },
                    complete: function() {
                        loading = false;
                        $('#loadingIndicator').hide();
                    }
                });
            }

            function createPostElement(post) {
                const postDate = new Date(post.create_date + 'T' + post.create_time);
                const formattedDate = postDate.toLocaleDateString('en-US', { 
                    year: 'numeric', 
                    month: 'short', 
                    day: 'numeric' 
                });
                const formattedTime = postDate.toLocaleTimeString('en-US', { 
                    hour: '2-digit', 
                    minute: '2-digit' 
                });

                let activityBadge = '';
                if (post.activity && post.activity.type) {
                    activityBadge = `<span class="activity-badge">${post.activity.type}</span>`;
                }

                return `
                    <div class="post-card">
                        <div class="post-header">
                            <div class="post-avatar">
                                <i class="fas fa-user"></i>
                            </div>
                            <div class="post-username">brandonchong${activityBadge}</div>
                            <div class="post-time">${formattedTime}</div>
                        </div>
                        <img src="/var/www/post.brandon.my/generated/${post.image_file}" class="post-image" alt="Post image">
                        <div class="post-actions">
                            <i class="far fa-heart post-action"></i>
                            <i class="far fa-comment post-action"></i>
                            <i class="far fa-bookmark post-action" style="float: right;"></i>
                        </div>
                        <div class="post-likes">1,024 likes</div>
                        <div class="post-caption">
                            <span class="post-username" style="margin-right: 5px;">brandonchong</span>
                            ${post.caption}
                        </div>
                        ${post.activity && post.activity.actions ? `
                        <div class="post-comments">
                            <i class="fas fa-tasks" style="margin-right: 5px;"></i>
                            ${post.activity.actions} • ${post.activity.time}
                        </div>` : ''}
                        <div class="post-date">${formattedDate}</div>
                    </div>
                `;
            }
        });
    </script>
</body>
</html>