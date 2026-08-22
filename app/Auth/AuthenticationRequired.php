<?php

declare(strict_types=1);

namespace App\Auth;

use RuntimeException;

final class AuthenticationRequired extends RuntimeException {}
