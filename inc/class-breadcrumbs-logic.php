<?php
/**
 * WPSEO plugin file.
 *
 * @package WPSEO\Inc
 */

/**
 * This class handles the Breadcrumbs generation and display.
 */
class WPSEO_Breadcrumbs_Logic {

	/**
	 * @var    object    Instance of this class
	 */
	public static $instance;

	/**
	 * @var    string    Blog's show on front setting, 'page' or 'posts'
	 */
	private $show_on_front;

	/**
	 * @var    mixed    Blog's page for posts setting, page id or false
	 */
	private $page_for_posts;

	/**
	 * @var mixed    Current post object
	 */
	private $post;

	/**
	 * @var    array    Array of crumbs
	 *
	 * Each element of the crumbs array can either have one of these keys:
	 *    "id"         for post types;
	 *    "ptarchive"  for a post type archive;
	 *    "term"       for a taxonomy term.
	 * OR it consists of a predefined set of 'text', 'url' and 'allow_html'.
	 */
	private $crumbs = array();

	/**
	 * @var array    Count of the elements in the $crumbs property
	 */
	private $crumb_count = 0;

	/**
	 * @var array    Array of individual (linked) html strings created from crumbs
	 */
	private $links = array();


	/**
	 * Create the breadcrumb.
	 */
	protected function __construct() {
		$this->post           = ( isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null );
		$this->show_on_front  = get_option( 'show_on_front' );
		$this->page_for_posts = get_option( 'page_for_posts' );

		$this->set_crumbs();
		$this->prepare_links();
	}

	/**
	 * Get breadcrumb array using the singleton instance of this class.
	 *
	 * @return array Returns the breadcrumbs as an array.
	 */
	public static function breadcrumb_array() {
		if ( ! ( self::$instance instanceof self ) ) {
			self::$instance = new self();
		}

		return self::$instance->links;
	}

	/**
	 * Returns the link url for a single id.
	 *
	 * When the target is private and the user isn't allowed to access it, just return an empty string.
	 *
	 * @param int $id The target id.
	 *
	 * @return string Empty string when post isn't accessible. An URL if accessible.
	 */
	protected function get_link_url_for_id( $id ) {
		$post_status = get_post_status( $id );
		$post_type   = get_post_type_object( get_post_type( $id ) );

		// Don't link if item is private and user does't have capability to read it.
		if ( $post_status === 'private' && $post_type !== null && ! current_user_can( $post_type->cap->read_private_posts ) ) {
			return '';
		}

		$url = get_permalink( $id );
		if ( $url === false ) {
			return '';
		}

		return $url;
	}

	/**
	 * Get a term's parents.
	 *
	 * @param    object $term Term to get the parents for.
	 *
	 * @return    array
	 */
	private function get_term_parents( $term ) {
		$tax     = $term->taxonomy;
		$parents = array();
		while ( $term->parent !== 0 ) {
			$term      = get_term( $term->parent, $tax );
			$parents[] = $term;
		}

		return array_reverse( $parents );
	}

	/**
	 * Find the deepest term in an array of term objects.
	 *
	 * @param array $terms Terms set.
	 *
	 * @return object
	 */
	private function find_deepest_term( $terms ) {
		/*
		 * Let's find the deepest term in this array, by looping through and then
		 * unsetting every term that is used as a parent by another one in the array.
		 */
		$terms_by_id = array();
		foreach ( $terms as $term ) {
			$terms_by_id[ $term->term_id ] = $term;
		}
		foreach ( $terms as $term ) {
			unset( $terms_by_id[ $term->parent ] );
		}
		unset( $term );

		/*
		 * As we could still have two subcategories, from different parent categories,
		 * let's pick the one with the lowest ordered ancestor.
		 */
		$parents_count = 0;
		$term_order    = 9999; // Because ASC.
		reset( $terms_by_id );
		$deepest_term = current( $terms_by_id );
		foreach ( $terms_by_id as $term ) {
			$parents = $this->get_term_parents( $term );

			if ( count( $parents ) >= $parents_count ) {

				$parents_count = count( $parents );

				$parent_order = 9999; // Set default order.
				foreach ( $parents as $parent ) {
					if ( $parent->parent === 0 && isset( $parent->term_order ) ) {
						$parent_order = $parent->term_order;
					}
				}
				unset( $parent );

				// Check if parent has lowest order.
				if ( $parent_order < $term_order ) {
					$term_order   = $parent_order;
					$deepest_term = $term;
				}
			}
		}

		return $deepest_term;
	}

