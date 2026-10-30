<?php
require_once __DIR__ . '/../includes/session_boot.php';
require_once __DIR__ . '/../includes/database_connect.php';
require_once __DIR__ . '/../includes/csrf_helper.php';

if (!isset($_SESSION['code_verified']) || $_SESSION['code_verified'] !== true) {
    header("Location: /authentication/forgot_password");
    exit;
}

$error_message = '';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    verify_csrf_token();
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    $email = $_SESSION['reset_email'];

    if (strlen($new_password) < 6) {
        $error_message = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_message = "Passwords do not match.";
    } else {
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

        $stmt = $pdo->prepare("UPDATE Users SET password_hash = :hash, reset_code = NULL, code_expires = NULL WHERE email = :email");
        $stmt->execute([
            'hash' => $hashed_password,
            'email' => $email
        ]);

        session_unset();
        session_destroy();

        header("Location: /authentication/login?reset=success");
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Create New Password | HI-POWER</title>
<style>
:root{--navy:#071a2b;--orange:#ff510b;--ink:#10233f;--muted:#7d8eaa;--line:#d8e1ed;--blue:#174ee8}
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;color:var(--ink);background:#fff}
.page{min-height:100vh;display:grid;grid-template-columns:1fr 1fr}
.visual{position:relative;min-height:100vh;overflow:hidden;background:linear-gradient(90deg,rgba(2,16,27,.68),rgba(2,16,27,.22)),url('https://images.unsplash.com/photo-1626224583764-f87db24ac4ea?auto=format&fit=crop&w=1200&q=80') center/cover no-repeat,#071a2b;color:#fff;padding:58px clamp(32px,5.5vw,92px);display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:13px;font-weight:800;font-size:27px;letter-spacing:.2px;position:relative;z-index:1}
.brand-mark{font-size:48px;line-height:1;color:var(--orange);font-weight:900;font-style:italic;transform:skew(-12deg)}
.brand small{display:block;font-size:13px;letter-spacing:1.4px;font-weight:600;margin-top:2px}
.visual-copy{margin-top:clamp(90px,13vh,160px);max-width:600px;position:relative;z-index:1}
.visual-copy h1{font-size:clamp(38px,4vw,64px);line-height:1.08;letter-spacing:-1.8px;margin:0 0 22px;font-weight:800}
.visual-copy h1 span{display:block;color:var(--orange)}
.visual-copy p{font-size:19px;line-height:1.65;color:#e1e9f3;max-width:480px;margin:0}
.form-side{min-height:100vh;position:relative;display:flex;align-items:center;justify-content:center;padding:48px clamp(28px,6vw,96px);overflow:hidden;background:#fff}
.form-side:before,.form-side:after{content:"";position:absolute;width:170px;height:14px;background:#eef2f8;transform:rotate(-48deg);right:-34px;top:40px}
.form-side:after{width:115px;background:var(--orange);right:-28px;top:58px}
.corner-bottom{position:absolute;right:-50px;bottom:38px;width:180px;height:13px;background:#eef2f8;transform:rotate(-48deg)}
.corner-bottom:after{content:"";position:absolute;right:-18px;top:28px;width:100px;height:8px;background:var(--orange)}
.form-wrap{width:100%;max-width:590px;position:relative;z-index:1}
.home{display:inline-flex;gap:10px;align-items:center;text-decoration:none;color:#657996;font-size:16px;font-weight:600;margin-bottom:48px}
.home:hover{color:var(--orange)}
h2{font-size:clamp(36px,3.2vw,50px);letter-spacing:-1.3px;margin:0 0 8px;color:#0c1d38}
.subtitle{font-size:19px;line-height:1.5;color:var(--muted);margin:0 0 34px}
.google{width:100%;height:64px;border:1.5px solid var(--line);border-radius:14px;background:#fff;color:#172743;font-size:17px;font-weight:650;display:flex;align-items:center;justify-content:center;gap:15px;cursor:pointer}
.google:hover{border-color:#aebed4;background:#fbfcfe}
.google-g{font-size:29px;font-weight:800;font-family:Arial,sans-serif;background:conic-gradient(from -45deg,#4285f4 0 25%,#34a853 0 50%,#fbbc05 0 72%,#ea4335 0);color:transparent;background-clip:text;-webkit-background-clip:text}
.divider{display:flex;align-items:center;gap:20px;color:#8291a9;font-size:15px;margin:28px 0}
.divider:before,.divider:after{content:"";height:1px;background:#dce3ed;flex:1}
.field{position:relative;margin-bottom:18px}
.field input{width:100%;height:64px;border:1.5px solid #d8e1ed;border-radius:13px;padding:0 52px 0 62px;font-size:16px;color:#172743;outline:none;background:#fff}
.field input::placeholder{color:#9aa9bf}
.field input:focus{border-color:var(--orange);box-shadow:0 0 0 3px rgba(255,81,11,.09)}
.field-icon{position:absolute;left:22px;top:50%;transform:translateY(-50%);font-size:21px;color:#253d5d;pointer-events:none}
.toggle{position:absolute;right:18px;top:50%;transform:translateY(-50%);border:0;background:none;color:#8b9ab0;font-size:20px;cursor:pointer}
.options{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:22px 0 34px;color:#667994;font-size:16px}
.remember{display:flex;align-items:center;gap:10px}
input[type=checkbox]{width:20px;height:20px;accent-color:var(--orange);margin:0}
a{color:var(--orange);text-decoration:none;font-weight:650}.primary{width:100%;height:64px;border:0;border-radius:13px;background:var(--orange);color:#fff;font-size:17px;font-weight:750;cursor:pointer;box-shadow:0 8px 18px rgba(255,81,11,.13);transition:transform .15s,background .15s}
.primary:hover{background:#ed4706;transform:translateY(-1px)}
.primary span{font-size:23px;margin-left:12px;vertical-align:-1px}
.bottom{margin:35px 0 0;text-align:center;color:#667994;font-size:16px}
.notice{display:none;margin:12px 0;color:#b63818;font-size:14px}
@media(max-width:900px){.page{grid-template-columns:1fr}.visual{min-height:360px;padding:32px 28px}.visual-copy{margin-top:55px}.visual-copy h1{font-size:42px}.visual-copy p{font-size:16px}.form-side{min-height:auto;padding:42px 24px 56px}.home{margin-bottom:28px}.form-wrap{max-width:590px}}
@media(max-width:480px){.visual{min-height:320px}.brand{font-size:22px}.brand-mark{font-size:40px}.visual-copy{margin-top:45px}.visual-copy h1{font-size:36px}.form-side{padding:32px 20px 42px}.subtitle{font-size:16px}.google,.field input,.primary{height:58px}.options{font-size:14px}}

a.google,a.google:hover{text-decoration:none}
.alert{margin:12px 0;padding:10px 14px;border-radius:10px;font-size:14px;font-weight:600}
.alert.err{background:#fef2f2;color:#dc2626;border:1px solid #fecaca}
.alert.ok{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0}
.notice{margin:8px 0 12px}.notice:empty{display:none}
.turnstile{display:flex;justify-content:center;margin:6px 0 24px}
</style>
</head>
<body>
<main class="page">
  <section class="visual" aria-label="Hi-Power badminton court">
    <div class="brand"><span class="brand-mark">H</span><span>HI-POWER<small>BADMINTON COURT</small></span></div>
    <div class="visual-copy">
      <h1>Almost <span>there.</span></h1>
      <p>Choose a strong password to secure your account. Make it something memorable but hard to guess.</p>
    </div>
  </section>
  <section class="form-side">
    <div class="corner-bottom"></div>
    <div class="form-wrap">
      <a class="home" href="/authentication/login">← <span>Back to login</span></a>
      <h2>Create new password</h2>
      <p class="subtitle">Your identity is verified. Set a new password for your account.</p>
      <?php if (!empty($error_message)): ?>
        <div class="alert err" role="alert"><?php echo htmlspecialchars($error_message); ?></div>
      <?php endif; ?>
      <form id="resetForm" method="POST" action="reset_password">
        <?php echo get_csrf_input(); ?>
        <div class="field"><span class="field-icon">♙</span><input id="password" type="password" name="new_password" placeholder="New password" autocomplete="new-password" minlength="6" required aria-label="New password"><button class="toggle" type="button" data-toggle="password" aria-label="Show password">◉</button></div>
        <div class="field"><span class="field-icon">♙</span><input id="confirm-password" type="password" name="confirm_password" placeholder="Confirm password" autocomplete="new-password" required aria-label="Confirm password"><button class="toggle" type="button" data-toggle="confirm-password" aria-label="Show confirm password">◉</button></div>
        <p class="notice" id="notice" role="alert"></p>
        <button class="primary" type="submit">Save New Password <span>→</span></button>
      </form>
      <p class="bottom">After saving, you'll be redirected to <a href="/authentication/login">sign in</a></p>
    </div>
  </section>
</main>
<script>
document.querySelectorAll('[data-toggle]').forEach(btn=>btn.addEventListener('click',()=>{
  const input=document.getElementById(btn.dataset.toggle);
  input.type=input.type==='password'?'text':'password';
  btn.textContent=input.type==='password'?'◉':'◎';
  btn.setAttribute('aria-label',input.type==='password'?'Show password':'Hide password');
}));
document.getElementById('resetForm').addEventListener('submit',e=>{
  const f=e.currentTarget, n=document.getElementById('notice');
  if(f.new_password.value!==f.confirm_password.value){
    e.preventDefault();n.textContent='Passwords do not match. Please check and try again.';n.style.display='block';f.confirm_password.focus();
  }
});
</script>
</body>
</html>