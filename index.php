<?php
require_once $_SERVER['DOCUMENT_ROOT'].'/function.php';
$token = isset($_COOKIE["token"]) ? $_COOKIE["token"] : null;
$user = isset($_SESSION["user_data"]) ? $_SESSION["user_data"] : null;
if (!checkLogin() || !$user || !$token) {
    echo '<script>window.top.location.replace("/auth/logon.php");</script>';
    exit();
}
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get-task') {

    header('Content-Type: application/json');

    $dataInput = [ "user_replace_id" => $user["user_id"] ?? '' ];
    $requestInfo = callApi("/api/request?action=get-task", "POST", $dataInput);

    echo json_encode([
        "count" => $requestInfo["count"] ?? 0
    ]);

    exit;
}
?>
<!DOCTYPE html>
<html lang="th">

<head>
    <meta charset="UTF-8">
    <meta http-equiv="refresh" content="28800">
    <link rel="stylesheet" href="/static/fontawesome/css/all.css">
    <link rel="stylesheet" href="/static/bootstrap/5.3.1/css/bootstrap.min.css">
    <link rel="stylesheet" href="/static/css/mainstyle.css">
    <script src="/static/bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
    <script src="/static/jquery/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="/static/datatable/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="/static/datatable/responsive.dataTables.min.css">
    <script src="/static/datatable/jquery.dataTables.min.js"></script>
    <script src="/static/datatable/dataTables.bootstrap5.min.js"></script>
    <script src="/static/datatable/dataTables.responsive.min.js"></script>
    <title>NOC Portal
        <? if (isset($serversite) && $serversite == "Staging"){ echo " > ".htmlspecialchars($serversite)." Server"; }?>
    </title>
</head>