	/**
	 * Retrieve the hierachical ancestors for the current 'post'.
	 *
	 * @return array
	 */
	private function get_post_ancestors() {
		$ancestors = array();

		if ( isset( $this->post->ancestors ) ) {
			if ( is_array( $this->post->ancestors ) ) {
				$ancestors = array_values( $this->post->ancestors );
			} else {
				$ancestors = array( $this->post->ancestors );
			}
		} elseif ( isset( $this->post->post_parent ) ) {
			$ancestors = array( $this->post->post_parent );
		}

		/**
		 * Filter: Allow changing the ancestors for the Yoast SEO breadcrumbs output.
		 *
		 * @api array $ancestors Ancestors.
		 */
		$ancestors = apply_filters( 'wp_seo_get_bc_ancestors', $ancestors );

		if ( ! is_array( $ancestors ) ) {
			trigger_error('The return value for the "wp_seo_get_bc_ancestors" filter should be an array.',
				E_USER_WARNING);
			$ancestors = (array) $ancestors;
		}

		// Reverse the order so it's oldest to newest.
		$ancestors = array_reverse( $ancestors );

		return $ancestors;
	}

	/**
	 * Determine the crumbs which should form the breadcrumb.
	 */
	private function set_crumbs() {
		/** @var WP_Query $wp_query */
		global $wp_query;

		$this->maybe_add_home_crumb();
		$this->maybe_add_blog_crumb();

		// Ignore coding standards for empty if statement.
		// @codingStandardsIgnoreStart
		if ( $this->is_front_page() ) {
			// Do nothing.
			// @codingStandardsIgnoreEnd
		} elseif ( $this->show_on_front === 'page' && is_home() ) {
			$this->add_blog_crumb();
		} elseif ( is_singular() ) {
			$this->maybe_add_pt_archive_crumb_for_post();

			if ( isset( $this->post->post_parent ) && 0 === $this->post->post_parent ) {
				$this->maybe_add_taxonomy_crumbs_for_post();
			}
			if ( isset( $this->post->post_parent ) && $this->post->post_parent !== 0 ) {
				$this->add_post_ancestor_crumbs();
			}

			if ( isset( $this->post->ID ) ) {
				$this->add_single_post_crumb( $this->post->ID );
			}
		} elseif ( is_post_type_archive() ) {
			$post_type = $wp_query->get( 'post_type' );

			if ( $post_type && is_string( $post_type ) ) {
				$this->add_ptarchive_crumb( $post_type );
			}
		} elseif ( is_tax() || is_tag() || is_category() ) {
			$this->add_crumbs_for_taxonomy();
		} elseif ( is_date() ) {
			if ( is_day() ) {
				$this->add_linked_month_year_crumb();
				$this->add_date_crumb();
			} elseif ( is_month() ) {
				$this->add_month_crumb();
			} elseif ( is_year() ) {
				$this->add_year_crumb();
			}
		} elseif ( is_author() ) {
			$user         = $wp_query->get_queried_object();
			$display_name = get_the_author_meta( 'display_name', $user->ID );
			$this->add_predefined_crumb(
				WPSEO_Options::get( 'breadcrumbs-archiveprefix' ) . ' ' . $display_name,
				null,
				true
			);
		} elseif ( is_search() ) {
			$this->add_predefined_crumb(
				WPSEO_Options::get( 'breadcrumbs-searchprefix' ) . ' "' . esc_html( get_search_query() ) . '"',
				null,
				true
			);
		} elseif ( is_404() ) {
			$this->add_predefined_crumb(
				WPSEO_Options::get( 'breadcrumbs-404crumb' ),
				null,
				true
			);
		}

		/**
		 * Filter: 'wpseo_breadcrumb_links' - Allow the developer to filter the Yoast SEO breadcrumb links, add to them, change order, etc.
		 *
		 * @api array $crumbs The crumbs array.
		 */
		$this->crumbs = apply_filters( 'wpseo_breadcrumb_links', $this->crumbs );

		$this->crumb_count = count( $this->crumbs );
	}

