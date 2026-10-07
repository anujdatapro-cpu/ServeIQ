<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';

$pageTitle = $pageTitle ?? 'ServeIQ | Find the right service';
$basePath = $basePath ?? '';
$assetVersion = (string) (filemtime(__DIR__ . '/../assets/css/style.css') . '.' . filemtime(__DIR__ . '/../assets/js/main.js'));
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="ServeIQ connects your real-world problem with the right local service professional.">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= $basePath ?>assets/css/style.css?v=<?= htmlspecialchars($assetVersion, ENT_QUOTES, 'UTF-8') ?>" rel="stylesheet">
</head>
<body class="<?= htmlspecialchars($bodyClass ?? '', ENT_QUOTES, 'UTF-8') ?>">
<?php require __DIR__ . '/navbar.php'; ?>
