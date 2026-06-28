<?php
/**
 * Plugin Name: Meilisearch Search
 * Description: Configurable InstantSearch UI for Meilisearch indexes. Use [meili_search] shortcode.
 * Version:     1.1.0
 * Author:      Rechtspreker / AVPVH
 */

defined('ABSPATH') || exit;

// --- Settings -----------------------------------------------------------

add_action('admin_menu', function () {
    add_options_page('Meilisearch', 'Meilisearch', 'manage_options', 'meili-search', 'meili_search_settings_page');
});

add_action('admin_init', function () {
    foreach (['meili_search_url', 'meili_search_key', 'meili_admin_key', 'meili_per_page', 'meili_snippet_length'] as $opt) {
        register_setting('meili_search', $opt);
    }
});

// --- AJAX: apply index settings -----------------------------------------

add_action('wp_ajax_meili_apply_index_settings', function () {
    check_ajax_referer('meili_admin', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $url   = get_option('meili_search_url');
    $key   = get_option('meili_admin_key');
    $index = sanitize_text_field($_POST['index'] ?? 'productions');

    $settings = [
        'typoTolerance' => [
            'enabled'            => !empty($_POST['typo_enabled']),
            'minWordSizeForTypos' => [
                'oneTypo'  => intval($_POST['one_typo']  ?? 5),
                'twoTypos' => intval($_POST['two_typos'] ?? 9),
            ],
        ],
    ];

    $sep_raw = trim($_POST['separators'] ?? '');
    if ($sep_raw !== '') {
        $chars = array_values(array_filter(array_map('trim', explode(',', $sep_raw))));
        if ($chars) $settings['separatorTokens'] = $chars;
    }

    $resp = wp_remote_request("{$url}/indexes/{$index}/settings", [
        'method'  => 'PATCH',
        'headers' => ['Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode($settings),
    ]);

    if (is_wp_error($resp)) { wp_send_json_error($resp->get_error_message()); }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $code >= 200 && $code < 300 ? wp_send_json_success($body) : wp_send_json_error($body);
});

// --- AJAX: search documents for editing ---------------------------------

add_action('wp_ajax_meili_search_docs', function () {
    check_ajax_referer('meili_admin', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $url   = get_option('meili_search_url');
    $key   = get_option('meili_admin_key');
    $index = sanitize_text_field($_POST['index'] ?? 'productions');
    $query = sanitize_text_field($_POST['query'] ?? '');

    // If query looks like an image URL, extract the document ID from the path
    if (filter_var($query, FILTER_VALIDATE_URL)) {
        $path  = parse_url($query, PHP_URL_PATH);
        $parts = pathinfo($path);
        $prod  = basename(dirname($path));
        $label = $parts['filename']; // e.g. page_66
        $query = "{$prod}__{$label}";
    }

    $resp = wp_remote_post("{$url}/indexes/{$index}/search", [
        'headers' => ['Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode([
            'q'                    => $query,
            'limit'                => 10,
            'attributesToRetrieve' => ['id', 'production', 'page_number', 'text', 'image_url'],
        ]),
    ]);

    if (is_wp_error($resp)) { wp_send_json_error($resp->get_error_message()); }
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    wp_send_json_success($body['hits'] ?? []);
});

// --- AJAX: save document text -------------------------------------------

add_action('wp_ajax_meili_save_doc', function () {
    check_ajax_referer('meili_admin', 'nonce');
    if (!current_user_can('manage_options')) wp_die('Unauthorized');

    $url   = get_option('meili_search_url');
    $key   = get_option('meili_admin_key');
    $index = sanitize_text_field($_POST['index'] ?? 'productions');
    $id    = sanitize_text_field($_POST['id']    ?? '');
    $text  = sanitize_textarea_field($_POST['text'] ?? '');

    if (!$id) wp_send_json_error('Geen document-ID opgegeven.');

    $resp = wp_remote_request("{$url}/indexes/{$index}/documents", [
        'method'  => 'PUT',
        'headers' => ['Authorization' => "Bearer {$key}", 'Content-Type' => 'application/json'],
        'body'    => wp_json_encode([['id' => $id, 'text' => $text]]),
    ]);

    if (is_wp_error($resp)) { wp_send_json_error($resp->get_error_message()); }
    $code = wp_remote_retrieve_response_code($resp);
    $body = json_decode(wp_remote_retrieve_body($resp), true);
    $code >= 200 && $code < 300 ? wp_send_json_success($body) : wp_send_json_error($body);
});

// --- Settings page ------------------------------------------------------

function meili_search_settings_page() {
    $nonce = wp_create_nonce('meili_admin');
    $ajax  = admin_url('admin-ajax.php');
    ?>
<div class="wrap">
  <h1>Meilisearch</h1>

  <form method="post" action="options.php">
    <?php settings_fields('meili_search'); ?>

    <h2>Verbinding</h2>
    <table class="form-table">
      <tr>
        <th>URL</th>
        <td><input type="url" name="meili_search_url" class="regular-text"
            value="<?php echo esc_attr(get_option('meili_search_url')); ?>">
            <p class="description">bijv. https://search.rechtspreker.nl</p></td>
      </tr>
      <tr>
        <th>Zoek-API-sleutel</th>
        <td><input type="text" name="meili_search_key" class="regular-text"
            value="<?php echo esc_attr(get_option('meili_search_key')); ?>">
            <p class="description">Alleen-zoeken sleutel — wordt in de frontend meegestuurd.</p></td>
      </tr>
      <tr>
        <th>Admin-API-sleutel</th>
        <td><input type="password" name="meili_admin_key" class="regular-text"
            value="<?php echo esc_attr(get_option('meili_admin_key')); ?>">
            <p class="description">Admin/master sleutel — alleen server-side gebruikt voor index-instellingen en document-bewerking.</p></td>
      </tr>
    </table>

    <h2>Weergave</h2>
    <table class="form-table">
      <tr>
        <th>Resultaten per pagina</th>
        <td><input type="number" name="meili_per_page" class="small-text" min="1" max="100"
            value="<?php echo esc_attr(get_option('meili_per_page', 10)); ?>"></td>
      </tr>
      <tr>
        <th>Snippet-lengte (woorden)</th>
        <td><input type="number" name="meili_snippet_length" class="small-text" min="10" max="300"
            value="<?php echo esc_attr(get_option('meili_snippet_length', 50)); ?>">
            <p class="description">Aantal woorden rondom de zoekterm in de samenvatting.</p></td>
      </tr>
    </table>

    <?php submit_button('Opslaan'); ?>
  </form>

  <hr>

  <h2>Index-instellingen</h2>
  <p class="description">Worden direct naar Meilisearch gestuurd via de Admin-sleutel.</p>
  <table class="form-table">
    <tr>
      <th>Index</th>
      <td><input type="text" id="mi-idx" value="productions" class="regular-text"></td>
    </tr>
    <tr>
      <th>Typofout-tolerantie</th>
      <td>
        <label><input type="checkbox" id="mi-typo-on" checked> Ingeschakeld</label><br><br>
        <label>1 typofout vanaf <input type="number" id="mi-one" value="5" class="small-text" min="1" max="30"> tekens</label><br>
        <label>2 typefouten vanaf <input type="number" id="mi-two" value="9" class="small-text" min="1" max="30"> tekens</label>
      </td>
    </tr>
    <tr>
      <th>Extra scheidingstekens</th>
      <td><input type="text" id="mi-sep" class="regular-text" placeholder="bijv. /, @, #">
          <p class="description">Kommagescheiden tekens waarmee woorden worden gesplitst (bijv. <code>/</code> zodat <code>pad/naar/bestand</code> in losse woorden valt).</p></td>
    </tr>
    <tr>
      <th></th>
      <td>
        <button class="button button-secondary" id="mi-apply">Toepassen op index</button>
        <span id="mi-apply-msg" style="margin-left:1em;color:#555;"></span>
      </td>
    </tr>
  </table>

  <hr>

  <h2>Document bewerken</h2>
  <p class="description">Zoek een geïndexeerde pagina op tekst of plak de afbeelding-URL als anker. Klik een resultaat aan om de tekst te bewerken en op te slaan.</p>
  <table class="form-table">
    <tr>
      <th>Index</th>
      <td><input type="text" id="mi-edit-idx" value="productions" class="regular-text"></td>
    </tr>
    <tr>
      <th>Zoek document</th>
      <td>
        <input type="text" id="mi-doc-q" class="regular-text"
               placeholder="Tekst, document-ID of afbeelding-URL">
        <button class="button" id="mi-doc-search">Zoeken</button>
      </td>
    </tr>
  </table>

  <div id="mi-results" style="display:none;margin:1em 0;max-height:320px;overflow-y:auto;border:1px solid #ccd0d4;border-radius:4px;"></div>

  <div id="mi-editor" style="display:none;margin-top:1.5em;">
    <h3 id="mi-doc-title" style="margin-bottom:.5em;"></h3>
    <div style="display:flex;gap:1.5em;align-items:flex-start;">
      <img id="mi-doc-img" src="" style="max-width:180px;border:1px solid #ddd;border-radius:3px;flex-shrink:0;" alt="">
      <div style="flex:1;min-width:0;">
        <textarea id="mi-doc-text" rows="14" style="width:100%;font-family:monospace;font-size:0.88em;"></textarea>
        <input type="hidden" id="mi-doc-id">
        <p>
          <button class="button button-primary" id="mi-doc-save">Opslaan</button>
          <span id="mi-save-msg" style="margin-left:1em;color:#555;"></span>
        </p>
      </div>
    </div>
  </div>
</div>

<script>
(function($){
  var nonce = <?php echo wp_json_encode($nonce); ?>;
  var ajax  = <?php echo wp_json_encode($ajax); ?>;

  /* Index settings */
  $('#mi-apply').on('click', function(e){
    e.preventDefault();
    $('#mi-apply-msg').text('Bezig…');
    $.post(ajax, {
      action:       'meili_apply_index_settings',
      nonce:        nonce,
      index:        $('#mi-idx').val(),
      typo_enabled: $('#mi-typo-on').is(':checked') ? 1 : '',
      one_typo:     $('#mi-one').val(),
      two_typos:    $('#mi-two').val(),
      separators:   $('#mi-sep').val(),
    }, function(r){
      if (r.success) {
        $('#mi-apply-msg').text('Toegepast (taak #' + (r.data.taskUid||'?') + ')');
      } else {
        $('#mi-apply-msg').text('Fout: ' + JSON.stringify(r.data));
      }
    });
  });

  /* Document search */
  function doSearch(){
    var $r = $('#mi-results').show().html('<p style="padding:1em">Zoeken…</p>');
    $('#mi-editor').hide();
    $.post(ajax, {
      action: 'meili_search_docs',
      nonce:  nonce,
      index:  $('#mi-edit-idx').val(),
      query:  $('#mi-doc-q').val(),
    }, function(r){
      if (!r.success || !r.data.length) {
        $r.html('<p style="padding:1em">Geen resultaten.</p>'); return;
      }
      var html = '<table class="widefat striped" style="border:none;">'
        + '<thead><tr><th>ID</th><th>Pag.</th><th>Tekst-preview</th></tr></thead><tbody>';
      r.data.forEach(function(h){
        html += '<tr style="cursor:pointer"'
          + ' data-id="'    + encodeURIComponent(h.id||'')         + '"'
          + ' data-text="'  + encodeURIComponent(h.text||'')       + '"'
          + ' data-img="'   + encodeURIComponent(h.image_url||'')  + '"'
          + ' data-title="' + encodeURIComponent(
              (h.production||h.id) + (h.page_number ? ' — pagina '+h.page_number : '')
            ) + '">'
          + '<td>' + $('<span>').text(h.id).html() + '</td>'
          + '<td>' + (h.page_number||'') + '</td>'
          + '<td style="max-width:420px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis">'
          +   $('<span>').text((h.text||'').substring(0,140)).html()
          + '</td></tr>';
      });
      html += '</tbody></table>';
      $r.html(html);

      $r.find('tr[data-id]').on('click', function(){
        var $row = $(this);
        $('#mi-doc-id').val(   decodeURIComponent($row.data('id')));
        $('#mi-doc-text').val( decodeURIComponent($row.data('text')));
        $('#mi-doc-title').text(decodeURIComponent($row.data('title')));
        var img = decodeURIComponent($row.data('img'));
        $('#mi-doc-img').attr('src', img).toggle(!!img);
        $('#mi-save-msg').text('');
        $('#mi-editor').show();
      });
    });
  }

  $('#mi-doc-search').on('click', function(e){ e.preventDefault(); doSearch(); });
  $('#mi-doc-q').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); doSearch(); }});

  /* Save document */
  $('#mi-doc-save').on('click', function(e){
    e.preventDefault();
    $('#mi-save-msg').text('Opslaan…');
    $.post(ajax, {
      action: 'meili_save_doc',
      nonce:  nonce,
      index:  $('#mi-edit-idx').val(),
      id:     $('#mi-doc-id').val(),
      text:   $('#mi-doc-text').val(),
    }, function(r){
      $('#mi-save-msg').text(r.success
        ? 'Opgeslagen! (taak #'+(r.data.taskUid||'?')+')'
        : 'Fout: '+JSON.stringify(r.data));
    });
  });

})(jQuery);
</script>
<?php
}

