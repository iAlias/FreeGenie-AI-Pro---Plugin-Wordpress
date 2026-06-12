<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

class FreeGenie_AI_Pro_Generator {

	private $post_id;
	private $opts;
	private $last_error = '';

	public function __construct( $post_id ) {
		$this->post_id = $post_id;
		$this->opts    = get_option( 'fgp_options', [] );
	}

	private function log( $msg ) {
		// Standard WP Error Log for maximum reliability
		error_log( "[FGP] [Post {$this->post_id}] $msg" );
	}

	/* ==========================================================================
	   PUBLIC METHODS
	   ========================================================================== */

	public function generate_full_article( $prompt, $imgPrompt = '' ) {
		$title = $this->catchy_title( $prompt );
		wp_update_post( [ 'ID' => $this->post_id, 'post_title' => $title, 'post_status' => 'draft' ] );

		$body = $this->provider_chain_article( $prompt, $title );
		if ( is_wp_error( $body ) ) return $body;

		$finalImgPrompt = $imgPrompt ?: $title;
		$attachId = $this->generate_image_chain( $finalImgPrompt );
		
		if ( $attachId ) {
			set_post_thumbnail( $this->post_id, $attachId );
		}

		$body .= "\n<hr><details><summary><strong>Prompt articolo & immagine</strong></summary>"
		       . "<p><em>" . esc_html( $prompt ) . "</em></p>"
		       . "<p><em>" . esc_html( $finalImgPrompt ) . "</em></p></details>";

		wp_update_post( [ 'ID' => $this->post_id, 'post_content' => wp_kses_post( $body ) ] );
		return true;
	}

	public function generate_featured_image_only( $title_override = '' ) {
		$post = get_post( $this->post_id );
		if ( ! $post ) return new WP_Error( 'no_post', 'Post non trovato' );

		$this->log( "=== START Image Gen V2 for: [{$post->post_title}] (ID: {$this->post_id}) ===" );

		// 1. Smart Prompt (Fast)
		$prompt = $this->generate_smart_image_prompt( $post );
		$this->log( "Prompt: $prompt" );

		// 2. Provider Chain
		$result = $this->generate_image_chain( $prompt );

		if ( $result['success'] ) {
			set_post_thumbnail( $this->post_id, $result['attach_id'] );
			$this->log( "✓ SUCCESS! Provider: {$result['provider']} | Attach ID: {$result['attach_id']} | Article: [{$post->post_title}]" );
			return true;
		}

		$this->log( "✗ FAILED for [{$post->post_title}]. Last Error: " . $this->last_error );
		return new WP_Error( 'image_failed', 'Errore: ' . ( $this->last_error ?: 'Tutti i provider hanno fallito.' ) );
	}

	/* ==========================================================================
	   IMAGE LOGIC (V2 - Reliable)
	   ========================================================================== */

	private function generate_image_chain( $prompt ) {
		// Default: Pexels -> Pixabay -> Pollinations -> Hugging Face -> DeepAI -> DALL-E -> Unsplash
		$chain = [ 'pexels', 'pixabay', 'pollinations', 'huggingface', 'deepai', 'openai', 'unsplash' ];
		
		// Respect user order if set
		$configured = $this->opts['provider_order'] ?? [];
		if ( ! empty( $configured ) ) {
			$custom = array_intersect( $configured, $chain );
			$remaining = array_diff( $chain, $custom );
			$chain = array_merge( $custom, $remaining );
		}

		foreach ( $chain as $provider ) {
			$this->log( "→ Trying provider: " . strtoupper($provider) );
			$result = false;
			
			// Strict Timeout logic inside each provider
			switch ( $provider ) {
				case 'pexels':       $result = $this->provider_pexels( $prompt ); break;
				case 'pixabay':      $result = $this->provider_pixabay( $prompt ); break;
				case 'pollinations': $result = $this->provider_pollinations( $prompt ); break;
				case 'huggingface':  $result = $this->provider_huggingface( $prompt ); break;
				case 'deepai':       $result = $this->provider_deepai( $prompt ); break;
				case 'openai':       $result = $this->provider_dalle( $prompt ); break;
				case 'unsplash':     $result = $this->provider_unsplash( $prompt ); break;
			}

			if ( $result ) {
				$this->log( "  ✓ Provider " . strtoupper($provider) . " returned data. Attaching..." );
				$attach_id = $this->safe_attach_image( $result, $prompt );
				if ( $attach_id ) {
					return [ 'success' => true, 'provider' => strtoupper($provider), 'attach_id' => $attach_id ];
				}
			} else {
				$this->log( "  ✗ Provider " . strtoupper($provider) . " failed or returned empty" );
			}
		}

		return [ 'success' => false ];
	}

	/* --- Providers --- */

