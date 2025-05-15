<?php
if (!defined('ABSPATH')) {
    exit;
}

class AssetProtection {
    private $cache_path;
    private $cache_url;
    private $cache_map = [];
    private static $runtime_cache = [];
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->cache_path = ABSPATH . 'assets';
        $this->cache_url = site_url('/assets');
        
        // Create cache directory if it doesn't exist
        if (!file_exists($this->cache_path)) {
            wp_mkdir_p($this->cache_path);
        }
        
        // Add protection files
        $this->setup_protection_files();
        
        // Load existing cache map
        $this->load_cache_map();
        
        // Add filters with high priority
        $this->add_filters();
    }

    private function setup_protection_files() {
        $htaccess_content = "
        <IfModule mod_rewrite.c>
        RewriteEngine On
        RewriteRule ^wp-content/ - [F]
        
        # Allow access to assets directory
        RewriteRule ^assets/ - [L]
        
        # Cache control headers
        <FilesMatch '\.(jpg|jpeg|png|gif|webp|avif|css|js)$'>
        Header set Cache-Control 'max-age=31536000, public'
        </FilesMatch>
        </IfModule>
        
        # Prevent directory listing
        Options -Indexes
        
        # Enable compression
        <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/plain text/html text/xml text/css application/xml application/xhtml+xml application/rss+xml application/javascript application/x-javascript image/svg+xml
        </IfModule>
        ";
        
        file_put_contents($this->cache_path . '/.htaccess', $htaccess_content);
    }

    private function load_cache_map() {
        $map_file = $this->cache_path . '/map.php';
        if (file_exists($map_file)) {
            $this->cache_map = include $map_file;
        }
    }

    private function add_filters() {
        // Core asset processing
        add_action('wp_enqueue_scripts', array($this, 'process_assets'), 999999);
        add_filter('script_loader_src', array($this, 'process_script_url'), 10, 2);
        add_filter('style_loader_src', array($this, 'process_style_url'), 10, 2);
        
        // Image processing
        add_filter('wp_get_attachment_url', array($this, 'process_attachment_url'), 10, 2);
        add_filter('wp_get_attachment_image_src', array($this, 'process_image_src'), 10, 4);
        add_filter('wp_calculate_image_srcset', array($this, 'process_image_srcset'), 10, 5);
        
        // WooCommerce specific
        add_filter('woocommerce_single_product_image_thumbnail_html', array($this, 'process_product_image_html'), 10, 2);
        add_filter('woocommerce_product_get_gallery_image_ids', array($this, 'process_gallery_image_ids'), 10, 2);
        
        // Output processing
        add_action('template_redirect', array($this, 'start_output_buffer'), 0);
    }

    public function start_output_buffer() {
        ob_start(array($this, 'process_output'));
    }

    public function process_output($content) {
        // Process all image tags
        $content = preg_replace_callback('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i', 
            array($this, 'replace_image_urls'), $content);
        
        // Process all script tags
        $content = preg_replace_callback('/<script[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/i',
            array($this, 'replace_script_urls'), $content);
        
        // Process all style tags
        $content = preg_replace_callback('/<link[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>/i',
            array($this, 'replace_style_urls'), $content);
        
        return $content;
    }

    private function replace_image_urls($matches) {
        $url = $matches[1];
        if ($this->should_process_asset($url)) {
            $new_url = $this->get_cached_url($url);
            if ($new_url) {
                return str_replace($url, $new_url, $matches[0]);
            }
        }
        return $matches[0];
    }

    private function get_cached_url($url) {
        // Check runtime cache first
        if (isset(self::$runtime_cache[$url])) {
            return self::$runtime_cache[$url];
        }

        $file_path = $this->get_local_path($url);
        if (!$file_path || !file_exists($file_path)) {
            return false;
        }

        $file_hash = md5($file_path . filemtime($file_path));
        $ext = pathinfo($url, PATHINFO_EXTENSION);
        
        // Determine subdirectory based on file type
        $subdir = $this->get_subdir_for_type($ext);
        
        // Check if already cached
        if (isset($this->cache_map[$file_hash])) {
            $cached_path = $this->cache_path . '/' . $this->cache_map[$file_hash];
            if (file_exists($cached_path)) {
                $cached_url = $this->cache_url . '/' . $this->cache_map[$file_hash];
                self::$runtime_cache[$url] = $cached_url;
                return $cached_url;
            }
        }

        // Cache new file
        $new_filename = $subdir . '/' . $file_hash . '.' . $ext;
        $cache_file = $this->cache_path . '/' . $new_filename;
        
        // Ensure subdirectory exists
        wp_mkdir_p(dirname($cache_file));
        
        if (copy($file_path, $cache_file)) {
            $this->cache_map[$file_hash] = $new_filename;
            $this->save_cache_map();
            
            $cached_url = $this->cache_url . '/' . $new_filename;
            self::$runtime_cache[$url] = $cached_url;
            return $cached_url;
        }

        return false;
    }

    private function get_subdir_for_type($ext) {
        $image_types = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'avif');
        if (in_array(strtolower($ext), $image_types)) {
            return 'img';
        }
        return strtolower($ext);
    }

    private function should_process_asset($url) {
        if (empty($url)) return false;
        
        // Only process local URLs
        $site_host = parse_url(get_site_url(), PHP_URL_HOST);
        $asset_host = parse_url($url, PHP_URL_HOST);
        
        return $asset_host === $site_host;
    }

    private function get_local_path($url) {
        $site_url = get_site_url();
        $file_path = str_replace($site_url, ABSPATH, $url);
        $real_path = realpath($file_path);
        
        // Security check - ensure path is within WordPress directory
        if ($real_path && strpos($real_path, ABSPATH) === 0) {
            return $real_path;
        }
        
        return false;
    }

    private function save_cache_map() {
        static $saving = false;
        
        if ($saving) return;
        
        $saving = true;
        $map_content = '<?php return ' . var_export($this->cache_map, true) . ';';
        file_put_contents($this->cache_path . '/map.php', $map_content);
        $saving = false;
    }

    public function process_product_image_html($html, $attachment_id) {
        if (empty($attachment_id)) return $html;
        
        $image_url = wp_get_attachment_url($attachment_id);
        if ($image_url) {
            $new_url = $this->get_cached_url($image_url);
            if ($new_url) {
                $html = str_replace($image_url, $new_url, $html);
            }
        }
        
        return $html;
    }

    public function process_image_src($image, $attachment_id, $size, $icon) {
        if (!is_array($image)) return $image;
        
        if ($this->should_process_asset($image[0])) {
            $new_url = $this->get_cached_url($image[0]);
            if ($new_url) {
                $image[0] = $new_url;
            }
        }
        
        return $image;
    }

    public function process_image_srcset($sources, $size_array, $image_src, $image_meta, $attachment_id) {
        if (!is_array($sources)) return $sources;
        
        foreach ($sources as &$source) {
            if (isset($source['url']) && $this->should_process_asset($source['url'])) {
                $new_url = $this->get_cached_url($source['url']);
                if ($new_url) {
                    $source['url'] = $new_url;
                }
            }
        }
        
        return $sources;
    }
}
