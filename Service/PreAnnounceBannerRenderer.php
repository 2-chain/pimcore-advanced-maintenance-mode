<?php

declare(strict_types=1);

namespace TwoChain\PimcoreAdvancedMaintenanceModeBundle\Service;

use DateTimeInterface;

class PreAnnounceBannerRenderer
{
    public function __construct(private readonly BundleConfiguration $config) {}

    public function render(PreAnnounceData $data, ?string $nonce = null): string
    {
        $target         = $data->at->format(DateTimeInterface::ATOM);
        $reason         = $data->reason !== null ? \htmlspecialchars($data->reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : '';
        $dismissKey     = 'amm_dismissed_' . $data->at->getTimestamp();
        $dismissStorage = $this->config->dismissPersistence === 'local' ? 'local' : 'session';
        $orange         = $this->config->urgencyOrangeMinutes;
        $red            = $this->config->urgencyRedMinutes;
        $nonceAttr      = $nonce !== null ? ' nonce="' . \htmlspecialchars($nonce, ENT_QUOTES, 'UTF-8') . '"' : '';

        $infoSvg    = '/bundles/pimcoreadmin/img/flat-color-icons/info.svg';
        $warnSvg    = '/bundles/pimcoreadmin/img/flat-color-icons/warning.svg';
        $errorSvg   = '/bundles/pimcoreadmin/img/flat-color-icons/overlay-error.svg';

        $reasonSpan = $this->reasonSpan($data->reason);

        return <<<HTML
            <div id="amm-banner"
                 data-target="{$target}"
                 data-reason="{$reason}"
                 data-dismiss-key="{$dismissKey}"
                 data-dismiss-storage="{$dismissStorage}"
                 data-orange-minutes="{$orange}"
                 data-red-minutes="{$red}"
                 style="position:fixed;top:16px;right:16px;z-index:99999;display:flex;align-items:flex-start;gap:10px;padding:12px 14px;background:#f59e0b;color:#1f2937;font-family:sans-serif;font-size:13px;border-radius:6px;box-shadow:0 4px 12px rgba(0,0,0,.25);max-width:320px;min-width:220px">
              <img id="amm-icon" src="{$infoSvg}" width="18" height="18" alt="" style="flex-shrink:0;margin-top:1px">
              <span style="flex:1;line-height:1.4">Maintenance in <strong id="amm-countdown">--:--</strong>{$reasonSpan}</span>
              <button id="amm-dismiss" aria-label="Dismiss" style="background:none;border:none;cursor:pointer;font-size:16px;color:inherit;padding:0;line-height:1;flex-shrink:0">&times;</button>
            </div>
            <script{$nonceAttr}>
            (function(){
              var banner = document.getElementById('amm-banner');
              if (!banner) return;

              var key   = banner.dataset.dismissKey;
              var store = null;
              try {
                store = banner.dataset.dismissStorage === 'local' ? localStorage : sessionStorage;
                if (store.getItem(key)) { banner.remove(); return; }
              } catch(e) { store = null; }

              var target = new Date(banner.dataset.target).getTime();
              var orange = parseInt(banner.dataset.orangeMinutes, 10) * 60000;
              var red    = parseInt(banner.dataset.redMinutes,    10) * 60000;
              var infoSrc  = '{$infoSvg}';
              var warnSrc  = '{$warnSvg}';
              var errorSrc = '{$errorSvg}';

              function update() {
                var now  = Date.now();
                var diff = target - now;
                if (diff <= 0) { banner.remove(); return; }
                var mm = String(Math.floor(diff / 60000)).padStart(2,'0');
                var ss = String(Math.floor((diff % 60000) / 1000)).padStart(2,'0');
                var countdown = document.getElementById('amm-countdown');
                if (countdown) countdown.textContent = mm + ':' + ss;
                var icon = document.getElementById('amm-icon');
                if (diff <= red) {
                  banner.style.background = '#dc2626'; banner.style.color = '#fff';
                  if (icon) icon.src = errorSrc;
                } else if (diff <= orange) {
                  banner.style.background = '#ea580c'; banner.style.color = '#fff';
                  if (icon) icon.src = warnSrc;
                } else {
                  banner.style.background = '#f59e0b'; banner.style.color = '#1f2937';
                  if (icon) icon.src = infoSrc;
                }
              }
              update();
              setInterval(update, 1000);

              var dismissBtn = document.getElementById('amm-dismiss');
              if (dismissBtn) {
                dismissBtn.addEventListener('click', function() {
                  try { if (store) store.setItem(key, '1'); } catch(e) {}
                  banner.remove();
                });
              }
            })();
            </script>
            HTML;
    }

    private function reasonSpan(?string $reason): string
    {
        if ($reason === null || $reason === '') {
            return '';
        }
        return ' — ' . \htmlspecialchars($reason, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