// --- Shortcode ----------------------------------------------------------
// Usage: [meili_search index="productions" placeholder="Zoek…" show_images="1"]

add_shortcode('meili_search', function ($atts) {
    $atts = shortcode_atts([
        'index'       => 'productions',
        'placeholder' => 'Zoeken…',
        'show_images' => '1',
    ], $atts, 'meili_search');

    $url         = get_option('meili_search_url', '');
    $key         = get_option('meili_search_key', '');
    $per_page    = intval(get_option('meili_per_page', 10));
    $snippet_len = intval(get_option('meili_snippet_length', 50));
    $show_img    = filter_var($atts['show_images'], FILTER_VALIDATE_BOOLEAN);
    $uid         = 'meili-' . wp_unique_id();

    if (!$url || !$key) {
        if (current_user_can('manage_options')) {
            return '<p><em>Meilisearch: stel de URL en API-sleutel in via <a href="' .
                admin_url('options-general.php?page=meili-search') . '">Instellingen → Meilisearch</a>.</em></p>';
        }
        return '';
    }

    ob_start(); ?>
<div id="<?php echo esc_attr($uid); ?>" class="meili-search-wrap">
  <div id="<?php echo esc_attr($uid); ?>-searchbox"></div>
  <div id="<?php echo esc_attr($uid); ?>-stats"></div>
  <div id="<?php echo esc_attr($uid); ?>-hits"></div>
  <div id="<?php echo esc_attr($uid); ?>-pagination"></div>
</div>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/instantsearch.css@8/themes/satellite-min.css">
<script src="https://cdn.jsdelivr.net/npm/instantsearch.js@4/dist/instantsearch.production.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/@meilisearch/instant-meilisearch@0.19/dist/instant-meilisearch.umd.js"></script>

<style>
.meili-search-wrap { max-width: 960px; margin: 0 auto; }
.meili-stats       { color: #666; font-size: 0.85em; margin: 0.5em 0 1em; }
.meili-search-wrap .ais-Hits-item {
  align-items: flex-start !important;
  gap: 1.2em !important;
  border: 1px solid #e0e0e0 !important;
  border-radius: 6px !important;
  padding: 1em !important;
  background: #fff !important;
}
.meili-search-wrap .ais-Hits-item img {
  width: 120px !important;
  max-width: 120px !important;
  height: auto !important;
  border: 1px solid #ddd;
  border-radius: 3px;
  object-fit: contain;
  flex-shrink: 0;
  display: block !important;
}
.meili-search-wrap .ais-Hits-item a.meili-thumb { flex-shrink:0 !important; display:block !important; width:120px !important; font-size:0; line-height:0; }
.meili-search-wrap .ais-Hits-item a.meili-thumb:hover { opacity:.85; }
.meili-search-wrap .ais-Hits-item .meili-body { flex:1 !important; min-width:0 !important; }
.meili-search-wrap .ais-Hits-item .meili-body a { text-decoration:none; color:#333; }
.meili-search-wrap .ais-Hits-item .meili-body a:hover { text-decoration:underline; }
.meili-search-wrap .ais-Hits-item h3 { margin:0 0 .4em !important; font-size:1em !important; }
.meili-search-wrap .ais-Hits-item p  { margin:0 !important; font-size:.88em; color:#555; line-height:1.6; }
.meili-search-wrap .ais-Hits-item em { background:#fff3b0; font-style:normal; padding:0 2px; border-radius:2px; }
</style>

<script>
(function () {
  var uid        = <?php echo wp_json_encode($uid); ?>;
  var showImages = <?php echo $show_img ? 'true' : 'false'; ?>;

  var searchClient = instantMeiliSearch(
    <?php echo wp_json_encode($url); ?>,
    <?php echo wp_json_encode($key); ?>
  ).searchClient;

  var search = instantsearch({
    indexName: <?php echo wp_json_encode($atts['index']); ?>,
    searchClient: searchClient,
  });

  search.addWidgets([
    instantsearch.widgets.searchBox({
      container: '#' + uid + '-searchbox',
      placeholder: <?php echo wp_json_encode($atts['placeholder']); ?>,
      showReset: true,
    }),
    instantsearch.widgets.stats({
      container: '#' + uid + '-stats',
      cssClasses: { root: 'meili-stats' },
      templates: {
        text: function (d) {
          if (d.nbHits === 0) return 'Geen resultaten.';
          return d.nbHits + ' resultaat' + (d.nbHits === 1 ? '' : 'en') +
                 ' (' + d.processingTimeMS + ' ms)';
        },
      },
    }),
    instantsearch.widgets.hits({
      container: '#' + uid + '-hits',
      templates: {
        item: function (hit) {
          var title = (hit.production ? hit.production : hit.id) +
                      (hit.page_number ? ' — pagina ' + hit.page_number : '');
          var snippetHtml = (hit._snippetResult && hit._snippetResult.text)
            ? hit._snippetResult.text.value
            : (hit.text ? hit.text.substring(0, 200) + '…' : '');
          var url     = hit.image_url || '';
          var pageNum = hit.page_number || '';
          if (showImages && url) {
            return '<a class="meili-thumb" href="' + url + '" target="_blank">'
              + '<img src="' + url + '" alt="pagina ' + pageNum + '" loading="lazy">'
              + '</a>'
              + '<div class="meili-body">'
              + '<h3><a href="' + url + '" target="_blank">' + title + '</a></h3>'
              + '<p>' + snippetHtml + '</p>'
              + '</div>';
          }
          return '<div class="meili-body"><h3>' + title + '</h3><p>' + snippetHtml + '</p></div>';
        },
        empty: function () { return ''; },
      },
    }),
    instantsearch.widgets.configure({
      hitsPerPage:          <?php echo $per_page; ?>,
      attributesToSnippet:  ['text:<?php echo $snippet_len; ?>'],
      snippetEllipsisText:  '…',
    }),
    instantsearch.widgets.pagination({
      container: '#' + uid + '-pagination',
    }),
  ]);

  search.start();
})();
</script>
<?php
    return ob_get_clean();
});
