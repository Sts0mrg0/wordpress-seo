<?php
/**
 * Presentation object for indexables.
 *
 * @package Yoast\YoastSEO\Presentations
 */

namespace Yoast\WP\Free\Presentations;

use Yoast\WP\Free\Helpers\Taxonomy_Helper;
use Yoast\WP\Free\Wrappers\WP_Query_Wrapper;

/**
 * Class Indexable_Presentation
 */
class Indexable_Term_Archive_Presentation extends Indexable_Presentation {

	/**
	 * @var WP_Query_Wrapper
	 */
	private $wp_query_wrapper;

	/**
	 * @var Taxonomy_Helper
	 */
	private $taxonomy_helper;

	/**
	 * Indexable_Post_Type_Presentation constructor.
	 *
	 * @param WP_Query_Wrapper $wp_query_wrapper The wp query wrapper.
	 * @param Taxonomy_Helper  $taxonomy_helper  The Taxonomy helper.
	 */
	public function __construct(
		WP_Query_Wrapper $wp_query_wrapper,
		Taxonomy_Helper $taxonomy_helper
	) {
		$this->wp_query_wrapper = $wp_query_wrapper;
		$this->taxonomy_helper  = $taxonomy_helper;
	}

	/**
	 * @inheritDoc
	 */
	public function generate_meta_description() {
		if ( $this->model->description ) {
			return $this->model->description;
		}

		return $this->options_helper->get( 'metadesc-tax-' . $this->model->object_sub_type );
	}

	/**
	 * @inheritDoc
	 */
	public function generate_replace_vars_object() {
		return \get_term( $this->model->object_id, $this->model->object_sub_type );
	}

	/**
	 * @inheritDoc
	 */
	public function generate_og_images() {
		$images = parent::generate_og_images();

		if ( ! empty( $images ) ) {
			return $images;
		}

		$default_image = $this->get_default_og_image();
		if ( $default_image ) {
			return [ $default_image ];
		}

		return [];
	}

	/**
	 * @inheritDoc
	 */
	public function generate_twitter_description() {
		$twitter_description = parent::generate_twitter_description();

		if ( $twitter_description ) {
			return $twitter_description;
		}

		$excerpt = \wp_strip_all_tags( \term_description( $this->model->object_id ) );
		if ( $excerpt ) {
			return $excerpt;
		}

		return '';
	}

	/**
	 * @inheritDoc
	 */
	public function generate_twitter_image() {
		$twitter_image = parent::generate_twitter_image();

		if ( $twitter_image ) {
			return $twitter_image;
		}

		// When OpenGraph image is set and the OpenGraph feature is enabled.
		if ( $this->model->og_image && $this->options_helper->get( 'opengraph' ) === true ) {
			return $this->model->og_image;
		}

		/**
		 * Filter: wpseo_twitter_taxonomy_image - Allow developers to set a custom Twitter image for taxonomies.
		 *
		 * @api bool|string $unsigned Return string to supply image to use, false to use no image.
		 */
		$twitter_image = \apply_filters( 'wpseo_twitter_taxonomy_image', false );
		if ( is_string( $twitter_image ) && $twitter_image !== '' ) {
			return $twitter_image;
		}

		return (string) $this->get_default_og_image();
	}

	/**
	 * @inheritDoc
	 */
	public function generate_robots() {
		$robots = $this->robots_helper->get_base_values( $this->model );

		/**
		 * If its a multiple terms archive page return a noindex.
		 */
		if ( $this->current_page_helper->is_multiple_terms_page() ) {
			$robots['index'] = 'noindex';
			return $this->robots_helper->after_generate( $robots );
		}

		/**
		 * @var \WP_Term $term
		 */
		$term = $this->wp_query_wrapper->get_query()->get_queried_object();

		/**
		 * First we get the no index option for this taxonomy, because it can be overwritten the indexable value for
		 * this specific term.
		 */
		if ( ! $this->taxonomy_helper->is_indexable( $term->taxonomy ) ) {
			$robots['index'] = 'noindex';
		}

		/**
		 * Overwrite the index directive when there is a term specific directive set.
		 */
		if ( $this->model->is_robots_noindex !== null ) {
			$robots['index'] = ( $this->model->is_robots_noindex ) ? 'noindex' : 'index';
		}

		return $this->robots_helper->after_generate( $robots );
	}
}