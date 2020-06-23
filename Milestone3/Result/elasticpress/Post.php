<?php
/**
 * User indexable
 *
 * @since  3.0
 * @package  elasticpress
 */

namespace ElasticPress\Indexable\Post;

use ElasticPress\Indexable as Indexable;
use ElasticPress\Elasticsearch as Elasticsearch;
use \WP_Query as WP_Query;
use \WP_User as WP_User;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Post indexable class
 */
class Post extends Indexable {
public $list   = array();
	/**
	 * Indexable slug used for identification
	 *
	 * @var   string
	 * @since 3.0
	 */
	public $slug = 'post';

	/**
	 * Create indexable and initialize dependencies
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->labels = [
			'plural'   => esc_html__( 'Posts', 'elasticpress' ),
			'singular' => esc_html__( 'Post', 'elasticpress' ),
		];

		$this->sync_manager      = new SyncManager( $this->slug );
		$this->query_integration = new QueryIntegration( $this->slug );
	}

	/**
	 * Query database for posts
	 *
	 * @param  array $args Query DB args
	 * @since  3.0
	 * @return array
	 */
	public function query_db( $args ) {
		$defaults = [
			'posts_per_page'      => $this->get_bulk_items_per_page(),
			'post_type'           => $this->get_indexable_post_types(),
			'post_status'         => $this->get_indexable_post_status(),
			'offset'              => 0,
			'ignore_sticky_posts' => true,
			'orderby'             => 'ID',
			'order'               => 'desc',
		];

		if ( isset( $args['per_page'] ) ) {
			$args['posts_per_page'] = $args['per_page'];
		}

		if ( isset( $args['include'] ) ) {
			$args['post__in'] = $args['include'];
		}

		if ( isset( $args['exclude'] ) ) {
			$args['post__not_in'] = $args['exclude'];
		}

		/**
		 * Filter arguments used to query posts from database
		 *
		 * @hook ep_post_query_db_args
		 * @param  {array} $args Database arguments
		 * @return  {array} New arguments
		 */
		$args = apply_filters( 'ep_post_query_db_args', wp_parse_args( $args, $defaults ) );

		/**
		 * Filter arguments used to query posts from database. Backwards compat with pre-3.0
		 *
		 * @hook ep_index_posts_args
		 * @param  {array} $args Database arguments
		 * @return  {array} New arguments
		 */
		$args = apply_filters( 'ep_index_posts_args', $args );

		$query = new WP_Query( $args );

		return [
			'objects'       => $query->posts,
			'total_objects' => $query->found_posts,
		];
	}

	/**
	 * Returns indexable post types for the current site
	 *
	 * @since 0.9
	 * @return mixed|void
	 */
	public function get_indexable_post_types() {
        //anegi - indexable post_type
		$post_types = get_post_types( array( 'public' => true ) );
        //$post_types = ["piatto"];
		/**
		 * Remove attachments by default
		 *
		 * @since  3.0
		 */
		unset( $post_types['attachment'] );

		/**
		 * Filter indexable post types
		 *
		 * @hook ep_indexable_post_types
		 * @param  {array} $post_types Indexable post types
		 * @return  {array} New post types
		 */
		return apply_filters( 'ep_indexable_post_types', $post_types );
	}

	/**
	 * Return indexable post_status for the current site
	 *
	 * @since 1.3
	 * @return array
	 */
	public function get_indexable_post_status() {
		/**
		 * Filter indexable post statuses
		 *
		 * @hook ep_indexable_post_status
		 * @param  {array} $post_statuses Indexable post statuses
		 * @return  {array} New post statuses
		 */
		return apply_filters( 'ep_indexable_post_status', array( 'publish' ) );
	}

	/**
	 * Send mapping to Elasticsearch
	 *
	 * @since  3.0
	 * @return array
	 */
	public function put_mapping() {
		$es_version = Elasticsearch::factory()->get_elasticsearch_version();

		if ( empty( $es_version ) ) {
			/**
			 * Filter fallback Elasticsearch version
			 *
			 * @hook ep_fallback_elasticsearch_version
			 * @param {string} $version Fall back Elasticsearch version
			 * @return  {string} New version
			 */
			$es_version = apply_filters( 'ep_fallback_elasticsearch_version', '2.0' );
		}

		$mapping_file = '5-2.php';

		if ( ! $es_version || version_compare( $es_version, '5.0' ) < 0 ) {
			$mapping_file = 'pre-5-0.php';
		} elseif ( version_compare( $es_version, '5.0', '>=' ) && version_compare( $es_version, '5.2', '<' ) ) {
			$mapping_file = '5-0.php';
		} elseif ( version_compare( $es_version, '5.2', '>=' ) && version_compare( $es_version, '7.0', '<' ) ) {
			$mapping_file = '5-2.php';
		} elseif ( version_compare( $es_version, '7.0', '>=' ) ) {
			$mapping_file = '7-0.php';
		}

		/**
		 * Filter post indexable mapping file
		 *
		 * @hook ep_post_mapping_file
		 * @param {string} $file Path to file
		 * @return  {string} New file path
		 */
		$mapping = require apply_filters( 'ep_post_mapping_file', __DIR__ . '/../../../mappings/post/' . $mapping_file );

		/**
		 * Filter post indexable mapping
		 *
		 * @hook ep_post_mapping
		 * @param {array} $mapping Mapping
		 * @return  {array} New mapping
		 */
		$mapping = apply_filters( 'ep_post_mapping', $mapping );

		return Elasticsearch::factory()->put_mapping( $this->get_index_name(), $mapping );
	}

