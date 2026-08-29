jQuery(function($){
 function nonce(){return window.SEOM&&SEOM.nonce?SEOM.nonce:'';}
 $('.seom-delete-404').on('click',function(){const b=$(this),id=b.data('id');if(!confirm('Delete this 404 entry?'))return;b.prop('disabled',true);$.post(SEOM.ajaxurl,{action:'seom_delete_404',id,nonce:nonce()},function(r){if(r.success)b.closest('tr').fadeOut(150,function(){$(this).remove()});else b.prop('disabled',false);});});
 $('#seom-clear-404').on('click',function(){if(!confirm('Clear all 404 logs?'))return;$.post(SEOM.ajaxurl,{action:'seom_clear_404',nonce:nonce()},function(){location.reload();});});
 function preview(){const t=$('[name=seom_title]').val()||$('#title').val()||'';const d=$('[name=seom_description]').val()||'';$('#seom-serp-title').text(t);$('#seom-serp-desc').text(d);$('.seom-counter[data-for=seom_title]').text(t.length+' characters');$('.seom-counter[data-for=seom_description]').text(d.length+' characters');}
 $(document).on('input','[name=seom_title],[name=seom_description]',preview); preview();
 $('.seom-run-analysis').on('click',function(){const box=$(this).closest('.seom-analysis'),id=box.data('post-id');$(this).prop('disabled',true).text('Analyzing…');$.post(SEOM.ajaxurl,{action:'seom_analyze',post_id:id,nonce:nonce()},function(r){const el=box.find('.seom-analysis-result');if(!r.success){el.text('Analysis failed.');return;}const d=r.data;let html='<div class="seom-analysis-score">SEO Score: <b>'+d.score+'/100</b> '+d.label+'</div><p>Words: '+d.words+' · Readability: '+d.readability+' · Headings: '+d.headings+' · Images: '+d.images+' · Links: '+d.links+'</p>';if(d.issues.length)html+='<ul>'+d.issues.map(x=>'<li>'+ $('<div>').text(x).html()+'</li>').join('')+'</ul>';el.html(html);}).always(()=>$('.seom-run-analysis').prop('disabled',false).text('Run SEO Analysis'));});
 $('#seom-image-scan').on('click',function(){const b=$(this);b.prop('disabled',true).text('Scanning…');$.post(SEOM.ajaxurl,{action:'seom_image_scan',nonce:nonce()},function(r){const el=$('#seom-image-results');if(!r.success){el.text('Scan failed.');return;}if(!r.data.items.length){el.html('<p>All scanned images have ALT text.</p>');return;}el.html('<p><strong>'+r.data.count+'</strong> images need ALT text.</p><table class="widefat striped"><thead><tr><th>Image</th><th>URL</th><th>Action</th></tr></thead><tbody>'+r.data.items.map(x=>'<tr><td>'+ $('<div>').text(x.title).html()+'</td><td>'+ $('<div>').text(x.url).html()+'</td><td><a class="button button-small" href="post.php?post='+x.id+'&action=edit">Edit</a></td></tr>').join('')+'</tbody></table>');}).always(()=>b.prop('disabled',false).text('Scan Images'));});
});

function seomBulkInit(){
 if(!jQuery('#seom-bulk-editor').length)return;
 const $=jQuery; let data=[];
 $('#seom-load-bulk').on('click',function(){const b=$(this);b.prop('disabled',true).text('Loading…');$.post(SEOM.ajaxurl,{action:'seom_bulk_list',page:1,nonce:SEOM.nonce},function(r){if(!r.success){$('#seom-bulk-editor').text('Could not load content.');return;}data=r.data;
  // Built via DOM APIs (not string concatenation) so values like SEO title/keyword
  // that may contain quote characters can never break out of an attribute and
  // inject markup — jQuery's .val()/.text() set DOM properties directly.
  const $shell=$('<div>').append($('<div class="seom-bulk-toolbar">').append($('<button type="button" class="button button-primary" id="seom-save-bulk">').text('Save Changes')));
  const $scroll=$('<div class="seom-bulk-scroll">');
  const $table=$('<table class="widefat striped">');
  $table.append($('<thead><tr><th>Content</th><th>SEO Title</th><th>Meta Description</th><th>Focus Keyword</th></tr></thead>'));
  const $tbody=$('<tbody>');
  data.forEach((x,i)=>{
    const $tr=$('<tr>').attr('data-index',i);
    $('<td>').append($('<strong>').text(x.title)).append('<br>').append($('<small>').text(x.type)).appendTo($tr);
    $('<td>').append($('<input type="text" class="widefat seom-bulk-title">').val(x.seo_title)).appendTo($tr);
    $('<td>').append($('<textarea class="widefat seom-bulk-desc" rows="2">').val(x.description)).appendTo($tr);
    $('<td>').append($('<input type="text" class="widefat seom-bulk-keyword">').val(x.keyword)).appendTo($tr);
    $tbody.append($tr);
  });
  $table.append($tbody); $scroll.append($table); $shell.append($scroll);
  $('#seom-bulk-editor').empty().append($shell.children());
  }).always(()=>b.prop('disabled',false).text('Reload Content'));});
 $(document).on('click','#seom-save-bulk',function(){const b=$(this),items=[];$('#seom-bulk-editor tbody tr').each(function(){const row=$(this),x=data[Number(row.data('index'))];items.push({id:x.id,seo_title:row.find('.seom-bulk-title').val(),description:row.find('.seom-bulk-desc').val(),keyword:row.find('.seom-bulk-keyword').val()});});b.prop('disabled',true).text('Saving…');$.post(SEOM.ajaxurl,{action:'seom_bulk_save',items,nonce:SEOM.nonce},function(r){alert(r.success?'Saved '+r.data.saved+' items.':'Save failed.');}).always(()=>b.prop('disabled',false).text('Save Changes'));});
}
jQuery(function(){seomBulkInit();});


