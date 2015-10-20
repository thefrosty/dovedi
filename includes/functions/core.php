<?php
namespace TenUp\Dovedi\Core;

/**
 * Default setup routine
 *
 * @uses add_action()
 * @uses do_action()
 *
 * @return void
 */
function setup() {
	$n = function( $function ) {
		return __NAMESPACE__ . "\\$function";
	};

	add_action( 'init', $n( 'i18n' ) );
	add_action( 'init', $n( 'init' ) );

	do_action( 'dovedi_loaded' );

	add_action( 'wp_login',                 $n( 'wp_login' ), 10, 2 );
	add_action( 'login_form_validate_totp', $n( 'validate_totp' ) );
	add_action( 'show_user_profile',        $n( 'user_options' ) );
	add_action( 'edit_user_profile',        $n( 'user_options' ) );
	add_action( 'personal_options_update',  $n( 'user_update' ) );
	add_action( 'edit_user_profile_update', $n( 'user_update' ) );
}

/**
 * Registers the default textdomain.
 *
 * @uses apply_filters()
 * @uses get_locale()
 * @uses load_textdomain()
 * @uses load_plugin_textdomain()
 * @uses plugin_basename()
 *
 * @return void
 */
function i18n() {
	$locale = apply_filters( 'plugin_locale', get_locale(), 'dovedi' );
	load_textdomain( 'dovedi', WP_LANG_DIR . '/dovedi/dovedi-' . $locale . '.mo' );
	load_plugin_textdomain( 'dovedi', false, plugin_basename( DOVEDI_PATH ) . '/languages/' );
}

/**
 * Initializes the plugin and fires an action other plugins can hook into.
 *
 * @uses do_action()
 *
 * @return void
 */
function init() {
	do_action( 'dovedi_init' );
}

/**
 * Activate the plugin
 *
 * @uses init()
 * @uses flush_rewrite_rules()
 *
 * @return void
 */
function activate() {
	// First load the init scripts in case any rewrite functionality is being loaded
	init();
	flush_rewrite_rules();
}

/**
 * Deactivate the plugin
 *
 * Uninstall routines should be in uninstall.php
 *
 * @return void
 */
function deactivate() {

}

/**
 * Show Two-Step Authentication Options
 *
 * @codeCoverageIgnore
 *
 * @param \WP_User $user
 */
function user_options( $user ) {
	if ( ! isset( $user->ID ) ) {
		return;
	}

	wp_nonce_field( 'totp_options', '_nonce_totp_options', false );
	$key = get_user_meta( $user->ID, '_totp_key', true );
	$site_name = get_bloginfo( 'name', 'display' );
	?>
	<table class="form-table">
		<tr id="totp">
			<th><label for="totp-authcode"><?php _e( 'Two-Step Authentication', 'dovedi' ); ?></label></th>
			<td>
				<?php if ( false === $key || empty( $key ) ) :
					$key = generate_key(); ?>
					<button type="button" class="button button-secondary" onclick="jQuery('#totp-enable').toggle();"><?php esc_html_e( 'Enable', 'dovedi' ); ?></button>
				<?php else : ?>
					<button type="button" class="button button-secondary" onclick="if(confirm('<?php echo esc_js( __( 'Are you sure you want to disable two-step authentication?', 'dovedi' ) ); ?>')){jQuery('[name=totp-key]').val('');}"><?php esc_html_e( 'Disable', 'dovedi' ); ?></button>
				<?php endif; ?>
				<div id="totp-enable" style="display:none;">
					<br />
					<img src="<?php echo esc_url( get_qr_code( $site_name, $user->user_email, $key ) ); ?>" id="totp-qrcode" />
					<p><strong><?php echo esc_html( $key ); ?></strong></p>
					<p><?php esc_html_e( 'Please scan the QR code or manually enter the key, then enter an authentication code from your app in order to complete setup', 'dovedi' ); ?></p>
					<p>
						<label for="totp-authcode"><?php esc_html_e( 'Authentication Code:', 'dovedi' ); ?></label>
						<input type="hidden" name="totp-key" value="<?php echo esc_attr( $key ) ?>" />
						<input type="tel" name="totp-authcode" id="totp-authcode" class="input" value="" size="20" pattern="[0-9]*" />
					</p>
				</div>
			</td>
		</tr>
	</table>

	<?php
}

/**
 * Update user options
 *
 * @param integer $user_id The user ID whose options are being updated.
 */
