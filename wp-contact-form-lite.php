<?php
/**
 * Plugin Name: WP Contact Form Lite
 * Plugin URI: https://github.com/Azanumer/wp-contact-form-lite
 * Description: A dependency-free contact form. Shortcode, spam traps, per-IP rate limiting and an optional submissions inbox — no external service, no builder.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Azan Umer
 * Author URI: https://azanumer.cv
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Text Domain: wp-contact-form-lite
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WCFL_VERSION', '1.0.0' );
define( 'WCFL_OPTION', 'wcfl_settings' );

/**
 * Default settings.
 */
function wcfl_default_settings() {
    return array(
        'recipient'       => get_option( 'admin_email' ),
        'subject_prefix'  => '[Contact Form]',
        'success_message' => 'Thank you! Your message has been sent.',
        'save_inbox'      => 1,
        'rate_limit'      => 5, // submissions per hour per IP.
        'min_seconds'     => 3, // minimum seconds between form render and submit.
    );
}

function wcfl_get_settings() {
    $saved = get_option( WCFL_OPTION, array() );
    return wp_parse_args( is_array( $saved ) ? $saved : array(), wcfl_default_settings() );
}

/**
 * Register the private submissions post type (only when the inbox is enabled).
 */
function wcfl_register_post_type() {
    $settings = wcfl_get_settings();
    if ( empty( $settings['save_inbox'] ) ) {
        return;
    }
    register_post_type(
        'wcfl_submission',
        array(
            'labels'       => array(
                'name'          => __( 'Form Submissions', 'wp-contact-form-lite' ),
                'singular_name' => __( 'Form Submission', 'wp-contact-form-lite' ),
            ),
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'menu_icon'    => 'dashicons-email-alt',
            'supports'     => array( 'title', 'editor' ),
            'capabilities' => array(
                'create_posts' => 'manage_options',
            ),
            'map_meta_cap' => true,
        )
    );
}
add_action( 'init', 'wcfl_register_post_type' );

/**
 * Render the form. Usage: [contact_form]
 */
function wcfl_shortcode( $atts ) {
    $settings = wcfl_get_settings();
    $output   = '';
    $errors   = array();
    $values   = array(
        'name'    => '',
        'email'   => '',
        'subject' => '',
        'message' => '',
    );

    if ( isset( $_POST['wcfl_submit'] ) ) {
        $result = wcfl_handle_submit( $settings );
        if ( is_wp_error( $result ) ) {
            $errors = $result->get_error_messages();
            $values = $result->get_error_data() ? $result->get_error_data() : $values;
        } else {
            return '<p class="wcfl-success">' . esc_html( $settings['success_message'] ) . '</p>';
        }
    }

    $form_id = 'wcfl-' . wp_unique_id();
    $output .= '<form class="wcfl-form" id="' . esc_attr( $form_id ) . '" method="post" action="' . esc_url( get_permalink() ) . '#' . esc_attr( $form_id ) . '">';
    $output .= wp_nonce_field( 'wcfl_submit', 'wcfl_nonce', true, false );

    foreach ( $errors as $error ) {
        $output .= '<p class="wcfl-error">' . esc_html( $error ) . '</p>';
    }

    // Honeypot: hidden from humans, filled by naive bots.
    $output .= '<p class="wcfl-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden;">'
        . '<label>' . esc_html__( 'Leave this field empty', 'wp-contact-form-lite' ) . ' '
        . '<input type="text" name="wcfl_website" value="" tabindex="-1" autocomplete="off"></label></p>';
    // Timing trap: form must take at least N seconds to submit.
    $output .= '<input type="hidden" name="wcfl_rendered_at" value="' . esc_attr( time() ) . '">';

    $fields = array(
        'name'    => __( 'Your name', 'wp-contact-form-lite' ),
        'email'   => __( 'Your email', 'wp-contact-form-lite' ),
        'subject' => __( 'Subject', 'wp-contact-form-lite' ),
        'message' => __( 'Message', 'wp-contact-form-lite' ),
    );
    foreach ( $fields as $key => $label ) {
        $value    = isset( $values[ $key ] ) ? $values[ $key ] : '';
        $required = ( 'subject' === $key ) ? '' : ' required';
        if ( 'message' === $key ) {
            $output .= '<p><label>' . esc_html( $label ) . '<br><textarea name="wcfl_' . esc_attr( $key ) . '" rows="5" required>' . esc_textarea( $value ) . '</textarea></label></p>';
        } else {
            $type    = ( 'email' === $key ) ? 'email' : 'text';
            $output .= '<p><label>' . esc_html( $label ) . '<br><input type="' . esc_attr( $type ) . '" name="wcfl_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"' . $required . '></label></p>';
        }
    }

    $output .= '<p><button type="submit" name="wcfl_submit" value="1">' . esc_html__( 'Send message', 'wp-contact-form-lite' ) . '</button></p>';
    $output .= '</form>';

    return $output;
}
add_shortcode( 'contact_form', 'wcfl_shortcode' );

