<?php
// templates/header.php

require_once dirname(__DIR__) . '/includes/auth_check.php';
require_once dirname(__DIR__) . '/includes/helpers.php';
require_once dirname(__DIR__) . '/includes/db.php';

$pageTitle = $pageTitle ?? 'SQL Server Monitor';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> - MSSQL Performance</title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@400;500;600;700&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <!-- App CSS -->
    <link rel="stylesheet" href="../assets/css/app.css">
    <!-- Theme & Accent Loader -->
    <script>
        (function() {
            const savedTheme = localStorage.getItem('prefmon-theme') || 'dark';
            if (savedTheme === 'light') {
                document.documentElement.classList.add('light-theme');
            } else {
                document.documentElement.classList.remove('light-theme');
            }
            
            const savedAccent = localStorage.getItem('prefmon-accent');
            if (savedAccent) {
                const accent = JSON.parse(savedAccent);
                document.documentElement.style.setProperty('--color-primary', accent.primary);
                document.documentElement.style.setProperty('--color-primary-glow', accent.glow);
                document.documentElement.style.setProperty('--color-primary-hover', accent.hover);
            }
        })();
    </script>
</head>
<body>
    <div class="app-container">
        <?php include __DIR__ . '/sidebar.php'; ?>
        <div class="main-content">
