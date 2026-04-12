<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Email Block Renderer
 *
 * Converts a blocks_json array into a complete table-based HTML email
 * with branded header/footer, dark-mode meta, and MSO conditionals.
 *
 * Block types: text, image, button (VML), divider, spacer, columns.
 *
 * @package HL_Core
 */
class HL_Email_Block_Renderer {

    /** Housman Learning logo URL used in the branded header. */
    const LOGO_URL = 'https://academy.housmanlearning.com/wp-content/uploads/2024/09/Housman-Learning-Logo-Horizontal-Color.svg';

    /** Email body max width. */
    const MAX_WIDTH = 600;

    /** @var self|null */
    private static $instance = null;

    /** @return self */
    public static function instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    // =========================================================================
    // Public API
    // =========================================================================

    /**
     * Render a full HTML email document from a blocks array.
     *
     * @param array  $blocks     Block objects (from blocks_json).
     * @param string $subject    Email subject line (for <title>).
     * @param array  $merge_tags Key => value merge-tag map (already resolved).
     * @return string Complete HTML document.
     */
    public function render( array $blocks, $subject = '', array $merge_tags = array() ) {
        $inner = $this->render_blocks_only( $blocks, $merge_tags );
        return $this->wrap_document( $inner, $subject );
    }

    /**
     * Render only the block rows (no document shell).
     * Used by the preview endpoint which supplies its own wrapper.
     *
     * @param array $blocks     Block objects.
     * @param array $merge_tags Key => value map.
     * @return string Table rows HTML.
     */
    public function render_blocks_only( array $blocks, array $merge_tags = array() ) {
        $html = '';
        foreach ( $blocks as $block ) {
            if ( ! is_array( $block ) || empty( $block['type'] ) ) {
                continue;
            }
            $html .= $this->render_block( $block, $merge_tags );
        }
        return $html;
    }

    /**
     * Convert a legacy plain-HTML body into a single-text-block array.
     * Used by the template migration to wrap old coaching email bodies.
     *
     * @param string $html Raw HTML body content.
     * @return array Blocks array with one text block.
     */
    public function build_legacy_template_blocks( $html ) {
        return array(
            array(
                'type'    => 'text',
                'content' => $html,
            ),
        );
    }

    // =========================================================================
    // Document Shell
    // =========================================================================

