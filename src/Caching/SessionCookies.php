<?php

declare(strict_types=1);

namespace Valicm\BunnyToolkit\Caching;

/**
 * Frameworks' default session cookies, by the start of their names.
 *
 * What a site that never renamed its session sends: Drupal (`SESS…` over
 * http, `SSESS…` over https), WordPress once somebody has logged in or opened
 * a password-protected post, Laravel, plain PHP and Symfony, Express, Flask
 * and, by prefix, Django. Rails names its cookie after the application, and
 * Go and Rust have no default, so those sites name theirs.
 */
final class SessionCookies
{
    public const DRUPAL = ['SESS', 'SSESS'];

    public const FRAMEWORKS = [
        'drupal' => self::DRUPAL,
        'wordpress' => ['wordpress_logged_in_', 'wp-postpass_'],
        'laravel' => ['laravel_session'],
        'php' => ['PHPSESSID'],
        'nodejs' => ['connect.sid'],
        'python' => ['session'],
        'django' => ['sessionid'],
    ];

    /**
     * A framework's session cookies, or none for one not listed.
     *
     * @return list<string>
     */
    public static function of(string $framework): array
    {
        return self::FRAMEWORKS[$framework] ?? [];
    }
}
