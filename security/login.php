<?php
require '../config.php';
include '../login_base.php';

if (is_post()) {
    $email    = req('email');
    $password = req('password');

    // Validate: email
    if ($email == '') {
        $_err['email'] = 'Required';
    } else if (!is_email($email)) {
        $_err['email'] = 'Invalid email';
    }

    // Validate: password
    if ($password == '') {
        $_err['password'] = 'Required';
    }

// Login user
if (!$_err) {
    $stm = $pdo->prepare('
        SELECT UserID, Username, Role, photo, email
        FROM user
        WHERE email = ?
          AND Password = SHA1(?)
          AND (IsDeleted = 0 OR IsDeleted IS NULL)
    ');
    $stm->execute([$email, $password]);
    $u = $stm->fetch(PDO::FETCH_ASSOC);

    if ($u) {
        // Blocked role check
        if (strtolower($u['Role']) === 'blocked') {
            echo "<script>
                    alert('❌ Your account has been blocked, Please contact support.');
                    window.location.href = '../login.php';
                  </script>";
            exit;
        }

        $_SESSION['user'] = $u;
        temp('info', 'Login successfully');
        login($u);
        header('Location: ../index.php');
        exit;
    } else {
        $_err['password'] = 'Not matched';
    }
}
}

include '../user/header.php';
?>

<main class="auth-split-64">
    <!-- Left 60% area -->
    <section class="auth-left">
        <video autoplay muted loop>
            <source src="../uploads/video.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </section>

    <!-- Right 40%: login panel -->
    <section class="auth-right">
        <div class="auth-card">
            <h2 class="auth-title">Log in</h2>

            <form method="post" class="form">
                <section class="field">
                    <label class="label" for="email">Email</label>
                    <?= html_text('email', 'maxlength="100" class="input" placeholder="Email"') ?>
                    <?= err('email') ?>
                </section>

                <section class="field">
                    <label class="label" for="password">Password</label>
                    <?= html_password('password', 'maxlength="100" class="input" placeholder="•••••"') ?>
                    <?= err('password') ?>
                </section>

                <section class="actions">
                    <div class="btn-group">
                        <button type="submit" class="btn-primary">Login</button>
                        <button type="reset" class="btn-ghost">Reset</button>
                    </div>
                    <div class="link-right">
                        <button type="button" class="btn-link" style="text-decoration: underline;" onclick="window.location.href='forgotpw.php'">
                            Forgot Password?
                        </button>
                    </div>
                </section>
            </form>
        </div>
    </section>
</main>

<?php include '../user/footer.php'; ?>

<style>
    :root {
        --panel: #f6f6f8;
        --text: #2a2a2a;
        --muted: #8b8b95;
        --black: #000000;
        --black-dark: #111111;
        --border: #e1e3ea;
        --shadow: 0 10px 25px rgba(0, 0, 0, .08);
        --radius: 14px;
    }

    html,
    body {
        height: 100%;
        margin: 0;
        overflow: hidden;
        /* 🚫 no page scroll */
    }

    /* 6:4 split layout */
    .auth-split-64 {
        display: grid;
        grid-template-columns: 65% 35%;
        min-height: 70vh;
        background: #fff;
    }

    .auth-left {
        padding: 0;
    }

    .auth-left video {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .auth-right {
        background: var(--panel);
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 48px 36px;
    }

    /* Card */
    .auth-card {
        width: 100%;
        max-width: 460px;
        padding: 32px 28px;
    }

    .auth-title {
        margin: 0 0 6px;
        font-size: 28px;
        font-weight: 700;
        color: var(--text);
    }

    .auth-subtitle {
        margin: 0 0 18px;
        font-size: 14px;
        color: var(--muted);
    }

    /* Form controls */
    .field {
        margin-bottom: 16px;
    }

    .label {
        display: block;
        font-size: 12px;
        font-weight: 700;
        color: #6b6b75;
        margin-bottom: 6px;
    }

    .input {
        width: 100%;
        height: 44px;
        padding: 10px 12px;
        border-radius: 10px;
        border: 1.5px solid var(--border);
        outline: none;
        font-size: 15px;
        transition: border .15s, box-shadow .15s;
        background: #fff;
    }

    .input:focus {
        border-color: var(--black);
        box-shadow: 0 0 0 3px rgba(0, 0, 0, .15);
    }

    /* Actions / buttons */
    .actions {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        margin-top: 6px;
    }

    .btn-group {
        display: flex;
        gap: 8px;
    }

    .btn-primary {
        height: 46px;
        padding: 0 18px;
        border: none;
        border-radius: 10px;
        background: var(--black);
        color: #fff;
        font-weight: 700;
        cursor: pointer;
    }

    .btn-primary:hover {
        background: var(--black-dark);
    }

    .btn-ghost {
        height: 46px;
        padding: 0 18px;
        border: 1.5px solid var(--border);
        background: #fff;
        border-radius: 10px;
        font-weight: 600;
        cursor: pointer;
    }

    .btn-link {
        background: transparent;
        border: none;
        color: var(--black);
        font-weight: 600;
        cursor: pointer;
        padding: 6px 0;
    }

    .btn-link:hover {
        text-decoration: underline;
    }

    .link-right {
        display: flex;
        align-items: center;
    }

    /* Mobile: stack; form takes full width */
    @media (max-width: 880px) {
        .auth-split-64 {
            grid-template-columns: 1fr;
        }

        .auth-left {
            display: none;
        }

        .auth-right {
            padding: 28px 18px;
        }

        .auth-card {
            padding: 26px 22px;
        }
    }
</style>