    /**
     * Wrap rendered block content in a full HTML document with branded
     * header, footer, dark-mode styles, and MSO conditionals.
     *
     * @param string $inner_html Rendered block rows.
     * @param string $subject    Document title.
     * @return string Complete HTML.
     */
    private function wrap_document( $inner_html, $subject ) {
        $max  = self::MAX_WIDTH;
        $logo = esc_url( self::LOGO_URL );
        $year = gmdate( 'Y' );
        $subj = esc_html( $subject );

        $html  = '<!DOCTYPE html>';
        $html .= '<html lang="en" xmlns="http://www.w3.org/1999/xhtml" xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">';
        $html .= '<head>';
        $html .= '<meta charset="utf-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<meta http-equiv="X-UA-Compatible" content="IE=edge">';
        $html .= '<!--[if mso]><xml><o:OfficeDocumentSettings><o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]-->';
        $html .= '<title>' . $subj . '</title>';
        $html .= '<style>';
        $html .= 'body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%}';
        $html .= 'table,td{mso-table-lspace:0;mso-table-rspace:0}';
        $html .= 'img{-ms-interpolation-mode:bicubic;border:0;height:auto;line-height:100%;outline:none;text-decoration:none}';
        $html .= 'body{margin:0;padding:0;width:100%!important;background-color:#F3F4F6}';
        // Dark mode (Apple Mail, Outlook 2019+, iOS Mail).
        $html .= '@media (prefers-color-scheme:dark){';
        $html .= 'body,.hl-email-body{background-color:#1a1a2e!important}';
        $html .= '.hl-email-card{background-color:#16213e!important}';
        $html .= '.hl-email-text{color:#e0e0e0!important}';
        $html .= '.hl-email-footer{background-color:#0f0f23!important}';
        $html .= '.hl-email-footer-text{color:#9CA3AF!important}';
        $html .= '}';
        // Mobile responsive.
        $html .= '@media only screen and (max-width:620px){';
        $html .= '.hl-email-container{width:100%!important;max-width:100%!important}';
        $html .= '.hl-email-col{display:block!important;width:100%!important;max-width:100%!important}';
        $html .= '}';
        $html .= '</style>';
        $html .= '</head>';
        $html .= '<body class="hl-email-body" style="margin:0;padding:0;background-color:#F3F4F6;">';

        // Outer centering table.
        $html .= '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background-color:#F3F4F6;">';
        $html .= '<tr><td align="center" style="padding:24px 16px;">';

        // Container table.
        $html .= '<table role="presentation" class="hl-email-container" cellpadding="0" cellspacing="0" width="' . $max . '" style="max-width:' . $max . 'px;margin:0 auto;">';

        // ── Branded Header ──
        $html .= '<tr><td style="background:#1A2B47;padding:32px 40px;text-align:center;border-radius:12px 12px 0 0;">';
        $html .= '<img src="' . $logo . '" alt="Housman Learning" width="200" style="display:inline-block;max-width:200px;width:200px;height:auto;">';
        $html .= '</td></tr>';

        // ── Content Card ──
        $html .= '<tr><td class="hl-email-card" style="background:#FFFFFF;padding:40px;">';
        $html .= $inner_html;
        $html .= '</td></tr>';

        // ── Footer ──
        $html .= '<tr><td class="hl-email-footer" style="background:#F4F5F7;padding:24px 40px;text-align:center;border-top:1px solid #E5E7EB;border-radius:0 0 12px 12px;">';
        $html .= '<p class="hl-email-footer-text" style="margin:0 0 8px;font-size:13px;color:#6B7280;">Housman Learning Academy</p>';
        $html .= '<p class="hl-email-footer-text" style="margin:0;font-size:12px;color:#9CA3AF;">' . esc_html__( 'This is an automated notification. Please do not reply to this email.', 'hl-core' ) . '</p>';
        $html .= '<p class="hl-email-footer-text" style="margin:0;font-size:11px;color:#9CA3AF;">&copy; ' . $year . ' Housman Learning</p>';
        $html .= '</td></tr>';

        $html .= '</table>'; // container
        $html .= '</td></tr></table>'; // outer
        $html .= '</body></html>';

        return $html;
    }

    // =========================================================================
    // Block Renderers
    // =========================================================================

    /**
     * Dispatch a single block to its type renderer.
     *
     * @param array $block      Block data.
     * @param array $merge_tags Key => value map.
     * @return string HTML for the block.
     */
    private function render_block( array $block, array $merge_tags ) {
        $type = $block['type'];
        switch ( $type ) {
            case 'text':
                return $this->render_text( $block, $merge_tags );
            case 'image':
                return $this->render_image( $block, $merge_tags );
            case 'button':
                return $this->render_button( $block, $merge_tags );
            case 'divider':
                return $this->render_divider( $block );
            case 'spacer':
                return $this->render_spacer( $block );
            case 'columns':
                return $this->render_columns( $block, $merge_tags );
            default:
                return '';
        }
    }

    /**
     * Text block — HTML content with merge tag substitution.
     *
     * SECURITY NOTE: Block content is trusted admin-authored HTML from the
     * builder's contenteditable editor. Sanitization (wp_kses_post) is
     * enforced at save time in HL_Admin_Email_Builder, not at render time,
     * to preserve intentional formatting (bold, links, etc.).
     */
    private function render_text( array $block, array $merge_tags ) {
        $content = $block['content'] ?? '';
        $content = $this->substitute_tags( $content, $merge_tags );

        // Alignment — allowlist only. Default "left" means emit no alignment (inherit).
        $align_raw = isset( $block['text_align'] ) ? (string) $block['text_align'] : 'left';
        $align     = in_array( $align_raw, array( 'left', 'center', 'right' ), true ) ? $align_raw : 'left';

        // Font size — clamp to 10..48 px. Default 16 means "no explicit size".
        $has_size = isset( $block['font_size'] );
        $size     = $has_size ? max( 10, min( 48, (int) $block['font_size'] ) ) : 16;

        // Build <td> inline style.
        $td_style  = 'font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;';
        $td_style .= 'line-height:1.6;color:#374151;padding:0 0 16px;';
        if ( $align !== 'left' ) {
            $td_style .= 'text-align:' . $align . ';';
        }
        // Emit font-size on <td> for all clients except Outlook Word engine.
        $td_style .= 'font-size:' . $size . 'px;';

        // A.3.1 — Outlook Word engine ignores <td> font-size. Wrap content in a <span>
        // that emits font-size on the inline element. Always emit (cheap, harmless for non-Outlook).
        $open_span  = '<span style="font-size:' . $size . 'px;line-height:1.6;color:#374151;">';
        $close_span = '</span>';

        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%">'
            . '<tr><td class="hl-email-text" style="' . $td_style . '">'
            . $open_span . $content . $close_span
            . '</td></tr></table>';
    }

