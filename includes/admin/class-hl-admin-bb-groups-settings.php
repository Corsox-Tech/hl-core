<?php
/**
 * Admin Settings — BuddyBoss Groups tab.
 *
 * @package HL_Core
 */
class HL_Admin_BB_Groups_Settings {

    private static $instance = null;

    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function handle_save() {
        if ( ! current_user_can( 'manage_hl_core' ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['hl_bb_groups_nonce'], 'hl_save_bb_groups' ) ) {
            return;
        }

        $community_id = ! empty( $_POST['hl_bb_global_community_group_id'] )
            ? absint( $_POST['hl_bb_global_community_group_id'] ) : 0;
        $mentor_id = ! empty( $_POST['hl_bb_global_mentor_group_id'] )
            ? absint( $_POST['hl_bb_global_mentor_group_id'] ) : 0;

        update_option( 'hl_bb_global_community_group_id', $community_id );
        update_option( 'hl_bb_global_mentor_group_id', $mentor_id );

        HL_BB_Group_Sync_Service::invalidate_cache();

        // Warn if same group selected for both
        if ( $community_id > 0 && $community_id === $mentor_id ) {
            add_settings_error( 'hl_bb_groups', 'duplicate_group',
                __( 'Warning: Global Community and Global Mentor are mapped to the same group.', 'hl-core' ),
                'warning'
            );
        }

        add_settings_error( 'hl_bb_groups', 'saved',
            __( 'BuddyBoss group settings saved.', 'hl-core' ), 'success' );
    }

    public function render_page_content() {
        settings_errors( 'hl_bb_groups' );

        if ( ! HL_BB_Group_Sync_Service::is_bb_groups_available() ) {
            echo '<div class="notice notice-warning"><p>';
            esc_html_e( 'BuddyBoss Platform must be active to configure group sync.', 'hl-core' );
            echo '</p></div>';
            return;
        }

        $groups = HL_BB_Group_Sync_Service::get_bb_groups_dropdown();
        $community_id = (int) get_option( 'hl_bb_global_community_group_id', 0 );
        $mentor_id    = (int) get_option( 'hl_bb_global_mentor_group_id', 0 );

        ?>
        <form method="post">
            <?php wp_nonce_field( 'hl_save_bb_groups', 'hl_bb_groups_nonce' ); ?>

            <div class="hl-settings-card">
                <h2><?php esc_html_e( 'BuddyBoss Group Mapping', 'hl-core' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'These settings control automatic BuddyBoss group membership. When configured, users are automatically added/removed from groups based on their B2E enrollments. Coaches and Coaching Directors are added as group moderators.', 'hl-core' ); ?>
                </p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="hl_bb_global_community_group_id">
                                <?php esc_html_e( 'Global Community Group', 'hl-core' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="hl_bb_global_community_group_id" id="hl_bb_global_community_group_id">
                                <option value=""><?php esc_html_e( '— None (disabled) —', 'hl-core' ); ?></option>
                                <?php foreach ( $groups as $gid => $label ) : ?>
                                    <option value="<?php echo esc_attr( $gid ); ?>"
                                        <?php selected( $community_id, $gid ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="hl_bb_global_mentor_group_id">
                                <?php esc_html_e( 'Global Mentor Group', 'hl-core' ); ?>
                            </label>
                        </th>
                        <td>
                            <select name="hl_bb_global_mentor_group_id" id="hl_bb_global_mentor_group_id">
                                <option value=""><?php esc_html_e( '— None (disabled) —', 'hl-core' ); ?></option>
                                <?php foreach ( $groups as $gid => $label ) : ?>
                                    <option value="<?php echo esc_attr( $gid ); ?>"
                                        <?php selected( $mentor_id, $gid ); ?>>
                                        <?php echo esc_html( $label ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
            </div>

            <?php submit_button( __( 'Save Settings', 'hl-core' ) ); ?>
        </form>
        <?php
    }
}
