<?php
/**
 * Plugin Name: Unique Slug Checker
 * Description: Check for unique slugs in real-time across default slug fields, Yoast, Rank Math, and Gutenberg editor.
 * Version: 1.2.0
 * Author: Mohsen Mahdavi
 * Author URI: https://github.com/m2mahdavi
 * Text Domain: unique-slug-checker
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Contributors: moma2work
 */

if (!defined('ABSPATH')) exit;

// Enqueue scripts for Classic Editor and meta boxes
add_action('admin_enqueue_scripts', 'usc_enqueue_admin_scripts');
function usc_enqueue_admin_scripts($hook) {
    if (!in_array($hook, ['post.php', 'post-new.php'])) {
        return;
    }

    wp_enqueue_script(
        'usc-script',
        plugin_dir_url(__FILE__) . 'assets/usc-script.js',
        ['jquery'],
        '1.1.0',
        true
    );

    wp_enqueue_style(
        'usc-style',
        plugin_dir_url(__FILE__) . 'assets/usc-style.css',
        [],
        '1.1.0'
    );

    wp_localize_script('usc-script', 'usc_ajax', [
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('usc_nonce'),
        'checking'   => esc_html__('Checking...', 'unique-slug-checker'),
        'available'  => esc_html__('✅ This slug is unique', 'unique-slug-checker'),
        'used'       => esc_html__('❌ This slug is already used in:', 'unique-slug-checker'),
        'invalid'    => esc_html__('❗ Invalid response.', 'unique-slug-checker'),
    ]);
}

// Enqueue script for Gutenberg editor
add_action('enqueue_block_editor_assets', 'usc_enqueue_gutenberg_assets');
function usc_enqueue_gutenberg_assets() {
    wp_enqueue_script(
        'usc-gutenberg',
        plugin_dir_url(__FILE__) . 'assets/usc-gutenberg.js',
        ['wp-data', 'wp-edit-post', 'wp-plugins'],
        '1.1.0',
        true
    );

    wp_localize_script('usc-gutenberg', 'usc_ajax', [
        'ajax_url'   => admin_url('admin-ajax.php'),
        'nonce'      => wp_create_nonce('usc_nonce'),
        'checking'   => esc_html__('Checking...', 'unique-slug-checker'),
        'available'  => esc_html__('✅ This slug is unique', 'unique-slug-checker'),
        'used'       => esc_html__('❌ This slug is already used in:', 'unique-slug-checker'),
        'invalid'    => esc_html__('❗ Invalid response.', 'unique-slug-checker'),
    ]);
}

// Ajax handler
add_action('wp_ajax_usc_check_slug', 'usc_ajax_check_slug');
function usc_ajax_check_slug() {
    // Verify request and nonce
    if (!isset($_POST['nonce'], $_POST['slug'], $_POST['post_id'])) {
        wp_send_json_error(['message' => esc_html__('Invalid request.', 'unique-slug-checker')]);
    }

    // Sanitize and validate inputs
    $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
    $slug = isset($_POST['slug']) ? sanitize_title(wp_unslash($_POST['slug'])) : '';
    $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;

    // Verify nonce
    if (!wp_verify_nonce($nonce, 'usc_nonce')) {
        wp_send_json_error(['message' => esc_html__('Security check failed.', 'unique-slug-checker')]);
    }

    if (empty($slug)) {
        wp_send_json_success(['exists' => false]);
    }

    // 1. Check with get_page_by_path first
    $existing_post = get_page_by_path($slug, OBJECT, 'any');
    
    if ($existing_post && $existing_post->ID != $post_id) {
        return usc_prepare_duplicate_response($existing_post);
    }

    // 2. If no result, perform wider search with optimization
    $duplicate = usc_find_duplicate_slug($slug, $post_id);
    
    if ($duplicate) {
        return usc_prepare_duplicate_response($duplicate);
    }

    wp_send_json_success(['exists' => false]);
}

/**
 * Optimized function to find duplicate slugs
 */
function usc_find_duplicate_slug($slug, $exclude_id) {
    // Limit to published posts for better performance
    $args = [
        'name'           => $slug,
        'post_type'      => 'any',
        'post_status'    => 'publish',
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'update_post_meta_cache' => false,
        'update_post_term_cache' => false,
    ];

    $posts = get_posts($args);
    
    // Initial check
    if (empty($posts)) {
        return false;
    }
    
    $found_id = $posts[0];
    
    // If found post is current post, continue searching
    if ($found_id == $exclude_id) {
        // Second search considering other statuses
        $args['post_status'] = ['pending', 'draft', 'future', 'private', 'trash'];
        $posts = get_posts($args);
        
        if (empty($posts)) {
            return false;
        }
        
        $found_id = $posts[0];
    }
    
    return ($found_id != $exclude_id) ? get_post($found_id) : false;
}

/**
 * Prepare response for duplicate slug
 */
function usc_prepare_duplicate_response($post) {
    wp_send_json_success([
        'exists'     => true,
        'post_id'    => $post->ID,
        'post_title' => esc_html($post->post_title),
        'permalink'  => esc_url(get_permalink($post->ID)),
        'edit_link'  => esc_url(get_edit_post_link($post->ID, 'raw')),
    ]);
}

// Add slug duplicate column to post list
add_filter('manage_posts_columns', 'usc_add_slug_column');
function usc_add_slug_column($columns) {
    $columns['slug_check'] = esc_html__('Slug Status', 'unique-slug-checker');
    return $columns;
}

add_action('manage_posts_custom_column', 'usc_render_slug_column', 10, 2);
function usc_render_slug_column($column, $post_id) {
    if ($column !== 'slug_check') {
        return;
    }

    $slug = get_post_field('post_name', $post_id);
    if (!$slug) {
        echo '<span class="usc-na">' . esc_html__('N/A', 'unique-slug-checker') . '</span>';
        return;
    }

    // Use get_page_by_path for better performance
    $existing_post = get_page_by_path($slug, OBJECT, 'any');
    
    if ($existing_post && $existing_post->ID != $post_id) {
        echo '<span class="usc-duplicate">⚠️ ' . esc_html__('Duplicate', 'unique-slug-checker') . '</span>';
        return;
    }

    // More thorough check if needed
    $args = [
        'name'           => $slug,
        'post_type'      => 'any',
        'post_status'    => ['publish', 'pending', 'draft', 'future', 'private', 'trash'],
        'posts_per_page' => 1,
        'fields'         => 'ids',
        'no_found_rows'  => true,
    ];

    $posts = get_posts($args);
    $posts = array_filter($posts, function($id) use ($post_id) {
        return $id != $post_id;
    });

    echo empty($posts) 
        ? '<span class="usc-unique">✔️ ' . esc_html__('Unique', 'unique-slug-checker') . '</span>'
        : '<span class="usc-duplicate">⚠️ ' . esc_html__('Duplicate', 'unique-slug-checker') . '</span>';
}