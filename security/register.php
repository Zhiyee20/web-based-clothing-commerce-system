<?php
require '../config.php';
include '../login_base.php';

if (!function_exists('h')) {
  function h($v)
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

function normalise_e164(string $countryCode, string $rawPhone): string
{
  $ccDigits = preg_replace('/\D+/', '', $countryCode); // "+60" -> "60"
  if ($ccDigits === '') $ccDigits = '60';

  $p = preg_replace('/\D+/', '', $rawPhone); // digits only

  // If user already included country code digits, remove it (avoid double)
  if (str_starts_with($p, $ccDigits)) {
    $p = substr($p, strlen($ccDigits));
  }

  // Malaysia trunk "0" removal
  if ($ccDigits === '60' && str_starts_with($p, '0')) {
    $p = ltrim($p, '0');
  }

  return '+' . $ccDigits . $p;
}

if (is_post()) {
  $username     = req('name');
  $email        = req('email');
  $country_code = trim((string)req('country_code')) ?: '+60';
  $phone_raw    = (string)req('phone_number'); // keep raw for validation display if needed
  $phone_number = normalise_e164($country_code, $phone_raw); // store E.164
  $password     = req('password');
  $confirm      = req('confirm');
  $gender       = req('gender'); // NEW

  // ===== Validation =====
  if ($email == '') {
    $_err['email'] = 'Required';
  } else if (!is_email($email)) {
    $_err['email'] = 'Invalid email';
  }

  if ($password == '') {
    $_err['password'] = 'Required';
  }

  if ($confirm == '') {
    $_err['confirm'] = 'Required';
  } else if ($password !== $confirm) {
    $_err['confirm'] = 'Password not matched';
  }

  if ($username == '') {
    $_err['name'] = 'Required';
  }

  if (trim($phone_raw) === '') {
    $_err['phone_number'] = 'Required';
  }

  if (empty($_err['phone_number']) && $country_code === '+60') {
    $after = preg_replace('/^\+60/', '', $phone_number);
    if (strlen($after) < 9 || strlen($after) > 11) {
      $_err['phone_number'] = 'Invalid Malaysia phone number format';
    }
  }

  // NEW: Gender validation
  $allowedGender = ['Male', 'Female'];
  if ($gender == '') {
    $_err['gender'] = 'Required';
  } elseif (!in_array($gender, $allowedGender, true)) {
    $_err['gender'] = 'Invalid gender';
  }

  // Optional server-side password-strength checks (mirror the checklist)
  if ($password) {
    if (strlen($password) < 8) {
      $_err['password'] = 'Must be at least 8 characters';
    }
    if (!preg_match('/[A-Z]/', $password)) {
      $_err['password'] = ($_err['password'] ?? '') ?: 'Must include an uppercase letter';
    }
    if (!preg_match('/\d/', $password)) {
      $_err['password'] = ($_err['password'] ?? '') ?: 'Must include a number';
    }
    if (!preg_match('/[^A-Za-z0-9]/', $password)) {
      $_err['password'] = ($_err['password'] ?? '') ?: 'Must include a symbol';
    }
  }

  // Photo (optional)
  $photoName = null;
  if (!empty($_FILES['photo']['name'])) {
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($_FILES['photo']['type'], $allowed)) {
      $_err['photo'] = 'Invalid image type';
    } else if ($_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
      $_err['photo'] = 'Upload error';
    }
  }

  // ===== Save (only if no errors) =====
  if (!$_err) {
    $stm = $pdo->prepare('SELECT UserID FROM user WHERE email=? LIMIT 1');
    $stm->execute([$email]);
    if ($stm->fetch()) {
      $_err['email'] = 'Email already registered';
    } else {
      $stm = $pdo->prepare('SELECT UserID FROM user WHERE phone_number=? LIMIT 1');
      $stm->execute([$phone_number]);
      if ($stm->fetch()) {
        $_err['phone_number'] = 'Phone number already registered';
      }
    }
    if (!$_err) {
      if (!empty($_FILES['photo']['name']) && empty($_err['photo'])) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $photoName = 'user_' . time() . '_' . substr(md5($email), 0, 6) . '.' . strtolower($ext);
        @mkdir(__DIR__ . '../../uploads', 0777, true);
        move_uploaded_file($_FILES['photo']['tmp_name'], __DIR__ . "../../uploads/$photoName");
      }

      // NEW: include gender in INSERT, Role capitalised to match enum('Member','Admin')
      $stm = $pdo->prepare('
                INSERT INTO user (Username, Email, Password, Role, phone_number, photo, gender)
                VALUES (?, ?, SHA1(?), "Member", ?, ?, ?)
            ');
      $stm->execute([$username, $email, $password, $phone_number, $photoName, $gender]);

      temp('info', 'Registered successfully, please log in.');
      header('Location: login.php');
      exit;
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
      <h2 class="auth-title">Register</h2>
      <form method="post" class="form" enctype="multipart/form-data">
        <section class="field">
          <label class="label" for="name">Username</label>
          <?= html_text('name', 'maxlength="100" class="input" ') ?>
          <?= err('name') ?>
        </section>

        <section class="field">
          <label class="label" for="email">Email</label>
          <?= html_text('email', 'maxlength="100" class="input" ') ?>
          <?= err('email') ?>
        </section>

        <section class="field">
          <label class="label" for="password">Password</label>
          <?= html_password('password', 'maxlength="100" class="input" id="password" placeholder="••••••••"') ?>
          <?= err('password') ?>
          <ul class="pw-rules" id="pwRules">
            <li data-rule="length"><span class="tick">✔︎</span> 8+ characters</li>
            <li data-rule="upper"><span class="tick">✔︎</span> 1 uppercase letter</li>
            <li data-rule="number"><span class="tick">✔︎</span> 1 number</li>
            <li data-rule="symbol"><span class="tick">✔︎</span> 1 symbol</li>
          </ul>
        </section>

        <section class="field">
          <label class="label" for="confirm">Confirm Password</label>
          <?= html_password('confirm', 'maxlength="100" class="input" id="confirm" placeholder="••••••••"') ?>
          <?= err('confirm') ?>
        </section>

        <section class="field">
          <label class="label" for="phone_number">Phone Number</label>

          <div class="phone-input-wrap">
            <select class="select phone-code" name="country_code" id="country_code">
              <option value="+60" <?= ((req('country_code') ?: '+60') === '+60' ? 'selected' : '') ?>>+60 (MY)</option>
              <option value="+65" <?= (req('country_code') === '+65' ? 'selected' : '') ?>>+65 (SG)</option>
              <option value="+62" <?= (req('country_code') === '+62' ? 'selected' : '') ?>>+62 (ID)</option>
            </select>

            <input
              class="input phone-number-only"
              type="text"
              name="phone_number"
              id="phone_number"
              maxlength="30"
              placeholder="12 345 6789"
              value="<?= h(req('phone_number')) ?>">
          </div>

          <?= err('phone_number') ?>
        </section>

        <!-- NEW: Gender field (fits your design, simple inline radios) -->
        <section class="field">
          <label class="label">Gender</label>
          <div class="gender-row">
            <label>
              <input type="radio" name="gender" value="Male"
                <?= (req('gender') === 'Male' ? 'checked' : '') ?>> Male
            </label>
            <label>
              <input type="radio" name="gender" value="Female"
                <?= (req('gender') === 'Female' ? 'checked' : '') ?>> Female
            </label>
          </div>
          <?= err('gender') ?>
        </section>

        <section class="field">
          <label class="label" for="photo">Photo</label>
          <div class="photo-wrap">
            <input type="file" id="photo" name="photo" accept="image/*" class="file-input" />
            <div class="photo-drop" id="photoDrop">
              <div class="photo-icon" aria-hidden>🖼️</div>
              <div class="photo-text">Click to upload</div>
            </div>
          </div>
          <?= err('photo') ?>
          <img id="photoPreview" class="photo-preview" alt="" style="display:none;" />
        </section>

        <section class="actions">
          <div class="btn-group">
            <button type="submit" class="btn-primary">Register</button>
            <button type="reset" class="btn-ghost">Reset</button>
          </div>
          <div class="link-right">
            <button type="button" class="btn-link" onclick="window.location.href='login.php'">
              Already have an account? Log in
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
    --black: #000;
    --black-dark: #111;
    --border: #e1e3ea;
    --shadow: 0 10px 25px rgba(0, 0, 0, .08);
  }

  /* 1) Let page be natural height (so footer is after content, NOT stuck) */
  html,
  body {
    margin: 0;
    /* no forced height, no overflow hidden */
  }

  /* 65% / 35% split; row height = content height
     => auth-left and auth-right automatically same height */
  .auth-split-64 {
    display: grid;
    grid-template-columns: 65% 35%;
    background: #fff;
  }

  /* Left column: fills row height same as right */
  .auth-left {
    overflow: hidden;
  }

  .auth-left video {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  /* Right column: normal content (page scrolls, not just this div) */
  .auth-right {
    background: var(--panel);
    padding: 40px 32px;
    display: flex;
    justify-content: center;
  }

  .auth-card {
    width: 100%;
    max-width: 460px;
  }

  .auth-title {
    font-size: 28px;
    font-weight: 700;
    margin-bottom: 12px;
    color: var(--text);
  }

  .field {
    margin-bottom: 12px;
  }

  .label {
    font-size: 12px;
    font-weight: 700;
    color: #6b6b75;
    margin-bottom: 4px;
  }

  .input {
    width: 100%;
    height: 40px;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1.5px solid var(--border);
    font-size: 14px;
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

  .photo-wrap {
    position: relative;
  }

  .file-input {
    position: absolute;
    inset: 0;
    opacity: 0;
    cursor: pointer;
  }

  .photo-drop {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    width: 100%;
    height: 90px;
    border: 1.5px dashed var(--border);
    border-radius: 10px;
    background: #fff;
  }

  .photo-icon {
    font-size: 24px;
  }

  .photo-text {
    font-size: 13px;
    color: var(--muted);
  }

  .photo-preview {
    margin-top: 8px;
    max-width: 100%;
    max-height: 90px;
    border-radius: 10px;
    box-shadow: var(--shadow);
  }

  .actions {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-top: 8px;
  }

  .btn-group {
    display: flex;
    gap: 8px;
  }

  .btn-primary {
    height: 40px;
    padding: 0 16px;
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
    height: 40px;
    padding: 0 16px;
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
    font-size: 13px;
    text-decoration: underline;
  }

  /* NEW: align gender radios nicely */
  .gender-row {
    display: flex;
    gap: 18px;
    font-size: 14px;
  }

  .phone-input-wrap {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  /* Country code dropdown */
  .phone-input-wrap .phone-code {
    flex: 0 0 120px;
    height: 60px;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1.5px solid var(--border);
    font-size: 14px;
    background: #fff;
    color: var(--text);
    cursor: pointer;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
  }

  /* Phone number input */
  .phone-input-wrap .phone-number-only {
    flex: 1 1 auto;
    height: 40px;
    padding: 8px 10px;
    border-radius: 10px;
    border: 1.5px solid var(--border);
    font-size: 14px;
  }

  /* Unified focus behaviour */
  .phone-input-wrap .phone-code:focus,
  .phone-input-wrap .phone-number-only:focus {
    border-color: var(--black);
    box-shadow: 0 0 0 3px rgba(0, 0, 0, 0.15);
    outline: none;
  }

  /* Mobile */
  @media (max-width:880px) {
    .auth-split-64 {
      grid-template-columns: 1fr;
    }

    .auth-left {
      display: none;
    }

    .auth-right {
      padding: 24px 16px;
    }
  }
</style>

<script>
  // Password live checklist
  (function() {
    const pw = document.getElementById('password');
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
    if (pw) {
      update(pw.value || '');
      pw.addEventListener('input', e => update(e.target.value));
    }
  })();

  // Photo preview
  (function() {
    const input = document.getElementById('photo');
    const preview = document.getElementById('photoPreview');
    if (!input || !preview) return;
    input.addEventListener('change', () => {
      const f = input.files && input.files[0];
      if (!f) {
        preview.style.display = 'none';
        return;
      }
      const reader = new FileReader();
      reader.onload = e => {
        preview.src = e.target.result;
        preview.style.display = 'block';
      };
      reader.readAsDataURL(f);
    });
  })();
</script>