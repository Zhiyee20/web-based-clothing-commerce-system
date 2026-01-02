<?php
require '../config.php';
include '../login_base.php';

require_once __DIR__ . '/../vendor/autoload.php';  // PHPMailer via Composer

// OPTIONAL while developing
error_reporting(E_ALL);
ini_set('display_errors', '1');

// =========================
// Helper: Send Email OTP
// =========================
function send_reset_email(string $toEmail, string $otp): bool
{
  $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

  try {
    $mail->SMTPDebug   = 0;          // 0 = no output
    $mail->Debugoutput = 'html';

    // SMTP settings (Gmail)
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'example@gmail.com';      // replace Gmail that has app password
    $mail->Password   = 'app-password';           // replace app password 
    $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;
    $mail->CharSet    = 'UTF-8';

    // From and To
    $mail->setFrom('example@gmail.com', 'Luxera Store Support');
    $mail->addAddress($toEmail);

    // Email content – OTP instead of link
    $mail->isHTML(true);
    $mail->Subject = 'Your Luxera Password Reset Code';
    $mail->Body    = "
          <div style='font-family:sans-serif;'>
            <h2>Password Reset Verification Code</h2>
            <p>We received a request to reset your Luxera account password.</p>
            <p>Your verification code is:</p>
            <p style='font-size:24px;font-weight:bold;letter-spacing:4px;'>{$otp}</p>
            <p>This code will expire in <strong>10 minutes</strong>.</p>
            <p>If you did not request this, you can safely ignore this email.</p>
            <hr>
            <small>For your security, do not share this code with anyone.</small>
          </div>
        ";

    $mail->send();
    return true;
  } catch (\PHPMailer\PHPMailer\Exception $e) {
    error_log("Mail error: " . $mail->ErrorInfo);
    return false;
  }
}