jQuery(function($){
  $('#seom-404-check-all').on('change', function(){ $('.seom-404-check').prop('checked', $(this).prop('checked')); });
  $('#seom-google-analytics-load').on('click', function(){
    const b=$(this); b.prop('disabled',true).text('Loading…');
    $.post(SEOM.ajaxurl,{action:'seom_google_analytics',days:28,nonce:SEOM.nonce},function(r){
      const el=$('#seom-google-analytics-result');
      if(!r.success){el.html('<div class="notice notice-error inline"><p>'+ $('<div>').text(r.data&&r.data.message?r.data.message:'Request failed.').html() +'</p></div>');return;}
      const rows=r.data.rows||[];
      if(!rows.length){el.html('<p>No Search Console rows returned for the selected period.</p>');return;}
      let html='<table class="widefat striped"><thead><tr><th>Date</th><th>Clicks</th><th>Impressions</th><th>CTR</th><th>Position</th></tr></thead><tbody>';
      rows.forEach(x=>{html+='<tr><td>'+ $('<div>').text(x.keys&&x.keys[0]||'').html()+'</td><td>'+Number(x.clicks||0).toLocaleString()+'</td><td>'+Number(x.impressions||0).toLocaleString()+'</td><td>'+Number((x.ctr||0)*100).toFixed(2)+'%</td><td>'+Number(x.position||0).toFixed(1)+'</td></tr>';});
      html+='</tbody></table>';el.html(html);
    }).always(()=>b.prop('disabled',false).text('Refresh Search Console Data'));
  });
  $('#seom-google-inspect').on('click', function(){
    const b=$(this),url=$('#seom-inspect-url').val(); b.prop('disabled',true).text('Inspecting…');
    $.post(SEOM.ajaxurl,{action:'seom_google_inspect',url:url,nonce:SEOM.nonce},function(r){
      const el=$('#seom-google-inspect-result'); if(!r.success){el.text(r.data&&r.data.message?r.data.message:'Inspection failed.');return;} el.text(JSON.stringify(r.data,null,2));
    }).always(()=>b.prop('disabled',false).text('Inspect URL'));
  });
});
(function($){
 $(document).on('click','#seom-indexing-submit',function(){
   var urls=$('#seom-indexing-urls').val().split(/\r?\n/).map(function(v){return v.trim();}).filter(Boolean);
   if(!urls.length){$('#seom-indexing-result').text('Enter at least one URL.');return;}
   var type=$('#seom-indexing-type').val(), $btn=$(this); $btn.prop('disabled',true).text('Submitting...');
   $.post(SEOM.ajaxurl,{action:'seom_indexing_submit',nonce:SEOM.nonce,urls:urls,type:type}).done(function(r){$('#seom-indexing-result').text(JSON.stringify(r,null,2));}).fail(function(){ $('#seom-indexing-result').text('Request failed.'); }).always(function(){ $btn.prop('disabled',false).text('Submit to Google'); });
 });
 $(document).on('click','#seom-indexing-status',function(){
   var url=$('#seom-indexing-status-url').val().trim(); if(!url){$('#seom-indexing-status-result').text('Enter a URL.');return;}
   var $btn=$(this); $btn.prop('disabled',true).text('Checking...');
   $.post(SEOM.ajaxurl,{action:'seom_indexing_status',nonce:SEOM.nonce,url:url}).done(function(r){$('#seom-indexing-status-result').text(JSON.stringify(r,null,2));}).fail(function(){ $('#seom-indexing-status-result').text('Request failed.'); }).always(function(){ $btn.prop('disabled',false).text('Get Status'); });
 });
})(jQuery);
