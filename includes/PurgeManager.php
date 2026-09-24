<?php
namespace ReallyCache;

/**
 * Smart, targeted purging. Every event that changes rendered output purges
 * exactly the URLs it affects; site-wide changes (menus, theme, widgets)
 * flush everything. Home page, archives, comments, terms and menus are all
 * covered — the places most cache plugins forget and serve stale.
 */
class PurgeManager {

	private $engine;

	public function __construct( Engine $engine ) {
		$this->engine = $engine;

		// Content changes → targeted purge.
		add_action( 'transition_post_status', [ $this, 'on_post_status_change' ], 10, 3 );
		add_action( 'comment_post', [ $this, 'on_comment' ], 10, 2 );
		add_action( 'transition_comment_status', [ $this, 'on_comment_status_change' ], 10, 3 );
		add_action( 'edited_term', [ $this, 'on_term_change' ], 10, 3 );
		add_action( 'delete_term', [ $this, 'on_term_change' ], 10, 3 );

		// Site-wide changes → full flush.
		add_action( 'wp_update_nav_menu', [ $this, 'purge_all' ] );
		add_action( 'switch_theme', [ $this, 'purge_all' ] );
		add_action( 'customize_save_after', [ $this, 'purge_all' ] );
		add_action( 'update_option_sidebars_widgets', [ $this, 'purge_all' ] );
		add_action( 'update_option_permalink_structure', [ $this, 'purge_all' ] );
		add_action( 'update_option_posts_per_page', [ $this, 'purge_all' ] );
	}

	public function purge_all() {
		$this->engine->purge_all();
	}

	public function on_post_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return; // Draft-to-draft churn never reached the public site.
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) ) {
			return;
		}
		if ( ! is_post_type_viewable( $post->post_type ) ) {
			return;
		}

		$this->engine->purge_urls( $this->urls_for_post( $post ), true );
	}

	public function on_comment( $comment_id, $approved ) {
		if ( 1 === $approved ) {
			$this->purge_comment_post( $comment_id );
		}
	}

	public function on_comment_status_change( $new_status, $old_status, $comment ) {
		if ( 'approved' === $new_status || 'approved' === $old_status ) {
			$this->purge_comment_post( $comment->comment_ID );
		}
	}

	public function on_term_change( $term_id, $tt_id, $taxonomy ) {
		$link = get_term_link( (int) $term_id, $taxonomy );
		if ( ! is_wp_error( $link ) ) {
			$this->engine->purge_url( $link, true );
		}
	}

	/** Everything a public post appears on. */
	private function urls_for_post( $post ) {
		$urls   = [];
		$urls[] = get_permalink( $post );

		if ( ! empty( $this->engine->config()['smart_cache'] ) ) {
			$urls[] = home_url( '/' );

			$posts_page = (int) get_option( 'page_for_posts' );
			if ( $posts_page ) {
				$urls[] = get_permalink( $posts_page );
			}
		}

		$archive = get_post_type_archive_link( $post->post_type );
		if ( $archive ) {
			$urls[] = $archive;
		}

		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = $link;
					}
				}
			}
		}

		$author_url = get_author_posts_url( (int) $post->post_author );
		if ( $author_url ) {
			$urls[] = $author_url;
		}

		return apply_filters( 'reallycache_purge_urls_for_post', $urls, $post );
	}

	private function purge_comment_post( $comment_id ) {
		$comment = get_comment( $comment_id );
		if ( $comment && $comment->comment_post_ID ) {
			$this->engine->purge_url( get_permalink( (int) $comment->comment_post_ID ), true );
		}
	}
}
