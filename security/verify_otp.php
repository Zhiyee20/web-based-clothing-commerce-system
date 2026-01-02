<?php
// verify_otp.php — verify SMS OTP and allow password reset (phone_number version)

require '../config.php';
include '../login_base.php';

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

if (!function_exists('h')) {
  function h($v)
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

$_err = [];
$otp   = '';
$new_password = '';
$confirm_password = '';

// DEV ONLY (mainly for SMS preview during development)
$devOtp = $_SESSION['dev_last_sms_otp'] ?? null;

// Which step to show: 'verify' (enter code) or 'password' (set new password)
$step = isset($_GET['step']) ? trim((string)$_GET['step']) : 'verify';

// Determine reset method for UI display (email or sms)
$methodForView = ($step === 'verify')
  ? ($_SESSION['pending_reset_method'] ?? 'sms')
  : ($_SESSION['reset_method'] ?? 'sms');

if (is_post()) {
  $stage = trim((string)($_POST['stage'] ?? 'verify'));

  /* =========================
   STEP 1: Verify OTP (email or SMS)
   ========================= */
  if ($stage === 'verify') {
    $step = 'verify';
    $otp  = trim((string)req('otp'));

    // Must have pending reset context from forgotpw.php
    $pendingUserId = $_SESSION['pending_reset_user_id'] ?? null;
    $pendingMethod = $_SESSION['pending_reset_method'] ?? null;

    if (!$pendingUserId || !$pendingMethod) {
      temp('info', 'Invalid reset session. Please start again.');
      header('Location: forgotpw.php');
      exit;
    }

    if ($otp === '') {
      $_err['otp'] = 'OTP is required';
    }

    if (!$_err) {
      // Find latest unused reset for this user + method
      $sql = "
            SELECT id AS reset_id,
                   token_hash,
                   expires_at,
                   user_id
            FROM password_resets
            WHERE user_id = ?
              AND method = ?
              AND used_at IS NULL
            ORDER BY created_at DESC
            LIMIT 1
        ";
      $stm = $pdo->prepare($sql);
      $stm->execute([$pendingUserId, $pendingMethod]);
      $row = $stm->fetch(PDO::FETCH_ASSOC);

      $genericError = 'Invalid or expired OTP. Please request a new one.';

      if (!$row) {
        $_err['otp'] = $genericError;
      } else {
        if (strtotime($row['expires_at']) < time()) {
          $_err['otp'] = 'OTP has expired. Please request a new one.';
        } elseif (!password_verify($otp, $row['token_hash'])) {
          $_err['otp'] = $genericError;
        } else {
          // OTP OK → store new session context for password step
          $_SESSION['reset_user_id']   = (int)$row['user_id'];
          $_SESSION['reset_reset_id']  = (int)$row['reset_id'];
          $_SESSION['reset_method']    = $pendingMethod;

          // Clear dev SMS test value
          unset($_SESSION['dev_last_sms_otp']);

          // Clear pending context
          unset($_SESSION['pending_reset_user_id'], $_SESSION['pending_reset_method']);

          header('Location: verify_otp.php?step=password');
          exit;
        }
      }
    }
  }

  /* =========================
   STEP 2: Set new password
   ========================= */
  if ($stage === 'password') {
    $step = 'password';
    $new_password      = trim((string)req('new_password'));
    $confirm_password  = trim((string)req('confirm_password'));

    // Must have session from valid OTP
    $userId  = $_SESSION['reset_user_id']  ?? null;
    $resetId = $_SESSION['reset_reset_id'] ?? null;
    $method  = $_SESSION['reset_method']   ?? null;

    if (!$userId || !$resetId || !$method) {
      temp('info', 'Invalid reset session. Please start again.');
      header('Location: forgotpw.php');
      exit;
    }

    if ($new_password === '') {
      $_err['new_password'] = 'New password is required';
    } elseif (strlen($new_password) < 8) {
      $_err['new_password'] = 'Password must be at least 8 characters';
    }

    if ($confirm_password === '') {
      $_err['confirm_password'] = 'Please confirm your password';
    } elseif ($new_password !== $confirm_password) {
      $_err['confirm_password'] = 'Passwords do not match';
    }

    if (!$_err) {
      // Double-check reset record still valid
      $stm = $pdo->prepare("
            SELECT id, expires_at, used_at
            FROM password_resets
            WHERE id = ? AND user_id = ? AND method = ?
            LIMIT 1
        ");
      $stm->execute([$resetId, $userId, $method]);
      $pr = $stm->fetch(PDO::FETCH_ASSOC);

      if (!$pr || $pr['used_at'] !== null || strtotime($pr['expires_at']) < time()) {
        temp('info', 'Reset request has expired or already been used. Please request again.');
        header('Location: forgotpw.php');
        exit;
      }

      // Hash new password using SHA1 (reset password)
      $newHash = sha1((string)$new_password);

      // Update password
      $up = $pdo->prepare("UPDATE user SET Password = ? WHERE UserID = ?");
      $up->execute([$newHash, $userId]);

      // Mark this reset as used
      $u2 = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
      $u2->execute([$resetId]);

      // Cleanup sessions
      unset(
        $_SESSION['reset_user_id'],
        $_SESSION['reset_reset_id'],
        $_SESSION['reset_method'],
        $_SESSION['dev_last_sms_otp']
      );
      session_regenerate_id(true);

      temp('info', 'Password updated successfully. Please log in.');
      header('Location: login.php');
      exit;
    }
  }
}

include '../user/header.php';
?>

<main class="auth-split-64">
  <!-- Left: video -->
  <section class="auth-left">
    <video autoplay muted loop>
      <source src="../uploads/video.mp4" type="video/mp4">
      Your browser does not support the video tag.
    </video>
  </section>

  <!-- Right: card -->
  <section class="auth-right">
    <div class="auth-card">
      <?php if ($step === 'verify'): ?>
        <h2 class="auth-title">Verify Code</h2>
        <p class="auth-subtitle">
          <?php if ($methodForView === 'email'): ?>
            Enter the verification code sent to your email.
          <?php else: ?>
            Enter the verification code sent to your phone.
          <?php endif; ?>
        </p>

        <?php if ($devOtp && $methodForView === 'sms'): ?>
          <p style="font-size:12px;color:#16a34a;">
            [DEV only] Your OTP is: <?= h($devOtp) ?>
          </p>
        <?php endif; ?>

        <form method="post" class="form" novalidate>
          <input type="hidden" name="stage" value="verify">

          <section class="field">
            <label class="label" for="otp">Verification code</label>
            <input class="input" type="text" name="otp" id="otp" value="<?= h($otp) ?>">
            <?php if (!empty($_err['otp'])): ?>
              <p class="error"><?= h($_err['otp']) ?></p>
            <?php endif; ?>
          </section>

          <section class="actions">
            <div class="btn-group" style="width:100%">
              <button type="submit" class="btn-primary" style="width:100%;">Verify Code</button>
            </div>
          </section>
        </form>
      <?php else: ?>

        <h2 class="auth-title">Set New Password</h2>
        <p class="auth-subtitle">Choose a strong password for your account.</p>

        <form method="post" class="form" novalidate>
          <input type="hidden" name="stage" value="password">

          <section class="field">
            <label class="label" for="new_password">New password</label>
            <input class="input" type="password" name="new_password" id="new_password">
            <?php if (!empty($_err['new_password'])): ?>
              <p class="error"><?= h($_err['new_password']) ?></p>
            <?php endif; ?>

            <!-- Password rules checklist (same UI as register/reset_password) -->
            <ul class="pw-rules" id="pwRules">
              <li data-rule="length"><span class="tick">✔︎</span> 8+ characters</li>
              <li data-rule="upper"><span class="tick">✔︎</span> 1 uppercase letter</li>
              <li data-rule="number"><span class="tick">✔︎</span> 1 number</li>
              <li data-rule="symbol"><span class="tick">✔︎</span> 1 symbol</li>
            </ul>
          </section>

          <section class="field">
            <label class="label" for="confirm_password">Confirm password</label>
            <input class="input" type="password" name="confirm_password" id="confirm_password">
            <?php if (!empty($_err['confirm_password'])): ?>
              <p class="error"><?= h($_err['confirm_password']) ?></p>
            <?php endif; ?>
          </section>

          <section class="actions">
            <div class="btn-group" style="width:100%">
              <button type="submit" class="btn-primary" style="width:100%;">Update Password</button>
            </div>
          </section>
        </form>
      <?php endif; ?>
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

  .error {
    color: #dc2626;
    font-size: 12px;
    margin-top: 4px;
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

  .select,
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

  .input:focus,
  .select:focus {
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

  .btn-link {
    background: transparent;
    border: none;
    color: #000;
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

  .pw-rules {
    list-style: none;
    padding: 4px 0 0;
    font-size: 12px;
    color: #2f7d32;
    text-align: center;
  }

  .pw-rules li {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    margin: 3px 8px;
  }

  .pw-rules .tick {
    display: inline-block;
    text-align: center;
    font-weight: 700;
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

<script>
  // Password live checklist for OTP reset (same logic as register/reset_password)
  (function() {
    const pw = document.getElementById('new_password');
    if (!pw) return;

    const rules = {
      length: document.querySelector('[data-rule="length"]'),
      upper: document.querySelector('[data-rule="upper"]'),
      number: document.querySelector('[data-rule="number"]'),
      symbol: document.querySelector('[data-rule="symbol"]')
    };

    function update(v) {
      const okLen = v.length >= 8;
      const okUp = /[A-Z]/.test(v);
      const okNum = /\d/.test(v);
      const okSym = /[^A-Za-z0-9]/.test(v);

      rules.length.style.color = okLen ? '#2f7d32' : '#8b8b95';
      rules.upper.style.color = okUp ? '#2f7d32' : '#8b8b95';
      rules.number.style.color = okNum ? '#2f7d32' : '#8b8b95';
      rules.symbol.style.color = okSym ? '#2f7d32' : '#8b8b95';
    }

    update(pw.value || '');
    pw.addEventListener('input', e => update(e.target.value));
  })();
</script>