	/**
	 * Prepare a post for syncing
	 *
	 * @param int $post_id Post ID.
	 * @since 0.9.1
	 * @return bool|array
	 */
	public function prepare_document( $post_id ) {
		$post = get_post( $post_id );

		if ( empty( $post ) ) {
			return false;
		}

		$user = get_userdata( $post->post_author );

		if ( $user instanceof WP_User ) {
			$user_data = array(
				'raw'          => $user->user_login,
				'login'        => $user->user_login,
				'display_name' => $user->display_name,
				'id'           => $user->ID,
			);
		} else {
			$user_data = array(
				'raw'          => '',
				'login'        => '',
				'display_name' => '',
				'id'           => '',
			);
		}

		$post_date         = $post->post_date;
		$post_date_gmt     = $post->post_date_gmt;
		$post_modified     = $post->post_modified;
		$post_modified_gmt = $post->post_modified_gmt;
		$comment_count     = absint( $post->comment_count );
		$comment_status    = $post->comment_status;
		$ping_status       = $post->ping_status;
		$menu_order        = absint( $post->menu_order );

		/**
		 * Filter to ignore invalid dates
		 *
		 * @hook ep_ignore_invalid_dates
		 * @param  {bool} $ignore True to ignore
		 * @param {int} $post_id Post ID
		 * @param  {WP_Post} $post Post object
		 * @return  {bool} New ignore value
		 */
		if ( apply_filters( 'ep_ignore_invalid_dates', true, $post_id, $post ) ) {
			if ( ! strtotime( $post_date ) || '0000-00-00 00:00:00' === $post_date ) {
				$post_date = null;
			}

			if ( ! strtotime( $post_date_gmt ) || '0000-00-00 00:00:00' === $post_date_gmt ) {
				$post_date_gmt = null;
			}

			if ( ! strtotime( $post_modified ) || '0000-00-00 00:00:00' === $post_modified ) {
				$post_modified = null;
			}

			if ( ! strtotime( $post_modified_gmt ) || '0000-00-00 00:00:00' === $post_modified_gmt ) {
				$post_modified_gmt = null;
			}
		}

		// To prevent infinite loop, we don't queue when updated_postmeta.
		remove_action( 'updated_postmeta', [ $this->sync_manager, 'action_queue_meta_sync' ], 10 );

		/**
		 * Filter to allow indexing of filtered post content
		 *
		 * @hook ep_allow_post_content_filtered_index
		 * @param  {bool} $ignore True to allow
		 * @return  {bool} New value
		 */
		$post_content_filtered_allowed = apply_filters( 'ep_allow_post_content_filtered_index', true );
        //START OF NEW PART - RESTAURANT
        //anegi - GET RESTAURANT details FROM ID
        $restaurant_title='';
        $restaurant_name='';
        $restaurant_address='';
        $city='';
		$food_ingredients = '';
		$food_keywords = '';
		$food_description = '';
        $r1 = 0;
        $r2 = 0;
        $r3 = 0;
        $r4 = 0;
        $r5 = 0;
        $weight = 0;
        if($post->post_type=='piatto'){
			$food_ingredients = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post )['food_ingredients'][0];
			$food_keywords = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post )['food_keywords'][0];
            $restaurantId = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post )['restaurant_associated'][0];
			$food_description = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post )['food_description'][0];
            $weight = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post )['food_ranking_weight'][0];
            global $wpdb;
            $results = $wpdb->get_results($wpdb->prepare('select p.post_title as restaurant_title,p.post_title as restaurant_name, pm.meta_value as restaurant_address , wt.name as city from wp_posts p join wp_postmeta pm on p.ID = pm.post_id left join wp_term_relationships wtr on wtr.object_id = pm.post_id left join wp_terms wt on wt.term_id= wtr.term_taxonomy_id join wp_term_taxonomy wtt on wtr.term_taxonomy_id = wtt.term_taxonomy_id and wtt.taxonomy = \'city\' where p.post_type = \'ristorante\' and p.post_status = \'publish\' and pm.meta_key in( \'restaurant_address\') and p.id = '.(int)$restaurantId),ARRAY_A);
            foreach ( $results as $result ) {
                $restaurant_title = $result['restaurant_title'];
                 $restaurant_name = $result['restaurant_name'];
                 $restaurant_address = $result['restaurant_address'];
                 $city = $result['city'];
            }

            global $wpdb;
            $results = $wpdb->get_results($wpdb->prepare('with temp as ( select p.id,pm_r.meta_value as rating, count(1) as count from wp_posts p join wp_postmeta pm on pm.meta_value = p.id and pm.meta_key = \'_assigned_to\' join wp_postmeta pm_r on pm.post_id = pm_r.post_id and pm_r.meta_key = \'_rating\' join wp_posts pr on pr.id = pm.post_id AND pr.post_status = \'publish\' where p.id = '.(int)$post_id.' AND p.post_type = \'piatto\' AND p.post_status = \'publish\' group by p.id,pm_r.meta_value ), t1 as(select t.id,t1.count as r1 from temp t join temp t1 on t1.id = t.id and t1.rating = t.rating and t1.rating = 1) ,t2 as(select t.id,t2.count as r2 from temp t join temp t2 on t2.id = t.id and t2.rating = t.rating and t2.rating = 2) ,t3 as(select t.id,t3.count as r3 from temp t join temp t3 on t3.id = t.id and t3.rating = t.rating and t3.rating = 3) ,t4 as(select t.id,t4.count as r4 from temp t join temp t4 on t4.id = t.id and t4.rating = t.rating and t4.rating = 4) ,t5 as(select t.id,t5.count as r5 from temp t join temp t5 on t5.id = t.id and t5.rating = t.rating and t5.rating = 5) select distinct t.id,IFNULL(t1.r1, 0) as r1,IFNULL(t2.r2, 0) as r2,IFNULL(t3.r3, 0) as r3,IFNULL(t4.r4, 0) as r4,IFNULL(t5.r5, 0) as r5 from temp t left join t1 on t1.id = t.id left join t2 on t2.id = t.id left join t3 on t3.id = t.id left join t4 on t4.id = t.id left join t5 on t5.id = t.id'),ARRAY_A);
            foreach ( $results as $result ) {
                 $r1 = $result['r1'];
                 $r2 = $result['r2'];
                 $r3 = $result['r3'];
                 $r4 = $result['r4'];
                 $r5 = $result['r5'];
            }

        }
        //aneg - END OF GET RESTAURANT details FROM ID
		$post_args = array(
			'post_id'               => $post_id,
			'ID'                    => $post_id,
			'post_author'           => $user_data,
			'post_date'             => $post_date,
			'post_date_gmt'         => $post_date_gmt,
			'post_title'            => $post->post_title,
			'post_excerpt'          => $post->post_excerpt,
			'post_content_filtered' => $post_content_filtered_allowed ? apply_filters( 'the_content', $post->post_content ) : '',
			'post_content'          => $post->post_content,
			'post_status'           => $post->post_status,
			'post_name'             => $post->post_name,
			'post_modified'         => $post_modified,
			'post_modified_gmt'     => $post_modified_gmt,
			'post_parent'           => $post->post_parent,
			'post_type'             => $post->post_type,
			'post_mime_type'        => $post->post_mime_type,
			'permalink'             => get_permalink( $post_id ),
			'terms'                 => $this->prepare_terms( $post ),
            //anegi - new fields to es
            'restaurant_title'      => $restaurant_title,
            'restaurant_name'       => $restaurant_name,
            'restaurant_address'    => $restaurant_address,
            'city'                  => $city,
			'food_ingredients'		=> $food_ingredients,
			'food_keywords'			=> $food_keywords,
			'food_description'		=> $food_description,
            'r1'                    => $r1,
            'r2'                    => $r2,
            'r3'                    => $r3,
            'r4'                    => $r4,
            'r5'                    => $r5,
            'weight'                => $weight,
            //anegi - end of new fields to es
			'meta'                  => $this->prepare_meta_types( $this->prepare_meta( $post ) ), // post_meta removed in 2.4.
			'date_terms'            => $this->prepare_date_terms( $post_date ),
			'comment_count'         => $comment_count,
			'comment_status'        => $comment_status,
			'ping_status'           => $ping_status,
			'menu_order'            => $menu_order,
			'guid'                  => $post->guid,
		);

		/**
		 * Filter sync arguments for a post. For backwards compatibility.
		 *
		 * @hook ep_post_sync_args
		 * @param  {array} $post_args Post arguments
		 * @param  {int} $post_id Post ID
		 * @return  {array} New arguments
		 */
		$post_args = apply_filters( 'ep_post_sync_args', $post_args, $post_id );

		/**
		 * Filter sync arguments for a post after meta preparation.
		 *
		 * @hook ep_post_sync_args_post_prepare_meta
		 * @param  {array} $post_args Post arguments
		 * @param  {int} $post_id Post ID
		 * @return  {array} New arguments
		 */
		$post_args = apply_filters( 'ep_post_sync_args_post_prepare_meta', $post_args, $post_id );

		// Turn back on updated_postmeta hook
		add_action( 'updated_postmeta', [ $this->sync_manager, 'action_queue_meta_sync' ], 10, 4 );

		return $post_args;
	}

	/**
	 * Prepare date terms to send to ES.
	 *
	 * @param string $post_date_gmt Post date
	 * @since 0.1.4
	 * @return array
	 */
	private function prepare_date_terms( $post_date_gmt ) {
		$timestamp  = strtotime( $post_date_gmt );
		$date_terms = array(
			'year'          => (int) date_i18n( 'Y', $timestamp ),
			'month'         => (int) date_i18n( 'm', $timestamp ),
			'week'          => (int) date_i18n( 'W', $timestamp ),
			'dayofyear'     => (int) date_i18n( 'z', $timestamp ),
			'day'           => (int) date_i18n( 'd', $timestamp ),
			'dayofweek'     => (int) date_i18n( 'w', $timestamp ),
			'dayofweek_iso' => (int) date_i18n( 'N', $timestamp ),
			'hour'          => (int) date_i18n( 'H', $timestamp ),
			'minute'        => (int) date_i18n( 'i', $timestamp ),
			'second'        => (int) date_i18n( 's', $timestamp ),
			'm'             => (int) ( date_i18n( 'Y', $timestamp ) . date_i18n( 'm', $timestamp ) ), // yearmonth
		);
		return $date_terms;
	}

	/**
	 * Prepare terms to send to ES.
	 *
	 * @param WP_Post $post Post object
	 * @since 0.1.0
	 * @return array
	 */
	private function prepare_terms( $post ) {
		$taxonomies          = get_object_taxonomies( $post->post_type, 'objects' );
		$selected_taxonomies = [];

		foreach ( $taxonomies as $taxonomy ) {
			if ( $taxonomy->public || $taxonomy->publicly_queryable ) {
				$selected_taxonomies[] = $taxonomy;
			}
		}

		/**
		 * Filter taxonomies to be synced with post
		 *
		 * @hook ep_sync_taxonomies
		 * @param  {array} $selected_taxonomies Selected taxonomies
		 * @param  {WP_Post} Post object
		 * @return  {array} New taxonomies
		 */
		$selected_taxonomies = apply_filters( 'ep_sync_taxonomies', $selected_taxonomies, $post );

		if ( empty( $selected_taxonomies ) ) {
			return [];
		}

		$terms = [];

		/**
		 * Filter to allow child terms to be indexed
		 *
		 * @hook ep_sync_terms_allow_hierarchy
		 * @param  {bool} $allow True means allow
		 * @return  {bool} New value
		 */
		$allow_hierarchy = apply_filters( 'ep_sync_terms_allow_hierarchy', true );

		foreach ( $selected_taxonomies as $taxonomy ) {
			// If we get a taxonomy name, we need to convert it to taxonomy object
			if ( ! is_object( $taxonomy ) && taxonomy_exists( (string) $taxonomy ) ) {
				$taxonomy = get_taxonomy( $taxonomy );
			}

			// We check if the $taxonomy object as name property. Backward compatibility since WP_Taxonomy introduced in WP 4.7
			if ( ! is_a( $taxonomy, '\WP_Taxonomy' ) || ! property_exists( $taxonomy, 'name' ) ) {
				continue;
			}

			$object_terms = get_the_terms( $post->ID, $taxonomy->name );

			if ( ! $object_terms || is_wp_error( $object_terms ) ) {
				continue;
			}

			$terms_dic = [];

			foreach ( $object_terms as $term ) {
				if ( ! isset( $terms_dic[ $term->term_id ] ) ) {
					$terms_dic[ $term->term_id ] = array(
						'term_id'          => $term->term_id,
						'slug'             => $term->slug,
						'name'             => $term->name,
						'parent'           => $term->parent,
						'term_taxonomy_id' => $term->term_taxonomy_id,
						'term_order'       => (int) $this->get_term_order( $term->term_taxonomy_id, $post->ID ),
					);
					if ( $allow_hierarchy ) {
						$terms_dic = $this->get_parent_terms( $terms_dic, $term, $taxonomy->name, $post->ID );
					}
				}
			}
			$terms[ $taxonomy->name ] = array_values( $terms_dic );
		}

		return $terms;
	}

	/**
	 * Recursively get all the ancestor terms of the given term
	 *
	 * @param array   $terms     Terms array
	 * @param WP_Term $term      Current term
	 * @param string  $tax_name  Taxonomy
	 * @param int     $object_id Post ID
	 *
	 * @return array
	 */
	private function get_parent_terms( $terms, $term, $tax_name, $object_id ) {
		$parent_term = get_term( $term->parent, $tax_name );
		if ( ! $parent_term || is_wp_error( $parent_term ) ) {
			return $terms;
		}
		if ( ! isset( $terms[ $parent_term->term_id ] ) ) {
			$terms[ $parent_term->term_id ] = array(
				'term_id'    => $parent_term->term_id,
				'slug'       => $parent_term->slug,
				'name'       => $parent_term->name,
				'parent'     => $parent_term->parent,
				'term_order' => $this->get_term_order( $parent_term->term_taxonomy_id, $object_id ),
			);
		}
		return $this->get_parent_terms( $terms, $parent_term, $tax_name, $object_id );
	}

	/**
	 * Retreives term order for the object/term_taxonomy_id combination
	 *
	 * @param int $term_taxonomy_id Term Taxonomy ID
	 * @param int $object_id        Post ID
	 *
	 * @return int Term Order
	 */
	protected function get_term_order( $term_taxonomy_id, $object_id ) {
		global $wpdb;

		$cache_key   = "{$object_id}_term_order";
		$term_orders = wp_cache_get( $cache_key );

		if ( false === $term_orders ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT term_taxonomy_id, term_order from $wpdb->term_relationships where object_id=%d;",
					$object_id
				),
				ARRAY_A
			);

			$term_orders = [];

			foreach ( $results as $result ) {
				$term_orders[ $result['term_taxonomy_id'] ] = $result['term_order'];
			}

			wp_cache_set( $cache_key, $term_orders );
		}

		return isset( $term_orders[ $term_taxonomy_id ] ) ? (int) $term_orders[ $term_taxonomy_id ] : 0;

	}

	/**
	 * Prepare post meta to send to ES
	 *
	 * @param WP_Post $post Post object
	 * @since 0.1.0
	 * @return array
	 */
	public function prepare_meta( $post ) {
		/**
		 * Filter pre-prepare meta for a post
		 *
		 * @hook ep_prepare_meta_data
		 * @param  {array} $meta Meta data
		 * @param  {WP_Post} $post Post object
		 * @return  {array} New meta
		 */
		$meta = apply_filters( 'ep_prepare_meta_data', (array) get_post_meta( $post->ID ), $post );

		if ( empty( $meta ) ) {
			/**
			 * Filter final list of prepared meta.
			 *
			 * @hook ep_prepared_post_meta
			 * @param  {array} $prepared_meta Prepared meta
			 * @param  {WP_Post} $post Post object
			 * @since  3.4
			 * @return  {array} Prepared meta
			 */
			return apply_filters( 'ep_prepared_post_meta', [], $post );
		}

		$prepared_meta = [];

		/**
		 * Filter indexable protected meta keys for posts
		 *
		 * @hook ep_prepare_meta_allowed_protected_keys
		 * @param  {array} $keys Allowed protected keys
		 * @param  {WP_Post} $post Post object
		 * @since  1.7
		 * @return  {array} New keys
		 */
		$allowed_protected_keys = apply_filters( 'ep_prepare_meta_allowed_protected_keys', [], $post );

		/**
		 * Filter public keys to exclude from indexed post
		 *
		 * @hook ep_prepare_meta_excluded_public_keys
		 * @param  {array} $keys Excluded protected keys
		 * @param  {WP_Post} $post Post object
		 * @since  1.7
		 * @return  {array} New keys
		 */
		$excluded_public_keys = apply_filters( 'ep_prepare_meta_excluded_public_keys', [], $post );

		foreach ( $meta as $key => $value ) {

			$allow_index = false;

			if ( is_protected_meta( $key ) ) {

				if ( true === $allowed_protected_keys || in_array( $key, $allowed_protected_keys, true ) ) {
					$allow_index = true;
				}
			} else {

				if ( true !== $excluded_public_keys && ! in_array( $key, $excluded_public_keys, true ) ) {
					$allow_index = true;
				}
			}

			/**
			 * Filter force whitelisting a meta key
			 *
			 * @hook ep_prepare_meta_whitelist_key
			 * @param  {bool} $whitelist True to whitelist key
			 * @param  {string} $key Meta key
			 * @param  {WP_Post} $post Post object
			 * @return  {bool} New whitelist value
			 */
			if ( true === $allow_index || apply_filters( 'ep_prepare_meta_whitelist_key', false, $key, $post ) ) {
				$prepared_meta[ $key ] = maybe_unserialize( $value );
			}
		}

		/**
		 * Filter final list of prepared meta.
		 *
		 * @hook ep_prepared_post_meta
		 * @param  {array} $prepared_meta Prepared meta
		 * @param  {WP_Post} $post Post object
		 * @since  3.4
		 * @return  {array} Prepared meta
		 */
		return apply_filters( 'ep_prepared_post_meta', $prepared_meta, $post );

	}

	/**
	 * Format WP query args for ES
	 *
	 * @param  array    $args     WP_Query arguments.
	 * @param  WP_Query $wp_query WP_Query object
	 * @since 0.9.0
	 * @return array
	 */
	public function format_args( $args, $wp_query ) {
		if ( ! empty( $args['posts_per_page'] ) ) {
			$posts_per_page = (int) $args['posts_per_page'];

			// ES have a maximum size allowed so we have to convert "-1" to a maximum size.
			if ( -1 === $posts_per_page ) {
				/**
				 * Set the maximum results window size.
				 *
				 * The request will return a HTTP 500 Internal Error if the size of the
				 * request is larger than the [index.max_result_window] parameter in ES.
				 * See the scroll api for a more efficient way to request large data sets.
				 *
				 * @return int The max results window size.
				 *
				 * @since 2.3.0
				 */

				/**
				 * Filter max result size if set to -1
				 *
				 * @hook ep_max_results_window
				 * @param  {int} Max result window
				 * @return {int} New window
				 */
				$posts_per_page = apply_filters( 'ep_max_results_window', 10000 );
			}
		} else {
			$posts_per_page = (int) get_option( 'posts_per_page' );
		}

		$formatted_args = array(
			'from' => 0,
			'size' => $posts_per_page,
		);

		/**
		 * Order and Orderby arguments
		 *
		 * Used for how Elasticsearch will sort results
		 *
		 * @since 1.1
		 */

		// Set sort order, default is 'desc'.
		if ( ! empty( $args['order'] ) ) {
			$order = $this->parse_order( $args['order'] );
		} else {
			$order = 'desc';
		}

		// Default sort for non-searches to date.
		if ( empty( $args['orderby'] ) && ( ! isset( $args['s'] ) || '' === $args['s'] ) ) {
			/**
			 * Filter default post query order by
			 *
			 * @hook ep_set_default_sort
			 * @param  {string} $sort Default sort
			 * @param  {string $order Order direction
			 * @return  {string} New default
			 */
			$args['orderby'] = apply_filters( 'ep_set_default_sort', 'date', $order );
		}

		// Set sort type.
		if ( ! empty( $args['orderby'] ) ) {
			$formatted_args['sort'] = $this->parse_orderby( $args['orderby'], $order, $args );
		} else {
			// Default sort is to use the score (based on relevance).
			$default_sort = array(
				array(
					'_score' => array(
						'order' => 'asc',
					),
				),
			);

			/**
			 * Filter default post query order by
			 *
			 * @hook ep_set_default_sort
			 * @param  {string} $sort Default sort
			 * @param  {string} $order Order direction
			 * @return  {string} New default
			 */
			$default_sort = apply_filters( 'ep_set_default_sort', $default_sort, $order );

			$formatted_args['sort'] = $default_sort;
		}

		$filter      = array(
			'bool' => array(
				'must' => [],
			),
		);
		$use_filters = false;

		/**
		 * Tax Query support
		 *
		 * Support for the tax_query argument of WP_Query. Currently only provides support for the 'AND' relation
		 * between taxonomies. Field only supports slug, term_id, and name defaulting to term_id.
		 *
		 * @use field = slug
		 *      terms array
		 * @since 0.9.1
		 */

		// Find root level taxonomies.
		switch ( $args ) {
			case ! empty( $args['category_name'] ):
				$args['tax_query'][] = array(
					'taxonomy' => 'category',
					'terms'    => array( $args['category_name'] ),
					'field'    => 'slug',
				);
				break;
			case ! empty( $args['cat'] ):
				$args['tax_query'][] = array(
					'taxonomy' => 'category',
					'terms'    => array( $args['cat'] ),
					'field'    => 'id',
				);
				break;
			case ! empty( $args['tag'] ):
				$args['tax_query'][] = array(
					'taxonomy' => 'post_tag',
					'terms'    => array( $args['tag'] ),
					'field'    => 'slug',
				);
				break;
			case ! empty( $args['tag__and'] ):
				$args['tax_query'][] = array(
					'taxonomy' => 'post_tag',
					'terms'    => $args['tag__and'],
					'field'    => 'term_id',
				);
				break;
			case ! empty( $args['tag_id'] ) && ! is_array( $args['tag_id'] ):
				$args['tax_query'][] = array(
					'taxonomy' => 'post_tag',
					'terms'    => $args['tag_id'],
					'field'    => 'term_id',
				);
				break;
		}

		/**
		 * Try to find other taxonomies set in the root of WP_Query
		 *
		 * @since  3.4
		 */
		$taxonomies = get_taxonomies();

        //anegi - new value slug_n for taxnomy
        $slug_n='';
		foreach ( $taxonomies as $tax_slug ) {
			if ( 'ep_custom_result' === $tax_slug ) {
				continue;
			}

			if ( ! empty( $args[ $tax_slug ] ) ) {
                $slug_n=$args[ $tax_slug ];
				$args['tax_query'][] = array(
					'taxonomy' => $tax_slug,
					'terms'    => (array) $args[ $tax_slug ],
					'field'    => 'slug',
				);
			}
		}
        // anegi - end of new value slug_n for taxnomy

		if ( ! empty( $args['tax_query'] ) ) {
			// Main tax_query array for ES.
			$es_tax_query = [];

			$tax_queries = $this->parse_tax_query( $args['tax_query'] );

			if ( ! empty( $tax_queries['tax_filter'] ) ) {
                //anegi - changed relation must to should
				$relation = 'should';

				if ( ! empty( $args['tax_query']['relation'] ) && 'or' === strtolower( $args['tax_query']['relation'] ) ) {
					$relation = 'should';
				}

				$es_tax_query[ $relation ] = $tax_queries['tax_filter'];

                //anegi - new filter for taxonomy
                $es_tax_query[ $relation ] = array(
                                                   'match'=>array(
                                                                    'city'=>$slug_n
                                                                     )
                                                   );
                //unset($es_tax_query[ $relation ]);
                //anegi - end of new filter for taxonomy
			}

			if ( ! empty( $tax_queries['tax_must_not_filter'] ) ) {
				$es_tax_query['must_not'] = $tax_queries['tax_must_not_filter'];
			}

			if ( ! empty( $es_tax_query ) ) {
				$filter['bool']['must'][]['bool'] = $es_tax_query;
			}

			$use_filters = true;
		}

		/**
		 * 'post_parent' arg support.
		 *
		 * @since 2.0
		 */
		if ( isset( $args['post_parent'] ) && '' !== $args['post_parent'] && 'any' !== strtolower( $args['post_parent'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'term' => array(
					'post_parent' => $args['post_parent'],
				),
			);

			$use_filters = true;
		}

		/**
		 * 'post__in' arg support.
		 *
		 * @since x.x
		 */
		if ( ! empty( $args['post__in'] ) ) {
			$filter['bool']['must'][]['bool']['must'] = array(
				'terms' => array(
					'post_id' => array_values( (array) $args['post__in'] ),
				),
			);

			$use_filters = true;
		}

		/**
		 * 'post__not_in' arg support.
		 *
		 * @since x.x
		 */
		if ( ! empty( $args['post__not_in'] ) ) {
			$filter['bool']['must'][]['bool']['must_not'] = array(
				'terms' => array(
					'post_id' => (array) $args['post__not_in'],
				),
			);

			$use_filters = true;
		}

		/**
		 * Author query support
		 *
		 * @since 1.0
		 */
		if ( ! empty( $args['author'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_author.id' => $args['author'],
				),
			);

			$use_filters = true;
		} elseif ( ! empty( $args['author_name'] ) ) {
			// Since this was set to use the display name initially, there might be some code that used this feature.
			// Let's ensure that any query vars coming in using author_name are in fact slugs.
			// This was changed back in ticket #1622 to use the display name, so we removed the sanitize_user() call.
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_author.display_name' => $args['author_name'],
				),
			);

			$use_filters = true;
		}

		/**
		 * Add support for post_mime_type
		 *
		 * If we have array, it will be fool text search filter.
		 * If we have string(like filter images in media screen), we will have mime type "image" so need to check it as
		 * regexp filter.
		 *
		 * @since 2.3
		 */
		if ( ! empty( $args['post_mime_type'] ) ) {
			if ( is_array( $args['post_mime_type'] ) ) {
				$filter['bool']['must'][] = array(
					'terms' => array(
						'post_mime_type' => (array) $args['post_mime_type'],
					),
				);

				$use_filters = true;
			} elseif ( is_string( $args['post_mime_type'] ) ) {
				$filter['bool']['must'][] = array(
					'regexp' => array(
						'post_mime_type' => $args['post_mime_type'] . '.*',
					),
				);

				$use_filters = true;
			}
		}

		/**
		 * Simple date params support
		 *
		 * @since 1.3
		 */
		$date_filter = DateQuery::simple_es_date_filter( $args );

		if ( ! empty( $date_filter ) ) {
			$filter['bool']['must'][] = $date_filter;
			$use_filters              = true;
		}

		/**
		 * 'date_query' arg support.
		 */
		if ( ! empty( $args['date_query'] ) ) {

			$date_query = new DateQuery( $args['date_query'] );

			$date_filter = $date_query->get_es_filter();

			if ( array_key_exists( 'and', $date_filter ) ) {
				$filter['bool']['must'][] = $date_filter['and'];
				$use_filters              = true;
			}
		}

		$meta_queries = [];

		/**
		 * Support meta_key
		 *
		 * @since  2.1
		 */
		if ( ! empty( $args['meta_key'] ) ) {
			if ( ! empty( $args['meta_value'] ) ) {
				$meta_value = $args['meta_value'];
			} elseif ( ! empty( $args['meta_value_num'] ) ) {
				$meta_value = $args['meta_value_num'];
			}

			if ( ! empty( $meta_value ) ) {
				$meta_queries[] = array(
					'key'   => $args['meta_key'],
					'value' => $meta_value,
				);
			}
		}

		/**
		 * Todo: Support meta_type
		 */

		/**
		 * 'meta_query' arg support.
		 *
		 * Relation supports 'AND' and 'OR'. 'AND' is the default. For each individual query, the
		 * following 'compare' values are supported: =, !=, EXISTS, NOT EXISTS. '=' is the default.
		 *
		 * @since 1.3
		 */
		if ( ! empty( $args['meta_query'] ) ) {
			$meta_queries = array_merge( $meta_queries, $args['meta_query'] );
		}

		if ( ! empty( $meta_queries ) ) {

			$relation = 'must';
			if ( ! empty( $args['meta_query'] ) && ! empty( $args['meta_query']['relation'] ) && 'or' === strtolower( $args['meta_query']['relation'] ) ) {
				$relation = 'should';
			}

			// get meta query filter
			$meta_filter = $this->build_meta_query( $meta_queries );

			if ( ! empty( $meta_filter ) ) {
				$filter['bool']['must'][] = $meta_filter;

				$use_filters = true;
			}
		}


		/**
		 * Allow for search field specification
		 *
		 * @since 1.0
		 */
		if ( ! empty( $args['search_fields'] ) ) {
			$search_field_args = $args['search_fields'];
			$search_fields     = array();

			if ( ! empty( $search_field_args['taxonomies'] ) ) {
				$taxes = (array) $search_field_args['taxonomies'];

				foreach ( $taxes as $tax ) {
					$search_fields[] = 'terms.' . $tax . '.name';
				}

				unset( $search_field_args['taxonomies'] );
			}

			if ( ! empty( $search_field_args['meta'] ) ) {
				$metas = (array) $search_field_args['meta'];

				foreach ( $metas as $meta ) {
					$search_fields[] = 'meta.' . $meta . '.value';
                    $search_fields[] = 'meta.' . $meta . '.raw';
				}
				unset( $search_field_args['meta'] );
			}

			if ( in_array( 'author_name', $search_field_args, true ) ) {
				$search_fields[] = 'post_author.login';

				$author_name_index = array_search( 'author_name', $search_field_args, true );
				unset( $search_field_args[ $author_name_index ] );
			}

           // $search_fields[] = 'restaurant_title.keyword';

			$search_fields = array_merge( $search_field_args, $search_fields );
		} else {
			$search_fields = array(
				'post_title',
				'post_excerpt',
				'post_content',
			);
		}
		/**
		 * Filter default post search fields
		 *
		 * @hook ep_search_fields
		 * @param  {array} $search_fields Default search fields
		 * @param  {array} $args WP Query arguments
		 * @return  {array} New defaults
		 */
		$search_fields = apply_filters( 'ep_search_fields', $search_fields, $args );

		$query = array(
            'bool' => array(
                'should' => array(
                   //anegi - new filter
					//search_fields
					array(
						 'bool' => array(
                			'must' => array(

								),
							 ),
					),
					array(
						 'bool' => array(
                			'must' => array(

								),
							 ),
					),
                   //anegi - end of new filter
                ),
            ),
        );

        /**
         * We are using ep_integrate instead of ep_match_all. ep_match_all will be
         * supported for legacy code but may be deprecated and removed eventually.
         *
         * @since 1.3
         */

