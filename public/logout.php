<?php
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use App\Auth;
use App\Http;

Http::securityHeaders();
Http::requirePost();
Auth::checkCsrf();
Auth::logout();
Http::redirect('index.php');
