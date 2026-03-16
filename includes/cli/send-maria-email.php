<?php
if (!defined('ABSPATH')) { echo "Must be run via wp eval-file.\n"; exit(1); }
global $wpdb;

$maria = get_user_by('email', 'maria.test.housman@yopmail.com');
if (!$maria) { echo "ERROR: Maria not found\n"; exit(1); }

$reset_key = get_password_reset_key($maria);
if (is_wp_error($reset_key)) {
    echo "ERROR: " . $reset_key->get_error_message() . "\n";
    exit(1);
}

$reset_url = 'https://academy.housmanlearning.com/wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode($maria->user_login);
echo "Reset URL: {$reset_url}\n";

$school_name = $wpdb->get_var($wpdb->prepare(
    "SELECT o.name FROM {$wpdb->prefix}hl_orgunit o INNER JOIN {$wpdb->prefix}hl_enrollment e ON e.school_id = o.orgunit_id WHERE e.user_id = %d AND e.cycle_id = 1 LIMIT 1",
    $maria->ID
));
if (!$school_name) $school_name = 'Housman Test School';

$name  = esc_html($maria->first_name);
$email = esc_html($maria->user_email);
$school = esc_html($school_name);
$safe_url = esc_url($reset_url);

$body = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
$body .= '<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">';
$body .= '<img src="https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg" alt="Housman Learning" width="220" style="display:inline-block;" /></td></tr>';
$body .= '<tr><td style="background:#FFFFFF;padding:40px;">';
$body .= '<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . $name . ',</p>';
$body .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been invited to participate in a research study through <strong>Housman Learning Academy</strong> in cycle with <strong>Lutheran Services Florida</strong>.</p>';
$body .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">An account has been created for you. To get started, please click the button below to set your password and access your assessments.</p>';
$body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;"><tr><td align="center">';
$body .= '<a href="' . $safe_url . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Accept Invitation &amp; Set Password</a>';
$body .= '</td></tr></table>';
$body .= '<div style="background:#DBEAFE;border-radius:8px;padding:20px 24px;margin:0 0 24px;border-left:4px solid #2C7BE5;">';
$body .= '<p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1A2B47;">Your account details:</p>';
$body .= '<table role="presentation" cellpadding="0" cellspacing="0">';
$body .= '<tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">Email:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . $email . '</td></tr>';
$body .= '<tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">School:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . $school . '</td></tr>';
$body .= '</table></div>';
$body .= '<div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:0;">';
$body .= '<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>';
$body .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">';
$body .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Set your password using the button above</td></tr>';
$body .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>';
$body .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>';
$body .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">4.</span> Post assessments will be available later in the program</td></tr>';
$body .= '</table></div>';
$body .= '<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">This invitation link expires in <strong>48 hours</strong>. If the link has expired, you can request a new one at the <a href="https://academy.housmanlearning.com/wp-login.php?action=lostpassword" style="color:#2C7BE5;text-decoration:none;">password reset page</a>.</p>';
$body .= '</td></tr>';
$body .= '<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">';
$body .= '<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>';
$body .= '<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you were invited to participate in a research cycle.<br>Please do not reply to this email.</p>';
$body .= '</td></tr></table>';

$headers = array('Content-Type: text/html; charset=UTF-8');
$sent = wp_mail($maria->user_email, "You've been invited to Housman Learning Academy", $body, $headers);
echo "Maria (NEW): " . ($sent ? "SENT" : "FAILED") . "\n";

if (!$sent) {
    global $phpmailer;
    if (isset($phpmailer) && $phpmailer->ErrorInfo) {
        echo "PHPMailer error: " . $phpmailer->ErrorInfo . "\n";
    }
}
