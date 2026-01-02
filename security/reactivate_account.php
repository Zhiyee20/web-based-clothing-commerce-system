<?php
require '../config.php';
include '../login_base.php';

if (is_post()) {
    $email    = req('email');
    $password = req('password');

    // Validate: email
    if ($email === '') {
        $_err['email'] = 'Required';
    } elseif (!is_email($email)) {
        $_err['email'] = 'Invalid email';
    }

    // Validate: password
    if ($password === '') {
        $_err['password'] = 'Required';
    }

    if (!$_err) {
        // Fetch user by email (include password + IsDeleted)
        $stm = $pdo->prepare('
            SELECT UserID, Username, Role, photo, email, Password, IsDeleted
            FROM user
            WHERE email = ?
            LIMIT 1
        ');
        $stm->execute([$email]);
        $u = $stm->fetch(PDO::FETCH_ASSOC);

        if (!$u || $u['Password'] !== sha1($password)) {
            $_err['password'] = 'Email or password not matched';
        } else {
            // Blocked role
            if (strtolower($u['Role']) === 'blocked') {
                $_err['email'] = 'This account has been blocked. Please contact support.';
            }
            // Not deleted anymore
            else if ((int)$u['IsDeleted'] === 0) {
                $_err['email'] = 'This account is already active. You can login directly.';
            }
            // OK: reactivate
            else {
                $up = $pdo->prepare('UPDATE user SET IsDeleted = 0 WHERE UserID = ?');
                $up->execute([$u['UserID']]);

                // Refresh user data (optional)
                $stm2 = $pdo->prepare('
                    SELECT UserID, Username, Role, photo, email
                    FROM user
                    WHERE UserID = ?
                ');
                $stm2->execute([$u['UserID']]);
                $userRow = $stm2->fetch(PDO::FETCH_ASSOC);

                if ($userRow) {
                    temp('info', 'Your account has been reactivated. Welcome back!');
                    // Auto-login after reactivation
                    $_SESSION['user'] = $userRow;
                    login($userRow, '../index.php');
                    exit;
                } else {
                    $_err['email'] = 'Unable to reactivate account. Please contact support.';
                }
            }
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

    <!-- Right 40%: reactivation panel -->
    <section class="auth-right">
        <div class="auth-card">
            <h2 class="auth-title">Reactivate Account</h2>
            <p class="auth-subtitle">
                Enter your email and your previous password to restore your account.
            </p>

            <form method="post" class="form">
                <section class="field">
                    <label class="label" for="email">Email</label>
                    <?= html_text('email', 'maxlength="100" class="input" placeholder="Email used before"') ?>
                    <?= err('email') ?>
                </section>

                <section class="field">
                    <label class="label" for="password">Previous Password</label>
                    <?= html_password('password', 'maxlength="100" class="input" placeholder="•••••"') ?>
                    <?= err('password') ?>
                </section>

                <section class="actions">
                    <div class="btn-group">
                        <button type="submit" class="btn-primary">
                            Reactivate Account
                        </button>
                        <button type="button"
                                class="btn-ghost"
                                onclick="window.location.href='../login.php'">
                            Back to Login
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
    }

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
