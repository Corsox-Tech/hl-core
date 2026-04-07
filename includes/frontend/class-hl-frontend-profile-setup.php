<?php
if (!defined('ABSPATH')) exit;

/**
 * Renderer for the [hl_profile_setup] shortcode.
 *
 * Multi-step form: Step 1 (Personal Info), Step 2 (Professional), Step 3 (Social + Consent).
 * Single POST submission on final step; partial saves via localStorage on client.
 * POST handling is in HL_Auth_Manager.
 *
 * @package HL_Core
 */
class HL_Frontend_Profile_Setup {

    /**
     * Allowed ethnicity values (spec I13).
     */
    const ETHNICITY_OPTIONS = array(
        'african_american'        => 'African-American',
        'asian'                   => 'Asian',
        'caucasian'               => 'Caucasian',
        'latino_hispanic'         => 'Latino or Hispanic',
        'native_american'         => 'Native American',
        'native_hawaiian_pacific' => 'Native Hawaiian or Pacific Islander',
        'other_unknown'           => 'Other/Unknown',
        'prefer_not_to_say'       => 'Prefer not to say',
    );

    /**
     * Gender options.
     */
    const GENDER_OPTIONS = array(
        'male'               => 'Male',
        'female'             => 'Female',
        'transgender'        => 'Transgender',
        'different_identity' => 'Different gender identity',
        'other'              => 'Other',
    );

    /**
     * Age range options.
     */
    const AGE_RANGE_OPTIONS = array(
        '18-24' => '18-24',
        '25-34' => '25-34',
        '35-44' => '35-44',
        '45-54' => '45-54',
        '55-64' => '55-64',
        '64+'   => '64+',
    );

    /**
     * Language options.
     */
    const LANGUAGE_OPTIONS = array(
        'en' => 'English',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
    );

    /**
     * Years of experience options (used for BOTH industry and position fields).
     */
    const YEARS_EXP_OPTIONS = array(
        'less_than_1'  => 'Less than 1',
        '1-3'          => '1-3',
        '4-6'          => '4-6',
        '7-9'          => '7-9',
        '10-12'        => '10-12',
        'more_than_12' => 'More than 12',
    );

    /**
     * US States + Mexico/Canada/Other for Location dropdown.
     */
    const LOCATION_OPTIONS = array(
        'AL' => 'Alabama',        'AK' => 'Alaska',        'AZ' => 'Arizona',
        'AR' => 'Arkansas',       'CA' => 'California',     'CO' => 'Colorado',
        'CT' => 'Connecticut',    'DE' => 'Delaware',       'FL' => 'Florida',
        'GA' => 'Georgia',        'HI' => 'Hawaii',         'ID' => 'Idaho',
        'IL' => 'Illinois',       'IN' => 'Indiana',        'IA' => 'Iowa',
        'KS' => 'Kansas',         'KY' => 'Kentucky',       'LA' => 'Louisiana',
        'ME' => 'Maine',          'MD' => 'Maryland',       'MA' => 'Massachusetts',
        'MI' => 'Michigan',       'MN' => 'Minnesota',      'MS' => 'Mississippi',
        'MO' => 'Missouri',       'MT' => 'Montana',        'NE' => 'Nebraska',
        'NV' => 'Nevada',         'NH' => 'New Hampshire',  'NJ' => 'New Jersey',
        'NM' => 'New Mexico',     'NY' => 'New York',       'NC' => 'North Carolina',
        'ND' => 'North Dakota',   'OH' => 'Ohio',           'OK' => 'Oklahoma',
        'OR' => 'Oregon',         'PA' => 'Pennsylvania',   'RI' => 'Rhode Island',
        'SC' => 'South Carolina', 'SD' => 'South Dakota',   'TN' => 'Tennessee',
        'TX' => 'Texas',          'UT' => 'Utah',           'VT' => 'Vermont',
        'VA' => 'Virginia',       'WA' => 'Washington',     'WV' => 'West Virginia',
        'WI' => 'Wisconsin',      'WY' => 'Wyoming',
        // --- International ---
        '--MX' => 'Mexico',
        '--CA' => 'Canada',
        '--OT' => 'Other',
    );

