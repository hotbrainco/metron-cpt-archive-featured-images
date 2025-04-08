<?php
/**
 * Plugin Name: Metron.Church ▸ CPT Archive Featured Images
 * Description: Adds a featured image field to CPT archive admin pages, with a settings panel to choose which post types to apply it to.
 * Version: 1.2
 * Author: metron.church
 */


///////////////////
//   CHANGELOG   //
///////////////////
/*
- 1.2 - Attempting to integrate with Bricks Builder
- 1.1 - Added settings page
- 1.0 - Add uploader to all CPT list pages
*/


///////////////////////////////////////////////////////////
// 📌 Add a settings page to the admin under "Settings"
///////////////////////////////////////////////////////////

add_action('admin_menu', function () {
    add_options_page(
        'Archive Image Settings',         // Page title
        'Archive Image Settings',         // Menu label
        'manage_options',                 // Capability
        'mc-archive-image-settings',      // Slug
        'mc_render_archive_image_settings_page' // Callback to render content
    );
});


// Add 'Settings' link on Plugins page
add_filter('plugin_action_links_' . plugin_basename(__FILE__), function ($links) {
    $settings_link = '<a href="' . admin_url('options-general.php?page=mc-archive-image-settings') . '">Settings</a>';
    array_unshift($links, $settings_link);
    return $links;
});


///////////////////////////////////////////////////////////
// 🧾 Render the settings page where admins can select CPTs
///////////////////////////////////////////////////////////

function mc_render_archive_image_settings_page() {
    $saved = false;

    // Handle form submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && check_admin_referer('mc_archive_settings_save')) {
        $enabled_cpts = isset($_POST['enabled_cpts']) ? array_map('sanitize_key', $_POST['enabled_cpts']) : [];
        update_option('mc_archive_uploader_enabled_cpts', $enabled_cpts);
        $saved = true;
    }

    // Get all custom post types (exclude built-ins like post/page)
    $all_cpts = get_post_types(['public' => true, '_builtin' => false], 'objects');
    $enabled = get_option('mc_archive_uploader_enabled_cpts', []);

    echo '<div class="wrap"><h1>Archive Image Settings</h1>';
    if ($saved) {
        echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
    }

    echo '<form method="post">';
    wp_nonce_field('mc_archive_settings_save');

    echo '<table class="form-table"><tbody>';
    foreach ($all_cpts as $cpt) {
        $checked = in_array($cpt->name, $enabled) ? 'checked' : '';
        echo "<tr><th scope='row'>{$cpt->labels->name}</th><td>";
        echo "<label><input type='checkbox' name='enabled_cpts[]' value='{$cpt->name}' {$checked}> Enable archive image uploader</label>";
        echo '</td></tr>';
    }
    echo '</tbody></table>';

    submit_button('Save Settings');
    echo '</form></div>';
}


///////////////////////////////////////////////////////////
// 🧱 Inject the uploader UI into selected CPT list pages
///////////////////////////////////////////////////////////

add_action('restrict_manage_posts', function () {
    $screen = get_current_screen();

    // Bail if not on a post list screen or post_type is missing
    if ($screen->base !== 'edit' || empty($screen->post_type)) return;

    // Only show if the post_type is enabled in plugin settings
    $enabled_cpts = get_option('mc_archive_uploader_enabled_cpts', []);
    if (!in_array($screen->post_type, $enabled_cpts, true)) return;

    $post_type = $screen->post_type;
    $option_name = "{$post_type}_archive_featured_image";
    $image_id = get_option($option_name);
    $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : '';

    echo '<div style="
        display: inline-flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
        margin: -5px 10px 10px 10px;
    ">';
    echo "<strong style='margin-right: 10px;'>Archive Image for <code>{$post_type}</code>:</strong>";
    if ($image_url) {
        echo "<img src='{$image_url}' style='height:40px; margin-right:10px;'>";
    }
    echo "<button type='button' class='button' id='upload-archive-image-{$post_type}'>Upload Image</button>";
    echo "<input type='hidden' id='archive_image_input_{$post_type}' value='" . esc_attr($image_id) . "'>";
    echo '</div>';
});


