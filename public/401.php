<?php
declare(strict_types=1);

http_response_code(401);

$pageTitle  = '401 Unauthorized - Cent Notes';
$activePage = null;
$hideNav    = true;
require __DIR__ . '/../app/partials/header.php';
?>
<style>
    .error-main {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 2rem;
    }

    .error-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.09);
        padding: 3rem 3.5rem;
        max-width: 480px;
        width: 100%;
        text-align: center;
    }

    .error-code {
        font-size: 3.5rem;
        font-weight: 800;
        color: #1a1a2e;
        line-height: 1;
        margin-bottom: .5rem;
    }

    .error-card h1 {
        font-size: 1.3rem;
        font-weight: 700;
        margin-bottom: .5rem;
    }

    .error-subtitle {
        font-size: .9rem;
        color: #666;
        margin-bottom: 2rem;
        line-height: 1.5;
    }

    .btn-home {
        display: inline-block;
        padding: .65rem 1.75rem;
        background: #1a1a2e;
        color: #fff;
        text-decoration: none;
        border-radius: 7px;
        font-size: .9rem;
        font-weight: 600;
        letter-spacing: .01em;
        transition: background .15s;
    }

    .btn-home:hover { background: #2d2d5e; }

    @media (max-width: 540px) {
        .error-card { padding: 2rem 1.5rem; }
    }
</style>

<main class="error-main">
    <div class="error-card">
        <div class="error-code">401</div>
        <h1>Unauthorized</h1>
        <p class="error-subtitle">
            You need to sign in to access this page.
        </p>
        <a class="btn-home" href="index.php">Go Home</a>
    </div>
</main>

<?php require __DIR__ . '/../app/partials/footer.php'; ?>