    public static function render($atts) {
        // PC2: No redirects here -- headers are already sent by the time
        // shortcode render() runs. The template_redirect handler in
        // HL_Auth_Manager::handle_auth_redirects() already ensures:
        //   - logged-out users are redirected to login
        //   - users with complete profiles are redirected to dashboard
        // If we reach this point, the user is logged in AND incomplete.

        if (!is_user_logged_in()) {
            // Fallback: return login link instead of redirect (headers already sent)
            return '<p>' . sprintf(
                __('Please <a href="%s">sign in</a> to complete your profile.', 'hl-core'),
                esc_url(HL_Auth_Service::get_login_page_url() ?: wp_login_url())
            ) . '</p>';
        }

        $user_id = get_current_user_id();
        $user    = wp_get_current_user();

        // Check for validation error from POST
        $errors = array();
        $session_token = isset($_COOKIE['hl_auth_session']) ? sanitize_text_field($_COOKIE['hl_auth_session']) : '';
        if (isset($_GET['hl_profile_error']) && $session_token) {
            $transient_key = 'hl_profile_err_' . substr(wp_hash($session_token), 0, 20);
            $errors = get_transient($transient_key) ?: array();
            delete_transient($transient_key);
        }

        // Existing profile data (partial save from previous attempt)
        $profile = HL_Auth_Repository::get($user_id);

        $logo_id  = get_theme_mod('custom_logo');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'full') : '';

        // $session_token already read and sanitized above (line 121) — reuse it

        ob_start();
        ?>
        <div class="hl-auth-card hl-auth-card--wide">
            <?php if ($logo_url) : ?>
                <img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>" class="hl-auth-logo">
            <?php endif; ?>

            <h1 class="hl-auth-title"><?php esc_html_e('Complete Your Profile', 'hl-core'); ?></h1>
            <p class="hl-auth-subtitle">
                <?php echo esc_html(sprintf(
                    __('Welcome, %s! Please complete your profile to continue.', 'hl-core'),
                    $user->first_name ?: $user->display_name
                )); ?>
            </p>

            <!-- Step Indicator (spec I7) -->
            <div class="hl-steps" role="tablist">
                <div class="hl-steps__item hl-steps__item--active" data-step="1" role="tab" aria-selected="true">
                    <span class="hl-steps__number">1</span>
                    <span class="hl-steps__label"><?php esc_html_e('Personal Info', 'hl-core'); ?></span>
                </div>
                <div class="hl-steps__divider"></div>
                <div class="hl-steps__item" data-step="2" role="tab" aria-selected="false">
                    <span class="hl-steps__number">2</span>
                    <span class="hl-steps__label"><?php esc_html_e('Professional', 'hl-core'); ?></span>
                </div>
                <div class="hl-steps__divider"></div>
                <div class="hl-steps__item" data-step="3" role="tab" aria-selected="false">
                    <span class="hl-steps__number">3</span>
                    <span class="hl-steps__label"><?php esc_html_e('Social Media', 'hl-core'); ?></span>
                </div>
            </div>

            <?php if (!empty($errors)) : ?>
                <div class="hl-auth-error" role="alert">
                    <span class="dashicons dashicons-warning"></span>
                    <div>
                        <?php foreach ($errors as $err) : ?>
                            <div><?php echo esc_html($err); ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <form class="hl-auth-form" method="post" action="" id="hl-profile-form"
                  data-user-id="<?php echo esc_attr($user_id); ?>">
                <?php wp_nonce_field('hl_profile_setup_action', 'hl_profile_nonce'); ?>
                <input type="hidden" name="hl_auth_action" value="profile_setup">
                <input type="hidden" name="hl_session_token" value="<?php echo esc_attr($session_token); ?>">

                <!-- ============================================ -->
                <!-- Step 1: Personal Information                  -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="1" role="tabpanel">

                    <!-- First Name + Last Name: 2-column grid -->
                    <div class="hl-field-row hl-field-row--2col">
                        <div class="hl-auth-field">
                            <label for="hl-first-name"><?php esc_html_e('First Name', 'hl-core'); ?> <span class="hl-required">*</span></label>
                            <input type="text" id="hl-first-name" name="hl_first_name"
                                   autocomplete="given-name" required
                                   value="<?php echo esc_attr($user->first_name); ?>">
                        </div>
                        <div class="hl-auth-field">
                            <label for="hl-last-name"><?php esc_html_e('Last Name', 'hl-core'); ?> <span class="hl-required">*</span></label>
                            <input type="text" id="hl-last-name" name="hl_last_name"
                                   autocomplete="family-name" required
                                   value="<?php echo esc_attr($user->last_name); ?>">
                        </div>
                    </div>

