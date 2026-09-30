<?php
declare(strict_types=1);

// Session initialization handler
// Ensures session_start() is called once safely across all pages

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
