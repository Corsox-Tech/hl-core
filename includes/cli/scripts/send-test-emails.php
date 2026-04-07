<?php
/**
 * Send 2 test emails to the yopmail test teachers.
 * Run via: wp eval-file wp-content/plugins/hl-core/includes/cli/send-test-emails.php
 */
if (!defined('ABSPATH')) { echo "Must be run via wp eval-file.\n"; exit(1); }

global $wpdb;

$headers = array(
    'Content-Type: text/html; charset=UTF-8',
    'From: Housman Learning Academy <noreply@academy.housmanlearning.com>',
);

$login_url  = wp_login_url();
$reset_page = wp_lostpassword_url();

// ── Helper: build the "existing teacher" email ───────────────────────
function hl_build_existing_email($user) {
    global $login_url, $reset_page;
    $name = esc_html($user->first_name);
    return '
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
  <tr>
    <td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">
      <img src="https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg" alt="Housman Learning" width="220" style="display:inline-block;" />
    </td>
  </tr>
  <tr>
    <td style="background:#FFFFFF;padding:40px;">
      <p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . $name . ',</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been enrolled in a research study through <strong>Housman Learning Academy</strong> as part of the Lutheran Services Florida cycle.</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Your account is ready and your assessment components are waiting for you. Please log in to get started with your <strong>Teacher Self-Assessment (Pre)</strong>.</p>
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;">
        <tr><td align="center">
          <a href="' . $login_url . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;letter-spacing:0.3px;">Log In to Your Account</a>
        </td></tr>
      </table>
      <div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:24px 0 0;">
        <p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Post assessments will be available later in the program</td></tr>
        </table>
      </div>
      <p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">If you have trouble logging in, please use the <a href="' . $reset_page . '" style="color:#2C7BE5;text-decoration:none;">password reset</a> option or contact your program coordinator.</p>
    </td>
  </tr>
  <tr>
    <td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">
      <p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>
      <p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you are enrolled in a research cycle.<br>Please do not reply to this email.</p>
    </td>
  </tr>
</table>';
}

// ── Helper: build the "new teacher" invitation email ─────────────────
function hl_build_new_email($user, $reset_url, $school_name) {
    $name  = esc_html($user->first_name);
    $email = esc_html($user->user_email);
    $school = esc_html($school_name);
    return '
<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;">
  <tr>
    <td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">
      <img src="https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg" alt="Housman Learning" width="220" style="display:inline-block;" />
    </td>
  </tr>
  <tr>
    <td style="background:#FFFFFF;padding:40px;">
      <p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . $name . ',</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been invited to participate in a research study through <strong>Housman Learning Academy</strong> in cycle with <strong>Lutheran Services Florida</strong>.</p>
      <p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">An account has been created for you. To get started, please click the button below to set your password and access your assessments.</p>
      <table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;">
        <tr><td align="center">
          <a href="' . esc_url($reset_url) . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;letter-spacing:0.3px;">Accept Invitation &amp; Set Password</a>
        </td></tr>
      </table>
      <div style="background:#DBEAFE;border-radius:8px;padding:20px 24px;margin:0 0 24px;border-left:4px solid #2C7BE5;">
        <p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1A2B47;">Your account details:</p>
        <table role="presentation" cellpadding="0" cellspacing="0">
          <tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">Email:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . $email . '</td></tr>
          <tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">School:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . $school . '</td></tr>
        </table>
      </div>
      <div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:0;">
        <p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" width="100%">
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Set your password using the button above</td></tr>
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>
          <tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">4.</span> Post assessments will be available later in the program</td></tr>
        </table>
      </div>
      <p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">This invitation link expires in <strong>48 hours</strong>. If the link has expired, you can request a new one at the <a href="' . esc_url(wp_lostpassword_url()) . '" style="color:#2C7BE5;text-decoration:none;">password reset page</a>.</p>
    </td>
  </tr>
  <tr>
    <td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">
      <p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>
      <p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you were invited to participate in a research cycle.<br>Please do not reply to this email.</p>
    </td>
  </tr>
</table>';
}

// ── Send Email A: Jane (OLD teacher) ─────────────────────────────────
$jane = get_user_by('email', 'jane.test.housman@yopmail.com');
if (!$jane) { echo "ERROR: Jane not found\n"; exit(1); }

$jane_html = hl_build_existing_email($jane);
$sent_jane = wp_mail(
    $jane->user_email,
    "You've been enrolled in Housman Learning Academy",
    $jane_html,
    $headers
);
echo "Jane Test (OLD teacher): " . ($sent_jane ? "SENT" : "FAILED") . " → {$jane->user_email}\n";

// ── Send Email B: Maria (NEW teacher) ────────────────────────────────
$maria = get_user_by('email', 'maria.test.housman@yopmail.com');
if (!$maria) { echo "ERROR: Maria not found\n"; exit(1); }

$reset_key = get_password_reset_key($maria);
if (is_wp_error($reset_key)) {
    echo "ERROR generating reset key for Maria: " . $reset_key->get_error_message() . "\n";
    exit(1);
}
$reset_url = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($reset_key) . '&login=' . rawurlencode($maria->user_login), 'login');

$school_name = $wpdb->get_var($wpdb->prepare(
    "SELECT o.name FROM {$wpdb->prefix}hl_orgunit o
     INNER JOIN {$wpdb->prefix}hl_enrollment e ON e.school_id = o.orgunit_id
     WHERE e.user_id = %d AND e.cycle_id = 1 LIMIT 1",
    $maria->ID
));
if (!$school_name) $school_name = 'Housman Test School';

$maria_html = hl_build_new_email($maria, $reset_url, $school_name);
$sent_maria = wp_mail(
    $maria->user_email,
    "You've been invited to Housman Learning Academy",
    $maria_html,
    $headers
);
echo "Maria Test (NEW teacher): " . ($sent_maria ? "SENT" : "FAILED") . " → {$maria->user_email}\n";

echo "\nCheck inboxes:\n";
echo "  Jane:  https://yopmail.com/en/?login=jane.test.housman\n";
echo "  Maria: https://yopmail.com/en/?login=maria.test.housman\n";
