<?php
/*
Plugin Name: WP Verified Checkmark
Plugin URI: https://github.com/chrstph-gg/wordpress-verified-user
Description: Adds a verified checkmark next to users' names based on their role with a "Verified" tooltip on hover.
Version: 2.0
Requires at least: 4.8
Tested up to: 6.6.1
Requires PHP: 5.6
Author: chrstph
Author URI: https://chrstph.gg
License: MIT

Copyright (c) 2026 chrstph. All rights reserved.
*/

if (!defined('ABSPATH')) {
    exit;
}

class WP_Verified_Checkmark {
    const OPT_ROLE   = 'verified_role_option';
    const OPT_OUTPUT = 'verified_checkmark_output'; // unicode|fa|svg

    public function __construct() {
        // Author name filters (common core functions)
        add_filter('the_author', [$this, 'filter_author_name'], 10, 1);
        add_filter('get_the_author_display_name', [$this, 'filter_author_name'], 10, 1);

        // Author links (many themes output links instead of plain names)
        add_filter('the_author_posts_link', [$this, 'filter_author_posts_link'], 10, 1);

        // Comments (use the comment ID to reliably map to a WP user)
        add_filter('get_comment_author', [$this, 'filter_comment_author'], 10, 2);
        add_filter('get_comment_author_link', [$this, 'filter_comment_author_link'], 10, 3);

        // Assets
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);

        // Settings
        add_action('admin_menu', [$this, 'create_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function enqueue_assets() {
        // Minimal CSS so this works even if Font Awesome isn't available / conflicts with themes.
        $css = '
.wpvc-verified{display:inline-block;vertical-align:baseline;line-height:1;margin-left:.25em}
.wpvc-verified[title]{cursor:help}
.wpvc-verified svg{width:1em;height:1em;display:block}
';
        wp_register_style('wp-verified-checkmark', false, [], '2.1');
        wp_enqueue_style('wp-verified-checkmark');
        wp_add_inline_style('wp-verified-checkmark', $css);

        // Only load Font Awesome if explicitly selected (avoids theme conflicts & extra requests).
        if ($this->get_output_mode() === 'fa') {
            wp_enqueue_style(
                'wpvc-font-awesome',
                'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
                [],
                '6.0.0-beta3'
            );
        }
    }

    private function get_output_mode() {
        $mode = get_option(self::OPT_OUTPUT, 'unicode');
        if (!in_array($mode, ['unicode', 'fa', 'svg'], true)) {
            $mode = 'unicode';
        }
        return $mode;
    }

    private function get_verified_role() {
        return get_option(self::OPT_ROLE, '');
    }

    private function current_user_is_verified_by_id($user_id) {
        if (!$user_id) {
            return false;
        }
        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return false;
        }
        $role = $this->get_verified_role();
        return $role && in_array($role, (array) $user->roles, true);
    }

    private function build_checkmark_markup() {
        $title = esc_attr__('Verified', 'wp-verified-checkmark');
        $mode  = $this->get_output_mode();

        // Maximum compatibility mode: survives esc_html() because it contains no HTML.
        if ($mode === 'unicode') {
            return ' ✓'; // keep leading space
        }

        if ($mode === 'fa') {
            return ' <i class="fa-solid fa-circle-check wpvc-verified" title="' . $title . '" aria-label="' . $title . '"></i>';
        }

        // Inline SVG (no external icon library required)
        $svg = '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M12 2C6.49 2 2 6.49 2 12s4.49 10 10 10 10-4.49 10-10S17.51 2 12 2zm-1.2 14.2l-3.6-3.6 1.4-1.4 2.2 2.2 4.6-4.6 1.4 1.4-6 6z"/></svg>';
        return ' <span class="wpvc-verified" title="' . $title . '" aria-label="' . $title . '">' . $svg . '</span>';
    }

