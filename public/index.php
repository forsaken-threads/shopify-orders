<?php
declare(strict_types=1);

$pageTitle  = 'Home - Utility App';
$activePage = null;
require __DIR__ . '/partials/header.php';
?>
<style>
    .home-main {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 2rem;
    }

    .hero {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.09);
        padding: 3rem 3.5rem;
        max-width: 480px;
        width: 100%;
        text-align: center;
    }

    .hero-logo {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3rem;
        height: 3rem;
        background: #1a1a2e;
        border-radius: 10px;
        margin-bottom: 1.5rem;
    }

    .hero-logo svg {
        width: 1.4rem;
        height: 1.4rem;
        fill: none;
        stroke: #fff;
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .hero h1 {
        font-size: 1.5rem;
        font-weight: 700;
        margin-bottom: .5rem;
        line-height: 1.25;
    }

    .hero-subtitle {
        font-size: .9rem;
        color: #666;
        margin-bottom: 2rem;
        line-height: 1.5;
    }

    .btn-login {
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

    .btn-login:hover { background: #2d2d5e; }

    @media (max-width: 540px) {
        .hero { padding: 2rem 1.5rem; }
    }
</style>

<main class="home-main">
    <div class="hero">
        <div class="hero-logo">
            <svg viewBox="0 0 24 24">
                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                <rect x="9" y="3" width="6" height="4" rx="1"/>
                <path d="M9 12h6M9 16h4"/>
            </svg>
        </div>
        <h1>Utility App<br>for Decantalize</h1>
        <p class="hero-subtitle">
            Manage and review your Shopify orders in one place.
        </p>
        <a class="btn-login" href="orders.php">Log in to view orders</a>
    </div>
</main>

<?php require __DIR__ . '/partials/footer.php'; ?>