function user_update( $user_id ) {
	if ( isset( $_POST['_nonce_totp_options'] ) ) {
		check_admin_referer( 'totp_options', '_nonce_totp_options' );

		$current_key = get_user_meta( $user_id, '_totp_key', true );

		// If the key was set, but the POST data isn't, delete it
		if ( $current_key && empty( $_POST['totp-key'] ) ) {
			delete_user_meta( $user_id, '_totp_key', $current_key );
			return;
		}

		// If the key hasn't changed or is invalid, do nothing.
		if ( ! isset( $_POST['totp-key'] ) || $current_key === $_POST['totp-key'] || ! preg_match( '/^[ABCDEFGHIJKLMNOPQRSTUVWXYZ234567]+$/', $_POST['totp-key'] ) ) {
			return;
		}

		if ( ! empty( $_POST['totp-authcode'] ) ) {
			if ( is_valid_authcode( $_POST['totp-key'], $_POST['totp-authcode'] ) ) {
				update_user_meta( $user_id, '_totp_key', $_POST['totp-key'] );
			}
		}
	}
}

/**
 * Handle the browser-based login.
 *
 * @param string  $user_login Username.
 * @param \WP_User $user WP_User object of the logged-in user.
 */
function wp_login( $user_login, $user ) {
	$key = get_user_meta( $user->ID, '_totp_key', true );

	if ( empty( $key ) ) {
		return;
	}

	wp_clear_auth_cookie();

	show_two_factor_login( $user );
	safe_exit();
}

/**
 * Display the login form.
 *
 * @param \WP_User $user WP_User object of the logged-in user.
 */
function show_two_factor_login( $user ) {
	if ( ! function_exists( 'login_header' ) ) {
		require_once( ABSPATH . WPINC . '/functions.wp-login.php' );
	}

	if ( ! $user ) {
		$user = wp_get_current_user();
	}

	$login_nonce = create_login_nonce( $user->ID );
	if ( ! $login_nonce ) {
		return safe_exit( esc_html__( 'Could not save login nonce.', 'dovedi' ) );
	}

	$redirect_to = isset( $_REQUEST['redirect_to'] ) ? $_REQUEST['redirect_to'] : $_SERVER['REQUEST_URI'];

	login_html( $user, $login_nonce['key'], $redirect_to );
}

/**
 * Generates the html form for the second step of the authentication process.
 *
 * @codeCoverageIgnore
 *
 * @param \WP_User      $user WP_User object of the logged-in user.
 * @param string        $login_nonce A string nonce stored in usermeta.
 * @param string        $redirect_to The URL to which the user would like to be redirected.
 * @param string        $error_msg Optional. Login error message.
 */
function login_html( $user, $login_nonce, $redirect_to, $error_msg = '' ) {
	$rememberme = 0;
	if ( isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'] ) {
		$rememberme = 1;
	}

	login_header();

	if ( ! empty( $error_msg ) ) {
		echo '<div id="login_error"><strong>' . esc_html( $error_msg ) . '</strong><br /></div>';
	}
	?>

	<form name="validate_totp" id="loginform" action="<?php echo esc_url( site_url( 'wp-login.php?action=validate_totp', 'login_post' ) ); ?>" method="post" autocomplete="off">
		<input type="hidden" name="wp-auth-id"    id="wp-auth-id"    value="<?php echo esc_attr( $user->ID ); ?>" />
		<input type="hidden" name="wp-auth-nonce" id="wp-auth-nonce" value="<?php echo esc_attr( $login_nonce ); ?>" />
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $redirect_to ); ?>" />
		<input type="hidden" name="rememberme"    id="rememberme"    value="<?php echo esc_attr( $rememberme ); ?>" />

		<?php authentication_page( $user ); ?>
	</form>

	<p id="backtoblog">
		<a href="<?php echo esc_url( home_url( '/' ) ); ?>" title="<?php esc_attr_e( 'Are you lost?', 'dovedi' ); ?>"><?php echo esc_html( sprintf( __( '&larr; Back to %s', 'dovedi' ), get_bloginfo( 'title', 'display' ) ) ); ?></a>
	</p>

	<?php
	/** This action is documented in wp-login.php */
	do_action( 'login_footer' ); ?>
	<div class="clear"></div>
	</body>
	</html>
	<?php
}

/**
 * Login form validation.
 */
function validate_totp() {

	if ( ! isset( $_POST['wp-auth-id'], $_POST['wp-auth-nonce'] ) ) {
		return;
	}

	$user = get_userdata( $_POST['wp-auth-id'] );
	if ( ! $user ) {
		return;
	}

	$nonce = $_POST['wp-auth-nonce'];
	if ( true !== verify_login_nonce( $user->ID, $nonce ) ) {
		wp_safe_redirect( get_bloginfo( 'url' ) );
		return safe_exit();
	}

	if ( true !== validate_authentication( $user ) ) {
		do_action( 'wp_login_failed', $user->user_login );

		$login_nonce = create_login_nonce( $user->ID );
		if ( ! $login_nonce ) {
			return;
		}

		login_html( $user, $login_nonce['key'], $_REQUEST['redirect_to'], esc_html__( 'ERROR: Invalid verification code.', 'dovedi' ) );
		return safe_exit();
	}

	delete_login_nonce( $user->ID );

	$rememberme = isset( $_REQUEST['rememberme'] ) && $_REQUEST['rememberme'];

	wp_set_auth_cookie( $user->ID, $rememberme );

	$redirect_to = apply_filters( 'login_redirect', $_REQUEST['redirect_to'], $_REQUEST['redirect_to'], $user );
	wp_safe_redirect( $redirect_to );

	safe_exit();
}

