<?php
declare(strict_types=1);

require __DIR__ . '/inc/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && csrf_valid()) {
    logout_user();
}
header('Location: ' . url('login.php'), true, 303);
