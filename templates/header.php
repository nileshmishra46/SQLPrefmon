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
            <?php if (isset($GLOBALS['repo_connection_error'])): ?>
                <div style="background: rgba(239, 68, 68, 0.1); border: 1px solid var(--color-warning); border-radius: 6px; padding: 0.75rem 1.25rem; margin-bottom: 1.5rem; font-size: 0.85rem; display: flex; align-items: center; gap: 0.75rem; animation: fade-in 0.3s ease-out; color: #f87171;">
                    <i class="fa-solid fa-triangle-exclamation" style="color: var(--color-warning); font-size: 1.1rem;"></i>
                    <div style="flex-grow: 1;">
                        <strong>Repository Connection Failed:</strong> <?= sanitize($GLOBALS['repo_connection_error']) ?>.
                        <span style="color: var(--text-secondary);">Automatically fell back to local SQLite database storage.</span>
                    </div>
                    <?php if (stripos($_SERVER['REQUEST_URI'], 'settings.php') === false): ?>
                        <a href="../admin/settings.php" class="btn btn-glow" style="padding: 0.2rem 0.6rem; font-size: 0.75rem; background: var(--color-warning); color: #000; font-weight: 600; text-decoration: none; border-radius: 4px; display: inline-block;">Fix Settings</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
