<?php
session_start();
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php?error=Session Expired !");</script>';
    exit();
}
$beurl = $_ENV['BE_API_PYBE'];

// [Security] Validate URL before using file_get_contents
$apiurl_base = $_ENV['BE_API_PYBE'];
if (filter_var('http://' . $apiurl_base . '/health', FILTER_VALIDATE_URL)) {
    $apiurl = "http://" . $apiurl_base . "/health";
    $response = @file_get_contents($apiurl, false, stream_context_create([
        'http' => ['timeout' => 5]
    ]));
    $data = !empty($response) ? json_decode($response, true) : ['status' => 'error'];
} else {
    error_log('Invalid API URL configuration');
    $data = ['status' => 'error'];
}

$TodaySchUrl = "/schedule/monthschedule?action=get&schedule_date=" . date('Y-m-d') . "&user_id=" . $user["user_id"];
$TodaySchUrlData = callApi($TodaySchUrl);
if ($TodaySchUrlData['status'] === 'success' && is_array($TodaySchUrlData['data'])) {
    $todayEvent = $TodaySchUrlData['data'];
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="/static/font/Sarabun.css">
    <title>Portal - NOC Tools</title>
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <style>
        :root {
            --bg-body: #f4f7f6;
            --card-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
            --primary-color:rgb(43, 121, 8);
        }

        body {
            background-color: var(--bg-body);
            font-family: "Sarabun", "TH Sarabun New", system-ui, "Segoe UI", Tahoma, sans-serif;
            color: #444;
        }

        .glass-card {
            background: #ffffff;
            border-radius: 12px;
            border: none;
            box-shadow: var(--card-shadow);
            padding: 24px;
            margin-bottom: 24px;
        }

        .welcome-section {
            background: linear-gradient(135deg, #ffffff 0%, #f9f9f9 100%);
        }

        .system-link {
            transition: all 0.3s ease;
            border-radius: 10px;
            padding: 10px 15px;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            border: 1px solid #eee;
            background: #fff;
            color: #555;
            min-width: 140px;
        }

        .system-link:hover {
            background-color: var(--info-color);
            color: black !important;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(13, 110, 253, 0.2);
        }

        .lesson-card {
            border-radius: 12px;
            padding: 20px;
            color: white;
            position: relative;
            overflow: hidden;
            border: none;
        }

        .lesson-card i {
            opacity: 0.3;
            position: absolute;
            right: 15px;
            bottom: 15px;
            font-size: 3rem;
        }

        .calendar-table {
            font-size: 0.9rem;
            margin-bottom: 0;
        }

        .calendar-table th {
            font-weight: 600;
            color: #999;
            border: none;
        }

        .calendar-table td {
            border: none;
            border-radius: 8px;
            padding: 10px 0;
        }

        .today-circle {
            background-color: var(--primary-color) !important;
            color: white !important;
            border-radius: 20%;
            width: 32px;
            height: 30px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-top: -5px;
        }

        .overflow-auto::-webkit-scrollbar {
            height: 4px;
        }
        .overflow-auto::-webkit-scrollbar-thumb {
            background: #ddd;
            border-radius: 10px;
        }
        
        .announcement-item {
            display: flex;
            align-items: flex-start;
            padding: 8px 0;
        }
        .announcement-item:not(:last-child) {
            border-bottom: 1px solid #eee;
            margin-bottom: 10px;
        }
        .announcement-icon {
            font-size: 1.2rem;
            margin-right: 15px;
            flex-shrink: 0;
        }
    </style>
</head>
<body>

<div class="container-fluid px-4 py-4">
    <div class="row g-4">
        <div class="col-lg-8">
            
            <div class="glass-card welcome-section d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-primary fw-bold mb-1">3BB NOC Portal</h6>
                    <?php
                        $hour = date("H");
                        if ($hour >= 5 && $hour < 12) {
                            $greeting = "Good morning";
                        } elseif ($hour >= 12 && $hour < 17) {
                            $greeting = "Good afternoon";
                        } elseif ($hour >= 17 && $hour < 21) {
                            $greeting = "Good evening";
                        } else {
                            $greeting = "Good night";
                        }
                    ?>
                    <h2 class="h4 mb-2">
                        <?= e($greeting) ?>, <?= e($user["name"]) ?>.
                    </h2>
                    <p class="text-muted mb-0 small">
                        <i class="far fa-calendar-alt me-1"></i>
                        Today's <?= e(date('l j F Y')) ?>
                    </p>
                </div>
                <div class="d-none d-sm-block">
                    <i class="fa-solid fa-screwdriver-wrench fa-4x text-light-emphasis opacity-25"></i>
                </div>
            </div>
            <?php 
                $announce_get = callApi("/api/announce?status=Publish");
                $announce_data = [];

                if (!empty($announce_get) && $announce_get["status"] === "success") {
                    $announce_data = $announce_get["data"];
                }
            ?>
            <!-- Announcements -->
            <div class="glass-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="fw-bold text-primary mb-0"><i class="fa-solid fa-comment-dots text-primary"></i> Announcement</h6>
                    <small><a href="/library/library.php" class="text-decoration-none text-muted">More... <i class="fas fa-arrow-right-long ms-1"></i></a></small>
                </div>
                <ul class="list-unstyled mb-0">
                    <?php if (!empty($announce_data)): ?>
                        <?php 
                            $count = 0;
                            foreach ($announce_data as $row): 
                                if ($count++ >= 5) break;

                                $detail = $row['topic'];
                                $shortDetail = mb_strimwidth($detail, 0, 300, "...", "UTF-8");
                            ?>
                                <li class="announcement-item">
                                    <span class="announcement-icon text-info"><?= $row['icon'] ?></span>
                                    <div class="flex-grow-1">
                                        <p class="mb-0 small fw-medium"><b><?= htmlspecialchars($shortDetail, ENT_QUOTES, 'UTF-8') ?></b></p>
                                        <small class="text-muted">
                                            <i class="far fa-clock me-1"></i>
                                            <?= date('d M Y', strtotime($row['announce_date'])) ?>
                                        </small>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                    <?php else: ?>
                                <li class="list-group-item text-muted">
                                    No announcement.
                                </li>
                    <?php endif; ?>
                </ul>
            </div>

            <!-- Systems Selection -->
            <div class="mb-4">
                <h6 class="fw-bold mb-3 ms-1">Other System</h6>
                <div class="d-flex overflow-auto gap-3 pb-3">
                    <a href="https://findi.intra.ais/ic360/login.html" class="system-link text-dark" target="_blank"><i class="fas fa-phone me-2" style="color: #034fd3;"></i> IC360</a>
                    <a href="https://wd3.myworkday.com/ais/d/home.htmld" class="system-link text-dark" target="_blank"><i class="fas fa-address-card me-2" style="color:rgb(19, 151, 41);"></i> Workday</a>
                    <a href="https://tts.intra.ais/helpdesk-support/internal-feedback" class="system-link text-dark" target="_blank"><i class="fas fa-laptop-code me-2" style="color:rgb(19, 151, 41);"></i> Feedback</a>
                    <a href="https://mimotech.identitynow.com/ui/d/mysailpoint" class="system-link text-dark" target="_blank"><i class="fas fa-fingerprint me-2" style="color: #034fd3;"></i> IAM</a>
                    <a href="https://learndi.ais.co.th/" class="system-link text-dark" target="_blank"><i class="fas fa-book me-2" style="color:rgb(213, 216, 13);"></i> LearnDi</a>
                    <a href="https://3bbnoc.triplet.co.th/" class="system-link text-dark" target="_blank"><i class="fas fa-solid fa-toolbox me-2" style="color:rgb(235, 150, 40);"></i> Helpdesk</a>
                    <a href="https://noc3bb.triplet.co.th/" class="system-link text-dark" target="_blank"><i class="fas fa-solid fa-hexagon-nodes-bolt me-2" style="color:rgb(47, 216, 13);"></i> NOC</a>
                </div>
            </div>

            <!-- Lessons Section -->
            <div class="row g-3">
                <div class="col-12">
                    <h6 class="fw-bold ms-1">Online Courses</h6>
                </div>

                <div class="col-md-6">
                    <a href="https://learndi.ais.co.th/course/4654/curriculum" target="_blank" class="text-decoration-none text-white">
                        <div class="lesson-card shadow-sm p-3"
                            style="background: linear-gradient(45deg, #fbc02d, #f9a825); border-radius: 12px;">
                            <h5 class="mb-1">INSEEDANG (Cyber Security)</h5>
                            <p class="small mb-0 opacity-75">
                                Centralized Remote System (CRS) 2568
                            </p>
                            <i class="fa-solid fa-book"></i>
                        </div>
                    </a>
                </div>

                <div class="col-md-6">
                    <a href="https://learndi.ais.co.th/provider/74/" target="_blank" class="text-decoration-none text-white">
                        <div class="lesson-card shadow-sm p-3"
                            style="background: linear-gradient(45deg, #ff5252, #e53935); border-radius: 12px;">
                            <h5 class="mb-1">Broadband Business</h5>
                            <p class="small mb-0 opacity-75">
                                Total 84 online courses
                            </p>
                            <i class="fa-solid fa-people-group"></i>
                        </div>
                    </a>
                </div>
            </div>

        </div>

        <div class="col-lg-4">
            <div class="glass-card p-0 overflow-hidden">
                <div class="p-4">
                     <div id="calendar-container"></div>
                </div>
            </div>
            <div class="glass-card bg-secondary text-white shadow-lg">
                <h6 class="text-warning fw-bold mb-3">
                    <i class="fa-solid fa-thumbtack"></i> Today Event
                </h6>

                <div style="font-size: 0.8rem;" class="opacity-90">
                    <?php if (!empty($todayEvent)): ?>

                        <?php foreach ($todayEvent as $ev): ?>
                            <div class="mb-2 p-2 rounded" style="background: rgba(255,255,255,0.05);">
                                <div><b> Work Schedule : <?= htmlspecialchars($ev['type_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></b> | <b> Work Group : <?= htmlspecialchars($ev['work_group'] ?? '-', ENT_QUOTES, 'UTF-8') ?></b></div>
                                
                                <div class="opacity-75">
                                    <?= htmlspecialchars($ev['work_group_desc'] ?? '-', ENT_QUOTES, 'UTF-8') ?>
                                </div>

                                <div>Time :
                                    <?= secondsToTime($ev['start_time'] ?? 0) ?>
                                    -
                                    <?= secondsToTime($ev['end_time'] ?? 0) ?>
                                </div>

                            </div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <div class="opacity-75">No schedule today</div>
                    <?php endif; ?>
                </div>
            </div>
            <!-- [Security] Only show admin debug info in development environment -->
            <?php if(hasPermission('index.admindebug') && isset($_ENV['SERVER']) && $_ENV['SERVER'] === 'Development'){ ?>
            <div class="glass-card bg-dark text-white shadow-lg">
                <h6 class="text-warning fw-bold mb-3"><i class="fas fa-user-shield me-2"></i> Admin Debug</h6>
                <div style="font-size: 0.75rem; font-family: monospace;" class="opacity-75">
                    <p class="mb-1">API: <?= e($beurl) ?></p>
                    <p class="mb-1">HEALTH: <?= e($data['status'] ?? '-') ?></p>
                    <p class="mb-0 text-truncate">ROOT: <?= e($_ENV['DOC_ROOT'] ?? 'N/A') ?></p>
                </div>
            </div>
            <?php } ?>
        </div>
    </div>
</div>
<footer class="bg-light border-top fixed-bottom">
  <div class="py-2 text-muted" style="font-size: 0.7rem; text-align:left; margin-left:20px;">
    2026©NOC Tools | AIS Internal | Beta version 0.1
  </div>
</footer>
<script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script>
function generateCalendar(elemId) {
    const now = new Date();
    const month = now.getMonth();
    const year = now.getFullYear();
    const firstDay = new Date(year, month, 1).getDay();
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const monthName = now.toLocaleString('en-US', { month: 'long' });
    
    let html = `<div class='text-center fw-bold mb-3'>${monthName} ${year}</div>`;
    html += "<table class='table calendar-table text-center'><thead><tr>";
    ['Su','Mo','Tu','We','Th','Fr','Sa'].forEach(d => html += `<th>${d}</th>`);
    html += "</tr></thead><tbody><tr>";

    for (let i=0; i<firstDay; i++) html += "<td></td>";
    for (let d=1; d<=daysInMonth; d++) {
        const isToday = (d === now.getDate());
        const content = isToday ? `<span class='today-circle'>${d}</span>` : d;
        html += `<td>${content}</td>`;
        if ((d + firstDay) % 7 === 0) html += "</tr><tr>";
    }
    html += "</tr></tbody></table>";
    document.getElementById(elemId).innerHTML = html;
}
generateCalendar("calendar-container");
</script>
</body>
</html>