$search_t = trim(preg_replace('!\s+!', ' ', $args['s']));

        $synonyms_list = array(
                    array('mcd', 'mc donalds'),
                    array('brgr', 'burger'),
        );

	//english
$stopwords_en = array('a','a\'s','able','about','above','according','accordingly','across','actually','after','afterwards','again','against','ain\'t','all','allow','allows','almost','alone','along','already','also','although','always','am','among','amongst','an','and','another','any','anybody','anyhow','anyone','anything','anyway','anyways','anywhere','apart','appear','appreciate','appropriate','are','aren\'t','around','as','aside','ask','asking','associated','at','available','away','awfully','b','be','became','because','become','becomes','becoming','been','before','beforehand','behind','being','believe','below','beside','besides','best','better','between','beyond','both','brief','but','by','c','c\'mon','c\'s','came','can','can\'t','cannot','cant','cause','causes','certain','certainly','changes','clearly','co','com','come','comes','concerning','consequently','consider','considering','contain','containing','contains','corresponding','could','couldn\'t','course','currently','d','definitely','described','despite','did','didn\'t','different','do','does','doesn\'t','doing','don\'t','done','down','downwards','during','e','each','edu','eg','eight','either','else','elsewhere','enough','entirely','especially','et','etc','even','ever','every','everybody','everyone','everything','everywhere','ex','exactly','example','except','f','far','few','fifth','first','five','followed','following','follows','for','former','formerly','forth','four','from','further','furthermore','g','get','gets','getting','given','gives','go','goes','going','gone','got','gotten','greetings','h','had','hadn\'t','happens','hardly','has','hasn\'t','have','haven\'t','having','he','he\'s','hello','help','hence','her','here','here\'s','hereafter','hereby','herein','hereupon','hers','herself','hi','him','himself','his','hither','hopefully','how','howbeit','however','i','i\'d','i\'ll','i\'m','i\'ve','ie','if','ignored','immediate','in','inasmuch','inc','indeed','indicate','indicated','indicates','inner','insofar','instead','into','inward','is','isn\'t','it','it\'d','it\'ll','it\'s','its','itself','j','just','k','keep','keeps','kept','know','known','knows','l','last','lately','later','latter','latterly','least','less','lest','let','let\'s','like','liked','likely','little','look','looking','looks','ltd','m','mainly','many','may','maybe','me','mean','meanwhile','merely','might','more','moreover','most','mostly','much','must','my','myself','n','name','namely','nd','near','nearly','necessary','need','needs','neither','never','nevertheless','new','next','nine','no','nobody','non','none','noone','nor','normally','not','nothing','novel','now','nowhere','o','obviously','of','off','often','oh','ok','okay','old','on','once','one','ones','only','onto','or','other','others','otherwise','ought','our','ours','ourselves','out','outside','over','overall','own','p','particular','particularly','per','perhaps','placed','please','plus','possible','presumably','probably','provides','q','que','quite','qv','r','rather','rd','re','really','reasonably','regarding','regardless','regards','relatively','respectively','right','s','said','same','saw','say','saying','says','second','secondly','see','seeing','seem','seemed','seeming','seems','seen','self','selves','sensible','sent','serious','seriously','seven','several','shall','she','should','shouldn\'t','since','six','so','some','somebody','somehow','someone','something','sometime','sometimes','somewhat','somewhere','soon','sorry','specified','specify','specifying','still','sub','such','sup','sure','t','t\'s','take','taken','tell','tends','th','than','thank','thanks','thanx','that','that\'s','thats','the','their','theirs','them','themselves','then','thence','there','there\'s','thereafter','thereby','therefore','therein','theres','thereupon','these','they','they\'d','they\'ll','they\'re','they\'ve','think','third','this','thorough','thoroughly','those','though','three','through','throughout','thru','thus','to','together','too','took','toward','towards','tried','tries','truly','try','trying','twice','two','u','un','under','unfortunately','unless','unlikely','until','unto','up','upon','us','use','used','useful','uses','using','usually','uucp','v','value','various','very','via','viz','vs','w','want','wants','was','wasn\'t','way','we','we\'d','we\'ll','we\'re','we\'ve','welcome','well','went','were','weren\'t','what','what\'s','whatever','when','whence','whenever','where','where\'s','whereafter','whereas','whereby','wherein','whereupon','wherever','whether','which','while','whither','who','who\'s','whoever','whole','whom','whose','why','will','willing','wish','with','within','without','won\'t','wonder','would','wouldn\'t','x','y','yes','yet','you','you\'d','you\'ll','you\'re','you\'ve','your','yours','yourself','yourselves','z','zero');
    //italian
