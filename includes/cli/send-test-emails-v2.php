<?php
if (!defined('ABSPATH')) { echo "Must be run via wp eval-file.\n"; exit(1); }
global $wpdb;

$headers = array('Content-Type: text/html; charset=UTF-8');
$logo = '<div style="text-align:center;"><img src="https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg" alt="Housman Learning" width="200" style="display:inline-block;max-width:200px;width:200px;height:auto;" /></div>';

$login_url  = 'https://academy.housmanlearning.com/wp-login.php';
$reset_page = 'https://academy.housmanlearning.com/wp-login.php?action=lostpassword';

// ── Email A: Jane (OLD teacher) ──────────────────────────────────────
$jane = get_user_by('email', 'jane.test.housman@yopmail.com');
if (!$jane) { echo "ERROR: Jane not found\n"; exit(1); }

$body_jane  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
$body_jane .= '<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">' . $logo . '</td></tr>';
$body_jane .= '<tr><td style="background:#FFFFFF;padding:40px;">';
$body_jane .= '<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . esc_html($jane->first_name) . ',</p>';
$body_jane .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been enrolled in a research study through <strong>Housman Learning Academy</strong> as part of the Lutheran Services Florida partnership.</p>';
$body_jane .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">Your account is ready and your assessment activities are waiting for you. Please log in to get started with your <strong>Teacher Self-Assessment (Pre)</strong>.</p>';
$body_jane .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;"><tr><td align="center">';
$body_jane .= '<a href="' . $login_url . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Log In to Your Account</a>';
$body_jane .= '</td></tr></table>';
$body_jane .= '<div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:24px 0 0;">';
$body_jane .= '<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>';
$body_jane .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">';
$body_jane .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>';
$body_jane .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>';
$body_jane .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Post assessments will be available later in the program</td></tr>';
$body_jane .= '</table></div>';
$body_jane .= '<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">If you have trouble logging in, please use the <a href="' . $reset_page . '" style="color:#2C7BE5;text-decoration:none;">password reset</a> option or contact your program coordinator.</p>';
$body_jane .= '</td></tr>';
$body_jane .= '<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">';
$body_jane .= '<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>';
$body_jane .= '<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you are enrolled in a research partnership.<br>Please do not reply to this email.</p>';
$body_jane .= '</td></tr></table>';

$sent_jane = wp_mail($jane->user_email, "You've been enrolled in Housman Learning Academy", $body_jane, $headers);
echo "Jane (OLD): " . ($sent_jane ? "SENT" : "FAILED") . "\n";

// ── Email B: Maria (NEW teacher) ─────────────────────────────────────
$maria = get_user_by('email', 'maria.test.housman@yopmail.com');
if (!$maria) { echo "ERROR: Maria not found\n"; exit(1); }

$reset_key = get_password_reset_key($maria);
if (is_wp_error($reset_key)) { echo "ERROR: " . $reset_key->get_error_message() . "\n"; exit(1); }
$reset_url = 'https://academy.housmanlearning.com/wp-login.php?action=rp&key=' . $reset_key . '&login=' . rawurlencode($maria->user_login);

$school_name = $wpdb->get_var($wpdb->prepare(
    "SELECT o.name FROM {$wpdb->prefix}hl_orgunit o INNER JOIN {$wpdb->prefix}hl_enrollment e ON e.school_id = o.orgunit_id WHERE e.user_id = %d AND e.partnership_id = 1 LIMIT 1",
    $maria->ID
));
if (!$school_name) $school_name = 'Housman Test School';

$name  = esc_html($maria->first_name);
$email = esc_html($maria->user_email);
$school = esc_html($school_name);
$safe_url = esc_url($reset_url);

$body_maria  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';
$body_maria .= '<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">' . $logo . '</td></tr>';
$body_maria .= '<tr><td style="background:#FFFFFF;padding:40px;">';
$body_maria .= '<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">Hello ' . $name . ',</p>';
$body_maria .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">You have been invited to participate in a research study through <strong>Housman Learning Academy</strong> in partnership with <strong>Lutheran Services Florida</strong>.</p>';
$body_maria .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">An account has been created for you. To get started, please click the button below to set your password and access your assessments.</p>';
$body_maria .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:32px 0;"><tr><td align="center">';
$body_maria .= '<a href="' . $safe_url . '" style="display:inline-block;background:#2ECC71;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Accept Invitation &amp; Set Password</a>';
$body_maria .= '</td></tr></table>';
$body_maria .= '<div style="background:#DBEAFE;border-radius:8px;padding:20px 24px;margin:0 0 24px;border-left:4px solid #2C7BE5;">';
$body_maria .= '<p style="margin:0 0 8px;font-size:14px;font-weight:600;color:#1A2B47;">Your account details:</p>';
$body_maria .= '<table role="presentation" cellpadding="0" cellspacing="0">';
$body_maria .= '<tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">Email:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . $email . '</td></tr>';
$body_maria .= '<tr><td style="padding:2px 12px 2px 0;font-size:14px;color:#6B7280;">School:</td><td style="padding:2px 0;font-size:14px;font-weight:600;color:#374151;">' . $school . '</td></tr>';
$body_maria .= '</table></div>';
$body_maria .= '<div style="background:#F4F5F7;border-radius:8px;padding:20px 24px;margin:0;">';
$body_maria .= '<p style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1A2B47;">What to expect:</p>';
$body_maria .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">';
$body_maria .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">1.</span> Set your password using the button above</td></tr>';
$body_maria .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">2.</span> Complete the <strong>Teacher Self-Assessment (Pre)</strong></td></tr>';
$body_maria .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">3.</span> Complete the <strong>Child Assessment (Pre)</strong> for your classroom</td></tr>';
$body_maria .= '<tr><td style="padding:4px 0;font-size:14px;line-height:1.5;color:#374151;"><span style="color:#2ECC71;font-weight:bold;margin-right:8px;">4.</span> Post assessments will be available later in the program</td></tr>';
$body_maria .= '</table></div>';
$body_maria .= '<p style="margin:24px 0 0;font-size:13px;line-height:1.5;color:#6B7280;">This invitation link expires in <strong>48 hours</strong>. If the link has expired, you can request a new one at the <a href="' . $reset_page . '" style="color:#2C7BE5;text-decoration:none;">password reset page</a>.</p>';
$body_maria .= '</td></tr>';
$body_maria .= '<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">';
$body_maria .= '<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>';
$body_maria .= '<p style="margin:0;font-size:12px;color:#9CA3AF;">This email was sent because you were invited to participate in a research partnership.<br>Please do not reply to this email.</p>';
$body_maria .= '</td></tr></table>';

$sent_maria = wp_mail($maria->user_email, "You've been invited to Housman Learning Academy", $body_maria, $headers);
echo "Maria (NEW): " . ($sent_maria ? "SENT" : "FAILED") . "\n";

echo "\nCheck inboxes:\n";
echo "  Jane:  https://yopmail.com/en/?login=jane.test.housman\n";
echo "  Maria: https://yopmail.com/en/?login=maria.test.housman\n";