/**
 * Create the login nonce.
 *
 *
 * @param int $user_id User ID.
 *
 * @return array|bool
 */
function create_login_nonce( $user_id ) {
	$login_nonce               = array();
	$login_nonce['key']        = wp_hash( $user_id . mt_rand() . microtime(), 'nonce' );
	$login_nonce['expiration'] = time() + HOUR_IN_SECONDS;

	if ( ! update_user_meta( $user_id, '_totp_nonce', $login_nonce ) ) {
		return false;
	}

	return $login_nonce;
}

/**
 * Delete the login nonce.
 *
 * @param int $user_id User ID.
 *
 * @return bool
 */
function delete_login_nonce( $user_id ) {
	return delete_user_meta( $user_id, '_totp_nonce' );
}

/**
 * Verify the login nonce.
 *
 * @param int    $user_id User ID.
 * @param string $nonce Login nonce.
 *
 * @return bool
*/
function verify_login_nonce( $user_id, $nonce ) {
	$login_nonce = get_user_meta( $user_id, '_totp_nonce', true );
	if ( ! $login_nonce ) {
		return false;
	}

	if ( $nonce !== $login_nonce['key'] || time() > $login_nonce['expiration'] ) {
		delete_login_nonce( $user_id );
		return false;
	}

	return true;
}

/**
 * Prints the form that prompts the user to authenticate.
 *
 * @codeCoverageIgnore
 *
 * @param \WP_User $user WP_User object of the logged-in user.
 */
function authentication_page( $user ) {
	require_once( ABSPATH .  '/wp-admin/includes/template.php' );
	?>
	<p>
		<label for="authcode"><?php esc_html_e( 'Authentication Code:', 'dovedi' ); ?></label>
		<input type="tel" name="authcode" id="authcode" class="input" value="" size="20" pattern="[0-9]*" />
	</p>
	<script type="text/javascript">
		setTimeout( function(){
			var d;
			try{
				d = document.getElementById('authcode');
				d.value = '';
				d.focus();
			} catch(e){}
		}, 200);
	</script>
	<?php
	submit_button( __( 'Authenticate', 'dovedi' ) );
}

/**
 * Validates authentication.
 *
 * @param \WP_User $user WP_User object of the logged-in user.
 *
 * @return bool Whether the user gave a valid code
 */
function validate_authentication( $user ) {
	$key = get_user_meta( $user->ID, '_totp_key', true );
	return is_valid_authcode( $key, $_REQUEST['authcode'] );
}

/**
 * Generates key
 *
 * @param int $bitsize Nume of bits to use for key.
 *
 * @return string $bitsize long string composed of available base32 chars.
 */
function generate_key( $bitsize = 128 ) {
	$base_32_chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	if ( 8 > $bitsize || 0 !== $bitsize % 8 ) {
		// @TODO: handle this case.
		return safe_exit();
	}

	$s 	= '';

	for ( $i = 0; $i < $bitsize / 8; $i++ ) {
		$s .= $base_32_chars[ rand( 0, 31 ) ];
	}

	return $s;
}

/**
 * Uses the Google Charts API to build a QR Code for use with an otpauth url
 *
 * @param string $site  Site name to display in the Authentication app.
 * @param string $user  Username to share with the Authentication app.
 * @param string $key   The secret key to share with the Authentication app.
 *
 * @return string A URL to use as an img src to display the QR code
 */
function get_qr_code( $site, $user, $key ) {
	$name = sanitize_title( $site ) . ':' . $user;
	$google_url = urlencode( 'otpauth://totp/' . $name . '?secret=' . $key );
	$google_url .= urlencode( '&issuer=' . urlencode( $site ) );
	return 'https://chart.googleapis.com/chart?chs=200x200&chld=M|0&cht=qr&chl=' . $google_url;
}

/**
 * Checks if a given code is valid for a given key, allowing for a certain amount of time drift
 *
 * @param string $key      The share secret key to use.
 * @param string $authcode The code to test.
 *
 * @return bool Whether the code is valid within the time frame
 */