	/**
	 * Determine whether we are on the front page of the site.
	 *
	 * @return bool
	 */
	private function is_front_page() {
		if ( $this->show_on_front === 'page' && is_front_page() ) {
			return true;
		}

		if ( $this->show_on_front === 'posts' && is_home() ) {
			return true;
		}

		return false;
	}

	/**
	 * Add a single id based crumb to the crumbs property.
	 *
	 * @param int $id Post ID.
	 */
	private function add_single_post_crumb( $id ) {
		$this->crumbs[] = array(
			'id' => $id,
		);
	}

	/**
	 * Add a term based crumb to the crumbs property.
	 *
	 * @param object $term Term data object.
	 */
	private function add_term_crumb( $term ) {
		$this->crumbs[] = array(
			'term' => $term,
		);
	}

	/**
	 * Add a ptarchive based crumb to the crumbs property.
	 *
	 * @param string $pt Post type.
	 */
	private function add_ptarchive_crumb( $pt ) {
		$this->crumbs[] = array(
			'ptarchive' => $pt,
		);
	}

	/**
	 * Add a predefined crumb to the crumbs property.
	 *
	 * @param string $text Text string.
	 * @param string $url URL string.
	 * @param bool   $allow_html Flag to allow HTML.
	 */
	private function add_predefined_crumb( $text, $url = '', $allow_html = false ) {
		$this->crumbs[] = array(
			'text'       => $text,
			'url'        => $url,
			'allow_html' => $allow_html,
		);
	}

	/**
	 * Add Homepage crumb to the crumbs property.
	 */
	private function maybe_add_home_crumb() {
		if ( WPSEO_Options::get( 'breadcrumbs-home' ) !== '' ) {
			$this->add_predefined_crumb(
				WPSEO_Options::get( 'breadcrumbs-home' ),
				WPSEO_Utils::home_url(),
				true
			);
		}
	}

	/**
	 * Add Blog crumb to the crumbs property.
	 */
	private function add_blog_crumb() {
		$this->add_single_post_crumb( $this->page_for_posts );
	}

	/**
	 * Add Blog crumb to the crumbs property for single posts where Home != blogpage.
	 */
	private function maybe_add_blog_crumb() {
		if ( ( 'page' === $this->show_on_front && 'post' === get_post_type() ) && ( ! is_home() && ! is_search() ) ) {
			if ( $this->page_for_posts && WPSEO_Options::get( 'breadcrumbs-display-blog-page' ) === true ) {
				$this->add_blog_crumb();
			}
		}
	}

	/**
	 * Add ptarchive crumb to the crumbs property if it can be linked to, for a single post.
	 */
	private function maybe_add_pt_archive_crumb_for_post() {
		// Never do this for the Post type archive for posts, as that would break `maybe_add_blog_crumb`.
		if ( $this->post->post_type === 'post' ) {
			return;
		}
		if ( isset( $this->post->post_type ) && get_post_type_archive_link( $this->post->post_type ) ) {
			$this->add_ptarchive_crumb( $this->post->post_type );
		}
	}

	/**
	 * Add taxonomy crumbs to the crumbs property for a single post.
	 */
	private function maybe_add_taxonomy_crumbs_for_post() {
		if ( WPSEO_Options::get( 'post_types-' . $this->post->post_type . '-maintax' ) && (string) WPSEO_Options::get( 'post_types-' . $this->post->post_type . '-maintax' ) !== '0' ) {
			$main_tax = WPSEO_Options::get( 'post_types-' . $this->post->post_type . '-maintax' );
			if ( isset( $this->post->ID ) ) {
				$terms = get_the_terms( $this->post, $main_tax );

				if ( is_array( $terms ) && $terms !== array() ) {

					$primary_term = new WPSEO_Primary_Term( $main_tax, $this->post->ID );
					if ( $primary_term->get_primary_term() ) {
						$breadcrumb_term = get_term( $primary_term->get_primary_term(), $main_tax );
					} else {
						$breadcrumb_term = $this->find_deepest_term( $terms );
					}

					if ( is_taxonomy_hierarchical( $main_tax ) && $breadcrumb_term->parent !== 0 ) {
						$parent_terms = $this->get_term_parents( $breadcrumb_term );
						foreach ( $parent_terms as $parent_term ) {
							$this->add_term_crumb( $parent_term );
						}
					}

					$this->add_term_crumb( $breadcrumb_term );
				}
			}
		}
	}

