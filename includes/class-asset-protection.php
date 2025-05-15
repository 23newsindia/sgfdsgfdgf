<?php
if (!defined('ABSPATH')) {
    exit;
}

class AssetProtection {
    private $cache_path;
    private $cache_url;
    private $cache_map = [];
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->cache_path = ABSPATH . 'assets';
        $this->cache_url = site_url('/assets');
        
        // Create cache directory if it doesn't exist
        if (!file_exists($this->cache_path)) {
            wp_mkdir_p($this->cache_path);
        }
        
        // Add protection files
        file_put_contents($this->cache_path . '/.htaccess', "Options -Indexes\nRewriteEngine On\nRewriteRule ^wp-content/ - [F]\n");
        file_put_contents(ABSPATH . '.htaccess', "RewriteEngine On\nRewriteRule ^assets/(.*)$ assets/$1 [L]\n", FILE_APPEND);
        
        // Load existing cache map
        $map_file = $this->cache_path . '/map.php';
        if (file_exists($map_file)) {
            $this->cache_map = include $map_file;
        }
        
        add_action('wp_enqueue_scripts', array($this, 'process_assets'), 999999);
        add_action('wp_head', array($this, 'process_inline_assets'), 1);
        add_filter('script_loader_tag', array($this, 'modify_script_tag'), 10, 3);
        add_filter('style_loader_tag', array($this, 'modify_style_tag'), 10, 3);
        add_filter('style_loader_src', array($this, 'process_style_url'), 10, 2);
        add_filter('script_loader_src', array($this, 'process_script_url'), 10, 2);
        add_filter('wp_get_attachment_url', array($this, 'process_attachment_url'), 10, 2);
        add_filter('wp_calculate_image_srcset', array($this, 'process_image_srcset'), 10, 5);
        add_filter('the_content', array($this, 'process_content_urls'));
        
