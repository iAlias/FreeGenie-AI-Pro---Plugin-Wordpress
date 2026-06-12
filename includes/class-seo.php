<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FreeGenie_AI_Pro_SEO {

	private static $instance;
	public static function instance(){ return self::$instance ?? ( self::$instance = new self ); }

	private function __construct(){
		add_filter( 'wpseo_metadesc',                   [ $this,'meta' ] );
		add_filter( 'rank_math/frontend/description',   [ $this,'meta' ] );
		add_filter( 'wpseo_title',                      [ $this,'seo_title' ] );
	}

	public function meta( $desc ){
		if( $desc ) return $desc;
		$post = get_post();
		return mb_substr( wp_strip_all_tags( $post->post_content ), 0, 155 );
	}

	public function seo_title( $title ){ return $title ?: get_the_title(); }
}
