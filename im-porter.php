<?php

/*
Plugin Name: IM-porter
Description: Import chat transcripts into WordPress.
Author: cfinke
Version: 1.0
License: GPLv2 or later
*/

if ( ! defined( 'WP_LOAD_IMPORTERS' ) )
	return;

define( 'IMPORT_DEBUG', true );

require_once ABSPATH . 'wp-admin/includes/import.php';

if ( ! class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';

	if ( file_exists( $class_wp_importer ) )
		require $class_wp_importer;
}

/**
 * Chat Importer imports chat transcripts into WordPress.
 *
 * @package WordPress
 * @subpackage Importer
 */
if ( class_exists( 'WP_Importer' ) ) {
	class IMporter_Import extends WP_Importer {
		var $posts = array();

		var $id = null;
		var $author = null;
		var $status = null;
		var $autotag = false;
		var $category = null;

		var $date = null;

		public function dispatch() {
			$this->header();

			$step = empty( $_GET['step'] ) ? 0 : intval( $_GET['step'] );

			switch ( $step ) {
				case 0:
					$this->greet();
				break;
				case 1:
					check_admin_referer( 'import-upload' );

					if ( $this->handle_upload() )
						$this->import_options();
				break;
				case 2:
					check_admin_referer( 'import-chats' );
					$this->id = intval( $_POST['import_id'] );
					$this->author = (int) $_POST['author'];
					$this->status = $_POST['status'] == 'private' ? 'private' : 'public';
					$this->autotag = isset( $_POST['autotag'] );
					$this->category = (int) $_POST['cat'];
					
					$file = get_attached_file( $this->id );
					set_time_limit( 0 );
					$this->import( $file );
				break;
			}

			$this->footer();
		}

		function handle_upload() {
			$file = wp_import_handle_upload();

			if ( isset( $file['error'] ) ) {
				echo '<p><strong>' . __( 'Sorry, there has been an error.', 'chat-importer' ) . '</strong><br />';
				echo esc_html( $file['error'] ) . '</p>';
				return false;
			} else if ( ! file_exists( $file['file'] ) ) {
				echo '<p><strong>' . __( 'Sorry, there has been an error.', 'chat-importer' ) . '</strong><br />';
				printf( __( 'The export file could not be found at <code>%s</code>. It is likely that this was caused by a permissions problem.', 'chat-importer' ), esc_html( $file['file'] ) );
				echo '</p>';
				return false;
			}

			$this->id = (int) $file['id'];

			return true;
		}

		function import_options() {
			$j = 0;

			?>
			<form action="<?php echo admin_url( 'admin.php?import=chats&step=2' ); ?>" method="post">
				<?php wp_nonce_field( 'import-chats' ); ?>
				<input type="hidden" name="import_id" value="<?php echo $this->id; ?>" />
				
				<table class="form-table">
					<tbody>
						<tr>
							<th scope="row">
								<label><?php esc_html_e( 'Set post statuses as...', 'chat-importer' ); ?></label>
							</th>
							<td>
								<label>
									<input name="status" type="radio" value="private" checked="checked" />
									<?php esc_html_e( 'Private', 'chat-importer' ); ?>
								</label>
								<label>
									<input name="status" type="radio" value="public" />
									<?php esc_html_e( 'Public', 'chat-importer' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row">
								<label for="chat-importer-autotag"><?php esc_html_e( 'Tag posts with participant usernames', 'chat-importer' ); ?></label>
							</th>
							<td>
								<input name="autotag" type="checkbox" value="1" id="chat-importer-autotag" checked="checked" />
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<label for="category"><?php esc_html_e( 'Import posts into Category', 'chat-importer' ) ?></label>
							</th>
							<td>
								<?php wp_dropdown_categories( array(
									'hide_empty' => false,
									'id' => 'category',
									'hierarchical' => true,
									) ); ?>
								(<a href="edit-tags.php?taxonomy=category"><?php _e( 'Add New Category', 'chat-importer' ); ?></a>)
							</td>
						</tr>
						<tr valign="top">
							<th scope="row">
								<?php esc_html_e( 'Assign posts to:', 'chat-importer' ); ?>
							</th>
							<td>
								<?php wp_dropdown_users( array( 'name' => 'author', 'selected' => get_current_user_id() ) ); ?>
							</td>
						</tr>
					</tbody>
				</table>

				<p class="submit"><input type="submit" class="button-primary" value="<?php esc_attr_e( 'Import Transcripts', 'chat-importer' ); ?>" /></p>
			</form>
			<?php
		}
		
		private function import( $file ) {
			if ( function_exists( 'zip_open' ) && stripos( $file, '.zip' ) !== false ) {
				if ( $zip_handle = zip_open( $file ) ) {
					while ( $zip_entry = zip_read( $zip_handle ) ) {
						$filepath = zip_entry_name( $zip_entry );
						$filename = basename( $filepath );

						if ( $filename[0] == '_' || $filename[0] == '.' )
							continue;

						$filesize = zip_entry_filesize( $zip_entry );
						
						if ( $filesize > 0 && zip_entry_open( $zip_handle, $zip_entry, "r" ) ) {
							$this->import_file(
								$filename,
								zip_entry_read( $zip_entry, $filesize )
							);
						}
					}
					
					zip_close( $zip_handle );
				}
			}
			else {
				$this->import_file( basename( $file ), file_get_contents( $file ) );
			}
			
			$this->process_posts();
			$this->import_end();
		}
		
		private function import_file( $filename, $file_contents, $date = null ) {
			if ( preg_match( '/[0-9]{4}-[0-9]{2}-[0-9]{2}/', $filename ) ) {
				$this->date = substr( $filename, 0, 10 );
			}
			else if ( $date ) {
				$this->date = $date;
			}
			else {
				$this->date = date( 'Y-m-d' );
			}
			
			$this->import_posts( $file_contents );
		}

		private function import_posts( $chat_contents ) {
			$raw_transcript = $chat_contents;
			
			$chats = array();
			
			if ( preg_match( '/^<HTML>/', $chat_contents ) ) {
				// AIM HTML log
				
				// Find the first timestamp in the chat.
				if ( preg_match_all( '/\(([0-9]+):([0-9]+):([0-9]+) ([AP]M)\)/', $chat_contents, $time_matches ) ) {
					if ( $time_matches[4][0] == 'PM' ) {
						if ( $time_matches[1][0] != '12' ) {
							$time_matches[1][0] += 12;
						}
					}
					else if ( $time_matches[1][0] == '12' ) {
						$time_matches[1][0] = 0;
					}

					$time = sprintf( '%02d:%02d:%02d', $time_matches[1][0], $time_matches[2][0], $time_matches[3][0] );

					$timestamp = $this->date . ' ' . $time;
				}
				else {
					$timestamp = $this->date . ' 00:00:00';
				}
			
				$chats[ $timestamp ] = $this->clean_log_aim( $chat_contents );
			}
			else if ( preg_match_all( '/^Conversation with ([\S]+) at ([^\n]*?) on/', $chat_contents, $matches ) ) {
				// AIM text log
				$timestamp = $matches[2][0];
				
				$chats[ $timestamp ] = array_pop( explode( "\n", $chat_contents, 2 ) );
			}
			else if ( preg_match( '/^<\?xml/', $chat_contents ) ) {
				// MSN log
				$chats = $this->clean_log_msn( $chat_contents );
			}
			else {
				$chats[ date( 'Y-m-d H:i:s' ) ] = $chat_contents;
			}
			
			foreach ( $chats as $timestamp => $chat_contents ) {
				preg_match_all( '/\n(?<username>[^\(^\n]+) \([^\)\n]+\): /', "\n" . $chat_contents, $username_matches );
			
				$usernames = array_unique( array_map( 'trim', $username_matches['username'] ) );
				
				foreach ( $usernames as $index => $username ) {
					if ( strpos( $usernames[$index], 'Auto response from' ) !== false ) {
						unset( $usernames[$index] );
					}
					else if ( strpos( $usernames[$index], 'signed off' ) !== false ) {
						unset( $usernames[$index] );
					}
					else if ( strpos( $usernames[$index], 'signed on' ) !== false ) {
						unset( $usernames[$index] );
					}
					else if ( strpos( $usernames[$index], 'Session concluded' ) !== false ) {
						unset( $usernames[$index] );
					}
				}
				
				$usernames = array_values( $usernames );
				
				if ( count( $usernames ) > 0 ) {
					if ( count( $usernames ) == 1 )
						$title = sprintf( __( 'Conversation with %s', 'chat-importer' ), $usernames[0] );
					else if ( count( $usernames ) == 2 )
						$title = sprintf( _x( 'Conversation between %1$s and %2$s', 'The two placeholders are each a single username.', 'chat-importer' ), $usernames[0], $usernames[1] );
					else
						$title = sprintf( _x( 'Conversation between %1$s, and %2$s', 'The first placeholder is a comma-separated list of usernames; the second placeholder is a single username.', 'chat-importer' ), implode( ', ', array_slice( $usernames, 0, count( $usernames ) - 1 ) ), $usernames[-1] );
					
					$title = apply_filters( 'chat_importer_post_title', $title, $usernames, $chat_contents );
					
					$this->posts[] = array(
						'post_title' => $title,
						'post_date_gmt' => get_gmt_from_date( $timestamp ),
						'post_date' => $timestamp,
						'post_content' => $this->chat_markup( $chat_contents ),
						'post_status' => $this->status,
						'post_category' => array( $this->category ),
						'post_author' => $this->author,
						'tags' => $this->autotag ? $usernames : array(),
						'transcript_raw' => $raw_transcript,
					);
				}
			}
		}

		private function process_posts() {
			$this->posts = apply_filters( 'wp_import_posts', $this->posts );
			
			foreach ( $this->posts as $post ) {
				$post = apply_filters( 'wp_import_post_data_raw', $post );
				
				$post_id = wp_insert_post( $post );
				
				if ( ! empty( $post['tags'] ) ) {
					wp_set_post_tags( $post_id, $post['tags'], true );
				}
				
				add_post_meta( $post_id, 'raw_import_data', $post['transcript_raw'] );
				
				set_post_format( $post_id, 'chat' );
			}
		}

		private function header() {
			echo '<div class="wrap">';
			screen_icon();
			echo '<h2>' . __( 'Import Chat Transcripts', 'chat-importer' ) . '</h2>';
		}

		private function footer() {
			echo '</div>';
		}

		private function greet() {
			echo '<div class="narrow">';
			wp_import_upload_form( 'admin.php?import=chats&step=1' );
			echo '</div>';
		}
		
		private function import_end() {
			wp_import_cleanup( $this->id );

			wp_cache_flush();
			foreach ( get_taxonomies() as $tax ) {
				delete_option( "{$tax}_children" );
				_get_term_hierarchy( $tax );
			}

			wp_defer_term_counting( false );
			wp_defer_comment_counting( false );

			echo '<p>' . __( 'All done.', 'chat-importer' ) . ' <a href="' . admin_url() . '">' . __( 'Have fun!', 'chat-importer' ) . '</a>' . '</p>';
			echo '<p>' . __( 'Remember to update the passwords and roles of imported users.', 'chat-importer' ) . '</p>';

			do_action( 'import_end' );
		}
		
		private function clean_log_aim( $chat_contents ) {
			// br2nl
			$chat_contents = preg_replace( '/\<br(\s*)?\/?\>/i', "\n", $chat_contents );

			// Make comments not comments
			$chat_contents = str_replace( array( '<!--', '-->' ), '', $chat_contents );

			// Strip all tags.
			$chat_contents = strip_tags( $chat_contents, array( 'hr', 'HR' ) );
			
			$chat_contents = html_entity_decode( $chat_contents );

			// Remove whitespace-only lines
			$chat_contents = implode( "\n", array_map( 'trim', explode( "\n", $chat_contents ) ) );

			// Remove consecutive newlines
			$chat_contents = preg_replace( "/\n{2,}/", "\n", $chat_contents );

			return $chat_contents;
		}
		
		/**
		 * MSN Messenger stores all chats with a contact in a single XML log.
		 */
		private function clean_log_msn( $log_contents ) {
			$chats = array();
			$xml = simplexml_load_string( $log_contents );

			$last_date = '';
			$chat_time = '';
			$chat_contents = '';

			foreach ( $xml->Message as $message ) {
				$date = (string) $message['Date'];

				if ( $date != $last_date ) {
					if ( $chat_contents ) {
						$chats[ date( 'Y-m-d H:i:s', strtotime( $date . ' ' . $chat_time ) ) ] = $chat_contents;
						$chat_contents = '';
					}

					$chat_time = (string) $message['Time'];
					$last_date = $date;
				}
				
				$chat_contents .= trim( str_replace( '(E-mail Address Not Verified)', '', (string) $message->From->User['FriendlyName'] ) ) . ' (' . (string) $message['Time'] . '): ' . (string) $message->Text . "\n";
			}

			if ( $chat_contents )
				$chats[ date( 'Y-m-d H:i:s', strtotime( $date . ' ' . $chat_time ) ) ] = $chat_contents;
			
			return $chats;
		}
		
		private function chat_markup( $chat, $timestamp = null, $chat_with = null ) {
			$chat_html = '';
			$participants = array();

			$lines = explode( "\n", trim( $chat ) );

			foreach ( $lines as $line ) {
				if ( strpos( $line, ': ' ) !== false ) {
					list( $prefix, $message ) = array_map( 'trim', explode( ': ', $line, 2 ) );
					
					if ( preg_match_all( '/\([^\)]+\)$/', $prefix, $parenthetical ) ) {
						$prefix = trim( str_replace( $parenthetical[0][0], '', $prefix ) ) . ' <time>' . $parenthetical[0][0] . '</time>';
						$participant = array_shift( explode( ' <time>', $prefix, 2 ) );
					}
					else {
						$participant = $prefix;
					}
					
					$class = "participant";
					
					if ( in_array( $participant, $participants ) ) {
						$class = "participant-" . ( array_search( $participant, $participants ) + 1 );
					}
					else {
						$participants[] = $participant;
						$class = "participant-" . count( $participants );
					}

					$chat_html .= '<p><span class="' . $class . '">' . $prefix . '</span>: ' . $message . '</p>';
				}
				else {
					$chat_html .= '<p>' . $line . '</p>';
				}
			}

			return $chat_html;
		}
	}

	$im_porter = new IMporter_Import();
	register_importer( 'chats', 'Chat Transcripts', __( 'Import chat transcripts (AIM, MSN) as posts.', 'chat-importer' ), array( $im_porter, 'dispatch' ) );
}