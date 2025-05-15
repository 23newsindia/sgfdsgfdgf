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
        $this->cache_path = $upload_dir['basedir'] . '/assets';
        $this->cache_url = $upload_dir['baseurl'] . '/assets';
        
        // Create cache directory if it doesn't exist
        if (!file_exists($this->cache_path)) {
            wp_mkdir_p($this->cache_path);
        }
        
        // Add protection file
        file_put_contents($this->cache_path . '/.htaccess', "Options -Indexes\nRewriteEngine On\nRewriteRule ^wp-content/ - [F]\n");
        
        // Load existing cache map
        $map_file = $this->cache_path . '/map.php';
        if (file_exists($map_file)) {
            $this->cache_map = include $map_file;
        }
        
        add_action('wp_enqueue_scripts', array($this, 'process_assets'), 999999);
        add_filter('script_loader_tag', array($this, 'modify_script_tag'), 10, 3);
        add_filter('style_loader_tag', array($this, 'modify_style_tag'), 10, 3);
        
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
        
        // Generate consistent hash based on file path
        $file_hash = md5($original_path);
        
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
        
        $new_filename = $file_hash . '.' . $type;
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
        // Ensure WooCommerce AJAX endpoints work
        if (strpos($endpoint, 'wc-ajax') !== false) {
            return $endpoint;
        }
        return $endpoint;
    }
}
