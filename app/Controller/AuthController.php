<?php

declare(strict_types=1);

namespace App\Controller;

use App\Http\Request;
use App\Http\Response;
use App\Service\AuthService;

final class AuthController
{
    public function __construct(private readonly AuthService $auth)
    {
    }

    public function showLogin(Request $request): Response
    {
        return $this->renderLogin();
    }

    public function login(Request $request): Response
    {
        $email = (string) ($request->post()['email'] ?? '');
        $password = (string) ($request->post()['password'] ?? '');

        if (!$this->auth->login($email, $password, $this->ipAddress($request), $this->userAgent($request))) {
            return $this->renderLogin('Invalid email, password, or inactive account.', $email);
        }

        return new Response('', 302, ['Location' => '/']);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($this->ipAddress($request), $this->userAgent($request));

        return new Response('', 302, ['Location' => '/login']);
    }

    private function ipAddress(Request $request): string
    {
        $value = $request->server()['REMOTE_ADDR'] ?? '';

        return is_string($value) ? substr($value, 0, 45) : '';
    }

    private function userAgent(Request $request): string
    {
        $value = $request->server()['HTTP_USER_AGENT'] ?? '';

        return is_string($value) ? substr($value, 0, 255) : '';
    }

    private function renderLogin(string $error = '', string $email = ''): Response
    {
        ob_start();
        require dirname(__DIR__, 2) . '/views/auth/login.php';
        $body = ob_get_clean();

        return Response::html(is_string($body) ? $body : '');
    }
}
