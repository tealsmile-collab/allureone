<?php
declare(strict_types=1);

/**
 * Amplitude browser tracking for the public deals storefront.
 * Admin screens must not call this.
 */
function deals_render_amplitude(): void
{
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/admin(?:/|$)#i', $script)) {
        return;
    }
    echo <<<'HTML'
<script type="module">
  import * as amplitude from 'https://cdn.jsdelivr.net/npm/@amplitude/analytics-browser@2/+esm';

  amplitude.init('e616b0354f9af02d249bfe8942463141', {
    autocapture: {
      pageViews: true,
      sessions: true,
      formInteractions: true,
      fileDownloads: true,
      elementInteractions: true
    }
  });
</script>
HTML;
}