$stopwords_it = array('IE','a','abbastanza','abbia','abbiamo','abbiano','abbiate','accidenti','ad','adesso','affinche','agl','agli','ahime','ahimè','ai','al','alcuna','alcuni','alcuno','all','alla','alle','allo','allora','altri','altrimenti','altro','altrove','altrui','anche','ancora','anni','anno','ansa','anticipo','assai','attesa','attraverso','avanti','avemmo','avendo','avente','aver','avere','averlo','avesse','avessero','avessi','avessimo','aveste','avesti','avete','aveva','avevamo','avevano','avevate','avevi','avevo','avrai','avranno','avrebbe','avrebbero','avrei','avremmo','avremo','avreste','avresti','avrete','avrà','avrò','avuta','avute','avuti','avuto','basta','bene','benissimo','berlusconi','brava','bravo','c','casa','caso','cento','certa','certe','certi','certo','che','chi','chicchessia','chiunque','ci','ciascuna','ciascuno','cima','cio','cioe','cioè','circa','citta','città','ciò','co','codesta','codesti','codesto','cogli','coi','col','colei','coll','coloro','colui','come','cominci','comunque','con','concernente','conciliarsi','conclusione','consiglio','contro','cortesia','cos','cosa','cosi','così','cui','d','da','dagl','dagli','dai','dal','dall','dalla','dalle','dallo','dappertutto','davanti','degl','degli','dei','del','dell','della','delle','dello','dentro','detto','deve','di','dice','dietro','dire','dirimpetto','diventa','diventare','diventato','dopo','dov','dove','dovra','dovrà','dovunque','due','dunque','durante','e','ebbe','ebbero','ebbi','ecc','ecco','ed','effettivamente','egli','ella','entrambi','eppure','era','erano','eravamo','eravate','eri','ero','esempio','esse','essendo','esser','essere','essi','ex','fa','faccia','facciamo','facciano','facciate','faccio','facemmo','facendo','facesse','facessero','facessi','facessimo','faceste','facesti','faceva','facevamo','facevano','facevate','facevi','facevo','fai','fanno','farai','faranno','fare','farebbe','farebbero','farei','faremmo','faremo','fareste','faresti','farete','farà','farò','fatto','favore','fece','fecero','feci','fin','finalmente','finche','fine','fino','forse','forza','fosse','fossero','fossi','fossimo','foste','fosti','fra','frattempo','fu','fui','fummo','fuori','furono','futuro','generale','gia','giacche','giorni','giorno','già','gli','gliela','gliele','glieli','glielo','gliene','governo','grande','grazie','gruppo','ha','haha','hai','hanno','ho','i','ieri','il','improvviso','in','inc','infatti','inoltre','insieme','intanto','intorno','invece','io','l','la','lasciato','lato','lavoro','le','lei','li','lo','lontano','loro','lui','lungo','luogo','là','ma','macche','magari','maggior','mai','male','malgrado','malissimo','mancanza','marche','me','medesimo','mediante','meglio','meno','mentre','mesi','mezzo','mi','mia','mie','miei','mila','miliardi','milioni','minimi','ministro','mio','modo','molti','moltissimo','molto','momento','mondo','mosto','nazionale','ne','negl','negli','nei','nel','nell','nella','nelle','nello','nemmeno','neppure','nessun','nessuna','nessuno','niente','no','noi','non','nondimeno','nonostante','nonsia','nostra','nostre','nostri','nostro','novanta','nove','nulla','nuovo','o','od','oggi','ogni','ognuna','ognuno','oltre','oppure','ora','ore','osi','ossia','ottanta','otto','paese','parecchi','parecchie','parecchio','parte','partendo','peccato','peggio','per','perche','perchè','perché','percio','perciò','perfino','pero','persino','persone','però','piedi','pieno','piglia','piu','piuttosto','più','po','pochissimo','poco','poi','poiche','possa','possedere','posteriore','posto','potrebbe','preferibilmente','presa','press','prima','primo','principalmente','probabilmente','proprio','puo','pure','purtroppo','può','qualche','qualcosa','qualcuna','qualcuno','quale','quali','qualunque','quando','quanta','quante','quanti','quanto','quantunque','quasi','quattro','quel','quella','quelle','quelli','quello','quest','questa','queste','questi','questo','qui','quindi','realmente','recente','recentemente','registrazione','relativo','riecco','salvo','sara','sarai','saranno','sarebbe','sarebbero','sarei','saremmo','saremo','sareste','saresti','sarete','sarà','sarò','scola','scopo','scorso','se','secondo','seguente','seguito','sei','sembra','sembrare','sembrato','sembri','sempre','senza','sette','si','sia','siamo','siano','siate','siete','sig','solito','solo','soltanto','sono','sopra','sotto','spesso','srl','sta','stai','stando','stanno','starai','staranno','starebbe','starebbero','starei','staremmo','staremo','stareste','staresti','starete','starà','starò','stata','state','stati','stato','stava','stavamo','stavano','stavate','stavi','stavo','stemmo','stessa','stesse','stessero','stessi','stessimo','stesso','steste','stesti','stette','stettero','stetti','stia','stiamo','stiano','stiate','sto','su','sua','subito','successivamente','successivo','sue','sugl','sugli','sui','sul','sull','sulla','sulle','sullo','suo','suoi','tale','tali','talvolta','tanto','te','tempo','ti','titolo','torino','tra','tranne','tre','trenta','troppo','trovato','tu','tua','tue','tuo','tuoi','tutta','tuttavia','tutte','tutti','tutto','uguali','ulteriore','ultimo','un','una','uno','uomo','va','vale','vari','varia','varie','vario','verso','vi','via','vicino','visto','vita','voi','volta','volte','vostra','vostre','vostri','vostro','è');

