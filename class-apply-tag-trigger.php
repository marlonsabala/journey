<?php
namespace POC_CF_CI;

if ( ! class_exists( 'apply_tag_trigger' ) ) {

    class apply_tag_trigger
    {
    	protected static $instance = null;
    	/**
    	 * Private constructor to make this a singleton
    	 *
    	 * @access private
    	 */
    	private function __construct()
    	{
    	    add_action( 'wpf_tags_applied', [ $this, 'wpf_tags_applied_cb' ], 10, 2 );
    	}

    	public static function get_instance() {
    		if ( null == self::$instance ) {
    			self::$instance = new self;
    		}
    		return self::$instance;
    	}

        public function wpf_tags_applied_cb( $user_id, $tags ) {

            // error_log('hooked action running for ' . $user_id);
            // error_log(print_r($tags, true));

            // error_log( print_r($tags, 1) );
            foreach ($tags as $tag) {
                // Here '14' is a length of 'consolidation_' text. We are checking if the tag starts with this substring.
                //if ( 'consolidation_' === substr( strtolower( $tag ), 0, 14 ) ) {

                if ( strpos( $tag, 'consolidation_' ) !== false ) {
                    // error_log('consolidation tag found');

                    // error_log('PROCESSING ITEMS CHECK');

                    $this->process_items_check( (int) $user_id );
                    // We just want to check whether we have consolidation item's tag is applied in the current list of tags.
                    break;
                }
            }
        }

        private function process_items_check( int $user_id = 0 ) {

            if ( $user_id ) {

                // Lets get all the ignored and completed items so that we can only fetch and check access of fewer items.
                $completed  = get_user_meta( $user_id, 'consolidation_completed', true );
                $ignored    = get_user_meta( $user_id, 'consolidation_ignored', true );
                $tobe       = get_user_meta( $user_id, 'consolidation_tobe', true );

                if ( empty( $completed ) ) $completed = array();
                if ( empty( $ignored ) ) $ignored = array();
                if ( empty( $tobe ) ) $tobe = array();
                $combined_status_posts = array_merge( $completed, $ignored, $tobe );


                // error_log('tobe before');
                // error_log( print_r($tobe, 1) );

                $all_items = get_posts(
                    array(
                        'fields'        => 'ids',
                        'numberposts'   => -1,
                        'post_type'     => 'consolidation',
                        'post_status'   => 'publish',
                        'post__not_in'  => $combined_status_posts,
                    )
                );

                // error_log('all items');
                // error_log( print_r($all_items, 1) );

                if ( ! empty( $all_items ) ) {
                    $flag_send_mail = false;
                    $new_item_id = 0; // used in sending email.
                    foreach ( $all_items as $item_id) {
                        if( wp_fusion()->access->user_can_access( $item_id, $user_id ) ) {
                            // error_log(' has access to ' . $item_id );
                            array_push( $tobe, $item_id );
                            $flag_send_mail = true;
                            $new_item_id = $item_id;
                        }
                    }
                    // error_log('user id ');
                    // error_log( $user_id );
                    // error_log('tobe');
                    // error_log( print_r($tobe, 1) );
                    update_user_meta( $user_id, 'consolidation_tobe', $tobe );

                    // send email for newly available items, if the user has 'member_basic' tag.
                    if ( $flag_send_mail && wp_fusion()->user->has_tag( 'member_basic', $user_id ) ) {
                        $emails_setting = get_option( 'consolidation_emails' );
                        if ( isset( $emails_setting[ 'item_available' ] ) ) {
                            $sub    = $emails_setting[ 'item_available' ][ 'sub' ];
                            $body   = $emails_setting[ 'item_available' ][ 'body' ];

                            // {nickname}
                            $userdata = get_userdata( $user_id );
                            $nickname = ( ! empty( $userdata->first_name ) ) ? $userdata->first_name : $userdata->user_login;

                            // {item_type}
                            $items = get_option( 'consolidation_items' );
                            $item_slug = get_post_meta( $new_item_id, 'item_type', true );
                            $item_type = @$items[ $item_slug ]['name'];

                            $task   = get_post( $new_item_id );

                            // {task_title}
                            $task_title = $task->post_title;

                            // {task_desc}
                            // $task_desc =  apply_filters( 'the_content', $task->post_content );
                            $task_desc =  $task->post_content;

                            $search = array( '{nickname}', '{task_title}', '{item_type}', '{task_desc}' );
                            $replace = array( $nickname, $task_title, $item_type, $task_desc );

                            $sub  = str_replace( $search, $replace, $sub );
                            $body = do_shortcode( str_replace( $search, $replace, $body ) );

                            $headers = array('Content-Type: text/html; charset=UTF-8');

                            wp_mail( $userdata->user_email, $sub, $body, $headers );
                            // $this->send_email_via_intercom( $user_id, $sub, $body );
                        }
                    }
                }
            }
        }

        /**
         * DEPRECATED.
         */
        private function send_email_via_intercom( $user_id, $subject, $body ) {

            error_log( 'Sending intercom email to user: ' . $user_id );
            // $ch_user = get_user_by( 'email', 'marlonsabala@gmail.com' );

            $intercom_id = get_user_meta( $user_id, 'wpf_intercom_contact_id', true );

            error_log( 'Intercom ID is: ' . $intercom_id );

            if ( $intercom_id ) {
                $ch = curl_init();

                curl_setopt( $ch, CURLOPT_URL, 'https://api.intercom.io/messages' );
                curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
                curl_setopt( $ch, CURLOPT_POST, 1 );
                curl_setopt( $ch, CURLOPT_POSTFIELDS, json_encode( 
                    array( 
                        "type"          => "admin",
                        "message_type"  => "email",
                        "subject"       => $subject,
                        "body"          => $body,
                        "template"      => "personal",
                        "from"          => array( "type" => "admin", "id" => "1425743" ),
                        "to"            => array( "type" => "user", "id" => $intercom_id ),
                    )
                ));

                $headers = array();
                $headers[] = 'Authorization: Bearer dG9rOjU1YmMwYTAyX2MzNDhfNGQ1YV9iODBhX2E2NGM5ZTUzNzQ4ZDoxOjA=';
                $headers[] = 'Accept: application/json';
                $headers[] = 'Content-Type: application/json';
                curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );

                $result = curl_exec( $ch );

                error_log( 'Intercom API response: ' . print_r( $result, 1 ) );

                if ( curl_errno( $ch ) ) {
                    error_log( 'Error:' . curl_error( $ch ) );
                }
                curl_close( $ch );
            }
        }
    }

    apply_tag_trigger::get_instance();
}