    /**
     * Best-effort author user-id resolution without relying on display-name parsing.
     */
    private function resolve_author_user_id($author_name) {
        // Preferred: global author data set during The Loop.
        global $authordata;
        if (isset($authordata) && is_object($authordata) && !empty($authordata->ID)) {
            return (int) $authordata->ID;
        }

        // Fallback: try to match current post author.
        $post_id = get_the_ID();
        if ($post_id) {
            $post = get_post($post_id);
            if ($post && !empty($post->post_author)) {
                return (int) $post->post_author;
            }
        }

        // Last resort: try to look up by login or nicename; some themes pass login/nicename.
        $user = get_user_by('login', $author_name);
        if (!$user) {
            $user = get_user_by('slug', sanitize_title($author_name));
        }
        if (!$user) {
            $user = get_user_by('email', $author_name);
        }
        return $user ? (int) $user->ID : 0;
    }

    // === Filters ===

    public function filter_author_name($author_name) {
        if (is_admin()) {
            return $author_name;
        }

        $user_id = $this->resolve_author_user_id($author_name);
        if ($this->current_user_is_verified_by_id($user_id)) {
            $author_name .= $this->build_checkmark_markup();
        }
        return $author_name;
    }

    public function filter_author_posts_link($link_html) {
        if (is_admin()) {
            return $link_html;
        }

        // Resolve from current context (Loop)
        $user_id = $this->resolve_author_user_id('');
        if (!$this->current_user_is_verified_by_id($user_id)) {
            return $link_html;
        }

        // Append inside the link so themes that style the link keep it consistent.
        $mark = $this->build_checkmark_markup();

        // If unicode mode, just append before closing </a> (safe even if link text is escaped already).
        if ($this->get_output_mode() === 'unicode') {
            return preg_replace('/<\/a>\s*$/', $mark . '</a>', $link_html, 1);
        }

        // HTML modes: insert right before closing </a>
        return preg_replace('/<\/a>\s*$/', $mark . '</a>', $link_html, 1);
    }

    public function filter_comment_author($author_name, $comment_id) {
        if (is_admin()) {
            return $author_name;
        }

        $comment = get_comment($comment_id);
        $user_id = ($comment && !empty($comment->user_id)) ? (int) $comment->user_id : 0;

        if ($this->current_user_is_verified_by_id($user_id)) {
            $author_name .= $this->build_checkmark_markup();
        }

        return $author_name;
    }

    public function filter_comment_author_link($return, $author, $comment_id) {
        if (is_admin()) {
            return $return;
        }

        $comment = get_comment($comment_id);
        $user_id = ($comment && !empty($comment->user_id)) ? (int) $comment->user_id : 0;

        if (!$this->current_user_is_verified_by_id($user_id)) {
            return $return;
        }

        $mark = $this->build_checkmark_markup();
        return preg_replace('/<\/a>\s*$/', $mark . '</a>', $return, 1);
    }

    // === Admin ===

    public function create_admin_menu() {
        add_options_page(
            'Verified Checkmark Settings',
            'Verified Checkmark',
            'manage_options',
            'wp-verified-checkmark',
            [$this, 'settings_page']
        );
    }

    public function register_settings() {
        register_setting('wp_verified_checkmark_settings', self::OPT_ROLE);
        register_setting('wp_verified_checkmark_settings', self::OPT_OUTPUT);
    }

    public function settings_page() {
        $roles = wp_roles()->roles;
        $output = $this->get_output_mode();
        ?>
        <div class="wrap">
            <h1>Verified Checkmark Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields('wp_verified_checkmark_settings'); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Select Role for Verified Checkmark:</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT_ROLE); ?>">
                                <?php foreach ($roles as $role_slug => $role): ?>
                                    <option value="<?php echo esc_attr($role_slug); ?>" <?php selected(get_option(self::OPT_ROLE), $role_slug); ?>>
                                        <?php echo esc_html($role['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Output Mode:</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT_OUTPUT); ?>">
                                <option value="unicode" <?php selected($output, 'unicode'); ?>>Unicode (max theme compatibility)</option>
                                <option value="svg" <?php selected($output, 'svg'); ?>>Inline SVG (no external library)</option>
                                <option value="fa" <?php selected($output, 'fa'); ?>>Font Awesome icon</option>
                            </select>
                            <p class="description">If a theme escapes author names with <code>esc_html()</code>, choose <strong>Unicode</strong> so the checkmark still shows (e.g. BeTheme setups often do this).</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

new WP_Verified_Checkmark();
