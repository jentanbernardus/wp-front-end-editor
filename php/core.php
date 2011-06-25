<?php

abstract class FEE_Core {
	static $options;

	private static $fields;
	private static $active_fields;
	private static $instances = array();

	private static $plugin_url;
	private static $nonce = 'front-editor';

	static function init( $options ) {
		self::$options = $options;

		add_action( 'wp_ajax_front-end-editor', array( __CLASS__, 'ajax_response' ) );
		add_action( 'wp_ajax_front-end-editor-link-query', array( __CLASS__, 'ajax_link_query_response' ) );
		
		add_action( 'template_redirect', array( __CLASS__, '_init' ) );
		// TODO: Add equivalent hook for BuddyPress
	}

	static function _init() {
		if ( !is_user_logged_in() || apply_filters( 'front_end_editor_disable', false ) ) {
			return;
		}

		self::make_instances();

		if ( self::$options->rich ) {
			FEE_AlohaEditor::enqueue();
		}

		add_action( 'wp_head', array( __CLASS__, 'add_filters' ), 100 );
		add_action( 'wp_footer', array( __CLASS__, 'scripts' ) );
	}

	static function scripts() {
		$wrapped = array_keys( FEE_Field_Base::get_wrapped() );

		if ( empty( $wrapped ) ) {
			return;
		}

		// Prepare data
		$data = array(
			'edit_text' => __( 'Edit', 'front-end-editor' ),
			'save_text' => __( 'Save', 'front-end-editor' ),
			'cancel_text' => __( 'Cancel', 'front-end-editor' ),
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'spinner' => admin_url( 'images/loading.gif' ),
			'nonce' => wp_create_nonce( self::$nonce ),
		);

		$url = plugins_url( 'js/', FRONT_END_EDITOR_MAIN_FILE );

		$dev = defined( 'SCRIPT_DEBUG' ) ? '.dev' : '';

		$css_dependencies = array();

		// Autosuggest
		if ( in_array( 'terminput', $wrapped ) ) {
			$data['suggest'] = array(
				'src' => self::get_src('suggest')
			);
		}

		// Thickbox
		if ( count( array_intersect( array( 'image', 'thumbnail', 'rich' ), $wrapped ) ) ) {
			$data['image'] = array(
				'url' => admin_url( 'media-upload.php?post_id=0&type=image&editable_image=1&TB_iframe=true&width=640' ),
				'change' => __( 'Change Image', 'front-end-editor' ),
				'insert' => __( 'Insert Image', 'front-end-editor' ),
				'revert' => '(' . __( 'Clear', 'front-end-editor' ) . ')',
				'tb_close' => get_bloginfo( 'wpurl' ) . '/wp-includes/js/thickbox/tb-close.png',
			);

			$css_dependencies[] = 'thickbox';
			$js_dependencies[] = 'thickbox';
		}

		// Core script
		if ( defined('SCRIPT_DEBUG') ) {
			wp_register_script( 'fee-class', $url . "class.js", array(), '1.0', true );
			$js_dependencies[] = 'fee-class';

			wp_register_script( 'fee-core', $url . "core.dev.js", $js_dependencies, FRONT_END_EDITOR_VERSION, true );
			$js_dependencies[] = 'fee-core';

			foreach ( glob( dirname( FRONT_END_EDITOR_MAIN_FILE ) . '/js/fields/*.js' ) as $file ) {
				$file = basename( $file );
				wp_register_script( "fee-fields-$file", $url . "fields/$file", array( 'fee-core' ), FRONT_END_EDITOR_VERSION, true );
				$js_dependencies[] = "fee-fields-$file";
			}
		} else {
			wp_register_script( 'fee-editor', $url . "editor.js", $js_dependencies, FRONT_END_EDITOR_VERSION, true );
			$js_dependencies[] = 'fee-editor';
		}

		wp_register_style( 'fee-editor', plugins_url( "css/editor$dev.css", FRONT_END_EDITOR_MAIN_FILE ), $css_dependencies, FRONT_END_EDITOR_VERSION );

?>
<script type='text/javascript'>
var FrontEndEditor = {};
FrontEndEditor.data = <?php echo json_encode( $data ) ?>;
</script>
<?php
		scbUtil::do_scripts( $js_dependencies );
		scbUtil::do_styles( 'fee-editor' );

		do_action( 'front_end_editor_loaded', $wrapped );
	}

	private static function get_src( $handle ) {
		global $wp_scripts;

		if ( !is_object( $wp_scripts ) ) {
			$wp_scripts = new WP_Scripts;
		}

		return get_bloginfo('wpurl') . $wp_scripts->registered[$handle]->src;
	}

