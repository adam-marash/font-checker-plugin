<?php
/*
Plugin Name: Font Discovery Plugin
Plugin URI: https://github.com/ediblesites/font-checker-plugin
Description: Discovers fonts used on a given website and stores the information, with live progress updates.
Version: 1.2
Author: Edible Sites
Author URI: https://ediblesites.com
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: ediblesites/font-checker-plugin
Primary Branch: main
*/

// Ensure direct access to this file is not allowed
if (!defined('ABSPATH')) {
    exit;
}

// Register the shortcode
add_shortcode('font_discovery_form', 'font_discovery_form_shortcode');

// Register custom post type
function register_site_post_type() {
    register_post_type('site', array(
        'labels' => array(
            'name' => 'Sites',
            'singular_name' => 'Site',
        ),
        'public' => true,
        'has_archive' => true,
        'supports' => array('title'),
    ));
}
add_action('init', 'register_site_post_type');

// Shortcode function
function font_discovery_form_shortcode() {
    ob_start();
    ?>
    <form id="font-discovery-form">
        <input type="url" id="website-url" name="website-url" required placeholder="Enter website URL">
        <button type="submit">Discover Fonts</button>
    </form>
    <div id="font-discovery-progress"></div>
    <div id="font-discovery-result"></div>

    <script>
    jQuery(document).ready(function($) {
        var progressInterval;
        
        $('#font-discovery-form').on('submit', function(e) {
            e.preventDefault();
            var url = $('#website-url').val();
            $('#font-discovery-progress').html('<p>Starting font discovery process...</p>');
            $('#font-discovery-result').html('');
            
            // Start progress updates
            progressInterval = setInterval(function() {
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'get_discovery_progress'
                    },
                    success: function(response) {
                        $('#font-discovery-progress').html(response);
                    }
                });
            }, 1000);
            
            $.ajax({
                url: '<?php echo admin_url('admin-ajax.php'); ?>',
                type: 'POST',
                data: {
                    action: 'discover_fonts',
                    url: url
                },
                success: function(response) {
                    clearInterval(progressInterval);
                    $('#font-discovery-result').html(response);
                    $('#font-discovery-progress').html('<p>Font discovery process completed.</p>');
                },
                error: function() {
                    clearInterval(progressInterval);
                    $('#font-discovery-progress').html('<p>An error occurred during the font discovery process.</p>');
                }
            });
        });
    });
    </script>
    <?php
    return ob_get_clean();
}

// AJAX handler for font discovery
add_action('wp_ajax_discover_fonts', 'discover_fonts_ajax_handler');
add_action('wp_ajax_nopriv_discover_fonts', 'discover_fonts_ajax_handler');

function discover_fonts_ajax_handler() {
    $url = $_POST['url'];
    update_option('font_discovery_progress', 'Starting font discovery for ' . $url);
    $fonts = discover_fonts($url);
    
    if ($fonts) {
        $response = '<h3>Discovered Fonts:</h3><ul>';
        foreach ($fonts as $font) {
            $response .= '<li>' . esc_html($font) . '</li>';
        }
        $response .= '</ul>';

        update_option('font_discovery_progress', 'Storing site information...');
        // Create a new 'site' post
        $post_id = wp_insert_post(array(
            'post_title' => $url,
            'post_content' => json_encode($fonts),
            'post_type' => 'site',
            'post_status' => 'publish'
        ));

        if (is_wp_error($post_id)) {
            $response .= '<p>Error storing site information.</p>';
        } else {
            $response .= '<p>Site information stored successfully.</p>';
        }
    } else {
        $response = '<p>No fonts discovered or unable to access the site.</p>';
    }

    update_option('font_discovery_progress', '');
    echo $response;
    wp_die();
}

// AJAX handler for progress updates
add_action('wp_ajax_get_discovery_progress', 'get_discovery_progress_ajax_handler');
add_action('wp_ajax_nopriv_get_discovery_progress', 'get_discovery_progress_ajax_handler');

function get_discovery_progress_ajax_handler() {
    $progress = get_option('font_discovery_progress', '');
    echo $progress ? '<p>' . esc_html($progress) . '</p>' : '';
    wp_die();
}

// Function to discover fonts
function discover_fonts($url) {
    $fonts = array();
    
    update_option('font_discovery_progress', 'Fetching main HTML content from ' . $url);
    // Get the main HTML content
    $html = fetch_url_content($url);
    if ($html === false) {
        update_option('font_discovery_progress', 'Failed to fetch HTML content from ' . $url);
        return false;
    }

    update_option('font_discovery_progress', 'Extracting fonts from main HTML');
    // Extract fonts from the main HTML
    $fonts = array_merge($fonts, extract_fonts_from_content($html));

    update_option('font_discovery_progress', 'Searching for linked stylesheets');
    // Find all linked stylesheets
    preg_match_all('/<link[^>]+?href=([\'"])(.*?)\1[^>]*?rel=([\'"])stylesheet\3/i', $html, $matches);
    
    if (!empty($matches[2])) {
        foreach ($matches[2] as $stylesheet_url) {
            // Make sure we have an absolute URL
            $stylesheet_url = url_to_absolute($url, $stylesheet_url);
            
            update_option('font_discovery_progress', 'Fetching and parsing stylesheet: ' . $stylesheet_url);
            // Fetch and parse each stylesheet
            $css_content = fetch_url_content($stylesheet_url);
            if ($css_content !== false) {
                $fonts = array_merge($fonts, extract_fonts_from_content($css_content));
            } else {
                update_option('font_discovery_progress', 'Failed to fetch stylesheet: ' . $stylesheet_url);
            }
        }
    }

    update_option('font_discovery_progress', 'Font discovery completed. Processing results...');
    return array_unique($fonts);
}

// Helper function to fetch URL content
function fetch_url_content($url) {
    $args = array(
        'timeout' => 60,
        'redirection' => 5,
        'sslverify' => false,
    );
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        return false;
    }

    return wp_remote_retrieve_body($response);
}

// Helper function to extract fonts from content
function extract_fonts_from_content($content) {
    $fonts = array();
    
    // Match font-family declarations
    preg_match_all('/font-family:\s*([^;}]+)/i', $content, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $font_match) {
            // Split in case of multiple fonts and clean up
            $font_list = explode(',', $font_match);
            foreach ($font_list as $font) {
                $font = trim($font, " \t\n\r\0\x0B'\"");
                if (!empty($font)) {
                    $fonts[] = $font;
                }
            }
        }
    }
    
    return $fonts;
}

// Helper function to convert relative URLs to absolute
function url_to_absolute($base, $rel) {
    // If it's already absolute, return it
    if (parse_url($rel, PHP_URL_SCHEME) != '') return $rel;

    // Parse base URL and convert rel to abs
    $base = parse_url($base);
    if ($rel[0] == '/') {
        $abs = $base['scheme'] . '://' . $base['host'] . $rel;
    } else {
        $abs = dirname($base['path']) . '/' . $rel;
    }

    // Replace '//' or '/./' or '/foo/../' with '/'
    $re = array('#(/\.?/)#', '#/(?!\.\.)[^/]+/\.\./#');
    for ($n = 1; $n > 0; $abs = preg_replace($re, '/', $abs, -1, $n)) {}

    return $base['scheme'] . '://' . $base['host'] . '/' . ltrim($abs, '/');
}

// Enqueue jQuery
function enqueue_jquery() {
    wp_enqueue_script('jquery');
}
add_action('wp_enqueue_scripts', 'enqueue_jquery');