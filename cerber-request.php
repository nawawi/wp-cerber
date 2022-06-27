<?php

final class CRB_Request {
	private static $remote_ip = null;
	private static $clean_uri = null; // No trailing slash, GET parameters and other junk symbols
	private static $request_uri = null; // Undecoded $_SERVER['REQUEST_URI']
	private static $uri_script = null; // With path and the starting slash (if script)
	private static $site_root = null; // Without trailing slash and path (site domain or IP address)
	private static $sub_folder = null; // Without trailing slash and site domain
	private static $the_path = null;
	private static $files = array();
	private static $recursion_counter = 0; // buffer overflow attack protection
	private static $el_counter = 0; // buffer overflow attack protection
	private static $bad_request = false; // buffer overflow attack protection
	private static $commenting = null; // A comment is submitted

	/**
	 * Returns clean "Request URI" without trailing slash and GET parameters
	 *
	 * @return string
	 */
	static function URI() {
		if ( isset( self::$clean_uri ) ) {
			return self::$clean_uri;
		}

		return self::purify();
	}

	/**
	 * Cleans up and normalizes the requested URI.
	 * Removes GET parameters and extra slashes, normalizes malformed URI.
	 *
	 * @return string
	 * @since 7.9.2
	 */
	private static function purify() {
		$uri = $_SERVER['REQUEST_URI'];

		if ( $pos = strpos( $uri, '?' ) ) {
			$uri = substr( $uri, 0, $pos );
		}

		if ( $pos = strpos( $uri, '#' ) ) {
			$uri = substr( $uri, 0, $pos ); // malformed
		}

		$uri = rtrim( urldecode( $uri ), '/' );

		self::$clean_uri = preg_replace( '/\/+/', '/', $uri );

		return self::$clean_uri;
	}

	static function parse_site_url() {
		if ( isset( self::$site_root ) ) {
			return;
		}

		list( self::$site_root, self::$sub_folder ) = crb_parse_site_url();

		/*$site_url = cerber_get_site_url(); // Including the path to WP files and stuff
		$p1       = strpos( $site_url, '//' );
		$p2       = strpos( $site_url, '/', $p1 + 2 );
		if ( $p2 !== false ) {
			self::$site_root  = substr( $site_url, 0, $p2 );
			self::$sub_folder = substr( $site_url, $p2 );
		}
		else {
			self::$site_root  = $site_url;
			self::$sub_folder = '';
		}*/

	}

	/**
	 * Requested URL as is
	 *
	 * @return string
	 */
	static function full_url() {

		self::parse_site_url();

		return self::$site_root . $_SERVER['REQUEST_URI'];

	}

	static function full_url_clean() {

		self::parse_site_url();

		return self::$site_root . self::URI();

	}