	// Register a new editable field
	static function register() {
		list ( $filter, $args ) = func_get_arg( 0 );

		if ( !class_exists( $args['class'] ) ) {
			trigger_error( "Class '{$args['class']}' does not exist", E_USER_WARNING );
			return false;
		}

		if ( !is_subclass_of( $args['class'], 'FEE_Field_Base' ) ) {
			trigger_error( "{$args['class']} must be a subclass of 'FEE_Field_Base", E_USER_WARNING );
			return false;
		}

		if ( isset( self::$fields[$filter] ) )
			$args = wp_parse_args( $args, self::$fields[$filter] );
		else
			$args = wp_parse_args( $args, array(
				'title' => ucfirst( str_replace( '_', ' ', $filter ) ),
				'type' => 'input',
				'priority' => 11,
				'argc' => 1
			) );

		self::$fields[$filter] = $args;

		return true;
	}

	static function get_title( $filter ) {
		return self::$fields[$filter]['title'];
	}

	static function make_instances() {
		$disabled = (array) self::$options->disabled;

		self::$active_fields = array();
		foreach ( self::get_fields() as $key => $data )
			if ( !in_array( $key, $disabled ) )
				self::$active_fields[ $key ] = $data;

		foreach ( self::$active_fields as $filter => $args ) {
			extract( $args );

			self::$instances[ $filter ] = new $class( $filter, $type );
		}
	}

	static function add_filters() {
		foreach ( self::$active_fields as $filter => $args ) {
			extract( $args );

			if ( empty( $title ) ) {
				continue;
			}	

			$instance = self::$instances[ $filter ];

			add_filter( $filter, array( $instance, 'wrap' ), $priority, $argc );
		}
	}

	static function get_fields() {
		// Safe hook for new editable fields to be registered
		if ( !did_action( 'front_end_editor_fields' ) ) {
			do_action( 'front_end_editor_fields' );
		}

		return self::$fields;
	}

	static function get_args( $filter ) {
		return self::$fields[ $filter ];
	}
	
	/**
	 * Handler for the aloha internal links plugin. 
	 * This handler will retrive wordpress posts that match the given query.
	 */
	static function ajax_link_query_response() {

		// Is user trusted?
		check_ajax_referer( self::$nonce, 'nonce' );
		$searchQuery  = $_POST['query'];
		
		$JSONresultSet = array();
		//TODO find a way to add the searchQuery into the 
		$WPresultSet = query_posts('&order=ASC');
		
		foreach ( $WPresultSet as $post) {
			//TODO Add preliminary check here
			array_push($JSONresultSet, array('id'=>$post->id,'name'=>$post->post_title, 'url'=>$post->guid , 'type'=>'wp_post', 'repositoryId'=>'wpInternalLinks'));
		}
		
		$result = array("results"=>$JSONresultSet);
		die( json_encode( $result ) );
		
	}

	/**
	 * Handler for front-end-editor post specific ajax requests like save,get 
	 */
	static function ajax_response() {
		// Is user trusted?
		check_ajax_referer( self::$nonce, 'nonce' );

		extract( scbUtil::array_extract( $_POST, array( 'callback', 'data' ) ) );

		$filter = $data['filter'];

		self::make_instances();

		// Is the current field defined?
		if ( !$instance = self::$instances[ $filter ] ) {
			die( -1 );
		}

		// Does the user have the right to do this?
		if ( !$instance->check( $data ) || !$instance->allow( $data ) ) {
			die( -1 );
		}

		$args = self::get_args( $filter );
		try {
			
			
			if ( 'save' == $callback ) {
				$content = stripslashes_deep( $_POST['content'] );
				$result = $instance->save( $data, $content );
				$result = @apply_filters( $filter, $result );
			} elseif ( 'get' == $callback ) {
				$result = (string) $instance->get( $data );

				if ( 'rich' == $data['type'] ) {
					$result = wpautop( $result );
				}
				$result = array( 'content' => $result );
			}
			
			
			
		} catch ( Exception $e ) {
			$result = array( 'error' => $e->getMessage() );
		}

		die( json_encode( $result ) );
	}
}

/**
 * Registers a new editable field
 *
 * @param string $filter
 * @param array $args(
 * 	'class' => string The name of the field handler class ( mandatory )
 * 	'title' => string The user-friendly title ( optional )
 * 	'type' => string: 'input' | 'textarea' | 'rich' | 'image' ( default: input )
 * 	'priority' => integer ( default: 11 )
 * 	'argc' => integer ( default: 1 )
 * )
 */
function fee_register_field() {
	$args = func_get_args();

	return FEE_Core::register( $args );
}

