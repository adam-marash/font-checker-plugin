<?php
/*
Plugin Name: Font Discovery Plugin
Plugin URI: https://github.com/ediblesites/font-checker-plugin
Description: Discovers fonts used on a given website and stores the information, with detailed logging.
Version: 1.7
Author: Edible Sites
Author URI: https://ediblesites.com
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: ediblesites/font-checker-plugin
Primary Branch: main
*/

if (!defined('ABSPATH')) {
    exit;
}

// Debug setting
define('FONT_DISCOVERY_DEBUG', false);

add_shortcode('font_discovery_form', 'font_discovery_form_shortcode');

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

function font_discovery_form_shortcode() {
    ob_start();
    ?>
    <form id="font-discovery-form" method="post">
        <input type="url" id="website-url" name="website-url" required placeholder="Enter website URL">
        <button type="submit">Discover Fonts</button>
    </form>
    <div id="font-discovery-result"></div>
    <?php
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['website-url'])) {
        $url = trim($_POST['website-url']);
        
        // Check if the URL starts with http:// or https://
        if (!preg_match("~^(?:f|ht)tps?://~i", $url)) {
            $url = "https://" . $url;
        }
        
        // Validate URL
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            $fonts_html = discover_fonts($url);
            echo $fonts_html;
            
            // Extract fonts from HTML response
            $fonts = array();
            preg_match_all('/<li>(.*?)<\/li>/', $fonts_html, $matches);
            if (!empty($matches[1])) {
                $fonts = $matches[1];
            }
            
            if (!empty($fonts)) {
                // Check for existing posts with the same URL
                $existing_posts = get_posts(array(
                    'post_type' => 'site',
                    'title' => $url,
                    'post_status' => 'any',
                    'posts_per_page' => -1,
                ));

                if (!empty($existing_posts)) {
                    // Update the most recent post
                    $post_id = $existing_posts[0]->ID;
                    $update_result = wp_update_post(array(
                        'ID' => $post_id,
                        'post_content' => json_encode($fonts),
                        'post_status' => 'publish'
                    ), true);

                    // Delete any older posts for this URL
                    for ($i = 1; $i < count($existing_posts); $i++) {
                        wp_delete_post($existing_posts[$i]->ID, true);
                    }

                    log_message('Updated existing post for URL: ' . $url);
                } else {
                    // Create a new post
                    $post_id = wp_insert_post(array(
                        'post_title' => $url,
                        'post_content' => json_encode($fonts),
                        'post_type' => 'site',
                        'post_status' => 'publish'
                    ), true);

                    log_message('Created new post for URL: ' . $url);
                }

                if (is_wp_error($post_id)) {
                    echo '<p>Error storing site information: ' . $post_id->get_error_message() . '</p>';
                    log_message('Error storing site information: ' . $post_id->get_error_message());
                } else {
                    echo '<p>Site information stored successfully.</p>';
                    log_message('Site information stored successfully for URL: ' . $url);
                }
            } else {
                echo '<p>No fonts were discovered to store.</p>';
                log_message('No fonts discovered for URL: ' . $url);
            }
        } else {
            echo '<p>Error: Invalid URL provided.</p>';
            log_message('Invalid URL provided: ' . $url);
        }
    }
    
    return ob_get_clean();
}

function discover_fonts($url) {
    $fonts = array();
    
    log_message('Starting font discovery for ' . $url);
    log_message('Attempting to access site at ' . $url);
    
    $site_check = wp_remote_get($url, array(
        'timeout' => 30,
        'sslverify' => false,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    ));

    if (is_wp_error($site_check)) {
        log_message('WP_Error in site check: ' . $site_check->get_error_message());
        return '<p>Error: Unable to access the site.</p>';
    }

    $status_code = wp_remote_retrieve_response_code($site_check);
    log_message('Site check status code: ' . $status_code);

    if ($status_code != 200) {
        log_message('Failed to access site. Status code: ' . $status_code);
        return '<p>Error: Unable to access the site. Status code: ' . $status_code . '</p>';
    }

    log_message('Successfully accessed site at ' . $url);

    $html = wp_remote_retrieve_body($site_check);
    log_message('Successfully retrieved HTML content from ' . $url);

    log_message('Extracting fonts from main HTML');
    $html_fonts = extract_fonts_from_content($html);
    if (!empty($html_fonts)) {
        log_message('Successfully extracted ' . count($html_fonts) . ' font declaration(s) from HTML');
        $fonts = array_merge($fonts, $html_fonts);
    } else {
        log_message('No font declarations found in HTML');
    }

    log_message('Searching for linked stylesheets');

    // Reverted to more permissive regex, but with added checks
    preg_match_all('/<link\s+(?:[^>]*?\s+)?href=([\'"])([^"\']+\.css[^"\']*)\1[^>]*?rel=([\'"])stylesheet\3|<link\s+(?:[^>]*?\s+)?rel=([\'"])stylesheet\4[^>]*?href=([\'"])([^"\']+\.css[^"\']*)\5/i', $html, $matches);

    $stylesheet_urls = array_merge($matches[2], $matches[6]);
    $stylesheet_urls = array_filter($stylesheet_urls); // Remove empty entries

    log_message('Found ' . count($stylesheet_urls) . ' potential stylesheet(s)');

    if (!empty($stylesheet_urls)) {
        foreach ($stylesheet_urls as $stylesheet_url) {
            $stylesheet_url = url_to_absolute($url, $stylesheet_url);
            
            log_message('Processing stylesheet: ' . $stylesheet_url);
            
            // Additional check to ensure it's a valid CSS URL
            if (preg_match('/\.css(\?.*)?$/i', $stylesheet_url)) {
                $css_content = fetch_url_content($stylesheet_url);
                if ($css_content !== false) {
                    log_message('Successfully retrieved stylesheet: ' . $stylesheet_url);
                    $css_fonts = extract_fonts_from_content($css_content);
                    if (!empty($css_fonts)) {
                        log_message('Successfully extracted ' . count($css_fonts) . ' font declaration(s) from CSS: ' . $stylesheet_url);
                        $fonts = array_merge($fonts, $css_fonts);
                    } else {
                        log_message('No font declarations found in CSS: ' . $stylesheet_url);
                    }
                } else {
                    log_message('Failed to fetch stylesheet: ' . $stylesheet_url);
                }
            } else {
                log_message('Skipping invalid CSS URL: ' . $stylesheet_url);
            }
        }
    } else {
        log_message('No linked stylesheets found');
    }

    $fonts = array_unique($fonts);
    
    log_message('Font discovery completed. Total fonts found: ' . count($fonts));
    
    if (!empty($fonts)) {
        $response = '<h3>Discovered Fonts:</h3><ul>';
        foreach ($fonts as $font) {
            $response .= '<li>' . esc_html($font) . '</li>';
        }
        $response .= '</ul>';
    } else {
        $response = '<p>No fonts discovered.</p>';
    }
    
    return $response;
}