	/**
	 * Does requested URL start with a given string?
	 * Safe for checking malformed URLs
	 *
	 * @param $str string
	 *
	 * @return bool
	 */
	static function is_url_start_with( $str ) {

		$url = self::full_url_clean();

		if ( substr( $str, - 1, 1 ) == '/' ) {
			$url = rtrim( $url, '/' ) . '/';
		}

		if ( 0 === strpos( $url, $str ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Does requested URL start with a given string?
	 * Safe for checking malformed URLs
	 *
	 * @param $str string
	 *
	 * @return bool
	 */
	static function is_url_equal( $str ) {

		$url = self::full_url_clean();

		if ( substr( $str, - 1, 1 ) == '/' ) {
			$url = rtrim( $url, '/' ) . '/';
		}

		if ( $url == $str ) {
			return true;
		}

		return false;
	}

	/**
	 * Check if the requested URI is equal to the given one. Process only non-malformed URL.
	 * May not be used to check for a malicious URI since they can be malformed.
	 *
	 * @param string $slug No domain, no subfolder installation path
	 *
	 * @return bool True if requested URI match the given string and it's not malformed
	 */
	static function is_equal( $slug ) {
		self::parse_site_url();
		$slug = ( $slug[0] != '/' ) ? '/' . $slug : $slug;
		$slug = self::$sub_folder . rtrim( $slug, '/' );
		$uri = rtrim( $_SERVER['REQUEST_URI'], '/' );

		if ( strlen( $slug ) === strlen( $uri )
		     && $slug == $uri ) {
			return true;
		}

		return false;
	}

	static function script() {
		if ( ! isset( self::$uri_script ) ) {
			if ( cerber_detect_exec_extension( self::URI() ) ) {
				self::$uri_script = strtolower( self::URI() );
			}
			else {
				self::$uri_script = false;
			}
		}

		return self::$uri_script;
	}

	// @since 7.9.2
	static function is_script( $val, $multiview = false ) {
		if ( ! self::script() ) {
			return false;
		}
		//$uri_script = self::$uri_script;
		self::parse_site_url();
		if ( self::$sub_folder ) {
			$uri_script = substr( self::$uri_script, strlen( self::$sub_folder ) );
		}
		else {
			$uri_script = self::$uri_script;
		}

		if ( is_array( $val ) ) {
			if ( in_array( $uri_script, $val ) ) {
				return true;
			}
		}
		elseif ( $uri_script == $val ) {
			return true;
		}

		return false;
	}

	/**
	 * WordPress search results page
	 *
	 * @return bool
	 */
	static function is_search() {
		if ( isset( $_GET['s'] ) ) {
			return true;
		}

		if ( self::is_start_with( '/search/' ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Returns true if the request URI starts with a given string.
	 * Suitable for malformed URI.
	 *
	 * @param string $str
	 *
	 * @return bool
	 */
	static function is_start_with( $str ) {
		static $cache;

		if ( ! $str ) {
			return false;
		}

		if ( ! isset( $cache[ $str ] ) ) {
			$len = strlen( $str );
			$sub = substr( self::URI(), 0, $len );

			$cache[ $str ] = ( $sub == $str );
		}

		return $cache[ $str ];
	}

	static function get_request_path() {
		if ( ! isset( self::$the_path ) ) {
			if ( ! $path = crb_array_get( $_SERVER, 'PATH_INFO' ) ) { // Like /index.php/path-to-some-page/ or rest route
				$path = $_SERVER['REQUEST_URI'];
			}
			self::$the_path = '/' . trim( urldecode( $path ), '/' ) . '/';
		}

		return self::$the_path;
	}

	/**
	 * Return decoded $_SERVER['REQUEST_URI']
	 *
	 * @return string
	 */
	static function get_request_URI() {
		if ( ! isset( self::$request_uri ) ) {
			self::$request_uri = trim( urldecode( $_SERVER['REQUEST_URI'] ) );
		}

		return self::$request_uri;
	}

	static function get_files() {
		if ( self::$files ) {
			return self::$files;
		}

		if ( $_FILES ) {
			self::parse_files( $_FILES );
		}

		return self::$files;
	}

	/**
	 * Parser for messy $_FILES
	 * @since 8.6.9
	 *
	 * @param $fields
	 */
	static function parse_files( $fields ) {
		foreach ( $fields as $element ) {
			self::$el_counter ++;
			if ( self::$el_counter > 100 ) { // Normal forms never reach this limit
				self::$bad_request = true;
				return;
			}
			if ( ( $name = crb_array_get( $element, 'name' ) )
			     && is_string( $name )
			     && ( $tmp_file = crb_array_get( $element, 'tmp_name' ) )
			     && is_string( $tmp_file )
			     && is_file( $tmp_file ) ) {
				self::$files[] = array( 'source_name' => $name, 'tmp_file' => $tmp_file );
			}
			elseif ( is_array( $element ) ) {
				self::$recursion_counter ++;
				if ( self::$recursion_counter > 100 ) { // Normal forms never reach this limit
					self::$bad_request = true;
					return;
				}
				self::parse_files( $element );
			}
		}
	}

	static function is_comment_sent() {
		if ( ! isset( self::$commenting ) ) {
			self::$commenting = self::_check_comment_sent();
		}

		return self::$commenting;
	}

	private static function _check_comment_sent() {

		if ( ! isset( $_SERVER['REQUEST_METHOD'] )
		     || $_SERVER['REQUEST_METHOD'] != 'POST'
		     || empty( $_POST )
		     || ! empty( $_GET ) ) {
			return false;
		}

		if ( cerber_is_custom_comment() ) {
			if ( ! empty( $_POST[ crb_get_compiled( 'custom_comm_mark' ) ] )
			     && self::is_equal( crb_get_compiled( 'custom_comm_slug' ) ) ) {
				return true;
			}
		}
		else {
			if ( self::is_script( '/' . WP_COMMENT_SCRIPT ) ) {
				return true;
			}
		}

		return false;
	}
}