    /**
     * Image block — responsive, optional link wrapper.
     */
    private function render_image( array $block, array $merge_tags ) {
        $src   = esc_url( $block['src'] ?? '' );
        $alt   = esc_attr( $block['alt'] ?? '' );
        $width = (int) ( $block['width'] ?? self::MAX_WIDTH );
        $link  = $block['link'] ?? '';

        if ( $width > self::MAX_WIDTH ) {
            $width = self::MAX_WIDTH;
        }

        $img = '<img src="' . $src . '" alt="' . $alt . '" width="' . $width . '" style="display:block;max-width:100%;width:' . $width . 'px;height:auto;border:0;">';

        if ( ! empty( $link ) ) {
            $link = $this->substitute_tags( $link, $merge_tags );
            $img  = '<a href="' . esc_url( $link ) . '" target="_blank">' . $img . '</a>';
        }

        return '<div style="margin:0 0 16px;text-align:center;">' . $img . '</div>';
    }

    /**
     * Button block — centered CTA with VML fallback for Outlook.
     */
    private function render_button( array $block, array $merge_tags ) {
        $label    = esc_html( $block['label'] ?? 'Click Here' );
        $url      = $block['url'] ?? '#';
        $url      = $this->substitute_tags( $url, $merge_tags );
        $url      = esc_url( $url );
        $bg_color = $this->sanitize_color( $block['bg_color'] ?? '#2C7BE5', '#2C7BE5' );
        $color    = $this->sanitize_color( $block['text_color'] ?? '#FFFFFF', '#FFFFFF' );

        $html  = '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:24px 0;">';
        $html .= '<tr><td align="center">';

        // VML for Outlook.
        $html .= '<!--[if mso]>';
        $html .= '<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="' . $url . '" style="height:48px;v-text-anchor:middle;width:220px;" arcsize="17%" strokecolor="' . $bg_color . '" fillcolor="' . $bg_color . '">';
        $html .= '<w:anchorlock/>';
        $html .= '<center style="color:' . $color . ';font-family:sans-serif;font-size:16px;font-weight:bold;">' . $label . '</center>';
        $html .= '</v:roundrect>';
        $html .= '<![endif]-->';

        // Non-Outlook.
        $html .= '<!--[if !mso]><!-->';
        $html .= '<a href="' . $url . '" target="_blank" style="display:inline-block;background:' . $bg_color . ';color:' . $color . ';font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;font-size:16px;font-weight:600;text-decoration:none;padding:14px 40px;border-radius:8px;mso-hide:all;">' . $label . '</a>';
        $html .= '<!--<![endif]-->';

        $html .= '</td></tr></table>';

        return $html;
    }

    /**
     * Divider block — horizontal rule.
     */
    private function render_divider( array $block ) {
        $color     = $this->sanitize_color( $block['color'] ?? '#E5E7EB', '#E5E7EB' );
        $thickness = max( 1, min( 4, (int) ( $block['thickness'] ?? 1 ) ) );

        return '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="margin:16px 0;">'
            . '<tr><td style="border-top:' . $thickness . 'px solid ' . $color . ';font-size:1px;line-height:1px;">&nbsp;</td></tr>'
            . '</table>';
    }

    /**
     * Spacer block — vertical whitespace.
     */
    private function render_spacer( array $block ) {
        $height = max( 8, min( 80, (int) ( $block['height'] ?? 24 ) ) );

        return '<div style="height:' . $height . 'px;line-height:' . $height . 'px;font-size:1px;">&nbsp;</div>';
    }

