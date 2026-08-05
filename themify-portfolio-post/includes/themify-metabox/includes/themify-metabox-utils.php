<?php

/**
 * Takes an array of options and return a one dimensional array of all the field names
 *
 * @return array
 * @since 1.0.2
 */
function themify_metabox_get_field_names( $arr ) {
	$list = array();
	if( ! empty( $arr ) ){ 
	    foreach( $arr as $metabox ){
		    if( ! empty( $metabox['options'] ) ) {
				$options = themify_metabox_make_flat_fields_array( $metabox['options'] );
				$options = array_filter( $options, function ( $item ) {
					if ( isset( $item['name'] ) )
						return true;
					return false;
				} );
			    $list = array_merge( $list, wp_list_pluck( $options, 'name' ) );
		    }
	    }
	}

	return apply_filters( 'themify_metabox_get_field_names', array_unique( $list ), $arr );
}

/**
 * Takes an options array and returns a one-dimensional list of fields
 *
 * @return array
 * @since 1.0.2
 */
function themify_metabox_make_flat_fields_array( $arr ) {
	$list = array();
	foreach ( $arr as $field ) {
	    if ( ! isset( $field['type'] ) ) {
			continue;
		}
		if ( $field['type'] === 'multi' ) {
			foreach ( $field['meta']['fields'] as $_field ) {
				$list[] = $_field;
			}
		} elseif ( $field['type'] === 'toggle_group' ) {
			foreach ( $field['meta'] as $_field ) {
				$list[] = $_field;
			}
		} else {
			$list[] = $field;
		}
	}

	return $list;
}

/**
 * Check if assignments are applied in the current context
 *
 * @since 1.0
 */
function themify_verify_assignments( $assignments ) {
	$query_object = get_queried_object();

	if ( ! empty( $assignments['roles'] )
		// check if *any* of user's role(s) matches
		&& ! count( array_intersect( wp_get_current_user()->roles, array_keys( $assignments['roles'], true ) ) )
	) {
		return false; // bail early.
	}
	unset( $assignments['roles'] );

	if ( ! empty($assignments ) ) {
		

		if (
			( isset($assignments['general']['home']) && is_front_page())
			|| (isset( $assignments['general']['page'] ) &&  is_page() && ! is_front_page() )
			|| ( is_singular('post') && isset($assignments['general']['single']) )
			|| ( isset($assignments['general']['search']) && is_search() )
			|| ( isset($assignments['general']['author'])  && is_author())
			|| ( isset($assignments['general']['category']) && is_category())
			|| ( isset($assignments['general']['tag'])  && is_tag())
			|| ( isset($query_object->post_type,$assignments['general'][$query_object->post_type]) && is_singular() && $query_object->post_type !== 'page' && $query_object->post_type !== 'post' )
			|| ( isset($query_object->taxonomy,$assignments['general'][$query_object->taxonomy]) && is_tax())
		) {
			return true;
		} else { // let's dig deeper into more specific visibility rules
			if ( ! empty( $assignments['tax'] ) ) {
				if ( is_single() ) {
					if ( ! empty( $assignments['tax']['category_single'] ) ) {
						$categories = wp_get_post_categories( get_queried_object_id(), array( 'fields' => 'slugs' ) );
						if ( array_intersect( array_keys( $assignments['tax']['category_single'], true ), $categories ) ) {
							return true;
						}
					}
				} else {
					foreach ( $assignments['tax'] as $tax => $terms ) {
						$terms = array_keys( $terms );
						if ( ( $tax === 'category' && is_category($terms) ) || ( $tax === 'post_tag' && is_tag( $terms ) ) || ( is_tax( $tax, $terms ) )
						) {
							return true;
						}
					}
				}
			}
			if (! empty( $assignments['post_type'] ) ) {
				foreach ( $assignments['post_type'] as $post_type => $posts ) {
					$posts = array_keys( $posts );

					/* child pages have unique names based on their permalink */
					if ( $post_type === 'page' && ! empty( $query_object->post_parent ) ) {
						$post_name = str_replace( home_url(), '', get_permalink( $query_object->ID ) );
						if ( in_array( $post_name, $posts ) ) {
							return true;
						}
					}

					if (
						// Post single
						( $post_type === 'post' && is_single() && is_single( $posts ) )
						// Page view
						|| ( $post_type === 'page' && (
								( is_page( $posts ) )
								|| ( ! is_front_page() && is_home() && in_array( get_post_field( 'post_name', get_option( 'page_for_posts' ) ), $posts ,true ) ) // check for Posts page
								|| ( themify_metabox_is_shop() && in_array( get_post_field( 'post_name', themify_metabox_shop_pageId()), $posts,true  ) ) // check for Shop page
						) )
						// Custom Post Types single view check
						|| ( is_singular( $post_type ) && in_array( $query_object->post_name, $posts,true ) )
					) {
						return true;
					}
				}
			}
		}
	}
	return false;

}