	/**
	 * Add hierarchical ancestor crumbs to the crumbs property for a single post.
	 */
	private function add_post_ancestor_crumbs() {
		$ancestors = $this->get_post_ancestors();
		if ( is_array( $ancestors ) && $ancestors !== array() ) {
			foreach ( $ancestors as $ancestor ) {
				$this->add_single_post_crumb( $ancestor );
			}
		}
	}

	/**
	 * Add taxonomy parent crumbs to the crumbs property for a taxonomy.
	 */
	private function add_crumbs_for_taxonomy() {
		$term = $GLOBALS['wp_query']->get_queried_object();

		// @todo adjust function name!!
		$this->maybe_add_preferred_term_parent_crumb( $term );

		$this->maybe_add_term_parent_crumbs( $term );

		$this->add_term_crumb( $term );
	}

	/**
	 * Add parent taxonomy crumb based on user defined preference.
	 *
	 * @param object $term Term data object.
	 */
	private function maybe_add_preferred_term_parent_crumb( $term ) {
		if ( WPSEO_Options::get( 'taxonomy-' . $term->taxonomy . '-ptparent' ) && (string) WPSEO_Options::get( 'taxonomy-' . $term->taxonomy . '-ptparent' ) !== '0' ) {
			if ( 'post' === WPSEO_Options::get( 'taxonomy-' . $term->taxonomy . '-ptparent' ) && $this->show_on_front === 'page' ) {
				if ( $this->page_for_posts ) {
					$this->add_blog_crumb();
				}

				return;
			}
			$this->add_ptarchive_crumb( WPSEO_Options::get( 'taxonomy-' . $term->taxonomy . '-ptparent' ) );
		}
	}

	/**
	 * Add parent taxonomy crumbs to the crumb property for hierachical taxonomy.
	 *
	 * @param object $term Term data object.
	 */
	private function maybe_add_term_parent_crumbs( $term ) {
		if ( is_taxonomy_hierarchical( $term->taxonomy ) && $term->parent !== 0 ) {
			foreach ( $this->get_term_parents( $term ) as $parent_term ) {
				$this->add_term_crumb( $parent_term );
			}
		}
	}

	/**
	 * Add month-year crumb to crumbs property.
	 */
	private function add_linked_month_year_crumb() {
		$this->add_predefined_crumb(
			$GLOBALS['wp_locale']->get_month( get_query_var( 'monthnum' ) ) . ' ' . get_query_var( 'year' ),
			get_month_link( get_query_var( 'year' ), get_query_var( 'monthnum' ) )
		);
	}

	/**
	 * Add (non-link) month crumb to crumbs property.
	 */
	private function add_month_crumb() {
		$this->add_predefined_crumb(
			WPSEO_Options::get( 'breadcrumbs-archiveprefix' ) . ' ' . esc_html( single_month_title( ' ', false ) ),
			null,
			true
		);
	}

	/**
	 * Add (non-link) year crumb to crumbs property.
	 */
	private function add_year_crumb() {
		$this->add_predefined_crumb(
			WPSEO_Options::get( 'breadcrumbs-archiveprefix' ) . ' ' . esc_html( get_query_var( 'year' ) ),
			null,
			true
		);
	}

	/**
	 * Add (non-link) date crumb to crumbs property.
	 *
	 * @param string $date Optional date string, defaults to post's date.
	 */
	private function add_date_crumb( $date = null ) {
		if ( is_null( $date ) ) {
			$date = get_the_date();
		} else {
			$date = mysql2date( get_option( 'date_format' ), $date, true );
			$date = apply_filters( 'get_the_date', $date, '' );
		}

		$this->add_predefined_crumb(
			WPSEO_Options::get( 'breadcrumbs-archiveprefix' ) . ' ' . esc_html( $date ),
			null,
			true
		);
	}


