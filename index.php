<?php
session_start();
include 'login_base.php';
include 'user/header.php';

// ————— Flash Message —————
if ($msg = temp('info')): ?>
    <div id="popupMessage" style="
    position: fixed; top: 100px; left: 50%; transform: translateX(-50%);
    background-color: #d4edda; color: #155724; padding: 15px 30px;
    border: 1px solid #c3e6cb; border-radius: 5px; box-shadow: 0 0 10px rgba(0,0,0,0.1);
    z-index: 9999; font-weight: bold; text-align: center;">
        <?= htmlspecialchars($msg) ?>
    </div>
    <script>
        setTimeout(() => {
            const pop = document.getElementById('popupMessage');
            if (pop) {
                pop.style.transition = "opacity 0.5s ease-out";
                pop.style.opacity = 0;
                setTimeout(() => pop.remove(), 500);
            }
        }, 3000);
    </script>
<?php endif; ?>

<?php
// ————— Config & PDO —————
require __DIR__ . '/config.php';
try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die("DB Connection failed: " . $e->getMessage());
}
include __DIR__ . '/user/target_promo_popup.php';
?>

<style>
    /*her n him*/
    /* Section Title */
    .new-essentials h2 {
        text-align: center;
        font-size: 2rem;
        margin-bottom: 30px;
        font-family: 'Playfair Display', serif;
    }

    /* Container for the two items */
    .essentials-container {
        display: flex;
        justify-content: center;
        gap: 30px;
        padding: 0 20px;
    }

    /* Individual Item Container */
    .essential-item {
        position: relative;
        flex: 1;
        width: 30%;
        max-width: 100vh;
        border-radius: 10px;
        overflow: hidden;
    }

    .essential-item img {
        width: 100%;
        height: 800px;
        object-fit: contain;
        border-radius: 10px;
    }

    /* Overlay Text */
    .overlay {
        position: absolute;
        top: 80%;
        left: 50%;
        transform: translate(-50%, -50%);
        text-align: center;
        color: white;
        background-color: transparent;
        /* Dark transparent background */
        padding: 20px;
        border-radius: 8px;
    }

    .overlay h3 {
        font-size: 1.5rem;
        font-family: 'Playfair Display', serif;
        margin-bottom: 10px;
    }

    /* Trending Section */


    /* Discover Collection Button */
    .discover-collection-btn {
        text-align: center;
        margin-top: 30px;
        margin-bottom: 30px;
    }

    .discover-collection-btn .btn {
        display: inline-block;
        padding: 10px 20px;
        background: transparent;
        color: #000;
        text-decoration: none;
        font-size: 18px;
        border: 2px solid #000;
        border-radius: 30px;
        transition: background-color 0.3s ease, color 0.3s ease;
        text-align: center;
    }

    .discover-collection-btn .btn:hover {
        background-color: #000;
        color: #fff;
    }
</style>
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&display=swap" rel="stylesheet">
<section class="video-section">
    <div class="video-container">
        <video autoplay muted loop>
            <source src="uploads/video.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
        <div class="video-text">
            <h1>Timeless Elegance</h1>
            <p>Discover the sophistication of our latest collection</p>
            <a href="user/product.php" class="btn">Explore</a>
        </div>
    </div>
</section>

<section class="new-essentials">
    <h2><?php echo date('F'); ?>’s New Essentials</h2>
    <div class="essentials-container">
        <div class="essential-item">
            <img src="uploads/for-her.png" alt="For Her">
            <div class="overlay">
                <h3>For Her</h3>
                <a href="#shop" class="btn" style="font-size: 0.9rem;">Discover</a>
            </div>
        </div>
        <div class="essential-item">
            <img src="uploads/for-him.png" alt="For Him">
            <div class="overlay">
                <h3>For Him</h3>
                <a href="#shop" class="btn" style="font-size: 0.9rem;">Discover</a>
            </div>
        </div>
    </div>
</section>

<?php
include __DIR__ . '/search/recommend.php';
include __DIR__ . '/user/campaign.php';
?>


<?php include 'user/footer.php'; ?>