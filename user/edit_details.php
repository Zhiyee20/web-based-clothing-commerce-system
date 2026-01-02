<?php

/***************************************************************************
 *  edit_details.php – edit profile, live-preview avatar, rollback on cancel
 ***************************************************************************/
session_start();
require_once '../login_base.php';   // provides $_db & helper auth()

auth();                          // only logged-in users

$userID = $_SESSION['user']['UserID'];
$stmt   = $_db->prepare(
    "SELECT Username,email,phone_number,gender,photo
             FROM user WHERE UserID=?"
);
$stmt->execute([$userID]);
$user = $stmt->fetch(PDO::FETCH_OBJ) or die('User not found');

$errors = [];

/* ──────────────────────────────────────────────────────────────
   Handle POST
────────────────────────────────────────────────────────────────*/
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /* 1. gather fields */
    $uName  = trim($_POST['username']       ?? $user->Username);
    $email  = trim($_POST['email']          ?? $user->email);
    $phone  = trim($_POST['phone_number']   ?? $user->phone_number);
    $gender =        $_POST['gender']       ?? $user->gender;

    /* 2. validation – username unique */
    if ($uName !== $user->Username) {
        $q = $_db->prepare("SELECT COUNT(*) FROM user
                            WHERE Username=? AND UserID<>?");
        $q->execute([$uName, $userID]);
        if ($q->fetchColumn()) $errors[] = 'Username already exists';
    }
    /* phone format + unique */
    if (!preg_match('/^\d{10,11}$/', $phone))
        $errors[] = 'Phone must have 10 or 11 digits';
    elseif ($phone !== $user->phone_number) {                // changed?
        $q = $_db->prepare("SELECT COUNT(*) FROM user
                            WHERE phone_number=? AND UserID<>?");
        $q->execute([$phone, $userID]);
        if ($q->fetchColumn()) $errors[] = 'Phone number already exists';
    }

    /* email format + unique */
    if (!filter_var($email, FILTER_VALIDATE_EMAIL))
        $errors[] = 'Invalid email';
    elseif ($email !== $user->email) {
        $q = $_db->prepare("SELECT COUNT(*) FROM user
                            WHERE email=? AND UserID<>?");
        $q->execute([$email, $userID]);
        if ($q->fetchColumn()) $errors[] = 'Email already exists';
    }


    /* avatar upload (optional) */
    $photoField = $user->photo;
    if (!empty($_FILES['photo']['name'])) {
        $f = $_FILES['photo'];
        $okTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($f['type'], $okTypes))
            $errors[] = 'Only JPEG, PNG, WebP allowed';
        elseif ($f['error'] == 0) {
            $dir = __DIR__ . '/../uploads/';
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            $ext = pathinfo($f['name'], PATHINFO_EXTENSION);
            $new = uniqid('', true) . '.' . $ext;
            if (move_uploaded_file($f['tmp_name'], $dir . $new)) {
                // remove old
                if ($user->photo && file_exists($dir . $user->photo))
                    unlink($dir . $user->photo);
                $photoField = $new;
            } else $errors[] = 'Failed to upload image';
        } else $errors[] = 'Upload error ' . $f['error'];
    }

    /* 3. update if no errors */
    if (!$errors) {
        $sql = "UPDATE user SET Username=?,email=?,phone_number=?,gender=?,photo=?";
        $prm = [$uName, $email, $phone, $gender, $photoField];
        
        $sql .= " WHERE UserID=?";
        $prm[] = $userID;
        $_db->prepare($sql)->execute($prm);

        // update session
        $_SESSION['user']['Username'] = $uName;
        $_SESSION['user']['email'] = $email;
        $_SESSION['user']['phone_number'] = $phone;
        $_SESSION['user']['gender'] = $gender;
        $_SESSION['user']['photo'] = $photoField;

        temp('info', 'Profile updated successfully!');   // ① set flash
        header('Location: profile.php');                 // ② go back to profile
        exit;
    }
}
/* avatar url */
$avatarURL = '../uploads/' . ($user->photo ?: 'default_user.jpg');
?>
<?php include 'header.php'; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Edit Profile</title>

    <link rel="stylesheet" href="../assets/edit_profile.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="assets/app.js"></script>
</head>

<body>
<main class="container-wrapper">

    <form id="edit-form" method="POST" enctype="multipart/form-data">
        <h1>Edit Profile</h1>

        <?php if ($errors): ?>
            <div class="error-box">
                <?php foreach ($errors as $e) echo '<p>' . htmlspecialchars($e) . '</p>'; ?>
            </div>
        <?php endif; ?>

        <!-- avatar choose + preview -->
        <div class="avatar-block">
            <img id="avatarPreview"
                src="<?= htmlspecialchars($avatarURL) ?>"
                alt="Avatar"
                onclick="document.getElementById('photo').click();">
            <input type="file" id="photo" name="photo" accept="image/*" style="display:none">
        </div>

        <label>Username <input type="text" name="username"
                value="<?= htmlspecialchars($user->Username) ?>" required></label>

        <label>Phone Number <input type="text" name="phone_number"
                value="<?= htmlspecialchars($user->phone_number) ?>" required></label>

        <label>Email <input type="email" name="email"
                value="<?= htmlspecialchars($user->email) ?>" required></label>

        <label>Gender
            <label><input type="radio" name="gender" value="Male"
                    <?= $user->gender == 'Male' ? 'checked' : '' ?>> Male</label>
            <label><input type="radio" name="gender" value="Female"
                    <?= $user->gender == 'Female' ? 'checked' : '' ?>> Female</label>
        </label>

        <div class="divider"></div>

        <br>

        <div class="btn-row">
            <button type="button" class="cancel-btn">Cancel</button>
            <button type="submit" class="save-btn">Save Changes</button>
        </div>
    </form>
   
    </main>
    <!-- live preview + rollback -->
    <script>
        $(function() {
            const $file = $('#photo'),
                $img = $('#avatarPreview'),
                $cancel = $('.cancel-btn');
            const orig = $img.attr('src');
            let temp = null;

            $('.avatar-block img#avatarPreview').css({
                width: '120px',
                height: '120px',
                'border-radius': '50%',
                'object-fit': 'cover',
                cursor: 'pointer'
            });

            $file.on('change', function() {
                const f = this.files[0];
                if (!f) return;
                if (temp) URL.revokeObjectURL(temp);
                temp = URL.createObjectURL(f);
                $img.attr('src', temp);
            });

            $('.cancel-btn').on('click', function() {
                window.location.href = 'profile.php';
            });
        });
    </script>
</body>

</html>
<?php include 'footer.php'; ?>