<body>

    <!-- Sidebar -->
    <div class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <span class="header-title badge rounded-pill text-bg-success text-wrap fw-bolder">
                <i class="fa-solid fa-toolbox"></i>
                NOC TOOLS</span>
            <button class="toggle-btn" id="toggleBtn">☰</button>
        </div>

        <ul class="menu" id="menu">
            <li>
                <div class="menu-item active" onclick="loadPage('home.php', this, 'in')">
                    <i class="fas fa-home"></i><span>HOME</span>
                </div>
            </li>

            <?php if(hasPermission('menu.schedule')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="toggleSubmenu(this)">
                    <i class="fas fa-calendar-alt"></i><span>NOC SCHEDULE ▸</span>
                </div>
                <div class="submenu">
                    <a href="/schedule/" onclick="loadPage(this.href, this, 'in'); return false;"
                        title="Monthly Schedule">
                        <i class="fa-regular fa-calendar"></i><span>Monthly Schedule</span>
                    </a>
                    <a href="/schedule/schedule_daily.php" onclick="loadPage(this.href, this, 'in'); return false;"
                        title="Daily Schedule">
                        <i class="fa-solid fa-calendar-day"></i><span>Daily Schedule</span>
                    </a>
                    <?php if(hasPermission('schedule.personal')){ ?>
                    <a href="/schedule/person-schedule.php" onclick="loadPage(this.href, this, 'in'); return false;"
                        title="Personal Schedule">
                        <i class="fa-solid fa-user-clock"></i><span>Personal Schedule</span>
                    </a>
                    <?php } ?>
                    <a href="/schedule/request_board.php" onclick="loadPage(this.href, this, 'in'); return false;"
                        title="Request">
                        <i class="fa-regular fa-calendar-check"></i>
                        <span>
                            Request
                            <span id="requestBadge" class="badge bg-danger rounded-pill ms-1 shadow-sm"
                                style="display:none;">
                                0
                            </span>
                        </span>
                    </a>
                    <?php if(hasPermission('schedule.report')){ ?>
                    <a href="/schedule/request_report.php" onclick="loadPage(this.href, this, 'in'); return false;"
                        title="Request Report">
                        <i class="fa-solid fa-square-poll-vertical"></i><span>Request Report</span>
                    </a>
                    <a href="/schedule/overtime_report.php" onclick="loadPage(this.href, this, 'in'); return false;"
                        title="Overtime Report">
                        <i class="fa-solid fa-square-poll-vertical"></i><span>OT Report</span>
                    </a>
                    <?php } ?>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.helpdesktool')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="toggleSubmenu(this)">
                    <i class="fa-solid fa-ghost"></i><span>HELPDESK TOOL ▸</span>
                </div>
                <div class="submenu">
                    <a href="/helpdesktool/serviceorder/service_order.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-database"
                            title="Service Order"></i><span>Service Order</span></a>
                    <a href="/helpdesktool/fms/view-cp.php" onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-tag fa-flip-horizontal" title="Check Owne"></i><span>Check
                            Owner</span></a>
                    <a href="/helpdesktool/nwjob/search-nwjob.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-burst"
                            title="Check Node/SO Job"></i><span>Check Node Job</span></a>
                    <a href="/helpdesktool/search-wr/search-wr.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-person-digging"
                            title="Check Activity"></i><span>Check Activity</span></a>
                    <a href="/helpdesktool/change-ticket-owner/"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-arrow-right-arrow-left" title="Change Owner"></i><span>Change
                            Owner</span></a>
                    <a href="https://mimotech.sharepoint.com/sites/Helpdesk3BB-Mass/"
                        onclick="loadPage(this.href, this, 'out'); return false;"><i class="fa-regular fa-handshake"
                            title="HD SharePoint"></i><span>HD SharePoint</span></a>
                    <hr class="submenu-child">
                    <a href="/helpdesktool/telnet/" onclick="loadPage(this.href, this, 'in'); return false;">
                        <i class="fa-stack fa-2xs" style="font-size: 8px; margin-left:-3px;" title="Telnet Leaseline">
                            <i class="fa-regular fa-square fa-stack-2x"></i>
                            <i class="fa-solid fa-terminal fa-stack-1x"></i>
                        </i> <span>Telnet Leaseline</span>
                    </a>
                    <a href="/helpdesktool/bras/" onclick="loadPage(this.href, this, 'in'); return false;">
                        <i class="fa-stack fa-2xs" style="font-size: 8px; margin-left:-3px;" title="Telnet Bras">
                            <i class="fa-regular fa-square fa-stack-2x"></i>
                            <i class="fa-solid fa-terminal fa-stack-1x"></i>
                        </i><span>Telnet Bras</span></a>
                    <a href="/helpdesktool/node-chain/" onclick="loadPage(this.href, this, 'in'); return false;">
                        <i class="fa-stack fa-2xs" style="font-size: 8px; margin-left:-3px;" title="Node Chain">
                            <i class="fa-regular fa-square fa-stack-2x"></i>
                            <i class="fa-solid fa-terminal fa-stack-1x"></i>
                        </i><span>Node chain</span>
                    </a>
                    <a href="/helpdesktool/me2mp/" onclick="loadPage(this.href, this, 'in'); return false;">
                        <i class="fa-stack fa-2xs" style="font-size: 8px; margin-left:-3px;" title="ME to MP">
                            <i class="fa-regular fa-square fa-stack-2x"></i>
                            <i class="fa-solid fa-terminal fa-stack-1x"></i>
                        </i><span>ME to MPLS</span>
                    </a>
                    <a href="/helpdesktool/generate-config/"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-laptop-file"></i><span>Gen Config</span></a>

                    <!-- <a href="http://10.233.97.83:8080/noctools/leasedline4jb.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-terminal"></i><span>Telnet Leaseline</span></a>
                <a href="underconstruction.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-address-book"></i><span>Check Owner</span></a>
                <a href="underconstruction.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-folder-open"></i><span>Report</span></a>
                <div class="submenu-divider"></div>
                <a href="underconstruction.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-terminal"></i><span>Telnet Mass</span></a>
                <a href="http://10.233.97.83:8080/noctools/node_chain.php?user_id=<?php echo $user["user_id"]; ?>" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-link"></i><span>Node chain</span></a>
                <div class="submenu-divider"></div>
                <a href="/helpdesktool/converter/" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-money-bill-transfer"></i><span>Converter</span></a>
                <a href="underconstruction.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-robot"></i><span>Bot</span></a> -->
                    <hr class="submenu-child">
                    <a href="/helpdesktool/converter/customer_email_templates.html"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-envelope-open-text"></i><span>Email Template</span></a>
                    <a href="/helpdesktool/converter/scb-report/"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-file-pdf"></i><span>SCB Report</span></a>
                    <a href="/helpdesktool/phone-tool/phone-tools.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-tty"></i><span>Phone Tool</span></a>
                    <a href="/helpdesktool/bot-handle/" onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-robot"></i><span>Bot Handle</span></a>
                    <a href="/helpdesktool/baac-service-monitor/"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-display"></i><span>BAAC Monitor</span></a>
                    <hr class="submenu-child">
                    <a href="/helpdesktool/ic360/" onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa fa-dashboard"></i><span>IC360 Dashboard</span></a>
                    <a href="/helpdesktool/overdue-ticket-tracker/"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-clipboard-list"></i><span>Ticket Tracker</span></a>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.masscomplaint')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="toggleSubmenu(this)">
                    <i class="fa-solid fa-wifi"></i><span>MASS TOOL ▸</span>
                </div>
                <div class="submenu">
                    <a href="/helpdesktool/radius/radius.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-house-signal"
                            title="3BB Radius"></i><span>3BB Radius</span></a>
                    <a href="/helpdesktool/fbb-radius/" onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-house-signal" title="FBB Radius"></i><span>FBB Radius</span></a>
                    <!-- <a href="/helpdesktool/radius/radius.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-house-signal" title="NOC Radius"></i><span>NOC Radius</span></a>
                <a href="underconstruction.php" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa fa-sitemap"></i><span>Telnet OLT</span></a> -->
                    <a href="/helpdesktool/telnet-mass/" onclick="loadPage(this.href, this, 'in'); return false;">
                        <i class="fa-stack fa-2xs" style="font-size: 8px; margin-left:-3px;" title="Telnet Complaint">
                            <i class="fa-regular fa-square fa-stack-2x"></i>
                            <i class="fa-solid fa-terminal fa-stack-1x"></i>
                        </i><span>Telnet MASS</span>
                    </a>
                    <a href="/helpdesktool/mass-mail/mass-mail.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-envelope-open-text"></i><span>Mass Mail</span></a>
                    <?php if(!hasPermission('menu.helpdesktool') && hasPermission('menu.masscomplaint')){ ?>
                    <a href="/helpdesktool/nwjob/search-nwjob.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-burst"
                            title="Check Node/SO Job"></i><span>Check Node Job</span></a>
                    <a href="/helpdesktool/search-wr/search-wr.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-person-digging"
                            title="Check Activity"></i><span>Check Activity</span></a>
                    <?php } ?>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.networktool')){ ?>
            <hr class="submenu-hr">
            <li>
                <!-- <div class="menu-item">
                <a href="/network-tools/" onclick="loadPage(this.href, this, 'out'); return false;"><i class="fa-solid fa-earth-americas" title="NETWORK TOOLS"></i><span>NETWORK TOOLS</span></a>
            </div> -->
                <div class="menu-item" onclick="loadPage('/network-tools/', this, 'out')">
                    <i class="fa-solid fa-earth-americas"></i><span>NETWORK TOOLS</span>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.generaltool')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="toggleSubmenu(this)">
                    <i class="fa-solid fa-toolbox"></i><span>GENERAL TOOL ▸</span>
                </div>
                <div class="submenu">
                    <a href="https://10.10.19.156:31943" onclick="loadPage(this.href, this, 'out'); return false;"><i
                            class="fa-solid fa-hexagon-nodes"></i><span>NCE (RO1/6/9/10)</span></a>
                    <a href="https://10.10.19.155:31943" onclick="loadPage(this.href, this, 'out'); return false;"><i
                            class="fa-solid fa-hexagon-nodes"></i><span>NCE (RO2/3/4/5)</span></a>
                    <a href="https://10.10.19.154:31943" onclick="loadPage(this.href, this, 'out'); return false;"><i
                            class="fa-solid fa-hexagon-nodes"></i><span>NCE (RO7/8)</span></a>
                    <a href="https://10.18.1.161:31943" onclick="loadPage(this.href, this, 'out'); return false;"><i
                            class="fa-solid fa-hexagon-nodes"></i><span>NCE METRONET</span></a>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.library')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="loadPage('/library/library.php', this, 'in')">
                    <i class="fa-regular fa-newspaper"></i><span>LIBRARY</span>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.contact')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="loadPage('/contact/index.php', this, 'in')">
                    <i class="fa-regular fa-address-book"></i><span>CONTACT POINT</span>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.linkhub')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="loadPage('/hub/portal_hub.php', this, 'in')">
                    <i class="fa-solid fa-layer-group"></i><span>LINKS HUB</span>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.setting')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="toggleSubmenu(this)">
                    <i class="fas fa-cogs"></i><span>SETTING ▸</span>
                </div>
                <div class="submenu">
                    <?php if(hasPermission('setting.schedule')){ ?>
                    <a href="/setting/schedule/schedule_setting.php"
                        onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-calendar-plus"></i><span>Schedule Setting</span></a>
                    <? } ?>
                    <?php if(hasPermission('user.user_manage')){ ?>
                    <a href="/setting/users/user.php" onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-user-gear"></i><span>User Setting</span></a>
                    <? } ?>
                    <!-- <a href="/setting/system/" onclick="loadPage(this.href, this, 'in'); return false;"><i class="fa-solid fa-computer"></i><span>System Setting</span></a> -->
                    <?php if(hasPermission('setting.rolesetting')){ ?>
                    <a href="/setting/users/role_manage.php" onclick="loadPage(this.href, this, 'in'); return false;"><i
                            class="fa-solid fa-shield-halved"></i><span>Role Management</span></a>
                    <? } ?>
                </div>
            </li>
            <?php } ?>
            <?php if(hasPermission('menu.about')){ ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="loadPage('about.html', this, 'in')">
                    <i class="fas fa-info-circle"></i><span>ABOUT</span>
                </div>
            </li>
            <?php } ?>
            <hr class="submenu-hr">
            <li>
                <div class="menu-item" onclick="window.location.href='./auth/logout.php'">
                    <i class="fas fa-sign-out-alt"></i><span>LOGOUT</span>
                </div>
            </li>
            <hr class="submenu-hr">
        </ul>

        <div class="sidebar-footer">
            <div class="user-info">
                <i class="fa fa-user-circle user-icon"></i>
                <span class="truncate" title="<?php echo htmlspecialchars($user["name"]); ?>">
                    <?php echo "ID: ".$user["emp_id"]." | ".$user["name"]; ?>
                </span>
                <span class="truncate">
                    <? if (isset($serversite) && $serversite == "Staging"){ echo htmlspecialchars($serversite)." Server"; }?>
                </span>
            </div>
        </div>
    </div>

    <div class="main-content" id="main-content">
        <div id="loading-overlay">
            <div class="spinner"></div>
            <p>Loading...</p>
        </div>
        <iframe id="main-iframe" src="home.php"></iframe>
    </div>

    <script>
    const sidebar = document.getElementById('sidebar');
    const toggleBtn = document.getElementById('toggleBtn');
    const mainIframe = document.getElementById('main-iframe');
    const loadingOverlay = document.getElementById('loading-overlay');

    mainIframe.onload = function() {
        try {
            const doc = mainIframe.contentWindow.document;
            const bodyText = doc.body.innerText || '';

            if (bodyText.includes('SESSION_EXPIRED')) {
                window.location.replace('/auth/logon.php?error=Session expired !');
            }
        } catch (e) {
            // กัน cross-origin error
        }
    };

    async function verifyToken() {
        try {
            const res = await fetch('/auth/check_token.php', {
                method: 'GET',
                credentials: 'include'
            });
            const data = await res.json();
            return data.status === 'valid';
        } catch (err) {
            return false;
        }
    }

    function handleActiveState(element) {
        if (!element) return;
        const sidebarItems = sidebar.querySelectorAll('.active');
        sidebarItems.forEach(item => item.classList.remove('active'));

        if (element.classList.contains('menu-item')) {
            element.classList.add('active');
        } else if (element.tagName === 'A') {
            element.classList.add('active');
            const parentLi = element.closest('li');
            if (parentLi) {
                const parentHeader = parentLi.querySelector('.menu-item');
                if (parentHeader) parentHeader.classList.add('active');
            }
        }
    }

    async function loadPage(page, element, mode) {
        const valid = await verifyToken();
        if (!valid) {
            window.top.location.href = '/auth/logon.php?error=Session Expired !';
            return;
        }

        handleActiveState(element);

        if (mode === 'in') {
            loadingOverlay.style.display = 'flex';
            mainIframe.src = page;
        } else {
            window.open(page, '_blank');
            return;
        }

        mainIframe.onload = () => {
            loadingOverlay.style.display = 'none';

            const iframeDoc = mainIframe.contentWindow.document;
            const badge = iframeDoc.getElementById("requestBadge");

            console.log("badge =", badge);
        };
    }

    toggleBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });

    function toggleSubmenu(element) {
        const parentLi = element.parentElement;
        handleActiveState(element);

        const allSubmenus = document.querySelectorAll('.menu li');
        allSubmenus.forEach(li => {
            if (li !== parentLi) li.classList.remove('open');
        });

        parentLi.classList.toggle('open');
    }
    async function fetchTaskCount() {
        console.log("fetching...");

        const response = await fetch('?ajax=get-task', {
            credentials: 'same-origin'
        });

        const text = await response.text();
        console.log("RAW:", text);

        const data = JSON.parse(text);

        const badge = document.getElementById("requestBadge");
        const count = data.count ?? 0;

        console.log("COUNT:", count);

        if (count > 0) {
            badge.style.display = "inline-block";
            badge.innerText = count;
        } else {
            badge.style.display = "none";
        }
    }
    fetchTaskCount();
    setInterval(fetchTaskCount, 10000);
    </script>

</body>

</html>