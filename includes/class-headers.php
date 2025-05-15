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

        // Base CSP directives with more permissive defaults
        $csp = array(
            "default-src" => array("'self'", "https:"),
            "script-src" => array("'self'", "'unsafe-inline'", "'unsafe-eval'", "https:", "blob:"),
            "style-src" => array("'self'", "'unsafe-inline'", "https:"),
            "img-src" => array("'self'", "data:", "https:", "*.googleapis.com", "*.gstatic.com"),
            "font-src" => array("'self'", "data:", "https:", "*.gstatic.com"),
            "connect-src" => array("'self'", "https:"),
            "frame-src" => array("'self'", "https:"),
            "object-src" => array("'none'")
        );

        // If strict CSP is enabled, override with stricter rules
        if ($enable_strict_csp) {
            $csp = array(
                "default-src" => array("'self'"),
                "script-src" => array("'self'", "'unsafe-inline'", "'unsafe-eval'", "blob:"),
                "style-src" => array("'self'", "'unsafe-inline'"),
                "img-src" => array("'self'", "data:", "*.googleapis.com", "*.gstatic.com"),
                "font-src" => array("'self'", "data:", "*.gstatic.com"),
                "connect-src" => array("'self'"),
                "frame-src" => array("'self'"),
                "object-src" => array("'none'")
            );

            // Add specific domains based on settings
            if ($allow_adsense) {
                $csp["script-src"] = array_merge($csp["script-src"], 
                    array("*.google.com", "*.googleadservices.com", "*.googlesyndication.com", "*.googletagservices.com")
                );
                $csp["img-src"] = array_merge($csp["img-src"], 
                    array("*.google.com", "*.googleusercontent.com", "*.doubleclick.net")
                );
                $csp["frame-src"] = array_merge($csp["frame-src"], 
                    array("*.google.com", "*.doubleclick.net")
                );
                $csp["connect-src"] = array_merge($csp["connect-src"],
                    array("*.google.com", "*.doubleclick.net", "*.googlesyndication.com", "fundingchoicesmessages.google.com", "*.adtrafficquality.google")
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
                    array("*.twitter.com", "*.twimg.com", "platform.twitter.com")
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
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: accelerometer=(), camera=(), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), payment=(), usb=()');
        header_remove('Server');
    }
}