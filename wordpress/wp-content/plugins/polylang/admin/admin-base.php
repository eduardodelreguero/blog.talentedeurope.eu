<?php

/**
 * Base class for both admin
 *
 * @since 1.8
 */
class PLL_Admin_Base extends PLL_Base {
	public $filter_lang, $curlang, $pref_lang;

	/**
	 * Loads the polylang text domain
	 * Setups actions needed on all admin pages
	 *
	 * @since 1.8
	 *
	 * @param object $links_model
	 */
	public function __construct( &$links_model ) {
		parent::__construct( $links_model );

		// Plugin i18n, only needed for backend
		load_plugin_textdomain( 'polylang', false, basename( POLYLANG_DIR ).'/languages' );

		// Adds the link to the languages panel in the WordPress admin menu
		add_action( 'admin_menu', array( $this, 'add_menus' ) );

		// Setup js scripts and css styles
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'admin_print_footer_scripts' ) );

		// Lingotek
		if ( ! defined( 'PLL_LINGOTEK_AD' ) || PLL_LINGOTEK_AD ) {
			require_once( POLYLANG_DIR . '/lingotek/lingotek.php' );
		}
	}

	/**
	 * Setups filters and action needed on all admin pages and on plugins page
	 * Loads the settings pages or the filters base on the request
	 *
	 * @since 1.2
	 *
	 * @param object $links_model
	 */
	public function init() {
		if ( ! $this->model->get_languages_list() ) {
			return;
		}

		$this->links = new PLL_Admin_Links( $this ); // FIXME needed here ?
		$this->static_pages = new PLL_Admin_Static_Pages( $this ); // FIXME needed here ?
		$this->filters_links = new PLL_Filters_Links( $this ); // FIXME needed here ?

		// Filter admin language for users
		// We must not call user info before WordPress defines user roles in wp-settings.php
		add_filter( 'setup_theme', array( $this, 'init_user' ) );
		add_filter( 'request', array( $this, 'request' ) );

		// Adds the languages in admin bar
		add_action( 'admin_bar_menu', array( $this, 'admin_bar_menu' ), 100 ); // 100 determines the position
	}

	/**
	 * Adds the link to the languages panel in the WordPress admin menu
	 *
	 * @since 0.1
	 */
	public function add_menus() {
		add_submenu_page( 'options-general.php', $title = __( 'Languages', 'polylang' ), $title, 'manage_options', 'mlang', '__return_null' );
	}

	/**
	 * Setup js scripts & css styles ( only on the relevant pages )
	 *
	 * @since 0.6
	 */
	public function admin_enqueue_scripts() {
		$screen = get_current_screen();
		if ( empty( $screen ) ) {
			return;
		}

		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// For each script:
		// 0 => the pages on which to load the script
		// 1 => the scripts it needs to work
		// 2 => 1 if loaded even if languages have not been defined yet, 0 otherwise
		// 3 => 1 if loaded in footer
		// FIXME: check if I can load more scripts in footer
		$scripts = array(
			'post'  => array( array( 'post', 'media', 'async-upload', 'edit' ),  array( 'jquery', 'wp-ajax-response', 'post', 'jquery-ui-autocomplete' ), 0 , 1 ),
			'media' => array( array( 'upload' ), array( 'jquery' ), 0 , 1 ),
			'term'  => array( array( 'edit-tags', 'term' ), array( 'jquery', 'wp-ajax-response', 'jquery-ui-autocomplete' ), 0, 1 ),
			'user'  => array( array( 'profile', 'user-edit' ), array( 'jquery' ), 0 , 0 ),
		);

		foreach ( $scripts as $script => $v ) {
			if ( in_array( $screen->base, $v[0] ) && ( $v[2] || $this->model->get_languages_list() ) ) {
				wp_enqueue_script( 'pll_' . $script, POLYLANG_URL . '/js/' . $script . $suffix . '.js', $v[1], POLYLANG_VERSION, $v[3] );
			}
		}

		wp_enqueue_style( 'polylang_admin', POLYLANG_URL . '/css/admin' . $suffix . '.css', array(), POLYLANG_VERSION );
	}

	/**
	 * Sets pll_ajax_backend on all backend ajax request
	 * The final goal is to detect if an ajax request is made on admin or frontend
	 *
	 * Takes care to various situations:
	 * when the ajax request has no options.data thanks to ScreenfeedFr
	 * see: https://wordpress.org/support/topic/ajaxprefilter-may-not-work-as-expected
	 * when options.data is a json string
	 * see: https://wordpress.org/support/topic/polylang-breaking-third-party-ajax-requests-on-admin-panels
	 * when options.data is an empty string (GET request with the method 'load')
	 * see: https://wordpress.org/support/topic/invalid-url-during-wordpress-new-dashboard-widget-operation
	 *
	 * @since 1.4
	 */
	public function admin_print_footer_scripts() {
		global $post_ID;

		$params = array( 'pll_ajax_backend' => 1 );
		if ( ! empty( $post_ID ) ) {
			$params = array_merge( $params, array( 'pll_post_id' => (int) $post_ID ) );
		}

		$str = http_build_query( $params );
		$arr = json_encode( $params );
?>
<script type="text/javascript">
	if (typeof jQuery != 'undefined') {
		(function($){
			$.ajaxPrefilter(function (options, originalOptions, jqXHR) {
				if ( -1 != options.url.indexOf( ajaxurl ) ) {
					if ( 'undefined' === typeof options.data ) {
						options.data = ( 'get' === options.type.toLowerCase() ) ? '<?php echo $str;?>' : <?php echo $arr;?>;
					} else {
						if ( 'string' === typeof options.data ) {
							if ( '' === options.data && 'get' === options.type.toLowerCase() ) {
								options.url = options.url+'&<?php echo $str;?>';
							} else {
								try {
									o = $.parseJSON(options.data);
									o = $.extend(o, <?php echo $arr;?>);
									options.data = JSON.stringify(o);
								}
								catch(e) {
									options.data = '<?php echo $str;?>&'+options.data;
								}
							}
						} else {
							options.data = $.extend(options.data, <?php echo $arr;?>);
						}
					}
				}
			});
		})(jQuery)
	}
</script><?php
	}

	/**
	 * Sets the admin current language, used to filter the content
	 *
	 * @since 2.0
	 */
	public function set_current_language() {
		$this->curlang = $this->filter_lang;

		// Edit Post
		if ( isset( $_REQUEST['pll_post_id'] ) ) {
			$this->curlang = $this->model->post->get_language( (int) $_REQUEST['pll_post_id'] );
		} elseif ( 'post.php' === $GLOBALS['pagenow'] && isset( $_GET['post'] ) && is_numeric( $_GET['post'] ) ) {
			$this->curlang = $this->model->post->get_language( (int) $_GET['post'] );
		} elseif ( 'post-new.php' === $GLOBALS['pagenow'] ) {
			$this->curlang = empty( $_GET['new_lang'] ) ? $this->pref_lang : $this->model->get_language( $_GET['new_lang'] );
		}

		// Edit Term
		// FIXME 'edit-tags.php' for backward compatibility with WP < 4.5
		elseif ( in_array( $GLOBALS['pagenow'], array( 'edit-tags.php', 'term.php' ) ) && isset( $_GET['tag_ID'] ) ) {
			$this->curlang = $this->model->term->get_language( (int) $_GET['tag_ID'] );
		} elseif ( 'edit-tags.php' === $GLOBALS['pagenow'] ) {
			if ( ! empty( $_GET['new_lang'] ) ) {
				$this->curlang = $this->model->get_language( $_GET['new_lang'] );
			} elseif ( empty( $this->curlang ) ) {
				$this->curlang = $this->pref_lang;
			}
		}

		// Ajax
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && ! empty( $_REQUEST['lang'] ) ) {
			$this->curlang = $this->model->get_language( $_REQUEST['lang'] );
		}
	}

	/**
	 * Defines the backend language and the admin language filter based on user preferences
	 *
	 * @since 1.2.3
	 */
	public function init_user() {
		// Backend locale
		add_filter( 'locale', array( $this, 'get_locale' ) );

		// Language for admin language filter: may be empty
		// $_GET['lang'] is numeric when editing a language, not when selecting a new language in the filter
		if ( ! defined( 'DOING_AJAX' ) && ! empty( $_GET['lang'] ) && ! is_numeric( $_GET['lang'] ) && current_user_can( 'edit_user', $user_id = get_current_user_id() ) ) {
			update_user_meta( $user_id, 'pll_filter_content', ( $lang = $this->model->get_language( $_GET['lang'] ) ) ? $lang->slug : '' );
		}

		$this->filter_lang = $this->model->get_language( get_user_meta( get_current_user_id(), 'pll_filter_content', true ) );

		// Set preferred language for use when saving posts and terms: must not be empty
		$this->pref_lang = empty( $this->filter_lang ) ? $this->model->get_language( $this->options['default_lang'] ) : $this->filter_lang;

		/**
		 * Filter the preferred language on amin side
		 * The preferred language is used for example to determine the language of a new post
		 *
		 * @since 1.2.3
		 *
		 * @param object $pref_lang preferred language
		 */
		$this->pref_lang = apply_filters( 'pll_admin_preferred_language', $this->pref_lang );

		$this->set_current_language();

		// Inform that the admin language has been set
		// Only if the admin language is one of the Polylang defined language
		if ( $curlang = $this->model->get_language( get_locale() ) ) {
			$GLOBALS['text_direction'] = $curlang->is_rtl ? 'rtl' : 'ltr'; // force text direction according to language setting
			/** This action is documented in frontend/choose-lang.php */
			do_action( 'pll_language_defined', $curlang->slug, $curlang );
		}
		else {
			/** This action is documented in include/class-polylang.php */
			do_action( 'pll_no_language_defined' ); // to load overriden textdomains
		}
	}

	/**
	 * Avoids parsing a tax query when all languages are requested
	 * Fixes https://wordpress.org/support/topic/notice-undefined-offset-0-in-wp-includesqueryphp-on-line-3877 introduced in WP 4.1
	 * @see the suggestion of @boonebgorges, https://core.trac.wordpress.org/ticket/31246
	 *
	 * @since 1.6.5
	 *
	 * @param array $qvars
	 * @return array
	 */
	public function request( $qvars ) {
		if ( isset( $qvars['lang'] ) && 'all' === $qvars['lang'] ) {
			unset( $qvars['lang'] );
		}

		return $qvars;
	}

	/**
	 * Get the locale based on user preference
	 *
	 * @since 0.4
	 *
	 * @param string $locale
	 * @return string modified locale
	 */
	public function get_locale( $locale ) {
		return ( $loc = get_user_meta( get_current_user_id(), 'user_lang', 'true' ) ) ? $loc : $locale;
	}

	/**
	 * Adds the languages list in admin bar for the admin languages filter
	 *
	 * @since 0.9
	 *
	 * @param object $wp_admin_bar
	 */
	public function admin_bar_menu( $wp_admin_bar ) {
		$url = ( is_ssl() ? 'https://' : 'http://' ) . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

		$all_item = (object) array(
			'slug' => 'all',
			'name' => __( 'Show all languages', 'polylang' ),
			'flag' => '<span class="ab-icon"></span>',
		);

		$selected = empty( $this->filter_lang ) ? $all_item : $this->filter_lang;

		$title = sprintf(
			'<span class="ab-label"%s>%s</span>',
			'all' === $selected->slug ? '' : sprintf( ' lang="%s"', esc_attr( $selected->get_locale( 'display' ) ) ),
			esc_html( $selected->name )
		);

		$wp_admin_bar->add_menu( array(
			'id'     => 'languages',
			'title'  => $selected->flag . $title,
			'meta'   => array( 'title' => __( 'Filters content by language', 'polylang' ) ),
		) );

		foreach ( array_merge( array( $all_item ), $this->model->get_languages_list() ) as $lang ) {
			if ( $selected->slug === $lang->slug ) {
				continue;
			}

			$wp_admin_bar->add_menu( array(
				'parent' => 'languages',
				'id'     => $lang->slug,
				'title'  => $lang->flag . esc_html( $lang->name ),
				'href'   => esc_url( add_query_arg( 'lang', $lang->slug, remove_query_arg( 'paged', $url ) ) ),
				'meta'   => 'all' === $lang->slug ? array() : array( 'lang' => esc_attr( $lang->get_locale( 'display' ) ) ),
			) );
		}
	}
}
