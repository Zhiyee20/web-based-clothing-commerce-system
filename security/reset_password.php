<?php
require '../config.php';
include '../login_base.php';

// Require login
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user'])) {
  temp('info', 'Please log in to change your password.');
  header('Location: login.php'); exit;
}

$userID = $_SESSION['user']['UserID'];

if (is_post()) {
    $current = req('current_password');
    $new     = req('new_password');
    $confirm = req('confirm_password');

    if ($current == '') { $_err['current_password'] = 'Required'; }

    if ($new == '') {
      $_err['new_password'] = 'Required';
    } else {
      if (strlen($new) < 8)                         $_err['new_password'] = 'Must be at least 8 characters';
      if (!preg_match('/[A-Z]/', $new))            $_err['new_password'] = $_err['new_password'] ?? 'Must include an uppercase letter';
      if (!preg_match('/\d/', $new))               $_err['new_password'] = $_err['new_password'] ?? 'Must include a number';
      if (!preg_match('/[^A-Za-z0-9]/', $new))     $_err['new_password'] = $_err['new_password'] ?? 'Must include a symbol';
    }

    if ($confirm == '') {
      $_err['confirm_password'] = 'Required';
    } else if ($new !== $confirm) {
      $_err['confirm_password'] = 'Password not matched';
    }

    if ($current && $new && $current === $new) {
      $_err['new_password'] = 'New password must be different from current password';
    }

    if (!$_err) {
        $stm = $pdo->prepare('
          UPDATE user
             SET Password = SHA1(?)
           WHERE UserID = ?
             AND Password = SHA1(?)
        ');
        $stm->execute([$new, $userID, $current]);

        if ($stm->rowCount() === 1) {
            temp('info', 'Password updated successfully.');
            header('Location: index.php'); exit;
        } else {
            $_err['current_password'] = 'Current password is incorrect';
        }
    }
}

include '../user/header.php';
?>

<main class="auth-split-64">
  <section class="auth-left">
    <video autoplay muted loop playsinline>
      <source src="../uploads/video.mp4" type="video/mp4">
      Your browser does not support the video tag.
    </video>
  </section>

  <section class="auth-right">
    <div class="auth-card">
      <h2 class="auth-title">Change Password</h2>

      <form method="post" class="form">
        <section class="field">
          <label class="label" for="current_password">Current Password</label>
          <?= html_password('current_password', 'maxlength="100" class="input" placeholder="••••••••"') ?>
          <?= err('current_password') ?>
        </section>

        <section class="field">
          <label class="label" for="new_password">New Password</label>
          <?= html_password('new_password', 'maxlength="100" class="input" id="new_password" placeholder="••••••••"') ?>
          <?= err('new_password') ?>

          <ul class="pw-rules" id="pwRules">
            <li data-rule="length"><span class="tick">✔︎</span> 8+ characters</li>
            <li data-rule="upper"><span class="tick">✔︎</span> 1 uppercase letter</li>
            <li data-rule="number"><span class="tick">✔︎</span> 1 number</li>
            <li data-rule="symbol"><span class="tick">✔︎</span> 1 symbol</li>
          </ul>
        </section>

        <section class="field">
          <label class="label" for="confirm_password">Confirm New Password</label>
          <?= html_password('confirm_password', 'maxlength="100" class="input" id="confirm_password" placeholder="••••••••"') ?>
          <?= err('confirm_password') ?>
        </section>

        <section class="actions">
          <div class="btn-group">
            <button type="submit" class="btn-primary">Update Password</button>
            <button type="reset" class="btn-ghost">Reset</button>
          </div>
        </section>
      </form>
    </div>
  </section>
</main>

<?php include '../user/footer.php'; ?>

<style>
  :root{
    --panel:#f6f6f8;
    --text:#2a2a2a;
    --muted:#8b8b95;
    --black:#000;
    --black-dark:#111;
    --border:#e1e3ea;
    --shadow:0 10px 25px rgba(0,0,0,.08);
    --radius:14px;
  }

  /* ✅ Updated layout same as register fix */
  html, body {
    margin: 0;
  }

  .auth-split-64 {
    display: grid;
    grid-template-columns: 65% 35%;
    background: #fff;
  }

  /* Left side matches right height */
  .auth-left {
    overflow: hidden;
  }
  .auth-left video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  /* Right side normal scroll behavior (footer below) */
  .auth-right {
    background: var(--panel);
    padding: 48px 36px;
    display: flex;
    justify-content: center;
  }

  .auth-card {
    width: 100%;
    max-width: 520px;
  }

  .auth-title {
    margin: 0 0 30px;
    font-size: 28px;
    font-weight: 700;
    color: var(--text);
  }

  .field { margin-bottom: 16px; }
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
    background: #fff;
    transition: border .15s, box-shadow .15s;
  }
  .input:focus {
    border-color: var(--black);
    box-shadow: 0 0 0 3px rgba(0,0,0,.15);
  }

  .pw-rules {
    list-style: none;
    padding: 8px 0 0;
    margin: 0;
    font-size: 14px;
    color: #2f7d32;
    text-align: center;
  }
  .pw-rules li {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    margin: 6px 12px;
  }
  .pw-rules .tick {
    display: inline-block;
    width: 18px;
    text-align: center;
    font-weight: 700;
  }

  .actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 6px;
  }
  .btn-group { display: flex; gap: 8px; }

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
  .btn-primary:hover { background: var(--black-dark); }

  .btn-ghost {
    height: 46px;
    padding: 0 18px;
    border: 1.5px solid var(--border);
    background: #fff;
    border-radius: 10px;
    font-weight: 600;
    cursor: pointer;
  }

  /* Mobile */
  @media (max-width:880px) {
    .auth-split-64 {
      grid-template-columns: 1fr;
    }
    .auth-left { display: none; }
    .auth-right { padding: 28px 18px; }
  }
</style>

<script>
// Live checklist for new password
(function(){
  const pw = document.getElementById('new_password');
  if (!pw) return;
  const rules = {
    length : document.querySelector('[data-rule="length"]'),
    upper  : document.querySelector('[data-rule="upper"]'),
    number : document.querySelector('[data-rule="number"]'),
    symbol : document.querySelector('[data-rule="symbol"]')
  };
  function update(v){
    const okLen = v.length >= 8;
    const okUp  = /[A-Z]/.test(v);
    const okNum = /\d/.test(v);
    const okSym = /[^A-Za-z0-9]/.test(v);
    rules.length.style.color = okLen ? '#2f7d32' : '#8b8b95';
    rules.upper .style.color = okUp  ? '#2f7d32' : '#8b8b95';
    rules.number.style.color = okNum ? '#2f7d32' : '#8b8b95';
    rules.symbol.style.color = okSym ? '#2f7d32' : '#8b8b95';
  }
  update(pw.value || '');
  pw.addEventListener('input', e => update(e.target.value));
})();
</script>