/**
 * Validate + deliver a submission. Returns true on success or WP_Error.
 */
function wcfl_handle_submit( $settings ) {
    $values = array(
        'name'    => isset( $_POST['wcfl_name'] ) ? sanitize_text_field( wp_unslash( $_POST['wcfl_name'] ) ) : '',
        'email'   => isset( $_POST['wcfl_email'] ) ? sanitize_email( wp_unslash( $_POST['wcfl_email'] ) ) : '',
        'subject' => isset( $_POST['wcfl_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['wcfl_subject'] ) ) : '',
        'message' => isset( $_POST['wcfl_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wcfl_message'] ) ) : '',
    );
    $errors = new WP_Error();

    if ( ! isset( $_POST['wcfl_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcfl_nonce'] ) ), 'wcfl_submit' ) ) {
        $errors->add( 'nonce', __( 'Security check failed. Please reload the page and try again.', 'wp-contact-form-lite' ) );
        return $errors;
    }

    // Honeypot filled = bot. Fail silently with a fake success.
    if ( ! empty( $_POST['wcfl_website'] ) ) {
        return true;
    }

    // Timing trap: submitted faster than a human can type.
    $rendered_at = isset( $_POST['wcfl_rendered_at'] ) ? absint( $_POST['wcfl_rendered_at'] ) : 0;
    if ( $rendered_at && ( time() - $rendered_at ) < (int) $settings['min_seconds'] ) {
        $errors->add( 'fast', __( 'Please take a moment to write your message before sending.', 'wp-contact-form-lite' ) );
        $errors->add_data( $values );
        return $errors;
    }

    if ( '' === $values['name'] ) {
        $errors->add( 'name', __( 'Please enter your name.', 'wp-contact-form-lite' ) );
    }
    if ( ! is_email( $values['email'] ) ) {
        $errors->add( 'email', __( 'Please enter a valid email address.', 'wp-contact-form-lite' ) );
    }
    if ( '' === $values['message'] ) {
        $errors->add( 'message', __( 'Please write a message.', 'wp-contact-form-lite' ) );
    }

    // Per-IP rate limit (transient counter, 1 hour window).
    $ip  = wcfl_client_ip();
    $key = 'wcfl_rate_' . md5( $ip );
    $hits = (int) get_transient( $key );
    if ( $hits >= (int) $settings['rate_limit'] ) {
        $errors->add( 'rate', __( 'Too many messages from your address. Please try again later.', 'wp-contact-form-lite' ) );
        $errors->add_data( $values );
        return $errors;
    }
    if ( $errors->has_errors() ) {
        $errors->add_data( $values );
        return $errors;
    }

    $subject = trim( $settings['subject_prefix'] . ' ' . $values['subject'] );
    $body    = sprintf(
        "Name: %s\nEmail: %s\nIP: %s\n\n%s",
        $values['name'],
        $values['email'],
        $ip,
        $values['message']
    );
    $headers = array( 'Reply-To: ' . $values['name'] . ' <' . $values['email'] . '>' );

    $sent = wp_mail( $settings['recipient'], $subject, $body, $headers );
    if ( ! $sent ) {
        $errors->add( 'mail', __( 'The message could not be sent. Please try again later.', 'wp-contact-form-lite' ) );
        $errors->add_data( $values );
        return $errors;
    }

    set_transient( $key, $hits + 1, HOUR_IN_SECONDS );

    if ( ! empty( $settings['save_inbox'] ) ) {
        wp_insert_post(
            array(
                'post_type'   => 'wcfl_submission',
                'post_title'  => $values['name'] . ' — ' . ( '' !== $values['subject'] ? $values['subject'] : __( '(no subject)', 'wp-contact-form-lite' ) ),
                'post_content' => $body,
                'post_status' => 'private',
            )
        );
    }

    return true;
}

/**
 * Best-effort client IP (respects the wcfl_client_ip filter for proxies).
 */
