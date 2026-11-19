<script>
  (function () {
    var entry = performance.getEntriesByType && performance.getEntriesByType('navigation')[0];
    var type = entry && entry.type;
    if (!type && performance.navigation && performance.navigation.type === 2) {
      type = 'back_forward';
    }
    if (type === 'back_forward') {
      document.documentElement.setAttribute('data-cream-nav', 'back_forward');
    }
  })();
</script>
<style>
#cream-loader{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;padding:24px;background:oklch(.968 .018 90);color:oklch(.245 .010 70);opacity:1;transition:opacity .24s ease;pointer-events:none}
#cream-loader.is-hiding{opacity:0}
#cream-loader[hidden]{display:none}
.cream-loader-card{display:grid;justify-items:center;gap:12px;text-align:center}
.cream-loader-mark{width:48px;height:48px;border-radius:50%;border:3px solid oklch(.885 .012 85);border-top-color:oklch(.680 .135 32);animation:cream-spin .8s linear infinite;box-shadow:3px 3px 0 oklch(.245 .010 70 / .08)}
#cream-loader-label{font-size:14px;letter-spacing:.04em;font-weight:700}
#cream-loader-detail{font:11px/1.4 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;color:oklch(.470 .010 70);letter-spacing:.08em;text-transform:uppercase}
@keyframes cream-spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){.cream-loader-mark{animation:none}.cream-loader-mark{border-top-color:oklch(.680 .135 32)}}
html[data-cream-nav="back_forward"] #cream-loader{display:none!important}
</style>
<noscript><style>#cream-loader{display:none!important}</style></noscript>
