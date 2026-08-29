<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Instant_Indexing {
    const CRON = 'seom_indexing_retry';
    const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    const PUBLISH_ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
    const STATUS_ENDPOINT = 'https://indexing.googleapis.com/v3/urlNotifications/metadata';
    const SCOPE = 'https://www.googleapis.com/auth/indexing';

    public static function init(): void {
        add_action('admin_post_seom_indexing_save',[__CLASS__,'save_settings']);
        add_action('admin_post_seom_indexing_upload',[__CLASS__,'upload_json']);
        add_action('admin_post_seom_indexing_submit',[__CLASS__,'admin_submit']);
        add_action('admin_post_seom_indexing_status',[__CLASS__,'admin_status']);
        add_action('admin_post_seom_indexing_clear',[__CLASS__,'clear_logs']);
        add_action('wp_ajax_seom_indexing_submit',[__CLASS__,'ajax_submit']);
        add_action('wp_ajax_seom_indexing_status',[__CLASS__,'ajax_status']);
        add_action(self::CRON,[__CLASS__,'retry_queued']);
        add_action('save_post',[__CLASS__,'on_save'],30,3);
        add_action('trashed_post',[__CLASS__,'on_trash'],30,1);
        self::ensure_schedule();
    }

    private static function ensure_schedule(): void {
        if (!wp_next_scheduled(self::CRON)) wp_schedule_event(time()+10*MINUTE_IN_SECONDS,'hourly',self::CRON);
    }

    public static function configured(): bool {
        $raw=(string)self::decrypt((string)seom_get('indexing_service_account',''));
        $json=json_decode($raw,true);
        return is_array($json) && !empty($json['client_email']) && !empty($json['private_key']);
    }

    public static function service_account(): array {
        $raw=(string)self::decrypt((string)seom_get('indexing_service_account',''));
        $json=json_decode($raw,true);
        return is_array($json)?$json:[];
    }

    public static function save_settings(): void {
        if(!current_user_can('manage_options') || !check_admin_referer('seom_indexing_save')) wp_die('Unauthorized.');
        foreach(['indexing_auto_publish','indexing_auto_delete','indexing_strict_mode'] as $k) seom_update($k,isset($_POST[$k])?1:0);
        seom_update('indexing_enabled',isset($_POST['indexing_enabled'])?1:0);
        seom_update('indexing_daily_limit',max(1,min(10000,absint($_POST['indexing_daily_limit']??200))));
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'instant','saved'=>1],admin_url('admin.php'))); exit;
    }

    public static function upload_json(): void {
        if(!current_user_can('manage_options') || !check_admin_referer('seom_indexing_upload')) wp_die('Unauthorized.');
        if(empty($_FILES['indexing_service_json']['tmp_name'])) self::back('missing');
        $name=sanitize_file_name((string)$_FILES['indexing_service_json']['name']);
        if(strtolower(pathinfo($name,PATHINFO_EXTENSION))!=='json') self::back('format');
        $raw=file_get_contents($_FILES['indexing_service_json']['tmp_name']);
        $data=json_decode((string)$raw,true);
        if(!is_array($data)||($data['type']??'')!=='service_account'||empty($data['client_email'])||empty($data['private_key'])||empty($data['project_id'])) self::back('invalid');
        seom_update('indexing_service_account',self::encrypt(wp_json_encode($data)));
        seom_update('indexing_enabled',1);
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'instant','uploaded'=>1],admin_url('admin.php'))); exit;
    }

    public static function admin_submit(): void {
        if(!current_user_can('manage_options') || !check_admin_referer('seom_indexing_manual')) wp_die('Unauthorized.');
        $post_id=absint($_GET['post_id']??0); $url=$post_id?get_permalink($post_id):'';
        $result=$url?self::submit([$url],'update',$post_id,true):new WP_Error('bad_url','Invalid post.');
        self::back(is_wp_error($result)?'error':'submitted',is_wp_error($result)?$result->get_error_message():'');
    }

    public static function ajax_submit(): void {
        check_ajax_referer('seom_admin','nonce'); if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'Unauthorized.']);
        $urls=[]; foreach((array)($_POST['urls']??[]) as $u){$u=esc_url_raw(wp_unslash((string)$u)); if($u)$urls[]=$u;}
        $action=in_array($_POST['type']??'update',['update','delete'],true)?sanitize_key($_POST['type']):'update';
        if(!$urls) wp_send_json_error(['message'=>'No valid URLs supplied.']);
        $result=self::submit(array_slice(array_values(array_unique($urls)),0,100),$action,0,true);
        if(is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()]);
        wp_send_json_success($result);
    }

    public static function ajax_status(): void {
        check_ajax_referer('seom_admin','nonce'); if(!current_user_can('manage_options')) wp_send_json_error(['message'=>'Unauthorized.']);
        $url=esc_url_raw(wp_unslash($_POST['url']??'')); if(!$url) wp_send_json_error(['message'=>'Enter a valid URL.']);
        $result=self::status($url); if(is_wp_error($result)) wp_send_json_error(['message'=>$result->get_error_message()]); wp_send_json_success($result);
    }

    public static function admin_status(): void {
        if(!current_user_can('manage_options')||!check_admin_referer('seom_indexing_status')) wp_die('Unauthorized.');
        $url=esc_url_raw(wp_unslash($_GET['url']??'')); $r=self::status($url);
        set_transient('seom_indexing_status_result',is_wp_error($r)?['error'=>$r->get_error_message()]:['data'=>$r],60);
        self::back('status');
    }

    public static function clear_logs(): void {
        if(!current_user_can('manage_options')||!check_admin_referer('seom_indexing_clear')) wp_die('Unauthorized.');
        global $wpdb; $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}seom_indexing_logs");
        wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'instant','cleared'=>1],admin_url('admin.php'))); exit;
    }

    public static function on_save(int $post_id, WP_Post $post, bool $update): void {
        if(wp_is_post_revision($post_id)||wp_is_post_autosave($post_id)||$post->post_status!=='publish') return;
        if(!(int)seom_get('indexing_enabled',0)||!(int)seom_get('indexing_auto_publish',1)) return;
        if(!current_user_can('edit_post',$post_id)) return;
        $url=get_permalink($post_id); if(!$url) return;
        if(!self::eligible($post_id,$url)) return;
        self::submit([$url],'update',$post_id,false);
    }

    public static function on_trash(int $post_id): void {
        if(!(int)seom_get('indexing_enabled',0)||!(int)seom_get('indexing_auto_delete',1)) return;
        $url=get_permalink($post_id); if(!$url) return;
        if(!self::eligible($post_id,$url)) return;
        self::submit([$url],'delete',$post_id,false);
    }

    private static function eligible(int $post_id,string $url): bool {
        if(!(int)seom_get('indexing_strict_mode',1)) return true;
        $schema=get_post_meta($post_id,'_seom_schema',true);
        $haystack=strtolower((string)$schema.' '.get_post_field('post_content',$post_id));
        return strpos($haystack,'jobposting')!==false || strpos($haystack,'broadcastevent')!==false;
    }

    public static function submit(array $urls,string $action='update',int $object_id=0,bool $manual=true) {
        if(!self::configured()) return new WP_Error('not_configured','Configure a Google Indexing API service account first.');
        if(!in_array($action,['update','delete'],true)) return new WP_Error('bad_action','Unsupported action.');
        $urls=array_values(array_filter(array_unique($urls),static fn($u)=>filter_var($u,FILTER_VALIDATE_URL)));
        if(!$urls) return new WP_Error('no_urls','No valid URLs.');
        $remaining=self::remaining_quota(); if($remaining<=0) return new WP_Error('quota','The configured daily Indexing API submission budget is exhausted.');
        $urls=array_slice($urls,0,min(100,$remaining));
        $token=self::access_token(); if(is_wp_error($token)) return $token;
        $out=['success'=>0,'failed'=>0,'items'=>[]];
        foreach($urls as $url){
            $body=['url'=>$url,'type'=>$action==='delete'?'URL_DELETED':'URL_UPDATED'];
            $resp=wp_remote_post(self::PUBLISH_ENDPOINT,['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$token,'Content-Type'=>'application/json'],'body'=>wp_json_encode($body)]);
            $code=is_wp_error($resp)?0:(int)wp_remote_retrieve_response_code($resp); $raw=is_wp_error($resp)?$resp->get_error_message():wp_remote_retrieve_body($resp); $data=json_decode((string)$raw,true);
            $ok=!is_wp_error($resp)&&$code>=200&&$code<300;
            self::log($url,$action,$ok?'success':'failed',$code,$ok?__('Submitted to Google Indexing API.','seo-manager'):((string)($data['error']['message']??$raw?:'Request failed.')),$object_id);
            if($ok){$out['success']++;}else{$out['failed']++; if($manual===false) self::queue($url,$action,$object_id);}
            $out['items'][]=['url'=>$url,'success'=>$ok,'code'=>$code,'response'=>is_array($data)?$data:$raw];
            usleep(150000);
        }
        seom_update('indexing_last_sync',time());
        return $out;
    }

    public static function status(string $url) {
        if(!self::configured()) return new WP_Error('not_configured','Configure a Google Indexing API service account first.');
        $token=self::access_token(); if(is_wp_error($token)) return $token;
        $resp=wp_remote_get(add_query_arg('url',$url,self::STATUS_ENDPOINT),['timeout'=>20,'headers'=>['Authorization'=>'Bearer '.$token]]);
        if(is_wp_error($resp)) return $resp;
        $code=(int)wp_remote_retrieve_response_code($resp); $data=json_decode(wp_remote_retrieve_body($resp),true);
        if($code<200||$code>=300) return new WP_Error('google_index_status',(string)($data['error']['message']??'Google status request failed.'),['status'=>$code,'body'=>$data]);
        return is_array($data)?$data:[];
    }

    private static function access_token() {
        $sa=self::service_account(); if(empty($sa['client_email'])||empty($sa['private_key'])) return new WP_Error('bad_credentials','Invalid service account JSON.');
        $now=time();
        $header=self::b64(['alg'=>'RS256','typ'=>'JWT']);
        $claim=self::b64(['iss'=>$sa['client_email'],'scope'=>self::SCOPE,'aud'=>self::TOKEN_ENDPOINT,'iat'=>$now,'exp'=>$now+3600]);
        $unsigned=$header.'.'.$claim; $signature='';
        if(!function_exists('openssl_sign') || !openssl_sign($unsigned,$signature,$sa['private_key'],OPENSSL_ALGO_SHA256)) return new WP_Error('openssl','OpenSSL could not sign the Google service account assertion.');
        $jwt=$unsigned.'.'.rtrim(strtr(base64_encode($signature),'+/','-_'),'=');
        $resp=wp_remote_post(self::TOKEN_ENDPOINT,['timeout'=>20,'body'=>['grant_type'=>'urn:ietf:params:oauth:grant-type:jwt-bearer','assertion'=>$jwt]]);
        if(is_wp_error($resp)) return $resp;
        $code=(int)wp_remote_retrieve_response_code($resp); $data=json_decode(wp_remote_retrieve_body($resp),true);
        if($code<200||$code>=300||empty($data['access_token'])) return new WP_Error('google_auth',(string)($data['error_description']??'Google authentication failed.'),['status'=>$code]);
        return (string)$data['access_token'];
    }

    private static function b64(array $data): string { return rtrim(strtr(base64_encode(wp_json_encode($data)), '+/','-_'),'='); }

    private static function queue(string $url,string $action,int $object_id): void {
        global $wpdb; $table=$wpdb->prefix.'seom_indexing_logs'; $now=current_time('mysql');
        $wpdb->insert($table,['url'=>$url,'action'=>$action,'status'=>'queued','response_code'=>0,'message'=>'Queued for retry.','object_id'=>$object_id,'created_at'=>$now,'updated_at'=>$now,'attempts'=>0,'provider'=>'google'],['%s','%s','%s','%d','%s','%d','%s','%s','%d','%s']);
    }

    private static function log(string $url,string $action,string $status,int $code,string $message,int $object_id): void {
        global $wpdb; $table=$wpdb->prefix.'seom_indexing_logs'; $now=current_time('mysql');
        $wpdb->insert($table,['url'=>$url,'action'=>$action,'status'=>$status,'response_code'=>$code,'message'=>substr($message,0,2000),'object_id'=>$object_id,'created_at'=>$now,'updated_at'=>$now,'attempts'=>1,'provider'=>'google'],['%s','%s','%s','%d','%s','%d','%s','%s','%d','%s']);
    }

    private static function remaining_quota(): int {
        global $wpdb; $table=$wpdb->prefix.'seom_indexing_logs'; $since=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS);
        $used=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE created_at >= %s AND status IN ('success','failed')",$since));
        return max(0,(int)seom_get('indexing_daily_limit',200)-$used);
    }

    public static function retry_queued(): void {
        if(!self::configured() || !(int)seom_get('indexing_enabled',0)) return;
        global $wpdb; $table=$wpdb->prefix.'seom_indexing_logs';
        $rows=$wpdb->get_results("SELECT * FROM $table WHERE status='queued' AND attempts < 3 ORDER BY id ASC LIMIT 20",ARRAY_A);
        foreach($rows as $row){
            $result=self::submit([(string)$row['url']],(string)$row['action'],absint($row['object_id']),false);
            $wpdb->update($table,['status'=>is_wp_error($result)?'queued':'success','message'=>is_wp_error($result)?$result->get_error_message():'Retry submitted.','attempts'=>absint($row['attempts'])+1,'updated_at'=>current_time('mysql')],['id'=>absint($row['id'])],['%s','%s','%d','%s'],['%d']);
        }
    }

    public static function get_logs(int $limit=100): array {
        global $wpdb; return (array)$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}seom_indexing_logs ORDER BY id DESC LIMIT %d",max(1,min(500,$limit))),ARRAY_A);
    }

    public static function quota_used(): int { global $wpdb; $since=gmdate('Y-m-d H:i:s',time()-DAY_IN_SECONDS); return (int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}seom_indexing_logs WHERE created_at >= %s AND status IN ('success','failed')",$since)); }

    public static function back(string $status,string $message=''): void { wp_safe_redirect(add_query_arg(['page'=>'seo-manager-indexing-group','subtab'=>'instant','status'=>$status,'message'=>$message],admin_url('admin.php'))); exit; }

    private static function key(): string { return hash('sha256',wp_salt('auth').wp_salt('secure_auth'),true); }
    private static function encrypt(string $value): string { if($value==='')return''; if(!function_exists('openssl_encrypt'))return base64_encode($value);$iv=random_bytes(16);$cipher=openssl_encrypt($value,'aes-256-cbc',self::key(),OPENSSL_RAW_DATA,$iv);return base64_encode($iv.$cipher); }
    private static function decrypt(string $value): string { if($value==='')return''; if(!function_exists('openssl_decrypt'))return(string)base64_decode($value);$raw=base64_decode($value,true);if(!$raw||strlen($raw)<17)return'';$iv=substr($raw,0,16);$cipher=substr($raw,16);$out=openssl_decrypt($cipher,'aes-256-cbc',self::key(),OPENSSL_RAW_DATA,$iv);return is_string($out)?$out:''; }
}