function extract_fonts_from_content($content) {
    $fonts = array();
    $fonts_to_ignore = array(
        'sans-serif',
        'inherit',
        'serif',
        'blink',
        'blinkmacsystemfont',
        'system-ui',
        'figtree',
        'star',
        '-apple-system'
    );
    
    // Match font-family declarations, including multi-line var() functions
    preg_match_all('/font-family\s*:\s*([^;}]+)/i', $content, $matches);
    
    if (!empty($matches[1])) {
        foreach ($matches[1] as $font_match) {
            // Remove any var() functions completely
            $font_match = preg_replace('/var\s*\([^)]+\)/', '', $font_match);
            
            // Split in case of multiple fonts and clean up
            $font_list = explode(',', $font_match);
            foreach ($font_list as $font) {
                $font = trim($font, " \t\n\r\0\x0B'\"");
                
                // Remove !important if it's at the end of the font name
                $font = preg_replace('/\s*!important\s*$/i', '', $font);
                
                $font_lower = strtolower($font); // Lowercase version for comparison
                
                // Skip if the font is in the ignore list or empty
                if (!in_array($font_lower, $fonts_to_ignore) && $font_lower !== '') {
                    // Convert to title case for storage
                    $fonts[] = ucwords($font);
                }
            }
        }
    }
    
    return array_unique($fonts);
}

function fetch_url_content($url) {
    $args = array(
        'timeout' => 60,
        'redirection' => 5,
        'sslverify' => false,
        'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
    );
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        log_message('Error fetching URL: ' . $response->get_error_message());
        return false;
    }

    $status_code = wp_remote_retrieve_response_code($response);
    if ($status_code !== 200) {
        log_message('Unexpected HTTP status code: ' . $status_code);
        return false;
    }

    return wp_remote_retrieve_body($response);
}

function url_to_absolute($base, $rel) {
    // If the relative URL is actually absolute, return it
    if (filter_var($rel, FILTER_VALIDATE_URL)) {
        return $rel;
    }

    // Parse base URL 
    $base = parse_url($base);
    if (!$base) {
        return false; // Invalid base URL
    }

    // If relative URL starts with //, prepend scheme
    if (substr($rel, 0, 2) === '//') {
        return isset($base['scheme']) ? $base['scheme'] . ':' . $rel : 'https:' . $rel;
    }

    // If relative URL starts with /, it's relative to the root
    if ($rel[0] === '/') {
        $path = $rel;
    } else {
        // It's a relative path
        $path = isset($base['path']) ? dirname($base['path']) . '/' . $rel : '/' . $rel;
    }

    // Resolve .. and .
    $parts = array_filter(explode('/', $path), 'strlen');
    $absolute = [];
    foreach ($parts as $part) {
        if ($part === '..') {
            array_pop($absolute);
        } elseif ($part !== '.') {
            $absolute[] = $part;
        }
    }

    $path = '/' . implode('/', $absolute);

    // Build the absolute URL
    $absolute_url = $base['scheme'] . '://' . $base['host'];
    if (isset($base['port'])) {
        $absolute_url .= ':' . $base['port'];
    }
    $absolute_url .= $path;

    return $absolute_url;
}

function log_message($message) {
    if (FONT_DISCOVERY_DEBUG && defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('[Font Discovery] ' . $message);
    }
}
