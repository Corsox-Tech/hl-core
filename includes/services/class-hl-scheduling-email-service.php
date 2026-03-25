<?php
if (!defined('ABSPATH')) exit;

/**
 * Scheduling Email Notification Service
 *
 * Branded HTML emails for coaching session booking, rescheduling,
 * cancellation, and API failure fallback notifications.
 *
 * @package HL_Core
 */
class HL_Scheduling_Email_Service {

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================================
    // Public Notification Methods
    // =========================================================================

    /**
     * Send "session booked" emails to mentor and coach.
     *
     * @param array $session_data {
     *     @type string $mentor_name
     *     @type string $mentor_email
     *     @type string $mentor_timezone
     *     @type string $coach_name
     *     @type string $coach_email
     *     @type string $coach_timezone
     *     @type string $session_datetime  WordPress local time (Y-m-d H:i:s).
     *     @type string $meeting_url       Zoom join URL (may be empty).
     * }
     */
    public function send_session_booked($session_data) {
        $mentor_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['mentor_timezone']);
        $coach_time  = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        // Email to mentor.
        $this->send(
            $session_data['mentor_email'],
            __('Your Coaching Session Has Been Scheduled', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['mentor_name'])),
                sprintf(
                    __('Your coaching session with <strong>%s</strong> has been scheduled.', 'hl-core'),
                    esc_html($session_data['coach_name'])
                ),
                $mentor_time,
                $session_data['meeting_url'] ?? ''
            )
        );

        // Email to coach.
        $this->send(
            $session_data['coach_email'],
            __('Coaching Session Scheduled', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                sprintf(
                    __('A coaching session has been scheduled with <strong>%s</strong>.', 'hl-core'),
                    esc_html($session_data['mentor_name'])
                ),
                $coach_time,
                $session_data['meeting_url'] ?? ''
            )
        );
    }

    /**
     * Send "session rescheduled" emails to mentor and coach.
     *
     * @param array $old_session Previous session data.
     * @param array $new_session New session data.
     */
    public function send_session_rescheduled($old_session, $new_session) {
        $old_mentor_time = $this->format_time_in_tz($old_session['session_datetime'], $new_session['mentor_timezone']);
        $new_mentor_time = $this->format_time_in_tz($new_session['session_datetime'], $new_session['mentor_timezone']);
        $old_coach_time  = $this->format_time_in_tz($old_session['session_datetime'], $new_session['coach_timezone']);
        $new_coach_time  = $this->format_time_in_tz($new_session['session_datetime'], $new_session['coach_timezone']);

        $mentor_detail = sprintf(
            __('Your coaching session has been rescheduled from <strong>%s</strong> to <strong>%s</strong>.', 'hl-core'),
            $old_mentor_time, $new_mentor_time
        );
        $coach_detail = sprintf(
            __('A coaching session with <strong>%s</strong> has been rescheduled from <strong>%s</strong> to <strong>%s</strong>.', 'hl-core'),
            esc_html($new_session['mentor_name']), $old_coach_time, $new_coach_time
        );

        // Email to mentor.
        $this->send(
            $new_session['mentor_email'],
            __('Your Coaching Session Has Been Rescheduled', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($new_session['mentor_name'])),
                $mentor_detail,
                $new_mentor_time,
                $new_session['meeting_url'] ?? ''
            )
        );

        // Email to coach.
        $this->send(
            $new_session['coach_email'],
            __('Coaching Session Rescheduled', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($new_session['coach_name'])),
                $coach_detail,
                $new_coach_time,
                $new_session['meeting_url'] ?? ''
            )
        );
    }

    /**
     * Send "session cancelled" emails to mentor and coach.
     *
     * @param array  $session_data    Session data.
     * @param string $cancelled_by_name Name of person who cancelled.
     */
    public function send_session_cancelled($session_data, $cancelled_by_name) {
        $mentor_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['mentor_timezone']);
        $coach_time  = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        $cancel_note = sprintf(
            __('This session was cancelled by %s.', 'hl-core'),
            esc_html($cancelled_by_name)
        );

        // Email to mentor.
        $this->send(
            $session_data['mentor_email'],
            __('Your Coaching Session Has Been Cancelled', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['mentor_name'])),
                sprintf(
                    __('Your coaching session on <strong>%s</strong> has been cancelled.', 'hl-core'),
                    $mentor_time
                ) . '<br>' . $cancel_note,
                '',
                ''
            )
        );

        // Email to coach.
        $this->send(
            $session_data['coach_email'],
            __('Coaching Session Cancelled', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                sprintf(
                    __('The coaching session with <strong>%s</strong> on <strong>%s</strong> has been cancelled.', 'hl-core'),
                    esc_html($session_data['mentor_name']), $coach_time
                ) . '<br>' . $cancel_note,
                '',
                ''
            )
        );
    }

    /**
     * Send fallback email when Outlook calendar event creation fails.
     *
     * @param array  $session_data  Session data.
     * @param string $error_message API error message.
     */
    public function send_outlook_fallback($session_data, $error_message) {
        $coach_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        // To coach.
        $this->send(
            $session_data['coach_email'],
            __('Action Required: Coaching Session Not Added to Calendar', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                sprintf(
                    __('<strong>%s</strong> (%s) scheduled a coaching session with you at <strong>%s</strong>, but it could not be added to your Outlook calendar automatically. Please add it manually.', 'hl-core'),
                    esc_html($session_data['mentor_name']),
                    esc_html($session_data['mentor_email']),
                    $coach_time
                ),
                $coach_time,
                $session_data['meeting_url'] ?? ''
            )
        );

        // To admin.
        $this->send(
            $this->get_admin_email(),
            __('Alert: Outlook Calendar Event Creation Failed', 'hl-core'),
            $this->build_body(
                __('Admin Notice', 'hl-core'),
                sprintf(
                    __('Outlook calendar event creation failed for a coaching session.<br><br><strong>Coach:</strong> %s<br><strong>Mentor:</strong> %s<br><strong>Date/Time:</strong> %s<br><strong>Error:</strong> %s', 'hl-core'),
                    esc_html($session_data['coach_name']),
                    esc_html($session_data['mentor_name']),
                    $coach_time,
                    esc_html($error_message)
                ),
                '',
                ''
            )
        );
    }

    /**
     * Send fallback email when Zoom meeting creation fails.
     *
     * @param array  $session_data  Session data.
     * @param string $error_message API error message.
     */
    public function send_zoom_fallback($session_data, $error_message) {
        $coach_time = $this->format_time_in_tz($session_data['session_datetime'], $session_data['coach_timezone']);

        // To coach.
        $this->send(
            $session_data['coach_email'],
            __('Action Required: Zoom Meeting Not Created', 'hl-core'),
            $this->build_body(
                sprintf(__('Hello %s,', 'hl-core'), esc_html($session_data['coach_name'])),
                sprintf(
                    __('<strong>%s</strong> scheduled a coaching session with you at <strong>%s</strong>, but the Zoom meeting could not be created automatically. Please create one manually and share the link with the mentor.', 'hl-core'),
                    esc_html($session_data['mentor_name']),
                    $coach_time
                ),
                '',
                ''
            )
        );

        // To admin.
        $this->send(
            $this->get_admin_email(),
            __('Alert: Zoom Meeting Creation Failed', 'hl-core'),
            $this->build_body(
                __('Admin Notice', 'hl-core'),
                sprintf(
                    __('Zoom meeting creation failed for a coaching session.<br><br><strong>Coach:</strong> %s<br><strong>Mentor:</strong> %s<br><strong>Date/Time:</strong> %s<br><strong>Error:</strong> %s', 'hl-core'),
                    esc_html($session_data['coach_name']),
                    esc_html($session_data['mentor_name']),
                    $coach_time,
                    esc_html($error_message)
                ),
                '',
                ''
            )
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Get admin email for fallback notifications.
     *
     * @return string
     */
    public function get_admin_email() {
        return get_option('admin_email');
    }

    /**
     * Format a WP local datetime string in a specific timezone for display.
     *
     * @param string $wp_datetime WordPress local time (Y-m-d H:i:s).
     * @param string $timezone    IANA timezone.
     * @return string Formatted display string.
     */
    private function format_time_in_tz($wp_datetime, $timezone) {
        if (empty($wp_datetime)) {
            return '';
        }

        try {
            $wp_tz = new DateTimeZone(wp_timezone_string());
            $dt    = new DateTime($wp_datetime, $wp_tz);

            if (!empty($timezone)) {
                $dt->setTimezone(new DateTimeZone($timezone));
            }

            return $dt->format('l, F j, Y \a\t g:i A T');
        } catch (Exception $e) {
            return $wp_datetime;
        }
    }

    /**
     * Send an HTML email via wp_mail.
     *
     * @param string $to      Recipient email.
     * @param string $subject Email subject.
     * @param string $body    Full HTML body.
     */
    private function send($to, $subject, $body) {
        $headers = array('Content-Type: text/html; charset=UTF-8');
        $result  = wp_mail($to, $subject, $body, $headers);

        if (!$result && class_exists('HL_Audit_Service')) {
            HL_Audit_Service::log('email_send_failed', array(
                'entity_type' => 'scheduling_email',
                'reason'      => 'wp_mail failed for: ' . $to . ' — Subject: ' . $subject,
            ));
        }
    }

    /**
     * Build a branded HTML email body.
     *
     * @param string $greeting    e.g. "Hello Jane,"
     * @param string $message     Main message HTML.
     * @param string $time_display Formatted date/time string (for details block).
     * @param string $meeting_url  Zoom link (optional).
     * @return string Full HTML.
     */
    private function build_body($greeting, $message, $time_display, $meeting_url) {
        $logo_url = 'https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg';

        $html  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="max-width:600px;margin:0 auto;font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;">';

        // Header.
        $html .= '<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">';
        $html .= '<img src="' . esc_url($logo_url) . '" alt="Housman Learning" width="200" style="display:inline-block;max-width:200px;width:200px;height:auto;">';
        $html .= '</td></tr>';

        // Body.
        $html .= '<tr><td style="background:#FFFFFF;padding:40px;">';
        $html .= '<p style="margin:0 0 24px;font-size:18px;font-weight:600;color:#1A2B47;">' . $greeting . '</p>';
        $html .= '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:#374151;">' . $message . '</p>';

        // Session details block.
        if (!empty($time_display)) {
            $html .= '<div style="background:#DBEAFE;border-radius:8px;padding:20px 24px;margin:24px 0;border-left:4px solid #2C7BE5;">';
            $html .= '<p style="margin:0;font-size:14px;font-weight:600;color:#1A2B47;">' . esc_html($time_display) . '</p>';
            $html .= '</div>';
        }

        // Zoom button.
        if (!empty($meeting_url)) {
            $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:24px 0;"><tr><td align="center">';
            $html .= '<a href="' . esc_url($meeting_url) . '" style="display:inline-block;background:#2d8cff;color:#FFFFFF;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;">Join Zoom Meeting</a>';
            $html .= '</td></tr></table>';
        }

        $html .= '</td></tr>';

        // Footer.
        $html .= '<tr><td style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">';
        $html .= '<p style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>';
        $html .= '<p style="margin:0;font-size:12px;color:#9CA3AF;">' . esc_html__('This is an automated notification. Please do not reply to this email.', 'hl-core') . '</p>';
        $html .= '</td></tr></table>';

        return $html;
    }
}
