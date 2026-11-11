<?php
/**
 * Plugin Name:       COD Verify Upload
 * Description:       Cash on Delivery চেকআউটে NID (সামনে/পেছনে) ও সেলফি আপলোড, অ্যাডমিন থেকে কন্ট্রোল এবং অর্ডার সম্পন্ন হওয়ার পর অটো-ডিলিট।
 * Version:           1.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            Atik Hosen
 * License:           GPLv2 or later
 * Text Domain:       cod-verify-upload
 */

defined( 'ABSPATH' ) || exit;

define( 'CODV_VERSION', '1.1.1' );
define( 'CODV_FILE', __FILE__ );
define( 'CODV_URL', plugin_dir_url( __FILE__ ) );

add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', CODV_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', CODV_FILE, false );
	}
} );

final class CODV {

	const OPT   = 'codv_settings';
	const SLOTS = array( 'front', 'back', 'selfie', 'photo' );

	/* ------------------------------------------------------------------ */
	/* Boot                                                               */
	/* ------------------------------------------------------------------ */

	public static function init() {
		register_activation_hook( CODV_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( CODV_FILE, array( __CLASS__, 'deactivate' ) );

		add_action( 'plugins_loaded', array( __CLASS__, 'boot' ) );
	}

	public static function boot() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', function () {
				echo '<div class="notice notice-error"><p><strong>COD Verify Upload:</strong> এই প্লাগইন চালাতে WooCommerce ইনস্টল ও অ্যাক্টিভ থাকতে হবে।</p></div>';
			} );
			return;
		}

		// Settings.
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );

		// Checkout (frontend).
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_checkout_after_order_review', array( __CLASS__, 'render_panel' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'validate_checkout' ) );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'save_to_order' ), 10, 1 );

		// Upload / delete (temporary files).
		add_action( 'wp_ajax_codv_upload', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'wp_ajax_nopriv_codv_upload', array( __CLASS__, 'ajax_upload' ) );
		add_action( 'wp_ajax_codv_delete', array( __CLASS__, 'ajax_delete' ) );
		add_action( 'wp_ajax_nopriv_codv_delete', array( __CLASS__, 'ajax_delete' ) );

		// Admin order screen.
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order_box' ) );
		add_action( 'wp_ajax_codv_view', array( __CLASS__, 'ajax_view' ) );
		add_action( 'admin_post_codv_delete_files', array( __CLASS__, 'admin_delete_files' ) );

		// Auto delete.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 10, 4 );
		add_action( 'codv_cleanup', array( __CLASS__, 'cron_cleanup' ) );
		if ( ! wp_next_scheduled( 'codv_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'codv_cleanup' );
		}
	}

	public static function activate() {
		self::ensure_dirs();
		if ( ! wp_next_scheduled( 'codv_cleanup' ) ) {
			wp_schedule_event( time() + 300, 'hourly', 'codv_cleanup' );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'codv_cleanup' );
	}

	/* ------------------------------------------------------------------ */
	/* Settings helpers                                                   */
	/* ------------------------------------------------------------------ */

	public static function defaults() {
		return array(
			'enabled'         => 'yes',
			'gateways'        => 'cod',
			'mode_upload'     => 'yes',
			'mode_info'       => 'yes',
			'default_mode'    => 'upload',
			'front'           => 'required',
			'back'            => 'optional',
			'selfie'          => 'required',
			'nid'             => 'required',
			'dob'             => 'required',
			'photo'           => 'required',
			'delete_info'     => 'yes',
			'shape'           => 'circle',
			'max_mb'          => 5,
			'auto_delete'     => 'yes',
			'delete_days'     => 3,
			'delete_statuses' => array( 'completed' ),
			'title'           => 'ক্যাশ অন ডেলিভারির জন্য ভেরিফিকেশন',
			'hint'            => '',
			'tab_upload'      => 'ছবি আপলোড',
			'tab_info'        => 'NID তথ্য',
			'label_nid'       => 'NID নম্বর',
			'label_dob'       => 'জন্ম তারিখ',
			'label_photo'     => 'আপনার ছবি',
			'label_front'     => 'NID সামনে',
			'label_back'      => 'NID পেছনে',
			'label_selfie'    => 'আপনার ছবি',
		);
	}

	public static function s( $key = null ) {
		$o = wp_parse_args( (array) get_option( self::OPT, array() ), self::defaults() );
		if ( null === $key ) {
			return $o;
		}
		return isset( $o[ $key ] ) ? $o[ $key ] : '';
	}

	public static function mode( $slot ) {
		$m = self::s( $slot );
		return in_array( $m, array( 'required', 'optional', 'disabled' ), true ) ? $m : 'disabled';
	}

	public static function items_for( $mode ) {
		$keys = ( 'upload' === $mode ) ? array( 'front', 'back', 'selfie' ) : array( 'nid', 'dob', 'photo' );
		$out  = array();
		foreach ( $keys as $k ) {
			if ( 'disabled' !== self::mode( $k ) ) {
				$out[] = $k;
			}
		}
		return $out;
	}

	public static function available_modes() {
		$m = array();
		if ( 'yes' === self::s( 'mode_upload' ) && self::items_for( 'upload' ) ) {
			$m[] = 'upload';
		}
		if ( 'yes' === self::s( 'mode_info' ) && self::items_for( 'info' ) ) {
			$m[] = 'info';
		}
		return $m;
	}

	public static function default_mode() {
		$av = self::available_modes();
		$d  = self::s( 'default_mode' );
		if ( in_array( $d, $av, true ) ) {
			return $d;
		}
		return $av ? $av[0] : 'upload';
	}

	public static function posted_mode() {
		$m = isset( $_POST['codv_mode'] ) ? sanitize_key( wp_unslash( $_POST['codv_mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $m, self::available_modes(), true ) ? $m : self::default_mode();
	}

	// Image slots that may currently be uploaded.
	public static function active_slots() {
		$out = array();
		foreach ( self::available_modes() as $mode ) {
			foreach ( self::items_for( $mode ) as $k ) {
				if ( in_array( $k, self::SLOTS, true ) ) {
					$out[] = $k;
				}
			}
		}
		return $out;
	}

	public static function gateways() {
		$list = array_filter( array_map( 'trim', explode( ',', (string) self::s( 'gateways' ) ) ) );
		return $list ? array_values( $list ) : array( 'cod' );
	}

	public static function is_active() {
		return 'yes' === self::s( 'enabled' ) && ! empty( self::available_modes() );
	}

	public static function label( $slot ) {
		return (string) self::s( 'label_' . $slot );
	}

	/* ------------------------------------------------------------------ */
	/* Private storage                                                    */
	/* ------------------------------------------------------------------ */

	public static function base() {
		$u = wp_upload_dir();
		return trailingslashit( $u['basedir'] ) . 'codv-private';
	}

	public static function ensure_dirs() {
		$base = self::base();
		foreach ( array( $base, $base . '/tmp', $base . '/orders' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
		}
		$ht = $base . '/.htaccess';
		if ( ! file_exists( $ht ) ) {
			@file_put_contents(
				$ht,
				"<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n"
			);
		}
		$ix = $base . '/index.php';
		if ( ! file_exists( $ix ) ) {
			@file_put_contents( $ix, "<?php\n// Silence is golden.\n" );
		}
	}

	public static function valid_id( $id, $slot = '' ) {
		if ( ! is_string( $id ) || ! preg_match( '/^(front|back|selfie|photo)-[a-f0-9]{32}\.(jpg|png|webp)$/', $id, $m ) ) {
			return false;
		}
		return '' === $slot || $m[1] === $slot;
	}

	public static function tmp_path( $id, $slot = '' ) {
		if ( ! self::valid_id( $id, $slot ) ) {
			return false;
		}
		$p = self::base() . '/tmp/' . $id;
		return file_exists( $p ) ? $p : false;
	}

	public static function digits_en( $v ) {
		return strtr(
			(string) $v,
			array( '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4', '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9' )
		);
	}

	public static function clean_nid( $v ) {
		$v = preg_replace( '/\D+/', '', self::digits_en( $v ) );
		return in_array( strlen( $v ), array( 10, 13, 17 ), true ) ? $v : '';
	}

	public static function clean_dob( $v ) {
		$v = trim( self::digits_en( $v ) );
		if ( preg_match( '/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $v, $m ) ) {
			$v = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
		}
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return '';
		}
		return ( $v > gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ) || (int) $m[1] < 1900 ) ? '' : $v;
	}

	private static function posted_text( $key ) {
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/* ------------------------------------------------------------------ */
	/* Frontend                                                           */
	/* ------------------------------------------------------------------ */

	private static function on_checkout_page() {
		return function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' );
	}

	public static function enqueue() {
		if ( ! self::on_checkout_page() || ! self::is_active() ) {
			return;
		}
		wp_enqueue_style( 'codv-fa', CODV_URL . 'assets/fa/css/all.min.css', array(), '6.5.2' );
		wp_enqueue_style( 'codv', CODV_URL . 'assets/codv.css', array( 'codv-fa' ), CODV_VERSION );
		wp_enqueue_script( 'codv', CODV_URL . 'assets/codv.js', array( 'jquery' ), CODV_VERSION, true );
		wp_localize_script(
			'codv',
			'codvData',
			array(
				'ajax'     => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'codv_nonce' ),
				'gateways' => self::gateways(),
				'i18n'     => array(
					'failed'   => 'আপলোড হয়নি, আবার চেষ্টা করুন।',
					'wait'     => 'ছবি আপলোড হচ্ছে, একটু অপেক্ষা করুন।',
					'required' => 'অনুগ্রহ করে প্রয়োজনীয় ছবিগুলো আপলোড করুন।',
					'delete'   => 'ছবি মুছুন',
					'nid'      => 'সঠিক NID নম্বর দিন (১০, ১৩ বা ১৭ ডিজিট)।',
				),
			)
		);
	}

	private static function svg( $name, $cls = '' ) {
		$map = array(
			'front'    => 'fa-regular fa-id-card',
			'back'     => 'fa-regular fa-address-card',
			'selfie'   => 'fa-regular fa-user',
			'photo'    => 'fa-regular fa-user',
			'nid'      => 'fa-regular fa-id-card',
			'dob'      => 'fa-regular fa-calendar-days',
			'upload'   => 'fa-regular fa-image',
			'info'     => 'fa-regular fa-id-card',
			'plus'     => 'fa-solid fa-plus',
			'close'    => 'fa-solid fa-xmark',
		);
		return '<i class="' . esc_attr( $map[ $name ] . ' ' . $cls ) . '" aria-hidden="true"></i>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function render_slot( $slot ) {
		$req = ( 'required' === self::mode( $slot ) );
		$lbl = self::label( $slot );
		?>
		<div class="codv-slot" data-slot="<?php echo esc_attr( $slot ); ?>" data-required="<?php echo $req ? '1' : '0'; ?>">
			<input type="hidden" class="codv-id" name="codv_<?php echo esc_attr( $slot ); ?>" value="">
			<input type="file" class="codv-file" accept="image/*">
			<div class="codv-box" role="button" tabindex="0" title="<?php echo esc_attr( $lbl ); ?>" aria-label="<?php echo esc_attr( $lbl ); ?>">
				<?php echo self::svg( $slot, 'codv-ico' ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
				<span class="codv-plus"><?php echo self::svg( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
				<?php if ( $req ) : ?><span class="codv-req" aria-hidden="true">*</span><?php endif; ?>
				<span class="codv-pv"><img class="codv-prev" alt=""></span>
				<button type="button" class="codv-del" title="<?php esc_attr_e( 'Delete', 'cod-verify-upload' ); ?>" aria-label="<?php esc_attr_e( 'Delete', 'cod-verify-upload' ); ?>"><?php echo self::svg( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
			</div>
			<span class="codv-msg"></span>
		</div>
		<?php
	}

	private static function render_field( $key, $type, $placeholder, $extra = '' ) {
		$req = ( 'required' === self::mode( $key ) );
		if ( 'dob' === $key ) {
			?>
			<div class="codv-field codv-field-dob">
				<input type="text" class="codv-input" name="codv_dob" data-key="dob" data-required="<?php echo $req ? '1' : '0'; ?>" inputmode="numeric" maxlength="10" placeholder="<?php echo esc_attr( $placeholder . ' (DD/MM/YYYY)' . ( $req ? ' *' : '' ) ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off">
				<button type="button" class="codv-cal" title="<?php echo esc_attr( $placeholder ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>"><?php echo self::svg( 'dob' ); // phpcs:ignore WordPress.Security.EscapeOutput ?></button>
				<input type="date" class="codv-datepick" tabindex="-1" aria-hidden="true" min="1900-01-01" max="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">
			</div>
			<?php
			return;
		}
		?>
		<label class="codv-field">
			<?php echo self::svg( $key ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			<input type="<?php echo esc_attr( $type ); ?>" class="codv-input" name="codv_<?php echo esc_attr( $key ); ?>" data-key="<?php echo esc_attr( $key ); ?>" data-required="<?php echo $req ? '1' : '0'; ?>" placeholder="<?php echo esc_attr( $placeholder . ( $req ? ' *' : '' ) ); ?>" aria-label="<?php echo esc_attr( $placeholder ); ?>" autocomplete="off" <?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
		</label>
		<?php
	}

	public static function render_panel() {
		if ( ! self::on_checkout_page() || ! self::is_active() ) {
			return;
		}
		$shape = 'square' === self::s( 'shape' ) ? 'codv-shape-square' : 'codv-shape-circle';
		$modes = self::available_modes();
		$def   = self::default_mode();
		$title = (string) self::s( 'title' );
		$hint  = (string) self::s( 'hint' );
		?>
		<div id="codv-holder" style="display:none">
			<div id="codv-panel" class="codv-panel <?php echo esc_attr( $shape ); ?>" style="display:none">
				<?php if ( '' !== $title ) : ?><p class="codv-title"><?php echo esc_html( $title ); ?></p><?php endif; ?>
				<?php if ( '' !== $hint ) : ?><p class="codv-hint"><?php echo esc_html( $hint ); ?></p><?php endif; ?>

				<input type="hidden" class="codv-mode" name="codv_mode" value="<?php echo esc_attr( $def ); ?>">

				<?php if ( count( $modes ) > 1 ) : ?>
					<div class="codv-tabs" role="tablist">
						<?php foreach ( $modes as $m ) : ?>
							<button type="button" class="codv-tab<?php echo $m === $def ? ' is-active' : ''; ?>" data-mode="<?php echo esc_attr( $m ); ?>" role="tab">
								<?php echo self::svg( $m ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								<span><?php echo esc_html( self::s( 'tab_' . $m ) ); ?></span>
							</button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'upload', $modes, true ) ) : ?>
					<div class="codv-pane" data-pane="upload"<?php echo 'upload' === $def ? '' : ' hidden'; ?>>
						<div class="codv-row">
							<?php foreach ( self::items_for( 'upload' ) as $slot ) { self::render_slot( $slot ); } ?>
						</div>
					</div>
				<?php endif; ?>

				<?php if ( in_array( 'info', $modes, true ) ) :
					$items = self::items_for( 'info' ); ?>
					<div class="codv-pane" data-pane="info"<?php echo 'info' === $def ? '' : ' hidden'; ?>>
						<div class="codv-info">
							<div class="codv-fields">
								<?php
								if ( in_array( 'nid', $items, true ) ) {
									self::render_field( 'nid', 'text', self::label( 'nid' ), 'inputmode="numeric" maxlength="17"' );
								}
								if ( in_array( 'dob', $items, true ) ) {
									self::render_field( 'dob', 'text', self::label( 'dob' ) );
								}
								?>
							</div>
							<?php if ( in_array( 'photo', $items, true ) ) { self::render_slot( 'photo' ); } ?>
						</div>
					</div>
				<?php endif; ?>

				<div class="codv-error"></div>
			</div>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* AJAX: upload / delete temporary files                              */
	/* ------------------------------------------------------------------ */

	private static function fail( $msg ) {
		wp_send_json_error( array( 'message' => $msg ) );
	}

	public static function ajax_upload() {
		check_ajax_referer( 'codv_nonce', 'nonce' );

		if ( ! self::is_active() ) {
			self::fail( 'আপলোড বন্ধ আছে।' );
		}

		$slot = isset( $_POST['slot'] ) ? sanitize_key( wp_unslash( $_POST['slot'] ) ) : '';
		if ( ! in_array( $slot, self::active_slots(), true ) ) {
			self::fail( 'ভুল অনুরোধ।' );
		}

		// Simple rate limit: 30 uploads / hour / IP.
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$key = 'codv_rl_' . md5( $ip );
		$cnt = (int) get_transient( $key );
		if ( $cnt >= 30 ) {
			self::fail( 'অনেকবার চেষ্টা হয়েছে, কিছুক্ষণ পর আবার চেষ্টা করুন।' );
		}
		set_transient( $key, $cnt + 1, HOUR_IN_SECONDS );

		if ( empty( $_FILES['file'] ) || ! empty( $_FILES['file']['error'] ) ) {
			self::fail( 'ফাইল পাওয়া যায়নি।' );
		}
		$f   = $_FILES['file']; // phpcs:ignore WordPress.Security
		$max = max( 1, (int) self::s( 'max_mb' ) ) * 1048576;
		if ( $f['size'] > $max ) {
			self::fail( 'ছবির সাইজ সর্বোচ্চ ' . (int) self::s( 'max_mb' ) . ' MB হতে পারবে।' );
		}
		if ( ! is_uploaded_file( $f['tmp_name'] ) ) {
			self::fail( 'ভুল ফাইল।' );
		}

		$info = @getimagesize( $f['tmp_name'] );
		$map  = array(
			IMAGETYPE_JPEG => 'jpg',
			IMAGETYPE_PNG  => 'png',
			IMAGETYPE_WEBP => 'webp',
		);
		if ( ! $info || ! isset( $map[ $info[2] ] ) ) {
			self::fail( 'শুধু JPG, PNG বা WEBP ছবি দেওয়া যাবে।' );
		}
		$ext  = $map[ $info[2] ];
		$mime = $info['mime'];

		self::ensure_dirs();
		$name = $slot . '-' . bin2hex( random_bytes( 16 ) ) . '.' . $ext;
		$dest = self::base() . '/tmp/' . $name;

		$ok     = false;
		$editor = wp_get_image_editor( $f['tmp_name'] );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1600, 1600, false );
			$saved = $editor->save( $dest, $mime );
			$ok    = ! is_wp_error( $saved ) && file_exists( $dest );
		}
		if ( ! $ok ) {
			$ok = move_uploaded_file( $f['tmp_name'], $dest );
		}
		if ( ! $ok ) {
			self::fail( 'ছবি সেভ করা যায়নি।' );
		}
		@chmod( $dest, 0640 );

		wp_send_json_success( array( 'id' => $name ) );
	}

	public static function ajax_delete() {
		check_ajax_referer( 'codv_nonce', 'nonce' );
		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$p  = self::tmp_path( $id );
		if ( $p ) {
			@unlink( $p );
		}
		wp_send_json_success();
	}

	/* ------------------------------------------------------------------ */
	/* Checkout validation + saving                                       */
	/* ------------------------------------------------------------------ */

	private static function applies() {
		$pm = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return self::is_active() && in_array( $pm, self::gateways(), true );
	}

	private static function posted_id( $slot ) {
		return self::posted_text( 'codv_' . $slot );
	}

	public static function validate_checkout() {
		if ( ! self::applies() ) {
			return;
		}
		$mode = self::posted_mode();
		foreach ( self::items_for( $mode ) as $key ) {
			$req   = ( 'required' === self::mode( $key ) );
			$label = '<strong>' . esc_html( self::label( $key ) ) . '</strong>';

			if ( in_array( $key, self::SLOTS, true ) ) {
				if ( $req && ! self::tmp_path( self::posted_id( $key ), $key ) ) {
					wc_add_notice( 'অনুগ্রহ করে আপলোড করুন: ' . $label, 'error' );
				}
			} elseif ( 'nid' === $key ) {
				$raw = self::posted_text( 'codv_nid' );
				if ( ( $req || '' !== $raw ) && '' === self::clean_nid( $raw ) ) {
					wc_add_notice( 'সঠিক ' . $label . ' দিন (১০, ১৩ বা ১৭ ডিজিট)।', 'error' );
				}
			} elseif ( 'dob' === $key ) {
				$raw = self::posted_text( 'codv_dob' );
				if ( ( $req || '' !== $raw ) && '' === self::clean_dob( $raw ) ) {
					wc_add_notice( 'সঠিক ' . $label . ' দিন।', 'error' );
				}
			}
		}
	}

	public static function save_to_order( $order_id ) {
		if ( ! self::applies() ) {
			return;
		}
		$mode = self::posted_mode();
		self::ensure_dirs();
		$dir   = self::base() . '/orders/' . absint( $order_id );
		$files = array();
		$info  = array();

		foreach ( self::items_for( $mode ) as $key ) {
			if ( in_array( $key, self::SLOTS, true ) ) {
				$id  = self::posted_id( $key );
				$src = self::tmp_path( $id, $key );
				if ( ! $src ) {
					continue;
				}
				wp_mkdir_p( $dir );
				$dest = $dir . '/' . $id;
				if ( @rename( $src, $dest ) || ( @copy( $src, $dest ) && @unlink( $src ) ) ) {
					$files[ $key ] = absint( $order_id ) . '/' . $id;
				}
			} elseif ( 'nid' === $key ) {
				$v = self::clean_nid( self::posted_text( 'codv_nid' ) );
				if ( '' !== $v ) {
					$info['nid'] = $v;
				}
			} elseif ( 'dob' === $key ) {
				$v = self::clean_dob( self::posted_text( 'codv_dob' ) );
				if ( '' !== $v ) {
					$info['dob'] = $v;
				}
			}
		}

		if ( $files || $info ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				if ( $files ) {
					$order->update_meta_data( '_codv_files', $files );
				}
				if ( $info ) {
					$order->update_meta_data( '_codv_info', $info );
				}
				$order->update_meta_data( '_codv_mode', $mode );
				$order->add_order_note( 'COD ভেরিফিকেশন জমা হয়েছে (' . ( 'info' === $mode ? 'NID তথ্য' : 'ছবি আপলোড' ) . ')।' );
				$order->save();
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin: order screen                                                */
	/* ------------------------------------------------------------------ */

	public static function admin_order_box( $order ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}
		$files   = (array) $order->get_meta( '_codv_files' );
		$info    = (array) $order->get_meta( '_codv_info' );
		$mode    = $order->get_meta( '_codv_mode' );
		$deleted = (int) $order->get_meta( '_codv_deleted_at' );
		$sched   = (int) $order->get_meta( '_codv_delete_at' );
		$has     = ( $files || $info );
		if ( ! $has && ! $deleted ) {
			return;
		}
		static $css = false;
		if ( ! $css ) {
			$css = true;
			echo '<style>
.codv-card{clear:both;margin:16px 0 0;border:1px solid #dcdcde;border-radius:10px;background:#fff;overflow:hidden;max-width:520px}
.codv-card-h{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f6f7f7;border-bottom:1px solid #dcdcde;font-weight:600;font-size:13px}
.codv-card-h svg{width:18px;height:18px;color:#2271b1}
.codv-chip{margin-left:auto;font-weight:500;font-size:11px;padding:2px 9px;border-radius:999px;background:#e7f1fb;color:#2271b1}
.codv-chip.warn{background:#fcf3e0;color:#8a5a00}
.codv-card-b{padding:14px}
.codv-thumbs{display:flex;flex-wrap:wrap;gap:14px}
.codv-thumb{text-align:center;font-size:11px;color:#50575e;text-decoration:none}
.codv-thumb img{display:block;width:104px;height:104px;object-fit:cover;border-radius:10px;border:1px solid #dcdcde;margin:0 0 4px;transition:box-shadow .15s}
.codv-thumb:hover img{box-shadow:0 2px 10px rgba(0,0,0,.18)}
.codv-info-list{margin:0 0 12px;display:grid;grid-template-columns:auto 1fr;gap:8px 14px;font-size:13px}
.codv-info-list dt{color:#646970;margin:0}
.codv-info-list dd{margin:0;font-weight:600;display:flex;align-items:center;gap:8px}
.codv-info-list code{background:#f0f0f1;padding:2px 7px;border-radius:5px;font-size:13px;letter-spacing:.5px}
.codv-copy{border:1px solid #c3c4c7;background:#fff;border-radius:5px;font-size:11px;padding:1px 8px;cursor:pointer}
.codv-card-f{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:10px 14px;border-top:1px solid #f0f0f1;background:#fcfcfc;font-size:12px;color:#646970}
.codv-card-f .button-link-delete{margin-left:auto}
.codv-muted{color:#787c82;font-size:13px;margin:0}
</style>';
		}
		$shield = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 5 6v5c0 4.5 3 8 7 10 4-2 7-5.500 7-10V6l-7-3z"/><path d="m9 12 2 2 4-4"/></svg>';

		echo '<div class="codv-card"><div class="codv-card-h">' . $shield . '<span>COD ভেরিফিকেশন</span>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $has && $mode ) {
			echo '<span class="codv-chip">' . esc_html( 'info' === $mode ? 'NID তথ্য' : 'ছবি আপলোড' ) . '</span>';
		}
		echo '</div><div class="codv-card-b">';

		if ( $has ) {
			if ( $info ) {
				echo '<dl class="codv-info-list">';
				if ( ! empty( $info['nid'] ) ) {
					echo '<dt>NID নম্বর</dt><dd><code>' . esc_html( $info['nid'] ) . '</code><button type="button" class="codv-copy" onclick="navigator.clipboard&&navigator.clipboard.writeText(\'' . esc_js( $info['nid'] ) . '\');this.textContent=\'✓\';">Copy</button></dd>';
				}
				if ( ! empty( $info['dob'] ) ) {
					echo '<dt>জন্ম তারিখ</dt><dd>' . esc_html( wp_date( 'd M Y', strtotime( $info['dob'] . ' 12:00:00 UTC' ), new DateTimeZone( 'UTC' ) ) ) . '</dd>';
				}
				echo '</dl>';
			}
			if ( $files ) {
				echo '<div class="codv-thumbs">';
				foreach ( self::SLOTS as $slot ) {
					if ( empty( $files[ $slot ] ) ) {
						continue;
					}
					$url = add_query_arg(
						array(
							'action'   => 'codv_view',
							'order'    => $order->get_id(),
							'slot'     => $slot,
							'_wpnonce' => wp_create_nonce( 'codv_view' ),
						),
						admin_url( 'admin-ajax.php' )
					);
					echo '<a class="codv-thumb" href="' . esc_url( $url ) . '" target="_blank" rel="noopener"><img src="' . esc_url( $url ) . '" alt="">' . esc_html( self::label( $slot ) ) . '</a>';
				}
				echo '</div>';
			}
		} else {
			echo '<p class="codv-muted">ভেরিফিকেশন ডেটা ডিলিট করা হয়েছে: ' . esc_html( wp_date( 'd M Y, h:i A', $deleted ) ) . '</p>';
		}
		echo '</div>';

		if ( $has ) {
			$del = wp_nonce_url(
				admin_url( 'admin-post.php?action=codv_delete_files&order=' . $order->get_id() ),
				'codv_delete_' . $order->get_id()
			);
			echo '<div class="codv-card-f">';
			if ( $sched ) {
				echo '<span class="codv-chip warn">অটো-ডিলিট: ' . esc_html( wp_date( 'd M Y, h:i A', $sched ) ) . '</span>';
			}
			echo '<a class="button-link button-link-delete" style="color:#b32d2e" href="' . esc_url( $del ) . '" onclick="return confirm(\'ছবি ও তথ্য স্থায়ীভাবে মুছে যাবে। নিশ্চিত?\');">এখনই ডিলিট করুন</a></div>';
		}
		echo '</div>';
	}

	public static function ajax_view() {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'codv_view' ) ) {
			wp_die( 'Invalid request', '', array( 'response' => 403 ) );
		}
		$order = wc_get_order( isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0 );
		$slot  = isset( $_GET['slot'] ) ? sanitize_key( wp_unslash( $_GET['slot'] ) ) : '';
		if ( ! $order || ! in_array( $slot, self::SLOTS, true ) ) {
			wp_die( 'Not found', '', array( 'response' => 404 ) );
		}
		$files = (array) $order->get_meta( '_codv_files' );
		if ( empty( $files[ $slot ] ) ) {
			wp_die( 'Not found', '', array( 'response' => 404 ) );
		}
		$path    = self::base() . '/orders/' . $files[ $slot ];
		$real    = realpath( $path );
		$baseReal = realpath( self::base() );
		if ( ! $real || ! $baseReal || 0 !== strpos( $real, $baseReal ) || ! is_file( $real ) ) {
			wp_die( 'Not found', '', array( 'response' => 404 ) );
		}
		$types = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
		$ext   = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		nocache_headers();
		header( 'Content-Type: ' . ( isset( $types[ $ext ] ) ? $types[ $ext ] : 'application/octet-stream' ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Content-Length: ' . filesize( $real ) );
		readfile( $real );
		exit;
	}

	public static function admin_delete_files() {
		$order_id = isset( $_GET['order'] ) ? absint( $_GET['order'] ) : 0;
		if ( ! current_user_can( 'edit_shop_orders' ) || ! $order_id ) {
			wp_die( 'Forbidden', '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'codv_delete_' . $order_id );
		self::delete_order_files( $order_id, 'ম্যানুয়ালি', true );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Auto delete                                                        */
	/* ------------------------------------------------------------------ */

	public static function delete_order_files( $order_id, $how = 'অটোমেটিক', $force_info = false ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$files = (array) $order->get_meta( '_codv_files' );
		$dir   = self::base() . '/orders/' . absint( $order_id );
		foreach ( $files as $rel ) {
			$p = self::base() . '/orders/' . $rel;
			if ( is_file( $p ) ) {
				@unlink( $p );
			}
		}
		if ( is_dir( $dir ) ) {
			foreach ( (array) glob( $dir . '/*' ) as $left ) {
				if ( is_file( $left ) ) {
					@unlink( $left );
				}
			}
			@rmdir( $dir );
		}
		$order->delete_meta_data( '_codv_files' );
		if ( $force_info || 'yes' === self::s( 'delete_info' ) ) {
			$order->delete_meta_data( '_codv_info' );
		}
		$order->delete_meta_data( '_codv_delete_at' );
		$order->update_meta_data( '_codv_deleted_at', time() );
		$order->add_order_note( 'COD ভেরিফিকেশন ডেটা ' . $how . ' ডিলিট করা হয়েছে।' );
		$order->save();
	}

	public static function on_status_changed( $order_id, $from, $to, $order ) {
		if ( ! $order ) {
			$order = wc_get_order( $order_id );
		}
		if ( ! $order ) {
			return;
		}
		$has = $order->get_meta( '_codv_files' ) || ( 'yes' === self::s( 'delete_info' ) && $order->get_meta( '_codv_info' ) );
		if ( ! $has ) {
			return;
		}
		$statuses = (array) self::s( 'delete_statuses' );
		if ( 'yes' === self::s( 'auto_delete' ) && in_array( $to, $statuses, true ) ) {
			$days = (int) self::s( 'delete_days' );
			if ( $days <= 0 ) {
				self::delete_order_files( $order_id );
			} else {
				$order->update_meta_data( '_codv_delete_at', time() + $days * DAY_IN_SECONDS );
				$order->save();
			}
		} elseif ( $order->get_meta( '_codv_delete_at' ) ) {
			// Status moved away from a "delete" status: cancel the schedule.
			$order->delete_meta_data( '_codv_delete_at' );
			$order->save();
		}
	}

	public static function cron_cleanup() {
		// 1) Orders whose delete time has passed.
		$ids = wc_get_orders(
			array(
				'limit'      => 100,
				'return'     => 'ids',
				'status'     => array_keys( wc_get_order_statuses() ),
				'meta_query' => array(
					array(
						'key'     => '_codv_delete_at',
						'value'   => time(),
						'compare' => '<=',
						'type'    => 'NUMERIC',
					),
				),
			)
		);
		foreach ( (array) $ids as $id ) {
			self::delete_order_files( $id );
		}

		// 2) Abandoned temporary uploads older than 24 hours.
		$tmp = self::base() . '/tmp';
		if ( is_dir( $tmp ) ) {
			foreach ( (array) glob( $tmp . '/*' ) as $f ) {
				if ( is_file( $f ) && basename( $f ) !== 'index.php' && filemtime( $f ) < time() - DAY_IN_SECONDS ) {
					@unlink( $f );
				}
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Admin settings page                                                */
	/* ------------------------------------------------------------------ */

	public static function admin_menu() {
		add_submenu_page(
			'woocommerce',
			'COD Verify Upload',
			'COD Verification',
			'manage_woocommerce',
			'codv-settings',
			array( __CLASS__, 'settings_page' )
		);
	}

	public static function register_settings() {
		register_setting(
			'codv_group',
			self::OPT,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => self::defaults(),
			)
		);
	}

	public static function sanitize( $in ) {
		$in = is_array( $in ) ? $in : array();
		$d  = self::defaults();
		$o  = array();

		$o['enabled']  = ( isset( $in['enabled'] ) && 'no' === $in['enabled'] ) ? 'no' : 'yes';
		$g             = isset( $in['gateways'] ) ? sanitize_text_field( $in['gateways'] ) : '';
		$o['gateways'] = '' !== $g ? $g : 'cod';

		$o['mode_upload']  = ( isset( $in['mode_upload'] ) && 'no' === $in['mode_upload'] ) ? 'no' : 'yes';
		$o['mode_info']    = ( isset( $in['mode_info'] ) && 'no' === $in['mode_info'] ) ? 'no' : 'yes';
		$o['default_mode'] = ( isset( $in['default_mode'] ) && 'info' === $in['default_mode'] ) ? 'info' : 'upload';

		foreach ( array( 'front', 'back', 'selfie', 'nid', 'dob', 'photo' ) as $s ) {
			$v       = isset( $in[ $s ] ) ? $in[ $s ] : $d[ $s ];
			$o[ $s ] = in_array( $v, array( 'required', 'optional', 'disabled' ), true ) ? $v : $d[ $s ];
		}

		$o['shape']       = ( isset( $in['shape'] ) && 'square' === $in['shape'] ) ? 'square' : 'circle';
		$o['max_mb']      = min( 20, max( 1, absint( isset( $in['max_mb'] ) ? $in['max_mb'] : 5 ) ) );
		$o['auto_delete'] = ( isset( $in['auto_delete'] ) && 'no' === $in['auto_delete'] ) ? 'no' : 'yes';
		$o['delete_info'] = ( isset( $in['delete_info'] ) && 'no' === $in['delete_info'] ) ? 'no' : 'yes';
		$o['delete_days'] = min( 365, absint( isset( $in['delete_days'] ) ? $in['delete_days'] : 3 ) );

		$st                   = ( isset( $in['delete_statuses'] ) && is_array( $in['delete_statuses'] ) ) ? array_map( 'sanitize_key', $in['delete_statuses'] ) : array();
		$o['delete_statuses'] = $st ? array_values( $st ) : array( 'completed' );

		$o['hint'] = isset( $in['hint'] ) ? sanitize_text_field( $in['hint'] ) : '';
		foreach ( array( 'title', 'tab_upload', 'tab_info', 'label_front', 'label_back', 'label_selfie', 'label_nid', 'label_dob', 'label_photo' ) as $t ) {
			$val     = isset( $in[ $t ] ) ? sanitize_text_field( $in[ $t ] ) : '';
			$o[ $t ] = '' !== $val ? $val : $d[ $t ];
		}
		return $o;
	}

	private static function select( $name, $value, $options ) {
		echo '<select name="' . esc_attr( self::OPT . '[' . $name . ']' ) . '">';
		foreach ( $options as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $value, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	private static function text( $name, $value, $class = 'regular-text' ) {
		echo '<input type="text" class="' . esc_attr( $class ) . '" name="' . esc_attr( self::OPT . '[' . $name . ']' ) . '" value="' . esc_attr( $value ) . '">';
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s    = self::s();
		$mode = array(
			'required' => 'আবশ্যক (Required)',
			'optional' => 'ঐচ্ছিক (Optional)',
			'disabled' => 'দেখাবে না (Hidden)',
		);

		echo '<div class="wrap"><h1>COD Verify Upload</h1>';

		// Block checkout warning.
		$page_id = function_exists( 'wc_get_page_id' ) ? wc_get_page_id( 'checkout' ) : 0;
		if ( $page_id > 0 ) {
			$post = get_post( $page_id );
			if ( $post && has_block( 'woocommerce/checkout', $post ) ) {
				echo '<div class="notice notice-warning"><p><strong>সতর্কতা:</strong> আপনার চেকআউট পেজ WooCommerce <em>Block</em> চেকআউট ব্যবহার করছে। এই প্লাগইন ক্লাসিক চেকআউটে কাজ করে। চেকআউট পেজের কনটেন্ট <code>[woocommerce_checkout]</code> শর্টকোড দিয়ে বদলে নিন।</p></div>';
			}
		}

		echo '<form method="post" action="options.php">';
		settings_fields( 'codv_group' );
		echo '<table class="form-table" role="presentation">';

		$yn = array( 'yes' => 'চালু (On)', 'no' => 'বন্ধ (Off)' );

		echo '<tr><th>COD ভেরিফিকেশন</th><td>';
		self::select( 'enabled', $s['enabled'], $yn );
		echo '<p class="description">বন্ধ থাকলে ক্যাশ অন ডেলিভারি সিলেক্ট করলে কোনো ট্যাব/বক্স দেখাবে না।</p></td></tr>';

		echo '<tr><th>পেমেন্ট মেথড ID</th><td>';
		self::text( 'gateways', $s['gateways'] );
		echo '<p class="description">ডিফল্ট: <code>cod</code>। একাধিক হলে কমা দিয়ে লিখুন।</p></td></tr>';

		echo '<tr><th>ছবি আপলোড সিস্টেম</th><td>';
		self::select( 'mode_upload', $s['mode_upload'], $yn );
		echo '<p class="description">NID সামনে/পেছনে ও সেলফি আপলোড।</p></td></tr>';

		echo '<tr><th>NID তথ্য সিস্টেম</th><td>';
		self::select( 'mode_info', $s['mode_info'], $yn );
		echo '<p class="description">NID নম্বর, জন্ম তারিখ ও ছবি ইনপুট। দুটো সিস্টেমই চালু থাকলে কাস্টমার একটা বেছে নেবে।</p></td></tr>';

		echo '<tr><th>ডিফল্টভাবে খোলা থাকবে</th><td>';
		self::select( 'default_mode', $s['default_mode'], array( 'upload' => 'ছবি আপলোড', 'info' => 'NID তথ্য' ) );
		echo '</td></tr>';

		echo '<tr><th colspan="2"><h2 style="margin:10px 0 0">ছবি আপলোড সিস্টেম</h2></th></tr>';
		foreach ( array( 'front' => 'NID সামনের ছবি', 'back' => 'NID পেছনের ছবি', 'selfie' => 'সেলফি' ) as $k => $name ) {
			echo '<tr><th>' . esc_html( $name ) . '</th><td>';
			self::select( $k, $s[ $k ], $mode );
			echo '</td></tr>';
		}

		echo '<tr><th colspan="2"><h2 style="margin:10px 0 0">NID তথ্য সিস্টেম</h2></th></tr>';
		foreach ( array( 'nid' => 'NID নম্বর', 'dob' => 'জন্ম তারিখ', 'photo' => 'সেলফি / ছবি' ) as $k => $name ) {
			echo '<tr><th>' . esc_html( $name ) . '</th><td>';
			self::select( $k, $s[ $k ], $mode );
			echo '</td></tr>';
		}

		echo '<tr><th colspan="2"><h2 style="margin:10px 0 0">সাধারণ সেটিংস</h2></th></tr>';

		echo '<tr><th>প্রিভিউ আকার</th><td>';
		self::select( 'shape', $s['shape'], array( 'circle' => 'গোল (Circle)', 'square' => 'চতুর্ভুজ (Square)' ) );
		echo '</td></tr>';

		echo '<tr><th>সর্বোচ্চ ফাইল সাইজ (MB)</th><td><input type="number" min="1" max="20" name="' . esc_attr( self::OPT ) . '[max_mb]" value="' . esc_attr( $s['max_mb'] ) . '" class="small-text"></td></tr>';

		echo '<tr><th>অটো-ডিলিট</th><td>';
		self::select( 'auto_delete', $s['auto_delete'], array( 'yes' => 'চালু', 'no' => 'বন্ধ (ম্যানুয়ালি ডিলিট করতে হবে)' ) );
		echo '<p style="margin-top:10px">অর্ডার নিচের স্ট্যাটাসে গেলে <input type="number" min="0" max="365" name="' . esc_attr( self::OPT ) . '[delete_days]" value="' . esc_attr( $s['delete_days'] ) . '" class="small-text"> দিন পর ছবি স্থায়ীভাবে ডিলিট হবে (<code>0</code> = সাথে সাথে)।</p>';
		echo '<p>';
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$slug = str_replace( 'wc-', '', $slug );
			echo '<label style="margin-right:14px;display:inline-block"><input type="checkbox" name="' . esc_attr( self::OPT ) . '[delete_statuses][]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, (array) $s['delete_statuses'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '</p></td></tr>';

		echo '<tr><th>NID নম্বর ও জন্ম তারিখও ডিলিট হবে?</th><td>';
		self::select( 'delete_info', $s['delete_info'], array( 'yes' => 'হ্যাঁ, ছবির সাথে ডিলিট হবে', 'no' => 'না, থেকে যাবে' ) );
		echo '</td></tr>';

		$texts = array(
			'title'        => array( 'বক্সের টাইটেল', 'large-text' ),
			'hint'         => array( 'নির্দেশনা লেখা (ফাঁকা রাখলে দেখাবে না)', 'large-text' ),
			'tab_upload'   => array( 'ট্যাব: ছবি আপলোড', 'regular-text' ),
			'tab_info'     => array( 'ট্যাব: NID তথ্য', 'regular-text' ),
			'label_front'  => array( 'লেবেল: NID সামনে', 'regular-text' ),
			'label_back'   => array( 'লেবেল: NID পেছনে', 'regular-text' ),
			'label_selfie' => array( 'লেবেল: সেলফি', 'regular-text' ),
			'label_nid'    => array( 'লেবেল/প্লেসহোল্ডার: NID নম্বর', 'regular-text' ),
			'label_dob'    => array( 'লেবেল: জন্ম তারিখ', 'regular-text' ),
			'label_photo'  => array( 'লেবেল: ছবি (NID তথ্য সিস্টেম)', 'regular-text' ),
		);
		foreach ( $texts as $k => $t ) {
			echo '<tr><th>' . esc_html( $t[0] ) . '</th><td>';
			self::text( $k, $s[ $k ], $t[1] );
			echo '</td></tr>';
		}
		echo '</table>';

		submit_button();
		echo '</form></div>';
	}
}

CODV::init();
