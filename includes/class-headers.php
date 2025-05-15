<?php
class SecurityHeaders {
    private static $headers_sent = false;
    
    public function add_security_headers() {
        if (self::$headers_sent || headers_sent() || !get_option('security_enable_xss', true)) {
            return;
        }
        
        self::$headers_sent = true;
        $this->set_csp_headers();
        $this->set_security_headers();
    }

    private function set_csp_headers() {
        // Get CSP settings
        $enable_strict_csp = get_option('security_enable_strict_csp', false);
        $allow_adsense = get_option('security_allow_adsense', false);
        $allow_youtube = get_option('security_allow_youtube', false);
        $allow_twitter = get_option('security_allow_twitter', false);

        // Get the site domain
        $site_domain = parse_url(get_site_url(), PHP_URL_HOST);

        // WordPress-specific CSP directives with more permissive defaults
        $csp = array(
            "default-src" => array("'self'", "https:", "data:"),
            "script-src" => array(
                "'self'",
                "'unsafe-inline'",
                "'unsafe-eval'",
                "https:",
                "blob:",
                "*.wordpress.org",
                "*.wp.com",
                "*.google.com",
                "*.googleapis.com",
                "*.gstatic.com",
                $site_domain
            ),
            "style-src" => array(
                "'self'",
                "'unsafe-inline'",
                "https:",
                "*.googleapis.com",
                "*.gstatic.com",
                $site_domain
            ),
            "img-src" => array(
                "'self'",
                "data:",
                "https:",
                "*.wp.com",
                "*.wordpress.org",
                "*.gravatar.com",
                "*.googleusercontent.com",
                "*.google.com",
                "*.gstatic.com",
                "*.bewakoof.com",
                "form-ext.contlo.com",
                $site_domain
            ),
            "font-src" => array("'self'", "data:", "https:", "*.gstatic.com", "*.googleapis.com"),
            "connect-src" => array("'self'", "https:", "*.google-analytics.com", "*.doubleclick.net"),
            "frame-src" => array("'self'", "https:", "*.doubleclick.net", "*.google.com"),
            "object-src" => array("'none'"),
            "base-uri" => array("'self'"),
            "form-action" => array("'self'", "https:"),
            "frame-ancestors" => array("'self'"),
            "manifest-src" => array("'self'")
        );

        // If strict CSP is enabled, use more restrictive rules but still allow essential resources
        if ($enable_strict_csp) {
            $csp["script-src"] = array_merge(
                array("'self'", "'unsafe-inline'", "'unsafe-eval'"),
                array($site_domain, "*.wordpress.org", "*.wp.com")
            );
            
            $csp["style-src"] = array_merge(
                array("'self'", "'unsafe-inline'"),
                array($site_domain, "*.googleapis.com")
            );
            
            $csp["img-src"] = array_merge(
                array("'self'", "data:"),
                array($site_domain, "*.wp.com", "*.gravatar.com", "*.bewakoof.com")
            );

            // Add third-party service permissions
            if ($allow_adsense) {
                $csp["script-src"] = array_merge($csp["script-src"], 
                    array("*.google.com", "*.googleadservices.com", "*.googlesyndication.com")
                );
                $csp["img-src"] = array_merge($csp["img-src"], 
                    array("*.google.com", "*.googleusercontent.com", "*.doubleclick.net")
                );
                $csp["frame-src"] = array_merge($csp["frame-src"], 
                    array("*.google.com", "*.doubleclick.net")
                );
            }

            if ($allow_youtube) {
                $csp["frame-src"] = array_merge($csp["frame-src"], 
                    array("*.youtube.com", "*.youtube-nocookie.com")
                );
                $csp["img-src"] = array_merge($csp["img-src"], 
                    array("*.ytimg.com")
                );
            }

            if ($allow_twitter) {
                $csp["script-src"] = array_merge($csp["script-src"], 
                    array("*.twitter.com", "*.twimg.com")
                );
                $csp["frame-src"] = array_merge($csp["frame-src"], 
                    array("*.twitter.com")
                );
                $csp["img-src"] = array_merge($csp["img-src"], 
                    array("*.twimg.com", "*.twitter.com")
                );
            }
        }

        // Build CSP string
        $csp_string = "";
        foreach ($csp as $directive => $sources) {
            $csp_string .= $directive . " " . implode(" ", array_unique($sources)) . "; ";
        }

        // Add upgrade-insecure-requests
        $csp_string .= "upgrade-insecure-requests";

        header("Content-Security-Policy: " . $csp_string);
    }

    private function set_security_headers() {
        // Standard security headers
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        
        // Modern security headers with relaxed CORS policies
        header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
        header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
        header('Cross-Origin-Resource-Policy: cross-origin');
        
        // Remove potentially dangerous headers
        header_remove('Server');
        header_remove('X-Powered-By');
    }
}
