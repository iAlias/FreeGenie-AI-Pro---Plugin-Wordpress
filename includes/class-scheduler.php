<?php
/** Scheduler flessibile – FreeGenie AI Pro 1.4.0 */
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FreeGenie_AI_Pro_Scheduler {

	private static $instance;
	public static function instance(){ return self::$instance ?? ( self::$instance = new self ); }

	private function __construct(){
		add_action( 'update_option_fgp_options', [ $this,'reset_schedule' ], 10, 2 );
		add_action( 'fgp_hourly',               [ $this,'maybe_publish' ] );
		$this->reset_schedule( null, get_option('fgp_options',[]) );
	}

	/* -------- pianifica un evento orario fisso -------- */
	public function reset_schedule( $old, $new ){
		wp_clear_scheduled_hook( 'fgp_hourly' );
		if( empty($new['weekdays']) || empty($new['per_day']) ) return;
		if( ! wp_next_scheduled( 'fgp_hourly' ) )
			wp_schedule_event( time()+3600, 'hourly', 'fgp_hourly' );
	}

	/* -------- all’ora di ogni ora decide se pubblicare -------- */
	public function maybe_publish(){
		$opt = get_option( 'fgp_options', [] );
		if( empty($opt['weekdays']) || empty($opt['per_day']) ) return;

		$today_w = intval( current_time('w') );              // 0=dom
		if( ! in_array( $today_w, $opt['weekdays'] ) ) return;

		$count_today = intval( get_transient('fgp_published_'.date('Ymd')) );
		if( $count_today >= $opt['per_day'] ) return;

		/* Genera e pubblica un articolo */
		require_once FGP_DIR.'includes/class-generator.php';

		$topic_pool = [
			'Tecnologia'              => [ '5 trend tech della settimana', 'Nuovi chip e semiconduttori 2025' ],
			'Intelligenza Artificiale' => [ 'AI generativa nel business', 'Etica dell’AI e regolamentazioni' ],
			'Smart Home'              => [ 'Domotica low-cost con Matter', 'Come scegliere un hub universale' ],
			'Blockchain'              => [ 'Blockchain enterprise 2025', 'DeFi: protocolli emergenti' ],
			'Mobile'                  => [ 'Android 16: funzioni top', 'iOS 19: novità nascoste' ],
			'Mobilità'                => [ 'Batterie allo stato solido', 'Micromobilità urbana 2025' ],
			'Scienza & Ricerca'       => [ 'Fusione nucleare: status', 'Telescopio Webb: scoperte' ],
		];

		$cat   = array_rand( $topic_pool );
		$topic = $topic_pool[ $cat ][ array_rand( $topic_pool[$cat] ) ];

		$post_id = wp_insert_post( [
			'post_title'  => 'Auto Draft',
			'post_status' => 'draft',
			'post_type'   => 'post',
			'post_author' => get_current_user_id(),
		], true );

		if( is_wp_error($post_id) ) return;

		$cat_id = get_cat_ID( $cat );
		if( $cat_id ) wp_set_post_categories( $post_id, [ $cat_id ] );

		$gen = new FreeGenie_AI_Pro_Generator( $post_id );
		$gen->generate_full_article( "{$cat}: {$topic}" );
		wp_publish_post( $post_id );

		/* aggiorna contatore giornaliero */
		set_transient( 'fgp_published_'.date('Ymd'), $count_today+1, DAY_IN_SECONDS+3600 );
	}
}