	private function provider_pexels( $prompt ) {
		$key = $this->opts['pexels_key'] ?? '';
		if ( ! $key ) return false;
		// Pexels search
		$url = "https://api.pexels.com/v1/search?query=" . urlencode( $prompt ) . "&per_page=1&orientation=landscape";
		$r = wp_remote_get( $url, [ 'headers' => [ 'Authorization' => $key ], 'timeout' => 10 ] );
		
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) {
			$this->last_error = 'Pexels Error'; return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		return $data['photos'][0]['src']['large2x'] ?? false;
	}

	private function provider_pixabay( $prompt ) {
		$key = $this->opts['pixabay_key'] ?? '';
		if ( ! $key ) return false;
		// Pixabay search
		$url = "https://pixabay.com/api/?key={$key}&q=" . urlencode( $prompt ) . "&image_type=photo&orientation=horizontal&per_page=3";
		$r = wp_remote_get( $url, [ 'timeout' => 10 ] );
		
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) {
			$this->last_error = 'Pixabay Error'; return false;
		}
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		return $data['hits'][0]['largeImageURL'] ?? false;
	}

	private function provider_pollinations( $prompt ) {
		// Direct URL, no API call needed to check availability, just try to download
		$encoded = urlencode( $prompt );
		return "https://image.pollinations.ai/prompt/{$encoded}?width=1280&height=720&nologo=true&model=flux";
	}

	private function provider_huggingface( $prompt ) {
		$key = $this->opts['huggingface_key'] ?? '';
		if ( ! $key ) return false;
		$url = "https://api-inference.huggingface.co/models/black-forest-labs/FLUX.1-dev";
		$r = wp_remote_post( $url, [
			'headers' => [ 'Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'inputs' => $prompt ] ),
			'timeout' => 45 // AI takes longer
		] );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) {
			$this->last_error = 'HF Error'; return false;
		}
		return wp_remote_retrieve_body( $r ); // Binary
	}

	private function provider_deepai( $prompt ) {
		$key = $this->opts['deepai_key'] ?? '';
		if ( ! $key ) return false;
		$r = wp_remote_post( 'https://api.deepai.org/api/image-generator', [
			'headers' => [ 'api-key' => $key ], 'body' => [ 'text' => $prompt ], 'timeout' => 45
		]);
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return false;
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		return $d['output_url'] ?? false;
	}

	private function provider_dalle( $prompt ) {
		$key = $this->opts['openai_key'] ?? '';
		if ( ! $key ) return false;
		$r = wp_remote_post( 'https://api.openai.com/v1/images/generations', [
			'headers' => [ 'Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [ 'model' => 'dall-e-3', 'prompt' => $prompt, 'n' => 1, 'size' => '1024x1024' ] ),
			'timeout' => 60
		]);
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return false;
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		return $d['data'][0]['url'] ?? false;
	}

	private function provider_unsplash( $prompt ) {
		$key = $this->opts['unsplash_key'] ?? '';
		if ( ! $key ) return false;
		$r = wp_remote_get( "https://api.unsplash.com/search/photos?query=" . urlencode( $prompt ) . "&client_id={$key}&per_page=1", [ 'timeout' => 10 ] );
		if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) return false;
		$d = json_decode( wp_remote_retrieve_body( $r ), true );
		return $d['results'][0]['urls']['regular'] ?? false;
	}

	/* --- Safe Attachment --- */
	private function safe_attach_image( $data, $filename_base ) {
		try {
			if ( ! $data ) return 0;
			$filename = sanitize_file_name( $filename_base ) . '.jpg';
			
			// 1. Get Bits
			if ( filter_var( $data, FILTER_VALIDATE_URL ) ) {
				$r = wp_remote_get( $data, [ 'timeout' => 30, 'sslverify' => false ] );
				if ( is_wp_error( $r ) || wp_remote_retrieve_response_code( $r ) !== 200 ) {
					$this->last_error = 'Download Failed'; return 0;
				}
				$bits = wp_remote_retrieve_body( $r );
			} else {
				$bits = $data;
			}

			if ( empty( $bits ) ) return 0;

			// 2. Save
			$upload = wp_upload_bits( $filename, null, $bits );
			if ( ! empty( $upload['error'] ) ) {
				$this->last_error = 'Upload Error: ' . $upload['error']; return 0;
			}

			// 3. Insert
			$attachment = [
				'post_mime_type' => 'image/jpeg', // Force jpeg for simplicity
				'post_title'     => $filename_base,
				'post_content'   => '',
				'post_status'    => 'inherit'
			];
			$attach_id = wp_insert_attachment( $attachment, $upload['file'], $this->post_id );
			if ( is_wp_error( $attach_id ) ) {
				$this->last_error = 'DB Error'; return 0;
			}

			// 4. Metadata (Try-Catch wrapper)
			try {
				require_once( ABSPATH . 'wp-admin/includes/image.php' );
				$attach_data = wp_generate_attachment_metadata( $attach_id, $upload['file'] );
				wp_update_attachment_metadata( $attach_id, $attach_data );
			} catch ( Throwable $t ) {
				$this->log( "Metadata generation failed (non-fatal): " . $t->getMessage() );
				// Continue anyway, we have the image
			}
			
			update_post_meta( $attach_id, '_wp_attached_file', _wp_relative_upload_path( $upload['file'] ) );
			return $attach_id;

		} catch ( Throwable $e ) {
			$this->last_error = 'Fatal: ' . $e->getMessage();
			$this->log( "CRITICAL: " . $e->getMessage() );
			return 0;
		}
	}

	private function generate_smart_image_prompt( $post ) {
		// Simplified for speed/memory
		$raw = substr( $post->post_content, 0, 5000 );
		$snippet = mb_substr( wp_strip_all_tags( $raw ), 0, 500 );
		
		if ( ! empty( $this->opts['openai_key'] ) ) {
			$sys = "Create a 3-word visual search term for this article. No formatting.";
			$p = $this->openai_simple( $sys . "\n\n" . $snippet );
			if ( $p ) return trim( $p, '"' );
		}
		return $post->post_title;
	}

	/* --- Text Logic (Legacy) --- */
	private function catchy_title( $draft ){
		if( preg_match('/su\\s+(.+)/i',$draft,$m) ) $draft=$m[1];
		$p="Crea un titolo max 60 caratteri, accattivante e chiaro in italiano: {$draft}";
		return wp_strip_all_tags( $this->openai_simple($p) ?: wp_trim_words($draft,10) );
	}
	private function provider_chain_article( $prompt, $title ){
		$order=$this->opts['provider_order']??['openai','cohere','deepai'];
		foreach($order as $p){
			$out = $p==='openai' ? $this->openai_article($prompt,$title)
			     : ($p==='cohere' ? $this->cohere_article($prompt)
			     : ($p==='deepai' ? $this->deepai_article($prompt) : ''));
			if( ! is_wp_error($out) && trim($out) ) return $out;
		}
		return new WP_Error('provider','Tutti i provider hanno fallito');
	}
	private function openai_article( $prompt, $title ){
		$key=$this->opts['openai_key']??''; if(!$key)return new WP_Error('openai','Key mancante');
		$sys="Scrivi un articolo ≥4000 parole in italiano, stile Wired. Includi fonti recenti (<12 mesi) in fondo (<h2>Fonti</h2>). Struttura H1 ({$title}) > H2/H3, pro/contro, FAQ (schema.org) conclusione. Argomento: {$prompt}. Restituisci HTML completo.";
		$r=wp_remote_post('https://api.openai.com/v1/chat/completions',[
			'headers'=>['Authorization'=>"Bearer {$key}",'Content-Type'=>'application/json'],
			'body'=>wp_json_encode(['model'=>'gpt-4o','messages'=>[['role'=>'user','content'=>$sys]],'max_tokens'=>4096,'temperature'=>0.7]),
			'timeout'=>120
		]);
		return $this->handle_resp($r,'openai');
	}
	private function cohere_article( $prompt ){
		$key=$this->opts['cohere_key']??''; if(!$key)return new WP_Error('cohere','Key mancante');
		$r=wp_remote_post('https://api.cohere.ai/v1/generate',[
			'headers'=>['Authorization'=>"Bearer {$key}",'Content-Type'=>'application/json'],
			'body'=>wp_json_encode(['model'=>'command','prompt'=>$prompt,'max_tokens'=>2048,'temperature'=>0.7]),
			'timeout'=>60]);
		return $this->handle_resp($r,'cohere','generations/0/text');
	}
	private function deepai_article( $prompt ){
		$key=$this->opts['deepai_key']??''; if(!$key)return new WP_Error('deepai','Key mancante');
		$r=wp_remote_post('https://api.deepai.org/api/text-generator',['headers'=>['api-key'=>$key],'body'=>['text'=>$prompt],'timeout'=>60]);
		return $this->handle_resp($r,'deepai','output');
	}
	private function openai_simple( $p ){
		$key=$this->opts['openai_key']??''; if(!$key)return '';
		$r=wp_remote_post('https://api.openai.com/v1/chat/completions',['headers'=>['Authorization'=>"Bearer {$key}",'Content-Type'=>'application/json'],'body'=>wp_json_encode(['model'=>'gpt-4o','messages'=>[['role'=>'user','content'=>$p]],'max_tokens'=>60,'temperature'=>0.9]),'timeout'=>30]);
		if(is_wp_error($r)||wp_remote_retrieve_response_code($r)!==200)return '';
		return json_decode(wp_remote_retrieve_body($r),true)['choices'][0]['message']['content']??'';
	}
	private function handle_resp( $res,$tag,$path=null ){
		if(is_wp_error($res))return $res;
		if(wp_remote_retrieve_response_code($res)!==200)return new WP_Error($tag,wp_remote_retrieve_body($res));
		$data=json_decode(wp_remote_retrieve_body($res),true);
		if($path){
			foreach(explode('/',$path) as $segment) $data = $data[$segment] ?? null;
		}else{
			$data=$data['choices'][0]['message']['content']??'';
		}
		return $data;
	}
}