	/**
	 * Take the crumbs array and convert each crumb to a single breadcrumb string.
	 *
	 * @link http://support.google.com/webmasters/bin/answer.py?hl=en&answer=185417 Google documentation on RDFA
	 */
	private function prepare_links() {
		if ( ! is_array( $this->crumbs ) || $this->crumbs === array() ) {
			return;
		}

		foreach ( $this->crumbs as $index => $crumb ) {
			$link_info = $crumb; // Keep pre-set url/text combis.

			if ( isset( $crumb['id'] ) ) {
				$link_info = $this->get_link_info_for_id( $crumb['id'] );
			}
			if ( isset( $crumb['term'] ) ) {
				$link_info = $this->get_link_info_for_term( $crumb['term'] );
			}
			if ( isset( $crumb['ptarchive'] ) ) {
				$link_info = $this->get_link_info_for_ptarchive( $crumb['ptarchive'] );
			}

			/**
			 * Filter: 'wpseo_breadcrumb_single_link_info' - Allow developers to filter the Yoast SEO Breadcrumb link information.
			 *
			 * @api array $link_info The breadcrumb link information.
			 *
			 * @param int   $index The index of the breadcrumb in the list.
			 * @param array $crumbs The complete list of breadcrumbs.
			 */
			$link_info = apply_filters( 'wpseo_breadcrumb_single_link_info', $link_info, $index, $this->crumbs );

			$this->links[ $index ] = $link_info;
		}
	}

	/**
	 * Retrieve link url and text based on post id
	 *
	 * @param int $id Post ID.
	 *
	 * @return array Array of link text and url
	 */
	private function get_link_info_for_id( $id ) {
		$link           = array();
		$link['url']  = $this->get_link_url_for_id( $id );
		$link['text'] = WPSEO_Meta::get_value( 'bctitle', $id );

		if ( $link['text'] === '' ) {
			$link['text'] = wp_strip_all_tags( get_the_title( $id ), true );
		}

		/**
		 * Filter: 'wp_seo_get_bc_title' - Allow developer to filter the Yoast SEO Breadcrumb title.
		 *
		 * @deprecated 5.8
		 * @api string $link_text The Breadcrumb title text.
		 *
		 * @param int $link_id The post ID.
		 */
		$link['text'] = apply_filters_deprecated('wp_seo_get_bc_title', array( $link['text'], $id ), 'WPSEO 5.8',
			'wpseo_breadcrumb_single_link_info');

		return $link;
	}

	/**
	 * Retrieve link url and text based on term object.
	 *
	 * @param object $term Term object.
	 *
	 * @return array Array of link text and url.
	 */
	private function get_link_info_for_term( $term ) {
		$link = array();

		$bctitle = WPSEO_Taxonomy_Meta::get_term_meta( $term, $term->taxonomy, 'bctitle' );
		if ( ! is_string( $bctitle ) || $bctitle === '' ) {
			$bctitle = $term->name;
		}

		$link['url']  = get_term_link( $term );
		$link['text'] = $bctitle;

		return $link;
	}

	/**
	 * Retrieve link url and text based on post type.
	 *
	 * @param string $pt Post type.
	 *
	 * @return array Array of link text and url.
	 */
	private function get_link_info_for_ptarchive( $pt ) {
		$link          = array();
		$archive_title = '';

		if ( WPSEO_Options::get( 'bctitle-ptarchive-' . $pt, '' ) !== '' ) {

			$archive_title = WPSEO_Options::get( 'bctitle-ptarchive-' . $pt );
		} else {
			$post_type_obj = get_post_type_object( $pt );
			if ( is_object( $post_type_obj ) ) {
				if ( isset( $post_type_obj->label ) && $post_type_obj->label !== '' ) {
					$archive_title = $post_type_obj->label;
				} elseif ( isset( $post_type_obj->labels->menu_name ) && $post_type_obj->labels->menu_name !== '' ) {
					$archive_title = $post_type_obj->labels->menu_name;
				} else {
					$archive_title = $post_type_obj->name;
				}
			}
		}

		$link['url']  = get_post_type_archive_link( $pt );
		$link['text'] = $archive_title;

		return $link;
	}
}
