<?php
if (!function_exists('maxAdminIsAuthed')) {
    function maxAdminIsAuthed(): bool
    {
        return !empty($_SESSION['max_admin_auth']) && (int)$_SESSION['max_admin_auth'] === 1;
    }
}

if (!function_exists('maxAdminRequireAuthJson')) {
    function maxAdminRequireAuthJson(): void
    {
        if (!maxAdminIsAuthed()) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Не авторизовано'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

if (!function_exists('maxAdminPasswordConst')) {
    function maxAdminPasswordConst(): string
    {
        return '75500';
    }
}

if (!function_exists('maxAdminHtml')) {
    function maxAdminHtml($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('maxAdminPost')) {
    function maxAdminPost(string $key, $default = '')
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('maxAdminJsonOut')) {
    function maxAdminJsonOut(array $payload): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('renderAdminNav')) {
    function renderAdminNav(string $active = ''): void
    {
        $pages = [
            'max_admin'               => ['label' => 'MAX Admin',            'url' => 'max_admin.php'],
            'max_event_center'        => ['label' => 'Event Center',         'url' => 'max_event_center.php'],
            'popup_templates'         => ['label' => 'UI Popup Templates',   'url' => 'ui_popup_templates.php'],
            'static_text'             => ['label' => 'Статические тексты',    'url' => 'static_text_center.php'],
            'warehouse'               => ['label' => 'Складская разметка',    'url' => 'warehouse_route_reclassifier.php'],
            'stock'                   => ['label' => 'Складские остатки',     'url' => 'warehouse_stock_report.php'],
            'slitex_tracker_rename'   => ['label' => 'SLITEX трекеры',       'url' => 'slitex_tracker_rename.php'],
            'map'                     => ['label' => 'Карта',                 'url' => '../maptest.php'],
        ];
        $isAuthed = maxAdminIsAuthed();
        echo '<div class="admin-nav">';
        foreach ($pages as $key => $page) {
            $cls = ($key === $active) ? 'btn admin-nav-active' : 'btn';
            $href = maxAdminHtml($page['url']);
            $label = maxAdminHtml($page['label']);
            echo "<a class=\"{$cls}\" href=\"{$href}\" style=\"text-decoration:none;display:inline-flex;align-items:center\">{$label}</a>\n";
        }
        if ($isAuthed) {
            echo '<a class="btn" href="?logout=1" style="text-decoration:none;display:inline-flex;align-items:center">Выйти</a>';
        }
        echo '</div>';
    }
}
