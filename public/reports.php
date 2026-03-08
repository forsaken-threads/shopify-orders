<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';
require __DIR__ . '/auth.php';

requireBasicAuth($config['auth_user'], $config['auth_password']);

$pageTitle  = 'Reports - Utility App';
$activePage = 'reports';
require __DIR__ . '/partials/header.php';
?>
<style>
    .coming-soon {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 3rem 2rem;
    }

    .coming-soon-card {
        background: #fff;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(0,0,0,.09);
        padding: 3.5rem 4rem;
        max-width: 480px;
        width: 100%;
        text-align: center;
    }

    .coming-soon-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 3.5rem;
        height: 3.5rem;
        background: #f0f2f5;
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }

    .coming-soon-icon svg {
        width: 1.6rem;
        height: 1.6rem;
        fill: none;
        stroke: #1a1a2e;
        stroke-width: 1.75;
        stroke-linecap: round;
        stroke-linejoin: round;
    }

    .coming-soon-card h2 {
        font-size: 1.35rem;
        font-weight: 700;
        margin-bottom: .6rem;
    }

    .coming-soon-card p {
        font-size: .9rem;
        color: #666;
        line-height: 1.55;
    }

    @media (max-width: 540px) {
        .coming-soon-card { padding: 2.5rem 1.75rem; }
    }
</style>

<div class="coming-soon">
    <div class="coming-soon-card">
        <div class="coming-soon-icon">
            <svg viewBox="0 0 24 24">
                <path d="M3 3v18h18"/>
                <path d="M7 16l4-4 4 4 4-7"/>
            </svg>
        </div>
        <h2>Reports — Coming Soon</h2>
        <p>
            This section is under construction. Order analytics, revenue summaries,
            and fulfilment reports will appear here.
        </p>
    </div>
</div>

<?php require __DIR__ . '/partials/footer.php'; ?>