if (!function_exists('h')) {
  function h($v)
  {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

$_err   = [];
$method = '';
$email  = '';
$phone  = '';

if (is_post()) {
  $method = trim((string)req('reset_method'));
  $email  = trim((string)req('email'));
  $phone  = trim((string)req('phone'));

  // ----- Validate: reset method -----
  if ($method === '') {
    $_err['reset_method'] = 'Please choose a reset method';
  }

  if ($method === 'email') {
    if ($email === '') {
      $_err['email'] = 'Email is required';
    } elseif (!is_email($email)) {
      $_err['email'] = 'Invalid email';
    }
  } elseif ($method === 'sms') {
    if ($phone === '') {
      $_err['phone'] = 'Phone number is required';
    }
  }

  // ----- Process when no validation error -----
  if (!$_err) {
    $genericMsg = 'If an account exists, a verification code has been sent.';
    $cooldownSeconds = 60; // minimum time between reset emails for same user+method

    /* ==================== Method 1: Email OTP ==================== */
    if ($method === 'email') {
      $stm = $pdo->prepare('SELECT UserID, email FROM user WHERE email = ? LIMIT 1');
      $stm->execute([$email]);
      $u = $stm->fetch(PDO::FETCH_ASSOC);

      if ($u) {
        // --- check for recent existing request (cooldown) ---
        $check = $pdo->prepare("
      SELECT created_at 
        FROM password_resets 
       WHERE user_id = ? 
         AND method = 'email'
       ORDER BY created_at DESC
       LIMIT 1
    ");
        $check->execute([$u['UserID']]);
        $lastCreated = $check->fetchColumn();

        // Only create new OTP if outside cooldown window
        if (!$lastCreated || (time() - strtotime($lastCreated)) >= $cooldownSeconds) {

          $otp    = random_int(100000, 999999); // 6-digit OTP
          $hash   = password_hash((string)$otp, PASSWORD_DEFAULT);
          $expiry = date('Y-m-d H:i:s', time() + 600); // 10 minutes expiry

          $ins = $pdo->prepare('
        INSERT INTO password_resets (user_id, token_hash, method, expires_at, created_at)
        VALUES (?, ?, "email", ?, NOW())
      ');
          $ins->execute([$u['UserID'], $hash, $expiry]);

          $ok = send_reset_email($u['email'], (string)$otp);

          if (!$ok) {
            $_err['email'] = 'Failed to send verification code. Please try again later.';
          }
        }

        // If no email error → proceed to OTP verification page
        if (empty($_err['email'])) {
          // Store context for verify_otp.php (optional but useful)
          if (session_status() === PHP_SESSION_NONE) {
            session_start();
          }
          $_SESSION['pending_reset_user_id'] = $u['UserID'];
          $_SESSION['pending_reset_method']  = 'email';

          temp('info', $genericMsg);
          header('Location: verify_otp.php');
          exit;
        }
      } else {
        // Email not found — show error on page
        $_err['email'] = 'No account found with this email address.';
      }
    }

    /* ==================== Method 2: SMS OTP ==================== */
    if ($method === 'sms') {
      // Country code select (e.g. +60) + local number → build full E.164 phone
      $countryCode = trim((string)req('country_code'));
      if ($countryCode === '') {
        $countryCode = '+60'; // default Malaysia
      }

      // Keep original for redisplay, but normalise to digits for lookup
      $digitsOnly = preg_replace('/\D+/', '', $phone);
      $fullPhone  = $countryCode . $digitsOnly; // e.g. +60 + 123456789 → +60123456789

      $stm = $pdo->prepare('SELECT UserID, phone_number FROM user WHERE phone_number = ? LIMIT 1');
      $stm->execute([$fullPhone]);
      $u = $stm->fetch(PDO::FETCH_ASSOC);

      if ($u) {
        // cooldown same as email
        $check = $pdo->prepare("
      SELECT created_at 
        FROM password_resets 
       WHERE user_id = ? 
         AND method = 'sms'
       ORDER BY created_at DESC
       LIMIT 1
    ");
        $check->execute([$u['UserID']]);
        $lastCreated = $check->fetchColumn();

        if (!$lastCreated || (time() - strtotime($lastCreated)) >= $cooldownSeconds) {

          $otp    = random_int(100000, 999999); // 6-digit OTP
          $hash   = password_hash((string)$otp, PASSWORD_DEFAULT);
          $expiry = date('Y-m-d H:i:s', time() + 600); // 10 minutes

          $ins = $pdo->prepare('
        INSERT INTO password_resets (user_id, token_hash, method, expires_at, created_at)
        VALUES (?, ?, "sms", ?, NOW())
      ');
          $ins->execute([$u['UserID'], $hash, $expiry]);

          // ---------- Twilio send ----------
          try {
            $twilioSid   = 'TWILIO_ACCOUNT_SID';
            $twilioToken = 'TWILIO_AUTH_TOKEN';
            $twilioFrom  = 'TWILIO_NUMBER';     

            // Use the phone number from DB (should already be E.164, e.g. +6012xxxxxxx)
            $toNumber    = $u['phone_number'];

            $client = new \Twilio\Rest\Client($twilioSid, $twilioToken);

            $body = "Your Luxera verification code is: {$otp}. It will expire in 10 minutes.";

            $msg = $client->messages->create(
              $toNumber,
              [
                'from' => $twilioFrom,
                'body' => $body,
              ]
            );

            // TEMP DEBUG: uncomment if still not working
            // echo '<pre>'; var_dump($msg->sid); echo '</pre>'; exit;

          } catch (\Throwable $e) {
            echo "<pre style='color:red'>Twilio SMS error: " . htmlspecialchars($e->getMessage()) . "</pre>";
            error_log('Twilio SMS error: ' . $e->getMessage());
            exit;
          }
        }

        // User exists (whether new OTP or within cooldown) → go to verify page
        if (session_status() === PHP_SESSION_NONE) {
          session_start();
        }
        $_SESSION['pending_reset_user_id'] = $u['UserID'];
        $_SESSION['pending_reset_method']  = 'sms';

        temp('info', $genericMsg);
        header('Location: verify_otp.php');
        exit;
      } else {
        // phone not found — remain on page and show error
        $_err['phone'] = 'No account found with this phone number.';
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

  <!-- Right 40%: reset panel -->
  <section class="auth-right">
    <div class="auth-card">
      <h2 class="auth-title">Forgot Password</h2>
      <p class="auth-subtitle"> </p>

      <form method="post" class="form" novalidate id="forgot-form">
        <!-- Select method -->
        <section class="field">
          <label class="label" for="reset_method">Reset using</label>
          <select class="select" name="reset_method" id="reset_method" required>
            <option value="">— Select reset method —</option>
            <option value="email" <?= $method === 'email' ? 'selected' : '' ?>>Email verification code</option>
            <option value="sms" <?= $method === 'sms'   ? 'selected' : '' ?>>SMS OTP</option>
          </select>
          <?php if (!empty($_err['reset_method'])): ?>
            <p class="error"><?= h($_err['reset_method']) ?></p>
          <?php endif; ?>
        </section>

        <!-- Email field (for email reset link) -->
        <section class="field" id="field-email">
          <label class="label" for="email">Email address</label>
          <input class="input" type="email" name="email" id="email" value="<?= h($email) ?>">
          <?php if (!empty($_err['email'])): ?>
            <p class="error"><?= h($_err['email']) ?></p>
          <?php endif; ?>
        </section>

        <!-- Phone field (for SMS OTP) -->
        <section class="field" id="field-phone">
          <label class="label" for="phone">Phone number</label>

          <div class="phone-input-wrap">
            <select class="select phone-code" name="country_code" id="country_code">
              <option value="+60" <?= (($_POST['country_code'] ?? '+60') === '+60' ? 'selected' : '') ?>>+60 (MY)</option>
              <option value="+65" <?= (($_POST['country_code'] ?? '') === '+65' ? 'selected' : '') ?>>+65 (SG)</option>
              <option value="+62" <?= (($_POST['country_code'] ?? '') === '+62' ? 'selected' : '') ?>>+62 (ID)</option>
              <!-- you can add more if needed -->
            </select>

            <input
              class="input phone-number-only"
              type="text"
              name="phone"
              id="phone"
              placeholder="12 345 6789"
              value="<?= h($phone) ?>">
          </div>

          <?php if (!empty($_err['phone'])): ?>
            <p class="error"><?= h($_err['phone']) ?></p>
          <?php endif; ?>
        </section>

        <section class="actions">
          <div class="btn-group" style="width:100%">
            <button type="submit" class="btn-primary" id="submit-btn" style="width:100%;">Submit</button>
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

  .phone-input-wrap {
    display: flex;
    gap: 8px;
    align-items: center;
  }

  .phone-input-wrap .phone-code {
    flex: 0 0 120px;
  }

  .phone-input-wrap .phone-number-only {
    flex: 1 1 auto;
  }

  .phone-input-wrap .phone-code,
  .phone-input-wrap .phone-number-only {
    height: 44px;
    /* same as .input height */
    display: flex;
    align-items: center;
    padding: 0 12px;
    /* matches .input padding */
    border-radius: 10px;
    border: 1.5px solid var(--border);
    background: #fff;
    font-size: 15px;
  }

  .phone-code {
    appearance: none; /* remove native arrow rendering */
    -webkit-appearance: none;
    background-position: right 10px center;
    background-repeat: no-repeat;
    cursor: pointer;
  }
</style>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const methodSelect = document.getElementById('reset_method');
    const emailField = document.getElementById('field-email');
    const phoneField = document.getElementById('field-phone');
    const form = document.getElementById('forgot-form');
    const submitBtn = document.getElementById('submit-btn');

    function updateFields() {
      const method = methodSelect.value;
      if (method === 'email') {
        emailField.style.display = 'block';
        phoneField.style.display = 'none';
      } else if (method === 'sms') {
        emailField.style.display = 'none';
        phoneField.style.display = 'block';
      } else {
        emailField.style.display = 'none';
        phoneField.style.display = 'none';
      }
    }

    methodSelect.addEventListener('change', updateFields);
    updateFields(); // initial

    // Prevent double submit on the front-end
    form.addEventListener('submit', function() {
      submitBtn.disabled = true;
      submitBtn.textContent = 'Sending...';
    });
  });

</script>
