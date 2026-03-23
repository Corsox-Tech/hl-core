<?php
if (!defined('ABSPATH')) exit;

// TODO: Implemented in Session 4
class HL_CLI_Setup_ELCPB_Y2_V2 {
    public static function register() {
        if (!defined('WP_CLI') || !WP_CLI) return;
        WP_CLI::add_command('hl-core setup-elcpb-y2-v2', array(new self(), 'run'));
    }
    public function run($args, $assoc_args) {
        WP_CLI::warning('Not yet implemented. Coming in Session 4.');
    }
}
