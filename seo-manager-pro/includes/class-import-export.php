<?php
if (!defined('ABSPATH')) exit;

final class SEO_Manager_Import_Export {
    public static function init(): void {
        add_action('admin_post_seom_404_import', [__CLASS__,'import_404']);
        add_action('admin_post_seom_404_export', [__CLASS__,'export_404']);
        add_action('admin_post_seom_404_bulk_delete', [__CLASS__,'bulk_delete_404']);
        add_action('admin_post_seom_redirect_import', [__CLASS__,'import_redirects']);
        add_action('admin_post_seom_redirect_export', [__CLASS__,'export_redirects']);
        add_action('admin_post_seom_redirect_template', [__CLASS__,'redirect_template']);
        add_action('admin_post_seom_seo_export', [__CLASS__,'export_seo']);
        add_action('admin_post_seom_seo_import', [__CLASS__,'import_seo']);
    }

    public static function import_404(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_404_import')) wp_die('Unauthorized.');
        if (empty($_FILES['seom_404_file']['tmp_name'])) { self::back('file'); }
        $file = $_FILES['seom_404_file'];
        $name = sanitize_file_name((string)$file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext,['csv','xlsx','xls'],true)) self::back('format');
        $rows = $ext === 'csv' ? self::read_csv($file['tmp_name']) : self::read_xlsx($file['tmp_name']);
        if (!$rows) self::back('empty');
        global $wpdb; $table=$wpdb->prefix.'seom_404_logs'; $count=0;
        foreach ($rows as $row) {
            $url = seom_clean_path((string)($row['url'] ?? $row['source'] ?? ''));
            if (!$url) continue;
            $hits=max(1,absint($row['hits']??1));
            $now=current_time('mysql');
            $existing=$wpdb->get_row($wpdb->prepare("SELECT id,hits FROM $table WHERE url=%s LIMIT 1",$url));
            if ($existing) $wpdb->update($table,['hits'=>(int)$existing->hits+$hits,'last_seen'=>$now],['id'=>(int)$existing->id],['%d','%s'],['%d']);
            else $wpdb->insert($table,['url'=>$url,'referrer'=>substr(sanitize_text_field((string)($row['referrer']??'')),0,255),'user_agent'=>substr(sanitize_text_field((string)($row['user_agent']??'')),0,1000),'ip_hash'=>'','hits'=>$hits,'first_seen'=>$now,'last_seen'=>$now],['%s','%s','%s','%s','%d','%s','%s']);
            $count++;
        }
        self::back('imported', $count);
    }


    public static function import_redirects(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_redirect_import')) wp_die('Unauthorized.');
        if (empty($_FILES['seom_redirect_file']['tmp_name'])) self::redirect_back('file');
        $file = $_FILES['seom_redirect_file'];
        if (!empty($file['error'])) self::redirect_back('upload_error');
        $name = sanitize_file_name((string) $file['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv','xlsx'], true)) self::redirect_back('format');
        $rows = $ext === 'csv' ? self::read_csv($file['tmp_name']) : self::read_xlsx($file['tmp_name']);
        if (!$rows) self::redirect_back('empty');

        global $wpdb;
        $table = $wpdb->prefix . 'seom_redirects';
        $imported = 0; $updated = 0; $skipped = 0;
        $now = current_time('mysql');
        $limit = 50000;

        foreach (array_slice($rows, 0, $limit) as $row) {
            $src = seom_clean_path((string)($row['source'] ?? $row['url'] ?? ''));
            $dst = seom_normalize_destination((string)($row['destination'] ?? $row['target'] ?? ''));
            if (!$src || !$dst || untrailingslashit($src) === untrailingslashit((string) wp_parse_url($dst, PHP_URL_PATH))) {
                $skipped++; continue;
            }
            $type = absint($row['type'] ?? 301);
            if (!in_array($type, [301,302,307,308], true)) $type = 301;
            $raw_enabled = strtolower(trim((string)($row['enabled'] ?? $row['status'] ?? '1')));
            $enabled = in_array($raw_enabled, ['0','false','off','disabled','inactive'], true) ? 0 : 1;

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE source=%s LIMIT 1", $src));
            $data = ['source'=>$src,'destination'=>$dst,'type'=>$type,'enabled'=>$enabled,'updated_at'=>$now];
            if ($existing) {
                $result = $wpdb->update($table, $data, ['id'=>(int)$existing], ['%s','%s','%d','%d','%s'], ['%d']);
                if ($result !== false) $updated++;
                else $skipped++;
            } else {
                $data['hits'] = 0; $data['created_at'] = $now;
                $result = $wpdb->insert($table, $data, ['%s','%s','%d','%d','%d','%s','%s']);
                if ($result) $imported++;
                else $skipped++;
            }
        }
        self::redirect_back('imported', $imported, $updated, $skipped);
    }

    public static function export_redirects(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_redirect_export')) wp_die('Unauthorized.');
        $format = sanitize_key($_GET['format'] ?? 'csv');
        if (!in_array($format, ['csv','xlsx'], true)) $format = 'csv';
        global $wpdb;
        $rows = $wpdb->get_results("SELECT source,destination,type,enabled,hits,created_at,updated_at FROM {$wpdb->prefix}seom_redirects ORDER BY id DESC LIMIT 50000", ARRAY_A);
        $headers = ['Source','Destination','Type','Enabled','Hits','Created At','Updated At'];
        $data = [];
        foreach ($rows as $r) {
            $data[] = [(string)$r['source'],(string)$r['destination'],(int)$r['type'],(int)$r['enabled'],(int)$r['hits'],(string)$r['created_at'],(string)$r['updated_at']];
        }
        if ($format === 'xlsx') {
            $content = self::xlsx_binary($headers, $data, 'Redirects');
            nocache_headers();
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="seo-manager-redirects-export.xlsx"');
            echo $content; exit;
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="seo-manager-redirects-export.csv"');
        $fh = fopen('php://output','w');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers); foreach ($data as $r) fputcsv($fh, self::sheet_safe_row($r));
        fclose($fh); exit;
    }

    public static function redirect_template(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_redirect_template')) wp_die('Unauthorized.');
        $headers = ['Source','Destination','Type','Enabled'];
        $data = [['/old-page/','https://example.com/new-page/',301,1]];
        $format = sanitize_key($_GET['format'] ?? 'csv');
        if ($format === 'xlsx') {
            $content = self::xlsx_binary($headers, $data, 'Redirect Import Template');
            nocache_headers(); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="seo-manager-redirect-template.xlsx"'); echo $content; exit;
        }
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="seo-manager-redirect-template.csv"');
        $fh=fopen('php://output','w'); fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh,$headers); fputcsv($fh,self::sheet_safe_row($data[0])); fclose($fh); exit;
    }

    public static function export_404(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_404_export')) wp_die('Unauthorized.');
        $format = in_array($_GET['format']??'csv',['csv','xlsx'],true) ? sanitize_key($_GET['format']) : 'csv';
        global $wpdb; $rows=$wpdb->get_results("SELECT url,referrer,hits,first_seen,last_seen FROM {$wpdb->prefix}seom_404_logs ORDER BY hits DESC,last_seen DESC LIMIT 50000",ARRAY_A);
        $headers=['URL','Referrer','Hits','First Seen','Last Seen']; $data=[]; foreach($rows as $r)$data[]=[(string)$r['url'],(string)$r['referrer'],(int)$r['hits'],(string)$r['first_seen'],(string)$r['last_seen']];
        if($format==='xlsx'){ $content=self::xlsx_binary($headers,$data); header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'); header('Content-Disposition: attachment; filename="seo-manager-404-export.xlsx"'); echo $content; exit; }
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="seo-manager-404-export.csv"'); $fh=fopen('php://output','w'); fwrite($fh, "\xEF\xBB\xBF"); fputcsv($fh,$headers); foreach($data as $r)fputcsv($fh,self::sheet_safe_row($r)); fclose($fh); exit;
    }

    public static function bulk_delete_404(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_404_bulk_delete')) wp_die('Unauthorized.');
        $ids=array_filter(array_map('absint',(array)($_POST['ids']??[]))); if(!$ids)self::back('deleted',0);
        global $wpdb; $ph=implode(',',array_fill(0,count($ids),'%d')); $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}seom_404_logs WHERE id IN ($ph)",$ids)); self::back('deleted',count($ids));
    }

    public static function export_seo(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_seo_export')) wp_die('Unauthorized.');
        $rows=[]; foreach(get_posts(['post_type'=>seom_post_types(),'post_status'=>'any','posts_per_page'=>-1,'fields'=>'ids']) as $id){$rows[]=['id'=>$id,'type'=>get_post_type($id),'url'=>get_permalink($id),'title'=>seom_meta($id,'title'),'description'=>seom_meta($id,'description'),'keyword'=>seom_meta($id,'keyword'),'canonical'=>seom_meta($id,'canonical'),'robots'=>seom_meta($id,'robots')];}
        nocache_headers(); header('Content-Type: application/json; charset=utf-8'); header('Content-Disposition: attachment; filename="seo-manager-seo-export.json"'); echo wp_json_encode(['version'=>SEOM_VERSION,'exported_at'=>current_time('mysql'),'items'=>$rows],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE); exit;
    }

    public static function import_seo(): void {
        if (!current_user_can('manage_options') || !check_admin_referer('seom_seo_import')) wp_die('Unauthorized.');
        if(empty($_FILES['seom_seo_file']['tmp_name']))self::back('seo_file'); $raw=file_get_contents($_FILES['seom_seo_file']['tmp_name']); $data=json_decode($raw,true); if(!is_array($data)||!is_array($data['items']??null))self::back('seo_format'); $saved=0;
        foreach($data['items'] as $row){$id=absint($row['id']??0); if(!$id||!get_post($id)||!current_user_can('edit_post',$id))continue; foreach(['title','description','keyword','canonical','robots'] as $k){if(!array_key_exists($k,$row))continue;$v=$k==='canonical'?esc_url_raw((string)$row[$k]):sanitize_textarea_field((string)$row[$k]); if($v==='')delete_post_meta($id,'_seom_'.$k);else update_post_meta($id,'_seom_'.$k,$v);} $saved++;} self::back('seo_imported',$saved);
    }

    private static function read_csv(string $path): array { $rows=[];$fh=fopen($path,'rb'); if(!$fh)return[]; $headers=fgetcsv($fh); if(!$headers){fclose($fh);return[];} $headers=array_map(fn($v)=>sanitize_key(str_replace([' ','-'],'_',trim((string)$v))),$headers); while(($line=fgetcsv($fh))!==false){$row=[];foreach($headers as $i=>$h)$row[$h]=$line[$i]??'';$rows[]=$row;}fclose($fh);return $rows; }

    private static function read_xlsx(string $path): array {
        if(!class_exists('ZipArchive'))return[]; $zip=new ZipArchive();if($zip->open($path)!==true)return[]; $shared=[];$sxml=$zip->getFromName('xl/sharedStrings.xml'); if($sxml){$sxml = preg_replace('/xmlns(?:\:[a-zA-Z0-9_]+)?="[^"]+"/', '', $sxml); $xml=simplexml_load_string($sxml);foreach($xml->si as $si){$text='';if(isset($si->t))$text=(string)$si->t;else foreach($si->r as $r)$text.=(string)$r->t;$shared[]=$text;}}
        $sheet=$zip->getFromName('xl/worksheets/sheet1.xml'); if(!$sheet){$zip->close();return[];} $sheet = preg_replace('/xmlns(?:\:[a-zA-Z0-9_]+)?="[^"]+"/', '', $sheet); $xml=simplexml_load_string($sheet);if(!$xml){$zip->close();return[];}$rows=[];foreach($xml->sheetData->row as $r){$cells=[];foreach($r->c as $c){$ref=(string)$c['r'];$col=preg_replace('/[0-9]/','',$ref);$idx=self::col_index($col);$type=(string)$c['t'];$val=(string)$c->v;if($type==='s')$val=$shared[(int)$val]??'';elseif($type==='inlineStr')$val=(string)$c->is->t;$cells[$idx]=$val;}if($cells){$max=max(array_keys($cells));$arr=[];for($i=0;$i<=$max;$i++)$arr[]=$cells[$i]??'';$rows[]=$arr;}}$zip->close();if(!$rows)return[];$headers=array_map(fn($v)=>sanitize_key(str_replace([' ','-'],'_',trim((string)$v))),$rows[0]);$out=[];foreach(array_slice($rows,1) as $line){$row=[];foreach($headers as $i=>$h)$row[$h]=$line[$i]??'';$out[]=$row;}return$out;
    }

    private static function col_index(string $col): int { $n=0;for($i=0,$l=strlen($col);$i<$l;$i++)$n=$n*26+(ord(strtoupper($col[$i]))-64);return $n-1; }

    /**
     * Neutralises CSV/XLSX formula injection (CWE-1236). Values that start
     * with =, +, -, @ (or tab/CR, which some spreadsheet apps also treat as
     * formula starts) are prefixed with a tab-safe apostrophe so Excel/Sheets
     * render them as text instead of executing them as a formula. This
     * matters here specifically because exported fields like the 404 log's
     * "referrer" are populated from the attacker-controlled HTTP Referer
     * header, not just admin-entered data.
     */
    private static function sheet_safe($value): string {
        $value = (string) $value;
        return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
    }

    private static function sheet_safe_row(array $row): array {
        return array_map([__CLASS__, 'sheet_safe'], $row);
    }

    private static function xlsx_binary(array $headers,array $rows,string $sheet_name = 'Data'): string {
        if(!class_exists('ZipArchive'))return''; $tmp=wp_tempnam('seo-manager-xlsx');$zip=new ZipArchive();$zip->open($tmp,ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
        $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $safe_sheet = htmlspecialchars(substr($sheet_name,0,31), ENT_XML1|ENT_COMPAT, 'UTF-8'); $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$safe_sheet.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
        $xml='<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';$all=array_merge([$headers],$rows);foreach($all as $ri=>$row){$r=$ri+1;$xml.='<row r="'.$r.'">';foreach(array_values($row) as $ci=>$value){$cell=self::col_name($ci).$r;$v=htmlspecialchars(self::sheet_safe((string)$value),ENT_XML1|ENT_COMPAT,'UTF-8');$xml.='<c r="'.$cell.'" t="inlineStr"><is><t>'.$v.'</t></is></c>';}$xml.='</row>';}$xml.='</sheetData></worksheet>';$zip->addFromString('xl/worksheets/sheet1.xml',$xml);$zip->close();$bin=file_get_contents($tmp);@unlink($tmp);return(string)$bin;
    }
    private static function col_name(int $n): string { $s='';$n++;while($n>0){$n--; $s=chr(65+($n%26)).$s;$n=intdiv($n,26);}return$s; }
    private static function back(string $status,int $count=0): void {wp_safe_redirect(add_query_arg(['subtab'=>'404','status'=>$status,'count'=>$count],seom_admin_page_url('links')));exit;}
    private static function redirect_back(string $status,int $imported=0,int $updated=0,int $skipped=0): void {wp_safe_redirect(add_query_arg(['subtab'=>'redirects','redirect_status'=>$status,'imported'=>$imported,'updated'=>$updated,'skipped'=>$skipped],seom_admin_page_url('links')));exit;}
}
