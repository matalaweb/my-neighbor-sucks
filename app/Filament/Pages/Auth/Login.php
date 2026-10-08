<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

/**
 * Session login with Filament's built-in rate limiting. There is no public
 * registration; owners invite members.
 */
class Login extends BaseLogin {}