$stopwords = array_merge($stopwords_en, $stopwords_it);
$filtered_list = array_values(array_diff(explode(' ', $search_t), $stopwords));
$filtered=trim(preg_replace('!\s+!', ' ', implode(" ",$filtered_list)));
$synonyms = json_encode($synonyms_list, JSON_UNESCAPED_UNICODE);
$stopwords_list = json_encode($stopwords, JSON_UNESCAPED_UNICODE);

        if ( ! empty( $args['s'] ) ) {
			//anegi - new filter & sort
			//$search_list = explode(" ",$args['s']);
            //echo $search_t;
			foreach ($filtered_list as $search_word) {
 				$query['bool']['should'][0]['bool']['must'][] = array(
					'match_phrase_prefix'=> array(
								'search_fields' => $search_word,
								),
					);

				}
            //echo json_encode(explode(" ",$args['s']));
//            foreach (explode(" ",$args['s']) as $search_word) {
//                echo $search_word.":";
//            }
            foreach (explode(" ",$args['s']) as $search_word) {
                //echo $search_word.':';
                $query['bool']['should'][1]['bool']['must'][] = array(
                    'match'=> array(
                        'search_fields' => array(
                            'query' => $search_word,
                            'fuzziness' => 'AUTO',
                            'max_expansions' =>1,
                            'prefix_length' =>1,
                            ),
                        ),
                );
            }
		$n      = sizeof($filtered_list);
			//echo $n;
		$r = $n -1;
			//echo $r;
		global $list;
		$list[] = $filtered;
		$this->appendCombination($filtered_list, $n, $r);

			$jsonSearchString = json_encode($filtered);
			$jsonSearchList = json_encode($list);
            //echo $jsonSearchList;
            //echo $stopwords_list;
            //echo $synonyms;
			$formatted_args['sort'] = array(
				array(
					'_script' => array(
						'type' => 'number',
						'script' => array(
							'lang' => 'painless',
                                          'source' => 'Map m = new HashMap(); m.put(1, 9); m.put(2, 36); m.put(3, 102); m.put(4, 252); m.put(5, 685); m.put(6, 1320); m.put(7, 2940); m.put(8, 6504); m.put(9, 14319); m.put(10, 31380); def arr_comb = '.$jsonSearchList.'; int searchLength = / /.split('.$jsonSearchString.'.toLowerCase()).length; arr_comb = arr_comb.stream().map(t->t.toLowerCase()).collect(Collectors.toList()); String text = \' \'; String pT = doc[\'post_title\'].size()>0 ? doc[\'post_title.raw\'].value : \' \'; String rT = doc[\'restaurant_title\'].size()>0 ? doc[\'restaurant_title.raw\'].value :\' \'; String rA = doc[\'restaurant_address\'].size()>0 ? doc[\'restaurant_address.raw\'].value :\' \'; String c = doc[\'city\'].size()>0 ? doc[\'city.raw\'].value :\' \'; String fI = doc[\'food_ingredients\'].size()>0 ? doc[\'food_ingredients.raw\'].value :\' \'; String fK = doc[\'food_keywords\'].size()>0 ? doc[\'food_keywords.raw\'].value :\' \'; text = pT + \',\' + rT + \',\' + rA + \',\' + c + \',\' + fI + \',\' + fK ; text = text.toLowerCase();            def stopwords = '.$stopwords_list.'; def z1 = Arrays.asList(/ /.split(text)); for(String x: stopwords){ z1 = z1.stream().map(t->t.trim().equals(x) ? \'\' : /,$/.matcher(/^,/.matcher(t.trim()).replaceAll(\'\')).replaceAll(\'\').equals(x) ? \',\' : /,\\\\w+/.matcher(t.trim()).replaceAll(\'\').equals(x)?t.replace(x+\',\',\',\') : /\\\\w+,/.matcher(t.trim()).replaceAll(\'\').equals(x)?t.replace(\',\'+x,\',\'):t).collect(Collectors.toList()); }      text = z1.join(\' \'); text = /\\\\s+/.matcher(/,,/.matcher(/, ,/.matcher(text.trim()).replaceAll(\',\')).replaceAll(\',\')).replaceAll(\',\'); def zz = Arrays.asList(/ /.split(text)); def synonyms = '.$synonyms.'; for (def p: synonyms){ zz = zz.stream().map( t -> p.contains(t) ? p[0] : p.contains(/^,/.matcher(t.trim()).replaceAll(\'\'))? \',\'+p[0] : p.contains(/,$/.matcher(t.trim()).replaceAll(\'\')) ? p[0] + \',\': p.contains(/,$/.matcher(/^,/.matcher(t.trim()).replaceAll(\'\')).replaceAll(\'\'))? \',\' + p[0] + \',\': Arrays.asList(/,/.split(t)).stream().filter( u -> p.contains(u) ).collect(Collectors.toList()).size() > 0 ? Arrays.asList(/,/.split(t)).stream().map( u -> p.contains(u) ? p[0] : u ).collect(Collectors.toList()).join(\',\'):t).collect(Collectors.toList()); } text = zz.join(\' \'); text = /\\\\s+/.matcher(/,,/.matcher(/, ,/.matcher(text.trim()).replaceAll(\',\')).replaceAll(\',\')).replaceAll(\',\');                                     double score = 0; def new_l = Arrays.asList(/,/.split(text)); def firstList = new ArrayList(); def secondList = new ArrayList(); for(String y: new_l){ String x = y.trim(); firstList.addAll(arr_comb.stream().filter(t -> x.contains(t)).collect(Collectors.toList())); secondList.addAll(arr_comb.stream().filter(t->Arrays.asList(/ /.split(x)).containsAll(Arrays.asList(/ /.split(t)))).collect(Collectors.toList())); } for(String z : firstList){ score = score + / /.split(z).length/2; } for(String z : secondList){ score = score + / /.split(z).length; }  score = score / m.get(searchLength) * 33.33;       long s1 = doc[\'r1\'].value; long s2 = doc[\'r2\'].value; long s3 = doc[\'r3\'].value; long s4 = doc[\'r4\'].value; long s5 = doc[\'r5\'].value; double p = (s1 * 0.0) + (s2 * 0.25) + (s3 * 0.5) + (s4 * 0.75) + (s5 * 1.0); double n = (s1 * 1.0) + (s2 * 0.75) + (s3 * 0.5) + (s4 * 0.25) + (s5 * 0.0); double wilsonScore = p + n > 0 ? ((p + 1.9208) / (p + n) - 1.96 * Math.sqrt((p * n) / (p + n) + 0.9604) / (p + n)) / (1 + 3.8416 / (p + n)) : 0; wilsonScore =  wilsonScore * 33.33;         double weight_score = doc[\'weight\'].size()>0 ? doc[\'weight\'].value/10 * 33.33 : 0; double total_score = score + wilsonScore + weight_score; return total_score;',
						),
						'order' => 'desc',
					),
				),


            array(
                  'post_title.raw'=>array(
                    'order'=> 'asc',
                ),
                  ),
            );

            //anegi - end of new filter

            /**
             * Filter formatted Elasticsearch post query (only contains query part)
             *
             * @hook ep_formatted_args_query
             * @param {array} $query Current query
             * @param {array} $query_vars Query variables
             * @return  {array} New query
             */
            $formatted_args['query'] = apply_filters( 'ep_formatted_args_query', $query, $args );
        } elseif ( ! empty( $args['ep_match_all'] ) || ! empty( $args['ep_integrate'] ) ) {
            $formatted_args['query']['match_all'] = array(
                'boost' => 1,
            );
        }


		/**
		 * Order by 'rand' support
		 *
		 * Ref: https://github.com/elastic/elasticsearch/issues/1170
		 */
		if ( ! empty( $args['orderby'] ) ) {
			$orderbys = $this->get_orderby_array( $args['orderby'] );
			if ( in_array( 'rand', $orderbys, true ) ) {
				$formatted_args_query                                      = $formatted_args['query'];
				$formatted_args['query']                                   = [];
				$formatted_args['query']['function_score']['query']        = $formatted_args_query;
				$formatted_args['query']['function_score']['random_score'] = (object) [];
			}
		}

		/**
		 * Sticky posts support
		 */

		// Check first if there's sticky posts and show them only in the front page
		$sticky_posts = get_option( 'sticky_posts' );
		$sticky_posts = ( is_array( $sticky_posts ) && empty( $sticky_posts ) ) ? false : $sticky_posts;

		if ( false !== $sticky_posts
			&& is_home()
			&& in_array( $args['ignore_sticky_posts'], array( 'false', 0 ), true ) ) {
			$new_sort = [
				[
					'_score' => [
						'order' => 'desc',
					],
				],
			];
			$formatted_args['sort'] = array(
				array(
					'_score' => array(
						'order' => 'desc',
					),
				),
			);
			$formatted_args['sort'] = array_merge( $new_sort, $formatted_args['sort'] );

			$formatted_args_query                                   = $formatted_args['query'];
			$formatted_args['query']                                = array();
			$formatted_args['query']['function_score']['query']     = $formatted_args_query;
			$formatted_args['query']['function_score']['functions'] = array(
				// add extra weight to sticky posts to show them on top
					(object) array(
						'filter' => array(
							'terms' => array( '_id' => $sticky_posts ),
						),
						'weight' => 20,
					),
			);
		}

		if ( ! empty( $args['post_type'] ) ) {
			// should NEVER be "any" but just in case
			if ( 'any' !== $args['post_type'] ) {
                //default
                //$post_types     = (array) $args['post_type'];
				//anegi changes
                $post_types     = ['piatto'];
                //end anegi changes
				$terms_map_name = 'terms';

				$filter['bool']['must'][] = array(
					$terms_map_name => array(
						'post_type.raw' => array_values( $post_types ),
					),
				);

				$use_filters = true;
			}
		} elseif ( empty( $args['s'] ) ) {
			$filter['bool']['must'][] = array(
				'term' => array(
					'post_type.raw' => 'post',
				),
			);

			$use_filters = true;
		}

		/**
		 * Like WP_Query in search context, if no post_status is specified we default to "any". To
		 * be safe you should ALWAYS specify the post_status parameter UNLIKE with WP_Query.
		 *
		 * @since 2.1
		 */
		if ( ! empty( $args['post_status'] ) ) {
			// should NEVER be "any" but just in case
			if ( 'any' !== $args['post_status'] ) {
				$post_status    = (array) ( is_string( $args['post_status'] ) ? explode( ',', $args['post_status'] ) : $args['post_status'] );
				$post_status    = array_map( 'trim', $post_status );
				$terms_map_name = 'terms';
				if ( count( $post_status ) < 2 ) {
					$terms_map_name = 'term';
					$post_status    = $post_status[0];
				}

				$filter['bool']['must'][] = array(
					$terms_map_name => array(
						'post_status' => $post_status,
					),
				);

				$use_filters = true;
			}
		} else {
			$statuses = get_post_stati( array( 'public' => true ) );

			if ( is_admin() ) {
				/**
				 * In the admin we will add protected and private post statuses to the default query
				 * per WP default behavior.
				 */
				$statuses = array_merge(
					$statuses,
					get_post_stati(
						array(
							'protected'              => true,
							'show_in_admin_all_list' => true,
						)
					)
				);

				if ( is_user_logged_in() ) {
					$statuses = array_merge( $statuses, get_post_stati( array( 'private' => true ) ) );
				}
			}

			$statuses = array_values( $statuses );

			$post_status_filter_type = 'terms';

			$filter['bool']['must'][] = array(
				$post_status_filter_type => array(
					'post_status' => $statuses,
				),
			);

			$use_filters = true;
		}


		if ( isset( $args['offset'] ) ) {
			$formatted_args['from'] = $args['offset'];
		}

		if ( isset( $args['paged'] ) && $args['paged'] > 1 ) {
			$formatted_args['from'] = $args['posts_per_page'] * ( $args['paged'] - 1 );
		}

		if ( $use_filters ) {
			$formatted_args['post_filter'] = $filter;
		}

		/**
		 * Support fields.
		 */
		if ( isset( $args['fields'] ) ) {
			switch ( $args['fields'] ) {
				case 'ids':
					$formatted_args['_source'] = array(
						'include' => array(
							'post_id',
						),
					);
					break;

				case 'id=>parent':
					$formatted_args['_source'] = array(
						'include' => array(
							'post_id',
							'post_parent',
						),
					);
					break;
			}
		}

		/**
		 * Aggregations
		 */
		if ( isset( $args['aggs'] ) && ! empty( $args['aggs']['aggs'] ) ) {
			$agg_obj = $args['aggs'];

			// Add a name to the aggregation if it was passed through
			if ( ! empty( $agg_obj['name'] ) ) {
				$agg_name = $agg_obj['name'];
			} else {
				$agg_name = 'aggregation_name';
			}

			// Add/use the filter if warranted
			if ( isset( $agg_obj['use-filter'] ) && false !== $agg_obj['use-filter'] && $use_filters ) {

				// If a filter is being used, use it on the aggregation as well to receive relevant information to the query
				$formatted_args['aggs'][ $agg_name ]['filter'] = $filter;
				$formatted_args['aggs'][ $agg_name ]['aggs']   = $agg_obj['aggs'];
			} else {
				$formatted_args['aggs'][ $agg_name ] = $agg_obj['aggs'];
			}
		}

		/**
		 * Filter formatted Elasticsearch [ost ]query (entire query)
		 *
		 * @hook ep_formatted_args_query
		 * @param {array} $formatted_args Formatted Elasticsearch query
		 * @param {array} $query_vars Query variables
		 * @param {array} $query Query part
		 * @return  {array} New query
		 */

		$formatted_args = apply_filters( 'ep_formatted_args', $formatted_args, $args, $wp_query );

		/**
		 * Filter formatted Elasticsearch [ost ]query (entire query)
		 *
		 * @hook ep_post_formatted_args
		 * @param {array} $formatted_args Formatted Elasticsearch query
		 * @param {array} $query_vars Query variables
		 * @param {array} $query Query part
		 * @return  {array} New query
		 */
        //anegi - print queries to test
        //echo json_encode($formatted_args);
		return apply_filters( 'ep_post_formatted_args', $formatted_args, $args, $wp_query );
	}

	/**
	 * Parse and build out our tax query.
	 *
	 * @access protected
	 *
	 * @param array $query Tax query
	 * @return array
	 */

 protected function appendCombination($arr, $n, $r){
    $data = array();
    $this->combinationUtil($arr, $data, 0, $n - 1, 0, $r);
}

 protected function combinationUtil($arr, $data, $start, $end, $index, $r){
    global $list;
    if ($index == $r) {
        $string = "";
        for ($j = 0; $j < $r; $j++) {
            $string = trim($string . " " . $data[$j]);
            if (count(explode(" ", $string)) > 0) {
                $list[] = $string;
            }
        }
        $string = "";
        return;
    }

    for ($i = $start; $i <= $end && $end - $i + 1 >= $r - $index; $i++) {
        $data[$index] = $arr[$i];
        $this->combinationUtil($arr, $data, $i + 1, $end, $index + 1, $r);
    }
}
	protected function parse_tax_query( $query ) {
		$tax_query = [
			'tax_filter'          => [],
			'tax_must_not_filter' => [],
		];
		$relation  = '';

		foreach ( $query as $tax_queries ) {
			// If we have a nested tax query, recurse through that
			if ( is_array( $tax_queries ) && empty( $tax_queries['taxonomy'] ) ) {
				$result      = $this->parse_tax_query( $tax_queries );
				$relation    = ( ! empty( $tax_queries['relation'] ) ) ? strtolower( $tax_queries['relation'] ) : 'and';
				$filter_type = 'and' === $relation ? 'must' : 'should';

				// Set the proper filter type and must_not filter, as needed
				if ( ! empty( $result['tax_must_not_filter'] ) ) {
					$tax_query['tax_filter'][] = [
						'bool' => [
							$filter_type => $result['tax_filter'],
							'must_not'   => $result['tax_must_not_filter'],
						],
					];
				} else {
					$tax_query['tax_filter'][] = [
						'bool' => [
							$filter_type => $result['tax_filter'],
						],
					];
				}
			}

			// Parse each individual tax query part
			$single_tax_query = $tax_queries;
			if ( ! empty( $single_tax_query['taxonomy'] ) ) {
				$terms = isset( $single_tax_query['terms'] ) ? (array) $single_tax_query['terms'] : array();
				$field = ( ! empty( $single_tax_query['field'] ) ) ? $single_tax_query['field'] : 'term_id';

				if ( 'name' === $field ) {
					$field = 'name.raw';
				}

				// Set up our terms object
				$terms_obj = array(
					'terms.' . $single_tax_query['taxonomy'] . '.' . $field => $terms,
				);

				$operator = ( ! empty( $single_tax_query['operator'] ) ) ? strtolower( $single_tax_query['operator'] ) : 'in';

				switch ( $operator ) {
					case 'exists':
						/**
						 * add support for "EXISTS" operator
						 *
						 * @since 2.5
						 */
						$tax_query['tax_filter'][]['bool'] = array(
							'must' => array(
								array(
									'exists' => array(
										'field' => key( $terms_obj ),
									),
								),
							),
						);

						break;
					case 'not exists':
						/**
						 * add support for "NOT EXISTS" operator
						 *
						 * @since 2.5
						 */
						$tax_query['tax_filter'][]['bool'] = array(
							'must_not' => array(
								array(
									'exists' => array(
										'field' => key( $terms_obj ),
									),
								),
							),
						);

						break;
					case 'not in':
						/**
						 * add support for "NOT IN" operator
						 *
						 * @since 2.1
						 */
						// If "NOT IN" than it should filter as must_not
						$tax_query['tax_must_not_filter'][]['terms'] = $terms_obj;

						break;
					case 'and':
						/**
						 * add support for "and" operator
						 *
						 * @since 2.4
						 */
						$and_nest = array(
							'bool' => array(
								'must' => array(),
							),
						);

						foreach ( $terms as $term ) {
							$and_nest['bool']['must'][] = array(
								'terms' => array(
									'terms.' . $single_tax_query['taxonomy'] . '.' . $field => (array) $term,
								),
							);
						}

						$tax_query['tax_filter'][] = $and_nest;

						break;
					case 'in':
					default:
						/**
						 * Default to IN operator
						 */
						// Add the tax query filter
						$tax_query['tax_filter'][]['terms'] = $terms_obj;

						break;
				}
			}
		}

		return $tax_query;
	}

	/**
	 * Parse an 'order' query variable and cast it to ASC or DESC as necessary.
	 *
	 * @since 1.1
	 * @access protected
	 *
	 * @param string $order The 'order' query variable.
	 * @return string The sanitized 'order' query variable.
	 */
	protected function parse_order( $order ) {
		if ( ! is_string( $order ) || empty( $order ) ) {
			return 'desc';
		}

		if ( 'ASC' === strtoupper( $order ) ) {
			return 'asc';
		} else {
			return 'desc';
		}
	}

	/**
	 * Convert the alias to a properly-prefixed sort value.
	 *
	 * @since 1.1
	 * @access protected
	 *
	 * @param string $orderbys Alias or path for the field to order by.
	 * @param string $default_order Default order direction
	 * @param  array  $args Query args
	 * @return array
	 */
	protected function parse_orderby( $orderbys, $default_order, $args ) {
		$orderbys = $this->get_orderby_array( $orderbys );

		$sort = [];

		foreach ( $orderbys as $key => $value ) {
			if ( is_string( $key ) ) {
				$orderby_clause = $key;
				$order          = $value;
			} else {
				$orderby_clause = $value;
				$order          = $default_order;
			}

			if ( ! empty( $orderby_clause ) && 'rand' !== $orderby_clause ) {
				if ( 'relevance' === $orderby_clause ) {
					$sort[] = array(
						'_score' => array(
							'order' => $order,
						),
					);
				} elseif ( 'date' === $orderby_clause ) {
					$sort[] = array(
						'post_date' => array(
							'order' => $order,
						),
					);
				} elseif ( 'type' === $orderby_clause ) {
					$sort[] = array(
						'post_type.raw' => array(
							'order' => $order,
						),
					);
				} elseif ( 'modified' === $orderby_clause ) {
					$sort[] = array(
						'post_modified' => array(
							'order' => $order,
						),
					);
				} elseif ( 'name' === $orderby_clause ) {
					$sort[] = array(
						'post_' . $orderby_clause . '.raw' => array(
							'order' => $order,
						),
					);
				} elseif ( 'title' === $orderby_clause ) {
					$sort[] = array(
						'post_' . $orderby_clause . '.sortable' => array(
							'order' => $order,
						),
					);
				} elseif ( 'meta_value' === $orderby_clause ) {
					if ( ! empty( $args['meta_key'] ) ) {
						$sort[] = array(
							'meta.' . $args['meta_key'] . '.raw' => array(
								'order' => $order,
							),
						);
					}
				} elseif ( 'meta_value_num' === $orderby_clause ) {
					if ( ! empty( $args['meta_key'] ) ) {
						$sort[] = array(
							'meta.' . $args['meta_key'] . '.long' => array(
								'order' => $order,
							),
						);
					}
				} else {
					$sort[] = array(
						$orderby_clause => array(
							'order' => $order,
						),
					);
				}
			}
		}

		return $sort;
	}

	/**
	 * Get Order by args Array
	 *
	 * @param string|array $orderbys Order by string or array
	 * @since 2.1
	 * @return array
	 */
	protected function get_orderby_array( $orderbys ) {
		if ( ! is_array( $orderbys ) ) {
			$orderbys = explode( ' ', $orderbys );
		}

		return $orderbys;
	}
}