function is_valid_authcode( $key, $authcode ) {
	/**
	 * Filter the maximum ticks to allow when checking valid codes.
	 *
	 * Ticks are the allowed offset from the correct time in 30 second increments,
	 * so the default of 4 allows codes that are two minutes to either side of server time
	 *
	 * @param int $max_ticks Max ticks of time correction to allow. Default 4.
	 */
	$max_ticks = apply_filters( 'totp-time-step-allowance', 4 );

	// Array of all ticks to allow, sorted using absolute value to test closest match first.
	$ticks = range( - $max_ticks, $max_ticks );
	usort( $ticks, __NAMESPACE__ . '\abssort' );

	$time = time() / 30;

	foreach ( $ticks as $offset ) {
		$log_time = $time + $offset;
		if ( calc_totp( $key, $log_time ) === $authcode ) {
			return true;
		}
	}
	return false;
}

/**
 * Calculate a valid code given the shared secret key
 *
 * @param string $key        The shared secret key to use for calculating code.
 * @param mixed  $step_count The time step used to calculate the code, which is the floor of time() divided by step size.
 * @param int    $digits     The number of digits in the returned code.
 * @param string $hash       The hash used to calculate the code.
 * @param int    $time_step  The size of the time step.
 *
 * @return string The totp code
 */
function calc_totp( $key, $step_count = false, $digits = 6, $hash = 'sha1', $time_step = 30 ) {
	$secret =  base32_decode( $key );

	if ( false === $step_count ) {
		$step_count = floor( time() / $time_step );
	}

	$timestamp = pack64( $step_count );

	$hash = hash_hmac( $hash, $timestamp, $secret, true );

	$offset = ord( $hash[19] ) & 0xf;

	$code = (
		        ( ( ord( $hash[ $offset + 0 ] ) & 0x7f ) << 24 ) |
		        ( ( ord( $hash[ $offset + 1 ] ) & 0xff ) << 16 ) |
		        ( ( ord( $hash[ $offset + 2 ] ) & 0xff ) << 8 ) |
		        ( ord( $hash[ $offset + 3 ] ) & 0xff )
	        ) % pow( 10, $digits );

	return str_pad( $code, $digits, '0', STR_PAD_LEFT );
}

/**
 * Pack stuff
 *
 * @param string $value The value to be packed.
 *
 * @return string Binary packed string.
 */
function pack64( $value ) {
	if ( version_compare( PHP_VERSION, '5.6.3', '>=' ) ) {
		return pack( 'J', $value );
	}
	$highmap = 0xffffffff << 32;
	$lowmap  = 0xffffffff;
	$higher  = ( $value & $highmap ) >> 32;
	$lower   = $value & $lowmap;
	return pack( 'NN', $higher, $lower );
}

/**
 * Decode a base32 string and return a binary representation
 *
 * @param string $base32_string The base 32 string to decode.
 *
 * @throws \Exception If string contains non-base32 characters.
 *
 * @return string Binary representation of decoded string
 */
function base32_decode( $base32_string ) {

	$base32_string 	= strtoupper( $base32_string );

	if ( ! preg_match( '/^[ABCDEFGHIJKLMNOPQRSTUVWXYZ234567]+$/', $base32_string, $match ) ) {
		throw new \Exception( 'Invalid characters in the base32 string.' );
	}

	$l 	= strlen( $base32_string );
	$n	= 0;
	$j	= 0;
	$binary = '';

	for ( $i = 0; $i < $l; $i++ ) {

		$n = $n << 5; // Move buffer left by 5 to make room.
		$n = $n + strpos( 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $base32_string[ $i ] ); 	// Add value into buffer.
		$j += 5; // Keep track of number of bits in buffer.

		if ( $j >= 8 ) {
			$j -= 8;
			$binary .= chr( ( $n & ( 0xFF << $j ) ) >> $j );
		}
	}

	return $binary;
}

/**
 * Used with usort to sort an array by distance from 0
 *
 * @param int $a First array element.
 * @param int $b Second array element.
 *
 * @return int -1, 0, or 1 as needed by usort
 */
function abssort( $a, $b ) {
	$a = abs( $a );
	$b = abs( $b );
	if ( $a === $b ) {
		return 0;
	}
	return ($a < $b) ? -1 : 1;
}

/**
 * A safe exit handler that will keep our code testable
 *
 * If you need to kill script execution with no output (e.g. when redirecting),
 * use this function. Your functions should never be calling die() or exit()
 * directly, as this makes them extremely difficult to test.
 *
 * @param string [$message] Message to pass on to wp_die
 */
function safe_exit( $message = '' ) {
	$die_handler = function () {
		return function () {
			die;
		};
	};
	add_filter( 'wp_die_ajax_handler', $die_handler );
	add_filter( 'wp_die_xmlrpc_handler', $die_handler );
	add_filter( 'wp_die_handler', $die_handler );
	wp_die( $message );
}