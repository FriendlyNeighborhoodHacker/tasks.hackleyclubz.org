<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../settings.php';
require_once __DIR__ . '/Files.php';
require_once __DIR__ . '/GroupManagement.php';

class ApplicationUI {

    // When the sidebar group list would exceed this many lines, it is
    // truncated and a "More…" link takes the user to groups/more.php.
    private const MAX_NAV_LINES = 9;

    /**
     * Generate a cache-busted URL for a static resource
     */
    public static function staticResourceUrl(string $path): string {
        $filePath = __DIR__ . '/../' . ltrim($path, '/');
        $version = @filemtime($filePath);
        if (!$version) {
            $version = date('Ymd');
        }
        return $path . '?v=' . $version;
    }

    /**
     * Generate a complete CSS link tag with cache-busting
     */
    public static function cssLink(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<link rel="stylesheet" href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">';
    }

    /**
     * Generate a complete JS script tag with cache-busting
     */
    public static function jsScript(string $path): string {
        $url = self::staticResourceUrl($path);
        return '<script src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></script>';
    }

    /**
     * The group navigation for the left sidebar. The user's current group is
     * always first; groups the user admins get Tasks/People/Settings sub-links.
     * Truncated with a "More…" link when it would exceed MAX_NAV_LINES lines.
     *
     * Returns [$html, $renderedAllGroups].
     */
    private static function groupNavHtml(array $user): string {
        $userId = (int)$user['id'];
        $ctx = UserContext::getLoggedInUserContext();
        $groups = GroupManagement::listGroupsForUser($userId);
        $currentGroupId = GroupManagement::resolveCurrentGroupId($ctx);

        // Current group first, others keep their name ordering.
        usort($groups, function ($a, $b) use ($currentGroupId) {
            $aCur = ((int)$a['id'] === $currentGroupId) ? 0 : 1;
            $bCur = ((int)$b['id'] === $currentGroupId) ? 0 : 1;
            return $aCur <=> $bCur ?: strcasecmp((string)$a['name'], (string)$b['name']);
        });

        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $requestGroupId = isset($_GET['group_id']) ? (int)$_GET['group_id'] : null;

        $html = '';
        $lines = 0;
        $shown = 0;

        foreach ($groups as $g) {
            $gid = (int)$g['id'];
            $isAdmin = !empty($g['is_owner']) || ($g['role'] ?? '') === 'admin' || !empty($user['is_admin']);
            $cost = $isAdmin ? 4 : 1; // name line + Tasks/People/Settings sub-links

            // Reserve a line for "More…" if the rest won't fit.
            $remainingGroups = count($groups) - $shown;
            $budget = self::MAX_NAV_LINES - ($remainingGroups > 1 ? 1 : 0);
            if ($lines + $cost > $budget && $shown > 0) {
                $html .= '<a href="/groups/more.php" class="sidebar-item sidebar-more">More…</a>';
                $lines++;
                $shown = -1; // marker: truncated
                break;
            }

            $isCurrent = ($gid === $currentGroupId);
            $onGroupPage = ($requestGroupId === $gid) || ($requestGroupId === null && $isCurrent);
            $tasksActive = $onGroupPage && (strpos($script, '/tasks/') === 0 || $script === '/index.php');

            $html .= '<a href="/tasks/index.php?group_id=' . $gid . '" class="sidebar-item sidebar-group' . ($isCurrent ? ' current-group' : '') . ($tasksActive && !$isAdmin ? ' active' : '') . '">' . h((string)$g['name']) . '</a>';
            $lines++;

            if ($isAdmin) {
                $peopleActive = $onGroupPage && strpos($script, '/groups/people') === 0;
                $settingsActive = $onGroupPage && strpos($script, '/groups/settings') === 0;
                $html .= '<a href="/tasks/index.php?group_id=' . $gid . '" class="sidebar-item sidebar-subitem' . ($tasksActive ? ' active' : '') . '">Tasks</a>'
                       . '<a href="/groups/people.php?group_id=' . $gid . '" class="sidebar-item sidebar-subitem' . ($peopleActive ? ' active' : '') . '">People</a>'
                       . '<a href="/groups/settings.php?group_id=' . $gid . '" class="sidebar-item sidebar-subitem' . ($settingsActive ? ' active' : '') . '">Settings</a>';
                $lines += 3;
            }
            $shown++;
        }

        $newActive = ($script === '/groups/add.php');
        $html .= '<a href="/groups/add.php" class="sidebar-item sidebar-new-group' . ($newActive ? ' active' : '') . '">+ New group</a>';

        return $html;
    }