                    <!-- Email: read-only -->
                    <div class="hl-auth-field">
                        <label for="hl-email"><?php esc_html_e('Email', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <input type="email" id="hl-email" name="hl_email" readonly
                               class="hl-input--readonly"
                               value="<?php echo esc_attr($user->user_email); ?>">
                    </div>

                    <!-- Nickname -->
                    <div class="hl-auth-field">
                        <label for="hl-nickname"><?php esc_html_e('Nickname', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <input type="text" id="hl-nickname" name="hl_nickname" required
                               placeholder="<?php esc_attr_e('What should we call you?', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->nickname ?? ''); ?>">
                    </div>

                    <!-- Phone Number (OPTIONAL) -->
                    <div class="hl-auth-field">
                        <label for="hl-phone-number"><?php esc_html_e('Phone Number', 'hl-core'); ?> <span class="hl-optional">(<?php esc_html_e('optional', 'hl-core'); ?>)</span></label>
                        <div class="hl-phone-group" role="group" aria-label="<?php esc_attr_e('Phone number', 'hl-core'); ?>">
                            <select name="hl_phone_country_code" id="hl-phone-cc"
                                    autocomplete="tel-country-code" class="hl-phone-cc">
                                <option value="+1" <?php selected($profile->phone_country_code ?? '+1', '+1'); ?>>+1 (US/CA)</option>
                                <option value="+52" <?php selected($profile->phone_country_code ?? '', '+52'); ?>>+52 (MX)</option>
                                <option value="+55" <?php selected($profile->phone_country_code ?? '', '+55'); ?>>+55 (BR)</option>
                            </select>
                            <input type="tel" id="hl-phone-number" name="hl_phone_number"
                                   autocomplete="tel-national"
                                   placeholder="<?php esc_attr_e('(555) 123-4567', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->phone_number ?? ''); ?>">
                        </div>
                    </div>

                    <!-- Gender (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Gender', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::GENDER_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_gender" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->gender ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Ethnicity (pill checkboxes, multi-select) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Ethnicity (select all that apply)', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-pill-check-group">
                            <?php
                            $selected_eth = !empty($profile->ethnicity) ? json_decode($profile->ethnicity, true) : array();
                            foreach (self::ETHNICITY_OPTIONS as $value => $label) :
                            ?>
                                <label class="hl-pill-check">
                                    <input type="checkbox" name="hl_ethnicity[]" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(in_array($value, $selected_eth, true)); ?>>
                                    <span class="hl-pill-check__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Location (dropdown: 50 states + international) -->
                    <div class="hl-auth-field">
                        <label for="hl-location"><?php esc_html_e('Location', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <select name="hl_location_state" id="hl-location" required>
                            <option value=""><?php esc_html_e('-- Select your location --', 'hl-core'); ?></option>
                            <?php
                            $separator_printed = false;
                            foreach (self::LOCATION_OPTIONS as $code => $name) :
                                // Print separator before international options
                                if (!$separator_printed && substr($code, 0, 2) === '--') :
                                    $separator_printed = true;
                                    ?>
                                    <option disabled value="">&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;&#9472;</option>
                                <?php endif; ?>
                                <option value="<?php echo esc_attr($code); ?>" <?php selected($profile->location_state ?? '', $code); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Age Range (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Age', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::AGE_RANGE_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_age_range" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->age_range ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Preferred Course Language (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Preferred Course Language', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::LANGUAGE_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_preferred_language" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->preferred_language ?? 'en'), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <button type="button" class="hl-auth-btn hl-step-next" data-next="2">
                        <span class="hl-auth-btn-text"><?php esc_html_e('Continue', 'hl-core'); ?></span>
                    </button>
                </div>

                <!-- ============================================ -->
                <!-- Step 2: Professional Information              -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="2" style="display:none;" role="tabpanel">

                    <!-- Years of Experience in Industry (radio buttons) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Years of Experience in Industry', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::YEARS_EXP_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_years_exp_industry" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->years_exp_industry ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Years of Experience in Current Position (radio buttons, SEPARATE field) -->
                    <div class="hl-auth-field">
                        <label><?php esc_html_e('Years of Experience in Current Position', 'hl-core'); ?> <span class="hl-required">*</span></label>
                        <div class="hl-radio-group">
                            <?php foreach (self::YEARS_EXP_OPTIONS as $value => $label) : ?>
                                <label class="hl-radio">
                                    <input type="radio" name="hl_years_exp_position" value="<?php echo esc_attr($value); ?>"
                                           <?php checked(($profile->years_exp_position ?? ''), $value); ?>>
                                    <span class="hl-radio__label"><?php echo esc_html($label); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Job Title (OPTIONAL) -->
                    <div class="hl-auth-field">
                        <label for="hl-job-title"><?php esc_html_e('Job Title', 'hl-core'); ?> <span class="hl-optional">(<?php esc_html_e('optional', 'hl-core'); ?>)</span></label>
                        <input type="text" id="hl-job-title" name="hl_job_title"
                               autocomplete="organization-title"
                               placeholder="<?php esc_attr_e('e.g., Lead Pre-K Teacher', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->job_title ?? ''); ?>">
                    </div>

                    <div class="hl-step-nav">
                        <button type="button" class="hl-auth-btn hl-auth-btn--secondary hl-step-prev" data-prev="1">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Back', 'hl-core'); ?></span>
                        </button>
                        <button type="button" class="hl-auth-btn hl-step-next" data-next="3">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Continue', 'hl-core'); ?></span>
                        </button>
                    </div>
                </div>

                <!-- ============================================ -->
                <!-- Step 3: Social Media + Consent                -->
                <!-- ============================================ -->
                <div class="hl-step-panel" data-step="3" style="display:none;" role="tabpanel">

                    <p class="hl-step-intro"><?php esc_html_e('All social media fields are optional.', 'hl-core'); ?></p>

                    <!-- Instagram (@ prefix) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-instagram"><?php esc_html_e('Instagram', 'hl-core'); ?></label>
                        <div class="hl-input-prefix-group">
                            <span class="hl-input-prefix">@</span>
                            <input type="text" id="hl-social-instagram" name="hl_social_instagram"
                                   placeholder="<?php esc_attr_e('username', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->social_instagram ?? ''); ?>">
                        </div>
                    </div>

                    <!-- X / Twitter (@ prefix) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-twitter"><?php esc_html_e('X (Twitter)', 'hl-core'); ?></label>
                        <div class="hl-input-prefix-group">
                            <span class="hl-input-prefix">@</span>
                            <input type="text" id="hl-social-twitter" name="hl_social_twitter"
                                   placeholder="<?php esc_attr_e('username', 'hl-core'); ?>"
                                   value="<?php echo esc_attr($profile->social_twitter ?? ''); ?>">
                        </div>
                    </div>

                    <!-- LinkedIn (URL) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-linkedin"><?php esc_html_e('LinkedIn', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-linkedin" name="hl_social_linkedin"
                               placeholder="<?php esc_attr_e('https://linkedin.com/in/yourprofile', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_linkedin ?? ''); ?>">
                    </div>

                    <!-- Facebook (URL) -->
                    <div class="hl-auth-field">
                        <label for="hl-social-facebook"><?php esc_html_e('Facebook', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-facebook" name="hl_social_facebook"
                               placeholder="<?php esc_attr_e('https://facebook.com/yourprofile', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_facebook ?? ''); ?>">
                    </div>

                    <!-- Website / URL -->
                    <div class="hl-auth-field">
                        <label for="hl-social-website"><?php esc_html_e('Website / URL', 'hl-core'); ?></label>
                        <input type="url" id="hl-social-website" name="hl_social_website"
                               placeholder="<?php esc_attr_e('https://yourwebsite.com', 'hl-core'); ?>"
                               value="<?php echo esc_attr($profile->social_website ?? ''); ?>">
                    </div>

                    <!-- Consent (required) -->
                    <div class="hl-consent-box">
                        <h3><?php esc_html_e('Research Participation Consent', 'hl-core'); ?></h3>
                        <div class="hl-consent-text">
                            <p><?php esc_html_e('By checking this box, you acknowledge that you have read and agree to participate in the Housman Learning research study. Your data will be used for research purposes and handled in accordance with our privacy policy.', 'hl-core'); ?></p>
                        </div>
                        <label class="hl-consent-check">
                            <input type="checkbox" name="hl_consent" value="1" required>
                            <span><?php esc_html_e('I agree to the research participation terms', 'hl-core'); ?></span>
                        </label>
                    </div>

                    <div class="hl-step-nav">
                        <button type="button" class="hl-auth-btn hl-auth-btn--secondary hl-step-prev" data-prev="2">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Back', 'hl-core'); ?></span>
                        </button>
                        <button type="submit" class="hl-auth-btn" id="hl-profile-submit">
                            <span class="hl-auth-btn-text"><?php esc_html_e('Complete Profile', 'hl-core'); ?></span>
                        </button>
                    </div>
                </div>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }
}