        // Handle WooCommerce AJAX
        add_filter('woocommerce_ajax_get_endpoint', array($this, 'modify_wc_ajax_endpoint'), 10, 2);
    }
    
    public function process_assets() {
        global $wp_scripts, $wp_styles;
        
        // Process scripts
        if (!empty($wp_scripts->registered)) {
            foreach ($wp_scripts->registered as $handle => $script) {
                if ($this->should_process_asset($script->src)) {
                    $new_url = $this->get_cached_asset($script->src, 'js');
                    if ($new_url) {
                        $script->src = $new_url;
                    }
                }
            }
        }
        
        // Process styles
        if (!empty($wp_styles->registered)) {
            foreach ($wp_styles->registered as $handle => $style) {
                if ($this->should_process_asset($style->src)) {
                    $new_url = $this->get_cached_asset($style->src, 'css');
                    if ($new_url) {
                        $style->src = $new_url;
                    }
                }
            }
        }
    }

    public function process_attachment_url($url, $attachment_id) {
        if ($this->should_process_asset($url)) {
            $mime_type = get_post_mime_type($attachment_id);
            $ext = $this->get_extension_from_mime($mime_type);
            if ($ext) {
                $new_url = $this->get_cached_asset($url, $ext);
                if ($new_url) {
                    return $new_url;
                }
            }
        }
        return $url;
    }

    public function process_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!is_array($sources)) {
            return $sources;
        }

        foreach ($sources as &$source) {
            if (isset($source['url']) && $this->should_process_asset($source['url'])) {
                $mime_type = get_post_mime_type($attachment_id);
                $ext = $this->get_extension_from_mime($mime_type);
                if ($ext) {
                    $new_url = $this->get_cached_asset($source['url'], $ext);
                    if ($new_url) {
                        $source['url'] = $new_url;
                    }
                }
            }
        }

        return $sources;
    }

    public function process_content_urls($content) {
        return preg_replace_callback('/src=[\'"]([^\'"]+)[\'"]/', array($this, 'replace_content_url'), $content);
    }

    private function replace_content_url($matches) {
        $url = $matches[1];
        if ($this->should_process_asset($url)) {
            $ext = pathinfo($url, PATHINFO_EXTENSION);
            if (in_array($ext, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'))) {
                $new_url = $this->get_cached_asset($url, $ext);
                if ($new_url) {
                    return 'src="' . $new_url . '"';
                }
            }
        }
        return $matches[0];
    }

    private function get_extension_from_mime($mime_type) {
        $mime_map = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/avif' => 'avif'
        );
        return isset($mime_map[$mime_type]) ? $mime_map[$mime_type] : false;
    }

    public function process_style_url($src, $handle) {
        if ($this->should_process_asset($src)) {
            $new_url = $this->get_cached_asset($src, 'css');
            if ($new_url) {
                return $new_url;
            }
        }
        return $src;
    }

    public function process_script_url($src, $handle) {
        if ($this->should_process_asset($src)) {
            $new_url = $this->get_cached_asset($src, 'js');
            if ($new_url) {
                return $new_url;
            }
        }
        return $src;
    }

    public function process_inline_assets() {
        ob_start(array($this, 'process_html_output'));
    }

    public function process_html_output($html) {
        // Process inline script tags
        $html = preg_replace_callback('/<script[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i', array($this, 'replace_asset_url'), $html);
        
        // Process inline style tags
        $html = preg_replace_callback('/<link[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i', array($this, 'replace_asset_url'), $html);
        
        // Process image tags
        $html = preg_replace_callback('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i', array($this, 'replace_asset_url'), $html);
        
        return $html;
    }

    private function replace_asset_url($matches) {
        $url = $matches[1];
        if ($this->should_process_asset($url)) {
            $ext = pathinfo($url, PATHINFO_EXTENSION);
            if (!$ext) {
                $ext = (strpos($matches[0], '.css') !== false) ? 'css' : 'js';
            }
            $new_url = $this->get_cached_asset($url, $ext);
            if ($new_url) {
                return str_replace($url, $new_url, $matches[0]);
            }
        }
        return $matches[0];
    }
    
    private function should_process_asset($src) {
        if (empty($src)) return false;
        
        // Only process local files
        $site_url = parse_url(get_site_url(), PHP_URL_HOST);
        $asset_url = parse_url($src, PHP_URL_HOST);
        
        return $asset_url === $site_url;
    }
    
    private function get_cached_asset($src, $type) {
        $original_path = $this->get_local_path($src);
        if (!$original_path || !file_exists($original_path)) return false;
        
        // Generate hash based on file path and content
        $file_hash = md5($original_path . filemtime($original_path));
        
        // Check if we already have this file cached
        if (isset($this->cache_map[$file_hash])) {
            $cached_file = $this->cache_path . '/' . $this->cache_map[$file_hash];
            if (file_exists($cached_file)) {
                return $this->cache_url . '/' . $this->cache_map[$file_hash];
            }
        }
        
        // Cache new file
        $content = file_get_contents($original_path);
        if (!$content) return false;
        
        // Create subdirectory based on file type
        $subdir = in_array($type, array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif')) ? 'img' : $type;
        $cache_subdir = $this->cache_path . '/' . $subdir;
        if (!file_exists($cache_subdir)) {
            wp_mkdir_p($cache_subdir);
        }
        
        // Generate obfuscated filename
        $new_filename = $subdir . '/' . substr($file_hash, 0, 8) . '.' . $type;
        $cache_file = $this->cache_path . '/' . $new_filename;
        
        if (file_put_contents($cache_file, $content)) {
            $this->cache_map[$file_hash] = $new_filename;
            $this->save_cache_map();
            return $this->cache_url . '/' . $new_filename;
        }
        
        return false;
    }
    
    private function save_cache_map() {
        $map_content = '<?php return ' . var_export($this->cache_map, true) . ';';
        file_put_contents($this->cache_path . '/map.php', $map_content);
    }
    
    private function get_local_path($src) {
        $site_url = get_site_url();
        $base_path = ABSPATH;
        
        $relative_path = str_replace($site_url, '', $src);
        return realpath($base_path . ltrim($relative_path, '/'));
    }
    
    public function modify_script_tag($tag, $handle, $src) {
        // Remove type attribute (HTML5 doesn't need it)
        $tag = str_replace(" type='text/javascript'", '', $tag);
        $tag = str_replace(' type="text/javascript"', '', $tag);
        
        // Remove ID attribute and version
        $tag = preg_replace('/\sid=[\'"][^\'"]*[\'"]/i', '', $tag);
        $tag = preg_replace('/\?ver=[^"\']*/', '', $tag);
        
        return $tag;
    }
    
    public function modify_style_tag($tag, $handle, $src) {
        // Remove ID attribute and version
        $tag = preg_replace('/\sid=[\'"][^\'"]*[\'"]/i', '', $tag);
        $tag = preg_replace('/\?ver=[^"\']*/', '', $tag);
        
        return $tag;
    }
    
    public function modify_wc_ajax_endpoint($endpoint, $request) {
        if (strpos($endpoint, 'wc-ajax') !== false) {
            return $endpoint;
        }
        return $endpoint;
    }
}