function wcfl_client_ip() {
    $ip = '';
    if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
        $parts = explode( ',', sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) );
        $ip    = trim( $parts[0] );
    } elseif ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
        $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
    }
    return apply_filters( 'wcfl_client_ip', $ip );
}

/**
 * Settings page.
 */
function wcfl_admin_menu() {
    add_options_page(
        __( 'Contact Form Lite', 'wp-contact-form-lite' ),
        __( 'Contact Form Lite', 'wp-contact-form-lite' ),
        'manage_options',
        'wcfl-settings',
        'wcfl_settings_page'
    );
}
add_action( 'admin_menu', 'wcfl_admin_menu' );

function wcfl_admin_init() {
    register_setting( 'wcfl_settings_group', WCFL_OPTION, 'wcfl_sanitize_settings' );
    add_settings_section( 'wcfl_main', __( 'Form settings', 'wp-contact-form-lite' ), '__return_false', 'wcfl-settings' );

    $fields = array(
        'recipient'       => __( 'Recipient email', 'wp-contact-form-lite' ),
        'subject_prefix'  => __( 'Subject prefix', 'wp-contact-form-lite' ),
        'success_message' => __( 'Success message', 'wp-contact-form-lite' ),
        'rate_limit'      => __( 'Max submissions per IP per hour', 'wp-contact-form-lite' ),
        'min_seconds'     => __( 'Minimum seconds before submit', 'wp-contact-form-lite' ),
    );
    foreach ( $fields as $key => $label ) {
        add_settings_field(
            'wcfl_' . $key,
            $label,
            'wcfl_render_field',
            'wcfl-settings',
            'wcfl_main',
            array( 'key' => $key )
        );
    }
    add_settings_field(
        'wcfl_save_inbox',
        __( 'Save submissions in the dashboard', 'wp-contact-form-lite' ),
        'wcfl_render_checkbox',
        'wcfl-settings',
        'wcfl_main'
    );
}
add_action( 'admin_init', 'wcfl_admin_init' );

function wcfl_render_field( $args ) {
    $settings = wcfl_get_settings();
    $key      = $args['key'];
    $type     = in_array( $key, array( 'rate_limit', 'min_seconds' ), true ) ? 'number' : 'text';
    printf(
        '<input type="%s" name="%s[%s]" value="%s" class="regular-text"%s>',
        esc_attr( $type ),
        esc_attr( WCFL_OPTION ),
        esc_attr( $key ),
        esc_attr( $settings[ $key ] ),
        ( 'number' === $type ) ? ' min="1"' : ''
    );
}

function wcfl_render_checkbox() {
    $settings = wcfl_get_settings();
    printf(
        '<label><input type="checkbox" name="%s[save_inbox]" value="1"%s> %s</label>',
        esc_attr( WCFL_OPTION ),
        checked( 1, (int) $settings['save_inbox'], false ),
        esc_html__( 'Keep a copy under Form Submissions in the admin menu.', 'wp-contact-form-lite' )
    );
}

function wcfl_sanitize_settings( $input ) {
    $clean = wcfl_default_settings();
    if ( isset( $input['recipient'] ) && is_email( $input['recipient'] ) ) {
        $clean['recipient'] = sanitize_email( $input['recipient'] );
    }
    if ( isset( $input['subject_prefix'] ) ) {
        $clean['subject_prefix'] = sanitize_text_field( $input['subject_prefix'] );
    }
    if ( isset( $input['success_message'] ) ) {
        $clean['success_message'] = sanitize_text_field( $input['success_message'] );
    }
    if ( isset( $input['rate_limit'] ) ) {
        $clean['rate_limit'] = max( 1, absint( $input['rate_limit'] ) );
    }
    if ( isset( $input['min_seconds'] ) ) {
        $clean['min_seconds'] = max( 0, absint( $input['min_seconds'] ) );
    }
    $clean['save_inbox'] = ! empty( $input['save_inbox'] ) ? 1 : 0;
    return $clean;
}

function wcfl_settings_page() {
    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }
    echo '<div class="wrap"><h1>' . esc_html__( 'Contact Form Lite', 'wp-contact-form-lite' ) . '</h1>';
    echo '<p>' . esc_html__( 'Use the shortcode [contact_form] on any page or post.', 'wp-contact-form-lite' ) . '</p>';
    echo '<form method="post" action="options.php">';
    settings_fields( 'wcfl_settings_group' );
    do_settings_sections( 'wcfl-settings' );
    submit_button();
    echo '</form></div>';
}
