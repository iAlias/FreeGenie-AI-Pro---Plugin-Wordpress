<?php
/**
 * Plugin Name: FreeGenie AI Pro – Advanced AI Content, Image & SEO Assistant
 * Version:     1.4.2
 * Author:      DigitStudio
 * License:     GPL-3.0+
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'FGP_DIR', plugin_dir_path( __FILE__ ) );
define( 'FGP_VER', '1.4.2' );

final class FreeGenie_AI_Pro {

	private static $instance;
	public static function instance(){ return self::$instance ?? ( self::$instance = new self ); }

	private function __construct() {

		add_action( 'admin_menu', [ $this,'admin_menu' ] );
		add_action( 'admin_init', [ $this,'register_settings' ] );

		add_action( 'add_meta_boxes', [ $this,'add_metabox' ] );
		add_action( 'wp_ajax_fgp_generate_article', [ $this,'ajax_generate' ] );
		add_action( 'wp_ajax_fgp_get_posts_by_date', [ $this,'ajax_get_posts_by_date' ] );
		add_action( 'wp_ajax_fgp_regenerate_single_image', [ $this,'ajax_regenerate_single_image' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_scripts' ] );

		require_once FGP_DIR.'includes/class-seo.php';
		require_once FGP_DIR.'includes/class-scheduler.php';
		FreeGenie_AI_Pro_SEO::instance();
		FreeGenie_AI_Pro_Scheduler::instance();
	}

	/* ---------------- Settings ---------------- */
	public function admin_menu(){
		add_menu_page(
			'FreeGenie AI Pro','FreeGenie AI Pro','manage_options',
			'freegenie-ai-pro',[ $this,'settings_page' ],'dashicons-edit',3
		);
	}

	public function register_settings(){
		register_setting( 'fgp_options', 'fgp_options', [
			'sanitize_callback'=>function( $o ){
				return [
					'openai_key'   => sanitize_text_field($o['openai_key']   ?? ''),
					'cohere_key'   => sanitize_text_field($o['cohere_key']   ?? ''),
					'deepai_key'   => sanitize_text_field($o['deepai_key']   ?? ''),
					'huggingface_key' => sanitize_text_field($o['huggingface_key'] ?? ''),
					'unsplash_key' => sanitize_text_field($o['unsplash_key'] ?? ''),
					'pexels_key'   => sanitize_text_field($o['pexels_key']   ?? ''),
					'pixabay_key'  => sanitize_text_field($o['pixabay_key']  ?? ''),

					'weekdays' => array_map('intval',$o['weekdays']??[]),
					'per_day'  => max(0,min(24,intval($o['per_day']??0))),

					'provider_order'=>array_values(array_intersect(
						$o['provider_order']??['pexels','pixabay','pollinations','huggingface','openai','cohere','deepai'],
						['pexels','pixabay','pollinations','huggingface','openai','cohere','deepai']
					)),
				];
			}
		] );
	}

	public function settings_page(){
		// Handle Clear Log Action
		if ( isset( $_POST['fgp_clear_log'] ) && check_admin_referer( 'fgp_clear_log_action', 'fgp_clear_log_nonce' ) ) {
			$log_file = WP_CONTENT_DIR . '/fgp-debug.log';
			if ( file_exists( $log_file ) ) {
				file_put_contents( $log_file, '' );
				echo '<div class="updated"><p>Log svuotato con successo.</p></div>';
			}
		}

		$o=get_option('fgp_options',[]);
		$days=['Dom','Lun','Mar','Mer','Gio','Ven','Sab'];
		?>
		<div class="wrap fgp-settings-container">
		<h1 style="font-size: 28px; margin-bottom: 24px;">🧞 FreeGenie AI Pro – Impostazioni</h1>
		
		<form method="post" action="options.php">
		<?php settings_fields('fgp_options'); ?>

		<!-- API Keys Section -->
		<div class="fgp-section">
			<h2>🔑 Chiavi API</h2>
			<div class="fgp-api-grid">
				<div class="fgp-api-field">
					<label for="openai_key">OpenAI API Key</label>
					<input type="text" id="openai_key" name="fgp_options[openai_key]" value="<?php echo esc_attr($o['openai_key']??'');?>" placeholder="sk-...">
				</div>
				<div class="fgp-api-field">
					<label for="cohere_key">Cohere API Key</label>
					<input type="text" id="cohere_key" name="fgp_options[cohere_key]" value="<?php echo esc_attr($o['cohere_key']??'');?>" placeholder="...">
				</div>
				<div class="fgp-api-field">
					<label for="deepai_key">DeepAI API Key</label>
					<input type="text" id="deepai_key" name="fgp_options[deepai_key]" value="<?php echo esc_attr($o['deepai_key']??'');?>" placeholder="...">
				</div>
				<div class="fgp-api-field">
					<label for="huggingface_key">Hugging Face API Key</label>
					<input type="text" id="huggingface_key" name="fgp_options[huggingface_key]" value="<?php echo esc_attr($o['huggingface_key']??'');?>" placeholder="hf_...">
				</div>
				<div class="fgp-api-field">
					<label for="unsplash_key">Unsplash Access Key</label>
					<input type="text" id="unsplash_key" name="fgp_options[unsplash_key]" value="<?php echo esc_attr($o['unsplash_key']??'');?>" placeholder="...">
				</div>
				<div class="fgp-api-field">
					<label for="pexels_key">Pexels API Key</label>
					<input type="text" id="pexels_key" name="fgp_options[pexels_key]" value="<?php echo esc_attr($o['pexels_key']??'');?>" placeholder="...">
				</div>
				<div class="fgp-api-field">
					<label for="pixabay_key">Pixabay API Key</label>
					<input type="text" id="pixabay_key" name="fgp_options[pixabay_key]" value="<?php echo esc_attr($o['pixabay_key']??'');?>" placeholder="...">
				</div>
			</div>
		</div>

		<!-- Auto Publisher Section -->
		<div class="fgp-section">
			<h2>📅 Auto-Publisher</h2>
			<div style="margin: 16px 0;">
				<label style="font-weight: 500; display: block; margin-bottom: 8px;">Giorni di pubblicazione</label>
				<div class="fgp-weekdays">
					<?php foreach($days as $i=>$d): ?>
						<label class="fgp-weekday-checkbox">
							<input type="checkbox" name="fgp_options[weekdays][]" value="<?php echo $i;?>" <?php checked(in_array($i,$o['weekdays']??[]));?>>
							<span><?php echo $d;?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
			<div style="margin-top: 20px;">
				<label for="per_day" style="font-weight: 500; display: block; margin-bottom: 8px;">Articoli per giorno</label>
				<input type="number" id="per_day" name="fgp_options[per_day]" min="0" max="24" value="<?php echo esc_attr($o['per_day']??0);?>" style="padding: 8px 12px; border: 1px solid #dcdcde; border-radius: 4px; width: 100px;">
			</div>
		</div>

		<!-- Provider Priority Section -->
		<div class="fgp-section">
			<h2>🎯 Priorità Provider Immagini</h2>
			<div class="fgp-provider-priority">
				<ul id="fgp_providers">
				<?php 
				$all_providers = ['pexels','pixabay','pollinations','huggingface','openai','cohere','deepai'];
				$saved_order = $o['provider_order'] ?? [];
				
				// Ensure all providers are included
				$ordered = array_intersect($saved_order, $all_providers);
				$missing = array_diff($all_providers, $ordered);
				$final_order = array_merge($ordered, $missing);
				
				foreach($final_order as $p): 
				?>
					<li data-provider="<?php echo esc_attr($p);?>">
						<input type="hidden" name="fgp_options[provider_order][]" value="<?php echo esc_attr($p);?>">
						<strong><?php echo strtoupper($p);?></strong>
					</li>
				<?php endforeach; ?>
				</ul>
				<p class="fgp-provider-hint">
					💡 Trascina per riordinare. I provider in alto verranno provati per primi. Pexels e Pixabay sono i più veloci e affidabili.
				</p>
			</div>
		</div>

		<div class="fgp-submit">
			<?php submit_button('Salva Impostazioni', 'primary', 'submit', false); ?>
		</div>
		</form>

		<hr class="fgp-divider">

		<!-- Bulk Image Generator Section -->
		<div class="fgp-section">
			<h2>🖼️ Bulk Image Generator</h2>
			<div class="fgp-bulk-controls">
				<input type="date" id="fgp_date_start">
				<span>→</span>
				<input type="date" id="fgp_date_end">
				<button type="button" id="fgp_bulk_generate">Genera Immagini</button>
			</div>
			<p class="fgp-provider-hint" style="margin-top: 12px;">
				Rigenera le immagini in evidenza per tutti gli articoli pubblicati in questo intervallo.
			</p>
			
			<div id="fgp_bulk_status" style="display:none; margin-top: 16px;">
				<div class="progress-bar">
					<div class="progress" style="width:0%;"></div>
				</div>
			</div>
			
			<div id="fgp-bulk-log"></div>
		</div>

		<!-- Debug Log Section -->
		<div class="fgp-section">
			<h2>🔍 Debug Log</h2>
			<p class="fgp-provider-hint">Visualizza il contenuto del file di log per diagnosticare eventuali errori.</p>
			<?php
			$log_file = WP_CONTENT_DIR . '/fgp-debug.log';
			$log_content = file_exists( $log_file ) ? file_get_contents( $log_file ) : 'Nessun log presente.';
			?>
			<div class="fgp-debug-log">
				<textarea readonly style="width:100%;height:300px;"><?php echo esc_textarea( $log_content ); ?></textarea>
			</div>
			
			<form method="post" style="margin-top:12px;">
				<?php wp_nonce_field( 'fgp_clear_log_action', 'fgp_clear_log_nonce' ); ?>
				<button type="submit" name="fgp_clear_log" class="button button-secondary" onclick="return confirm('Sei sicuro di voler cancellare il log?');">Svuota Log</button>
			</form>
		</div>

	</div>
	<script>
	// Enhanced drag and drop with better visual feedback
	document.addEventListener('DOMContentLoaded',()=>{
		const list=document.getElementById('fgp_providers');
		let draggedItem = null;
		
		list.querySelectorAll('li').forEach(li=>{
			li.draggable=true;
			
			li.addEventListener('dragstart',e=>{
				draggedItem = li;
				li.classList.add('dragging');
				e.dataTransfer.effectAllowed = 'move';
			});
			
			li.addEventListener('dragend',e=>{
				li.classList.remove('dragging');
				list.querySelectorAll('li').forEach(item => item.classList.remove('drag-over'));
			});
			
			li.addEventListener('dragover',e=>{
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				if (draggedItem && draggedItem !== li) {
					li.classList.add('drag-over');
				}
			});
			
			li.addEventListener('dragleave',e=>{
				li.classList.remove('drag-over');
			});
			
			li.addEventListener('drop',e=>{
				e.preventDefault();
				li.classList.remove('drag-over');
				if(draggedItem && draggedItem !== li){
					list.insertBefore(draggedItem, li);
				}
			});
		});
	});
	</script>
	<?php }

	/* ---------------- Metabox ---------------- */
	public function add_metabox(){
		add_meta_box('fgp_writer','FreeGenie AI Pro Writer',[ $this,'render_metabox' ],'post','normal','high');
	}
	public function render_metabox( $post ){
		wp_nonce_field('fgp_nonce','fgp_nonce_field');?>
		<p><label>Prompt articolo</label><br>
		<textarea id="fgp_prompt" style="width:100%;height:70px;"></textarea></p>
		<p><label>Prompt immagine (facoltativo)</label><br>
		<input id="fgp_img_prompt" style="width:100%;"></p>
		<p><button class="button button-primary" id="fgp_generate">Genera articolo AI</button></p>
		<script>(function($){
			$('#fgp_generate').on('click',function(){
				if($(this).prop('disabled'))return;
				const p=$('#fgp_prompt').val().trim();
				if(!p){alert('Prompt articolo mancante');return;}
				$(this).prop('disabled',true);const sp=$('<span class="spinner is-active" style="margin-left:6px;"></span>').insertAfter(this);
				$.post(ajaxurl,{
					action:'fgp_generate_article',
					prompt:p,
					img_prompt:$('#fgp_img_prompt').val().trim(),
					post_id:<?php echo (int)$post->ID;?>,
					_wpnonce:$('#fgp_nonce_field').val()
				},function(r){
					sp.remove();$('#fgp_generate').prop('disabled',false);
					alert(r.success?'Articolo generato! Aggiorna.':'Errore: '+r.data);
				});
			});
		})(jQuery);</script>
	<?php }

	/* ---------------- AJAX ---------------- */
	public function ajax_generate(){
		check_ajax_referer('fgp_nonce');
		if( ! current_user_can('edit_posts') ) wp_send_json_error('Permessi');
		require_once FGP_DIR.'includes/class-generator.php';
		$g=new FreeGenie_AI_Pro_Generator((int)$_POST['post_id']);
		$r=$g->generate_full_article(
			sanitize_text_field($_POST['prompt']??''),
			sanitize_text_field($_POST['img_prompt']??'')
		);
		is_wp_error($r)?wp_send_json_error($r->get_error_message()):wp_send_json_success('ok');
	}

	public function ajax_get_posts_by_date() {
		check_ajax_referer( 'fgp_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permessi' );

		$start = sanitize_text_field( $_POST['start'] ?? '' );
		$end   = sanitize_text_field( $_POST['end'] ?? '' );

		if ( ! $start || ! $end ) wp_send_json_error( 'Date mancanti' );

		$query = new WP_Query( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'date_query'     => [
				[
					'after'     => $start,
					'before'    => $end,
					'inclusive' => true,
				],
			],
		] );

		wp_send_json_success( $query->posts );
	}

	public function ajax_regenerate_single_image() {
		set_time_limit(300);
		@ini_set( 'memory_limit', '512M' );

		register_shutdown_function( function() {
			$error = error_get_last();
			if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ] ) ) {
				if ( ob_get_length() ) ob_clean();
				wp_send_json_error( 'Fatal Error: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line'] );
			}
		} );
		
		check_ajax_referer( 'fgp_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Permessi' );

		$post_id = (int) ( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) wp_send_json_error( 'ID mancante' );

		require_once FGP_DIR . 'includes/class-generator.php';
		$g = new FreeGenie_AI_Pro_Generator( $post_id );
		$r = $g->generate_featured_image_only();

		is_wp_error( $r ) ? wp_send_json_error( $r->get_error_message() ) : wp_send_json_success( 'ok' );
	}

	public function enqueue_admin_scripts( $hook ) {
		if ( 'toplevel_page_freegenie-ai-pro' !== $hook ) return;
		wp_enqueue_style( 'fgp-admin-styles', plugins_url( 'admin-styles.css', __FILE__ ), [], FGP_VER );
		wp_enqueue_script( 'fgp-admin', plugins_url( 'admin.js', __FILE__ ), [ 'jquery' ], FGP_VER, true );
		wp_localize_script( 'fgp-admin', 'fgp_vars', [
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fgp_nonce' ),
		] );
	}
}
FreeGenie_AI_Pro::instance();
