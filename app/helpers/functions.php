<?php

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function base_path(string $path = ''): string
{
    return dirname(__DIR__, 2) . ($path ? '/' . ltrim($path, '/') : '');
}

function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function render(string $view, array $data = []): void
{
    extract($data, EXTR_SKIP);
    ob_start();
    require base_path('views/' . $view . '.php');
    $content = ob_get_clean();
    require base_path('views/layout.php');
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Your session token has expired. Go back, refresh the page, and try again.');
    }
}

function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function has_role(string ...$roles): bool
{
    $user = current_user();
    return $user && in_array($user['role_name'], $roles, true);
}

function require_auth(): void
{
    if (!current_user()) {
        $_SESSION['intended_url'] = $_SERVER['REQUEST_URI'] ?? '/';
        redirect('/login');
    }
}

function require_role(string ...$roles): void
{
    require_auth();
    if (!has_role(...$roles)) {
        http_response_code(403);
        render('error', ['title' => 'Access denied', 'message' => 'Your role does not permit this action.']);
        exit;
    }
}

function method_is(string $method): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === strtoupper($method);
}

function active_nav(string $prefix): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return ($prefix === '/' ? $path === '/' : str_starts_with($path, $prefix)) ? 'active' : '';
}

function article_icon(string $type): string
{
    return match ($type) {
        'Error' => 'bi-exclamation-octagon',
        'SOP' => 'bi-list-check',
        'Request' => 'bi-inbox',
        default => 'bi-file-earmark-text',
    };
}

function severity_class(?string $severity): string
{
    return match ($severity) {
        'Critical' => 'text-bg-danger',
        'High' => 'bg-danger-subtle text-danger-emphasis border border-danger-subtle',
        'Medium' => 'text-bg-warning',
        'Low' => 'text-bg-info',
        default => 'text-bg-secondary',
    };
}

function pagination_query(array $changes = []): string
{
    return http_build_query(array_merge($_GET, $changes));
}