    /**
     * Columns block — two-column layout that stacks on mobile.
     * Supports 5 splits: 50/50, 60/40, 40/60, 33/67, 67/33.
     */
    private function render_columns( array $block, array $merge_tags ) {
        $split = $block['split'] ?? '50/50';
        list( $left_width, $right_width ) = $this->get_column_widths( $split );

        $left_blocks  = $block['left']  ?? array();
        $right_blocks = $block['right'] ?? array();

        $left_html  = '';
        $right_html = '';
        foreach ( $left_blocks as $sub ) {
            if ( is_array( $sub ) && ! empty( $sub['type'] ) ) {
                $left_html .= $this->render_block( $sub, $merge_tags );
            }
        }
        foreach ( $right_blocks as $sub ) {
            if ( is_array( $sub ) && ! empty( $sub['type'] ) ) {
                $right_html .= $this->render_block( $sub, $merge_tags );
            }
        }

        $max      = self::MAX_WIDTH - 80; // Account for container padding.
        $left_px  = (int) round( $max * $left_width / 100 );
        $right_px = $max - $left_px;

        $html  = '<!--[if mso]><table role="presentation" cellpadding="0" cellspacing="0" width="100%"><tr><td width="' . $left_px . '" valign="top"><![endif]-->';
        $html .= '<div class="hl-email-col" style="display:inline-block;vertical-align:top;width:' . $left_width . '%;max-width:' . $left_px . 'px;">';
        $html .= $left_html;
        $html .= '</div>';
        $html .= '<!--[if mso]></td><td width="' . $right_px . '" valign="top"><![endif]-->';
        $html .= '<div class="hl-email-col" style="display:inline-block;vertical-align:top;width:' . $right_width . '%;max-width:' . $right_px . 'px;">';
        $html .= $right_html;
        $html .= '</div>';
        $html .= '<!--[if mso]></td></tr></table><![endif]-->';

        return '<div style="margin:0 0 16px;">' . $html . '</div>';
    }

    /**
     * Resolve a split label to integer column width percentages.
     * Falls back to [50, 50] for any unknown split.
     *
     * @param string $split Split label (e.g. "60/40").
     * @return int[] [left, right]
     */
    private function get_column_widths( $split ) {
        switch ( $split ) {
            case '60/40': return array( 60, 40 );
            case '40/60': return array( 40, 60 );
            case '33/67': return array( 33, 67 );
            case '67/33': return array( 67, 33 );
            case '50/50':
            default:      return array( 50, 50 );
        }
    }

    // =========================================================================
    // Merge Tag Substitution
    // =========================================================================

    /**
     * Substitute merge tags in content, then strip any unresolved tags.
     *
     * @param string $content    Content with {{tag}} placeholders.
     * @param array  $merge_tags Key => value (already esc_html'd by registry).
     * @return string Substituted content.
     */
    private function substitute_tags( $content, array $merge_tags ) {
        foreach ( $merge_tags as $key => $value ) {
            $content = str_replace( '{{' . $key . '}}', $value, $content );
        }

        // Strip unresolved tags (except deferred ones like password_reset_url).
        $deferred = array( 'password_reset_url' );
        $content = preg_replace_callback( '/\{\{([a-zA-Z0-9_]+)\}\}/', function ( $matches ) use ( $deferred ) {
            if ( in_array( $matches[1], $deferred, true ) ) {
                return $matches[0]; // Keep deferred tags.
            }
            // Log unresolved tag.
            if ( class_exists( 'HL_Audit_Service' ) ) {
                HL_Audit_Service::log( 'email_unresolved_tag', array(
                    'entity_type' => 'email_template',
                    'tag'         => $matches[1],
                ) );
            }
            return '';
        }, $content );

        return $content;
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Sanitize a CSS color value (3- or 6-digit hex only).
     *
     * @param string $color    Color value.
     * @param string $fallback Fallback if invalid.
     * @return string Sanitized hex color.
     */
    private function sanitize_color( $color, $fallback = '#333333' ) {
        if ( preg_match( '/^#([0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $color ) ) {
            return $color;
        }
        return $fallback;
    }
}