/**
 * Take an array and converts it to multiple input[type="hidden"]
 *
 * @return string
 */
function themify_array_to_input( $array, $prefix = '' ) {
	$output = '';
	if (count( array_filter( array_keys( $array ), 'is_string' ) ) ) {
		foreach ( $array as $key => $value ) {
			if ( empty( $prefix ) ) {
				$name = $key;
			} else {
				$name = $prefix . '[' . $key . ']';
			}
			if ( is_array( $value ) ) {
				$output .= themify_array_to_input( $value, $name );
			} else {
				$output .= '<input type="hidden" value="' . esc_attr( $value ) . '" name="' . esc_attr( $name ) . '">';
			}
		}
	} else {
		foreach ($array as $item) {
			if ( is_array($item) ) {
				$output .= themify_array_to_input( $item, $prefix . '[]' );
			} else {
				$output .= '<input type="hidden" name="' . esc_attr( $prefix ) . '[]" value="' . esc_attr( $item ) . '">';
			}
		}
	}

	return $output;
}

/**
 * Sanitize a metabox field value based on its type.
 *
 * @param array       $field Field definition.
 * @param mixed       $value Raw submitted value.
 * @return mixed Sanitized value.
 */
function themify_metabox_sanitize_field_value( $field, $value ) {
	if ( is_array( $value ) ) {
		$sanitized = array();
		foreach ( $value as $key => $item ) {
			$sanitized[ is_string( $key ) ? sanitize_key( $key ) : $key ] = is_array( $item )
				? themify_metabox_sanitize_field_value( $field, $item )
				: sanitize_text_field( $item );
		}
		return $sanitized;
	}

	$type = isset( $field['type'] ) ? $field['type'] : 'textbox';

	switch ( $type ) {
		case 'textarea':
			return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_data( $value );
		case 'textbox':
			return current_user_can( 'unfiltered_html' ) ? sanitize_text_field( $value ) : wp_kses_data( $value );
		case 'color':
			$color = sanitize_hex_color( $value );
			return $color ? $color : sanitize_text_field( $value );
		case 'image':
		case 'audio':
		case 'video':
		case 'font':
			return esc_url_raw( $value );
		case 'checkbox':
			return 'on' === $value ? 'on' : '';
		case 'hidden':
		case 'date':
		case 'dropdown':
		case 'dropdownbutton':
		case 'radio':
		case 'layout':
		case 'image_radio':
		case 'gallery_shortcode':
		case 'query_category':
			return sanitize_text_field( $value );
		default:
			return is_string( $value ) ? sanitize_text_field( $value ) : $value;
	}
}

if ( ! function_exists( 'themify_get_file_contents' ) ) :
/**
 * Safely read an uploaded file's contents.
 *
 * @param string $file Path to uploaded temp file.
 * @return string File contents or empty string on failure.
 */
function themify_get_file_contents( $file ) {
	if ( ! is_string( $file ) || '' === $file || ! is_uploaded_file( $file ) || ! is_readable( $file ) ) {
		return '';
	}
	$contents = file_get_contents( $file );
	return false !== $contents ? $contents : '';
}
endif;

/**
 * Checks if Woocommerce plugin is active and returns the proper value
 *
 * @return bool
 */
function themify_metabox_is_woocommerce_active() {
	if(function_exists('themify_is_woocommerce_active')){
		return themify_is_woocommerce_active();
	}
	static $is = null;
	if ( $is===null ) {
		$plugin = 'woocommerce/woocommerce.php';
		include_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		return is_plugin_active( $plugin )
			// validate if $plugin actually exists, the plugin might be active however not installed.
			&& is_file( trailingslashit( WP_PLUGIN_DIR ) . $plugin );
	}
	return $is;
}

/**
 * Returns the ID of the designated Shop page in WC plugin
 *
 * @return false|int
 */
function themify_metabox_shop_pageId(){
	if(function_exists('themify_shop_pageId')){
		return themify_shop_pageId();
	}
	static $id = null;
	if ( $id === null ) {
		if ( themify_metabox_is_woocommerce_active() ) {
			$id = wc_get_page_id( 'shop' );
			if ( $id <= 0 ) { //wc bug, page id isn't from wc settings,the default should be page with slug 'shop'
				$page = get_page_by_path( 'shop' );
				$id = ! empty( $page ) ? (int) $page->ID : false;
			}
		} else {
			$id = false;
	    }
	}

	return $id;
}


function themify_metabox_is_shop(){
	if(function_exists('themify_is_shop')){
		return themify_is_shop();
	}
	return themify_metabox_is_woocommerce_active() && is_shop();
}

function themify_metabox_enque($url){//backward
	return $url;
}