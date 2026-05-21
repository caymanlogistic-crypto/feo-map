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
