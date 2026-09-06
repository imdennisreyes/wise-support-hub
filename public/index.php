<?php

require_once dirname(__DIR__) . '/app/bootstrap.php';

$path = rtrim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/') ?: '/';

try {
    if ($path === '/login') AuthController::login();
    elseif ($path === '/logout' && method_is('POST')) AuthController::logout();
    elseif ($path === '/') HomeController::dashboard();
    elseif ($path === '/search') HomeController::search();
    elseif ($path === '/search/select' && method_is('POST')) HomeController::selectSearchResult();
    elseif ($path === '/troubleshoot' && method_is('POST')) HomeController::guide();
    elseif ($path === '/knowledge-base') ArticleController::browse();
    elseif ($path === '/errors') ArticleController::browse('Error');
    elseif ($path === '/sops') ArticleController::browse('SOP');
    elseif ($path === '/requests') ArticleController::browse('Request');
    elseif ($path === '/systems') HomeController::taxonomyPage('systems');
    elseif ($path === '/carriers') HomeController::taxonomyPage('carriers');
    elseif (preg_match('#^/articles/(\d+)$#', $path, $m)) ArticleController::show((int) $m[1]);
    elseif (preg_match('#^/articles/(\d+)/feedback$#', $path, $m) && method_is('POST')) ArticleController::feedback((int) $m[1]);
    elseif (str_starts_with($path, '/admin') || $path === '/reports') AdminController::route($path);
    else {
        http_response_code(404);
        render('error', ['title' => 'Page not found', 'message' => 'The page you requested could not be found.']);
    }
} catch (PDOException $exception) {
    error_log($exception->getMessage());
    http_response_code(500);
    render('error', ['title' => 'Database error', 'message' => 'The requested operation could not be completed. Please try again or contact an administrator.']);
} catch (Throwable $exception) {
    error_log($exception->__toString());
    http_response_code(500);
    render('error', ['title' => 'Unexpected error', 'message' => 'Something went wrong while processing the request.']);
}
