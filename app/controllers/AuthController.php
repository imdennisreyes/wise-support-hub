<?php

final class AuthController
{
    public static function login(): void
    {
        if (current_user()) redirect('/');
        if (method_is('POST')) {
            verify_csrf();
            $email = trim($_POST['email'] ?? '');
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || ($_POST['password'] ?? '') === '') {
                render('login', ['title' => 'Sign in', 'error' => 'Enter a valid email address and password.', 'email' => $email]);
                return;
            }
            if (Auth::attempt($email, (string) $_POST['password'])) {
                $to = $_SESSION['intended_url'] ?? '/';
                unset($_SESSION['intended_url']);
                header('Location: ' . $to);
                exit;
            }
            render('login', ['title' => 'Sign in', 'error' => 'The email or password is incorrect, or the account is inactive.', 'email' => $email]);
            return;
        }
        render('login', ['title' => 'Sign in']);
    }

    public static function logout(): void
    {
        require_auth();
        verify_csrf();
        Auth::logout();
        redirect('/login');
    }
}