///////////////////////////////////////////////////////////
// 🎨 Enqueue the WordPress Media Library and JS uploader
///////////////////////////////////////////////////////////

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'edit.php') return;

    wp_enqueue_media();

    // Inline JavaScript to handle media uploader interaction
    wp_add_inline_script('jquery-core', <<<JS
    jQuery(document).ready(function($) {
        $('button[id^="upload-archive-image-"]').on('click', function(e) {
            e.preventDefault();
            const postType = $(this).attr('id').replace('upload-archive-image-', '');
            const frame = wp.media({ title: 'Select Archive Image', multiple: false });
            frame.on('select', function() {
                const attachment = frame.state().get('selection').first().toJSON();
                $('#archive_image_input_' + postType).val(attachment.id);
                $.post(ajaxurl, {
                    action: 'save_archive_image',
                    post_type: postType,
                    image_id: attachment.id
                }, () => location.reload());
            });
            frame.open();
        });
    });
JS);
});


///////////////////////////////////////////////////////////
// 💾 AJAX endpoint to save archive image ID to wp_options
///////////////////////////////////////////////////////////

add_action('wp_ajax_save_archive_image', function () {
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $post_type = sanitize_key($_POST['post_type'] ?? '');
    $image_id = absint($_POST['image_id'] ?? 0);

    if ($post_type && $image_id) {
        update_option("{$post_type}_archive_featured_image", $image_id);
    }

    wp_die(); // required for AJAX handler
});


///////////////////////////////////////////////////////////
// 📦 Helper: Get archive image URL for current or given CPT
///////////////////////////////////////////////////////////

function get_cpt_archive_image_url($post_type = null) {
    $post_type = $post_type ?: get_post_type();
    $image_id = get_option("{$post_type}_archive_featured_image");
    return $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
}


///////////////////////////////////////////////////////////
// 🔧 Optional: Shortcode for use in templates or Bricks
///////////////////////////////////////////////////////////

add_shortcode('archive_hero_image', function () {
    return get_cpt_archive_image_url();
});


///////////////////////////////////////////////////////////
// 🧱 Bricks Dynamic Tag: {archive_image}
///////////////////////////////////////////////////////////

add_filter('bricks/dynamic_tags_list', function ($tags) {
  $tags[] = [
    'name'  => '{archive_image}',
    'label' => 'Archive Image',
    'group' => 'Metron',
  ];
  return $tags;
});

/**
 * Handle {archive_image} as a raw dynamic tag value
 */
add_filter('bricks/dynamic_data/render_tag', 'mc_render_archive_image_tag', 20, 3);
function mc_render_archive_image_tag($tag, $post = null, $context = 'text') {
  if ($tag !== '{archive_image}') {
    return $tag;
  }

  return mc_get_archive_image_url();
}

/**
 * Handle {archive_image} when embedded in content or attribute strings
 */
add_filter('bricks/dynamic_data/render_content', 'mc_render_archive_image_in_content', 20, 3);
add_filter('bricks/frontend/render_data', 'mc_render_archive_image_in_content', 20, 2);

function mc_render_archive_image_in_content($content, $post = null, $context = 'text') {
  if (strpos($content, '{archive_image}') === false) {
    return $content;
  }

  $url = mc_get_archive_image_url();
  return str_replace('{archive_image}', $url, $content);
}

/**
 * Logic to get the archive image URL based on post type
 */
function mc_get_archive_image_url() {
  $post_type = null;

  // Archive page context
  if (is_post_type_archive()) {
    $post_type = get_query_var('post_type');
  }

  // Fallback: queried object
  if (!$post_type) {
    $object = get_queried_object();
    if ($object instanceof WP_Post_Type) {
      $post_type = $object->name;
    } elseif (isset($object->post_type)) {
      $post_type = $object->post_type;
    }
  }

  // Final fallback from $post global
  global $post;
  if (!$post_type && $post instanceof WP_Post) {
    $post_type = $post->post_type;
  }

  if (!$post_type) return '';

  $image_id = get_option("{$post_type}_archive_featured_image");
  return $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
}


///////////////////////////////////////////////////////////
// 🧹 Cleanup: Remove plugin options on uninstall
///////////////////////////////////////////////////////////

// Register uninstall hook
register_uninstall_hook(__FILE__, 'mc_archive_image_cleanup_on_uninstall');

/**
 * Deletes all options created by this plugin.
 */
function mc_archive_image_cleanup_on_uninstall() {
    // Get the list of CPTs that had the uploader enabled
    $enabled_cpts = get_option('mc_archive_uploader_enabled_cpts', []);

    // Delete the archive image option for each CPT
    foreach ($enabled_cpts as $cpt) {
        delete_option("{$cpt}_archive_featured_image");
    }

    // Delete the settings option itself
    delete_option('mc_archive_uploader_enabled_cpts');
}
