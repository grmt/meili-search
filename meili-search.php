<?php
/**
 * Plugin Name: Meilisearch Search
 * Description: Configurable InstantSearch UI for Meilisearch indexes. Use [meili_search] shortcode.
 * Version:     1.0.0
 * Author:      Rechtspreker / AVPVH
 */

defined('ABSPATH') || exit;

// --- Settings -----------------------------------------------------------

add_action('admin_menu', function () {
    add_options_page(
        'Meilisearch',
        'Meilisearch',
        'manage_options',
        'meili-search',
        'meili_search_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('meili_search', 'meili_search_url');
    register_setting('meili_search', 'meili_search_key');
});

function meili_search_settings_page() { ?>
<div class="wrap">
  <h1>Meilisearch</h1>
  <form method="post" action="options.php">
    <?php settings_fields('meili_search'); ?>
    <table class="form-table">
      <tr>
        <th>URL</th>
        <td><input type="url" name="meili_search_url" class="regular-text"
            value="<?php echo esc_attr(get_option('meili_search_url')); ?>">
            <p class="description">e.g. https://search.rechtspreker.nl</p>
        </td>
      </tr>
      <tr>
        <th>Search API Key</th>
        <td><input type="text" name="meili_search_key" class="regular-text"
            value="<?php echo esc_attr(get_option('meili_search_key')); ?>">
            <p class="description">Search-only key (safe to expose in frontend).</p>
        </td>
      </tr>
    </table>
    <?php submit_button(); ?>
  </form>
</div>
<?php }

// --- Shortcode ----------------------------------------------------------
// Usage: [meili_search index="productions" placeholder="Zoek…" show_images="1"]

add_shortcode('meili_search', function ($atts) {
    $atts = shortcode_atts([
        'index'       => 'productions',
        'placeholder' => 'Zoeken…',
        'show_images' => '1',
    ], $atts, 'meili_search');

    $url        = get_option('meili_search_url', '');
    $key        = get_option('meili_search_key', '');
    $show_img   = filter_var($atts['show_images'], FILTER_VALIDATE_BOOLEAN);
    $uid        = 'meili-' . wp_unique_id();

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
.meili-hit {
  display: flex !important;
  flex-direction: row !important;
  align-items: flex-start !important;
  gap: 1.2em;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 1em;
  margin-bottom: 1em;
  background: #fff;
}
.meili-hit img {
  width: 120px !important;
  min-width: 120px !important;
  max-width: 120px !important;
  height: auto !important;
  border: 1px solid #ddd;
  border-radius: 3px;
  object-fit: contain;
  align-self: flex-start;
  flex-shrink: 0;
}
a.meili-hit-thumb { flex-shrink: 0; display: block !important; width: 120px; }
a.meili-hit-thumb:hover { opacity: 0.85; }
.meili-hit-body  { flex: 1; min-width: 0; }
.meili-hit-body a { text-decoration: none; color: inherit; }
.meili-hit-body a:hover { text-decoration: underline; }
.meili-hit h3    { margin: 0 0 0.4em; font-size: 1em; color: #333; }
.meili-hit p     { margin: 0; font-size: 0.88em; color: #555; line-height: 1.6; }
.meili-hit em    { background: #fff3b0; font-style: normal; padding: 0 2px; border-radius: 2px; }
</style>

<script>
(function () {
  var uid        = <?php echo json_encode($uid); ?>;
  var showImages = <?php echo $show_img ? 'true' : 'false'; ?>;

  var searchClient = instantMeiliSearch(
    <?php echo json_encode($url); ?>,
    <?php echo json_encode($key); ?>
  ).searchClient;

  var search = instantsearch({
    indexName: <?php echo json_encode($atts['index']); ?>,
    searchClient: searchClient,
  });

  search.addWidgets([
    instantsearch.widgets.searchBox({
      container: '#' + uid + '-searchbox',
      placeholder: <?php echo json_encode($atts['placeholder']); ?>,
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
        item: function (hit, bind) {
          var title = (hit.production ? hit.production : hit.id) +
                      (hit.page_number ? ' — pagina ' + hit.page_number : '');
          var snippet = bind.components.Snippet({ hit: hit, attribute: 'text' });
          if (showImages && hit.image_url) {
            return bind.html`
              <div class="meili-hit">
                <a href="${hit.image_url}" target="_blank" class="meili-hit-thumb">
                  <img src="${hit.image_url}" alt=${'pagina ' + hit.page_number} loading="lazy">
                </a>
                <div class="meili-hit-body">
                  <h3><a href="${hit.image_url}" target="_blank">${title}</a></h3>
                  <p>${snippet}</p>
                </div>
              </div>`;
          }
          return bind.html`
            <div class="meili-hit">
              <div class="meili-hit-body">
                <h3>${title}</h3>
                <p>${snippet}</p>
              </div>
            </div>`;
        },
        empty: function () { return ''; },
      },
    }),
    instantsearch.widgets.configure({
      attributesToSnippet: ['text:50'],
      snippetEllipsisText: '…',
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