    /**
     * Page shell with left sidebar navigation:
     * - the user's groups at the top of the sidebar (scrolls if too long)
     * - Admin menu and profile photo anchored at the bottom
     * - on narrow screens the sidebar collapses behind a top-left menu icon
     */
    public static function headerHtml(string $title): void {
        $u = current_user();
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $cur = basename($script);
        $siteTitle = Settings::siteTitle();

        // On admin pages the admin submenu renders open (desktop keeps it as a
        // persistent rail so users can navigate between admin sections).
        $inAdminSection = !empty($u['is_admin']) && strpos($script, '/admin/') === 0;

        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.h($title).' - '.h($siteTitle).'</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body'.($inAdminSection ? ' class="admin-submenu-open"' : '').'>';

        if ($u) {
            // Mobile top bar: menu icon + site title
            echo '<div class="mobile-topbar">'
               . '<button id="sidebarToggle" class="sidebar-toggle" aria-label="Open menu" aria-expanded="false" aria-controls="sidebar">'
               . '<span></span><span></span><span></span>'
               . '</button>'
               . '<a class="mobile-topbar-title" href="/index.php">'.h($siteTitle).'</a>'
               . '</div>';
            echo '<div id="sidebarBackdrop" class="sidebar-backdrop"></div>';

            echo '<aside class="sidebar" id="sidebar">';
            echo '<div class="sidebar-title"><a href="/index.php">'.h($siteTitle).'</a></div>';

            echo '<nav class="sidebar-nav">';
            echo self::groupNavHtml($u);
            echo '</nav>';

            // Bottom-anchored: Admin menu (peer of the profile photo) + profile
            echo '<div class="sidebar-bottom">';

            if (!empty($u['is_admin'])) {
                $adminItems = [
                    ['path' => '/admin/users.php', 'label' => 'Users'],
                    ['path' => '/admin/groups.php', 'label' => 'All Groups'],
                    ['path' => '/admin/settings.php', 'label' => 'Settings'],
                    ['path' => '/admin/activity_log.php', 'label' => 'Activity Log'],
                    ['path' => '/admin/email_log.php', 'label' => 'Email Log'],
                ];
                $adminLinks = '';
                foreach ($adminItems as $item) {
                    $active = $inAdminSection && ($cur === basename($item['path']));
                    $adminLinks .= '<a href="'.h($item['path']).'" role="menuitem"'.($active ? ' class="active"' : '').'>'.h($item['label']).'</a>';
                }
                echo '<div class="sidebar-menu-wrap">'
                   . '<button type="button" id="adminToggle" class="sidebar-item sidebar-menu-toggle" aria-expanded="'.($inAdminSection ? 'true' : 'false').'" aria-controls="adminMenu">'
                   . '<span class="sidebar-item-icon" aria-hidden="true">&#9881;</span> Admin'
                   . '</button>'
                   . '<div id="adminMenu" class="popup-menu admin-submenu'.($inAdminSection ? '' : ' hidden').'" role="menu" aria-hidden="'.($inAdminSection ? 'false' : 'true').'">'
                   .   '<div class="admin-submenu-title">Admin</div>'
                   .   $adminLinks
                   . '</div>'
                   . '</div>';
            }

            $name = trim((string)($u['first_name'] ?? '').' '.(string)($u['last_name'] ?? ''));
            $initials = strtoupper((string)substr((string)($u['first_name'] ?? ''), 0, 1).(string)substr((string)($u['last_name'] ?? ''), 0, 1));
            $photoUrl = Files::profilePhotoUrl($u['photo_public_file_id'] ?? null, 32);

            if ($photoUrl !== '') {
                $avatar = '<img class="sidebar-avatar" src="'.h($photoUrl).'" alt="">';
            } else {
                $avatar = '<span class="sidebar-avatar sidebar-avatar-initials" aria-hidden="true">'.h($initials).'</span>';
            }

            echo '<div class="sidebar-menu-wrap">'
               . '<button type="button" id="profileToggle" class="sidebar-item sidebar-menu-toggle sidebar-profile" aria-expanded="false" aria-controls="profileMenu" title="'.h($name).'" aria-label="Account menu for '.h($name).'">'
               . $avatar
               . '</button>'
               . '<div id="profileMenu" class="popup-menu hidden" role="menu" aria-hidden="true">'
               .   '<a href="/profile/" role="menuitem">My Profile</a>'
               .   '<a href="/profile/change_password.php" role="menuitem">Change Password</a>'
               .   '<a href="/logout.php" role="menuitem">Logout</a>'
               . '</div>'
               . '</div>';

            echo '</div>'; // .sidebar-bottom
            echo '</aside>';

            // Sidebar behavior: mobile drawer, persistent admin rail (desktop) /
            // accordion (mobile), and the profile popup menu.
            echo '<script>document.addEventListener("DOMContentLoaded",function(){'
               . 'var sidebar=document.getElementById("sidebar");'
               . 'var toggle=document.getElementById("sidebarToggle");'
               . 'var backdrop=document.getElementById("sidebarBackdrop");'
               . 'function isDesktop(){return window.matchMedia("(min-width: 769px)").matches;}'
               . 'function closeSidebar(){sidebar.classList.remove("open");backdrop.classList.remove("show");if(toggle)toggle.setAttribute("aria-expanded","false");}'
               . 'function openSidebar(){sidebar.classList.add("open");backdrop.classList.add("show");if(toggle)toggle.setAttribute("aria-expanded","true");}'
               . 'if(toggle){toggle.addEventListener("click",function(){sidebar.classList.contains("open")?closeSidebar():openSidebar();});}'
               . 'if(backdrop){backdrop.addEventListener("click",closeSidebar);}'
               // Admin submenu: toggled by its button; stays open on desktop (a
               // navigation rail), so outside clicks only close it on mobile.
               . 'var adminBtn=document.getElementById("adminToggle");'
               . 'var adminMenu=document.getElementById("adminMenu");'
               . 'function setAdmin(open){if(!adminMenu)return;adminMenu.classList.toggle("hidden",!open);adminMenu.setAttribute("aria-hidden",open?"false":"true");if(adminBtn)adminBtn.setAttribute("aria-expanded",open?"true":"false");document.body.classList.toggle("admin-submenu-open",open);}'
               . 'if(adminBtn&&adminMenu){adminBtn.addEventListener("click",function(e){e.preventDefault();setAdmin(adminMenu.classList.contains("hidden"));});}'
               // Profile popup: standard popup behavior everywhere.
               . 'var profileBtn=document.getElementById("profileToggle");'
               . 'var profileMenu=document.getElementById("profileMenu");'
               . 'function setProfile(open){if(!profileMenu)return;profileMenu.classList.toggle("hidden",!open);profileMenu.setAttribute("aria-hidden",open?"false":"true");if(profileBtn)profileBtn.setAttribute("aria-expanded",open?"true":"false");}'
               . 'if(profileBtn&&profileMenu){profileBtn.addEventListener("click",function(e){e.preventDefault();setProfile(profileMenu.classList.contains("hidden"));});}'
               . 'document.addEventListener("click",function(e){'
               .   'if(profileBtn&&profileMenu&&!profileBtn.contains(e.target)&&!profileMenu.contains(e.target))setProfile(false);'
               . '});'
               . 'document.addEventListener("keydown",function(e){if(e.key==="Escape"){setProfile(false);closeSidebar();}});'
               . '});</script>';

            echo '<div class="content"><main>';
        } else {
            // Logged-out shell (rarely used; auth pages render their own layout)
            echo '<div class="mobile-topbar always"><a class="mobile-topbar-title" href="/login.php">'.h($siteTitle).'</a></div>';
            echo '<div class="content no-sidebar"><main>';
        }
    }

    public static function footerHtml(): void {
        echo '</main></div>' . self::jsScript('/main.js') . '</body></html>';
    }

    /**
     * Minimal chrome for the token-authenticated email flow (/t/): no sidebar,
     * no navigation — just a centered column with the site title.
     */
    public static function minimalHeaderHtml(string $title): void {
        $siteTitle = Settings::siteTitle();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>'.h($title).' - '.h($siteTitle).'</title>';
        echo self::cssLink('/styles.css');
        echo '</head><body class="minimal">';
        echo '<div class="minimal-header">'.h($siteTitle).'</div>';
        echo '<div class="minimal-content"><main>';
    }

    public static function minimalFooterHtml(): void {
        echo '</main></div>' . self::jsScript('/main.js') . '</body></html>';
    }
}
