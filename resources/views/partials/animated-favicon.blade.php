{{--
    Animated site icon.

    Chrome/Edge do not animate GIF favicons (they render the first frame only),
    while Firefox does. To animate everywhere, the GIF is drawn onto a tiny
    canvas on a timer and each snapshot is pushed into <link rel="icon">.
    The tab is paused while hidden, and the canvas is 32x32 at ~8 fps.
--}}
<script>
(function () {
    try {
        if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        function startFavicon() {
            var src = @js(asset('favicon.gif'));
            var size = 32;
            var img = new Image();

            img.onload = function () {
                if (!img.naturalWidth || !img.naturalHeight) return;

                var canvas = document.createElement('canvas');
                canvas.width = size;
                canvas.height = size;
                var ctx = canvas.getContext('2d');
                if (!ctx) return;

                var link = document.querySelector('link[rel="icon"][type="image/gif"]');
                if (!link) {
                    link = document.createElement('link');
                    link.rel = 'icon';
                    document.head.appendChild(link);
                }

                var scale = Math.min(size / img.naturalWidth, size / img.naturalHeight);
                var w = Math.round(img.naturalWidth * scale);
                var h = Math.round(img.naturalHeight * scale);
                var dx = Math.round((size - w) / 2);
                var dy = Math.round((size - h) / 2);

                var lastHref = '';
                var timer = null;

                function shouldPause() {
                    return document.hidden || !!document.fullscreenElement;
                }

                function frame() {
                    if (shouldPause()) return;
                    ctx.clearRect(0, 0, size, size);
                    ctx.drawImage(img, dx, dy, w, h);
                    var href = canvas.toDataURL('image/png');
                    if (href !== lastHref) {
                        lastHref = href;
                        link.href = href;
                    }
                }

                function syncTimer() {
                    clearInterval(timer);
                    if (!shouldPause()) {
                        timer = setInterval(frame, 240);
                        frame();
                    }
                }

                syncTimer();
                document.addEventListener('visibilitychange', syncTimer);
                document.addEventListener('fullscreenchange', syncTimer);
            };

            img.src = src;
        }

        // Defer until after page load & idle so the animated icon never competes
        // with initial render, images, or video startup.
        window.addEventListener('load', function () {
            if ('requestIdleCallback' in window) {
                requestIdleCallback(startFavicon, { timeout: 2000 });
            } else {
                setTimeout(startFavicon, 800);
            }
        }, { once: true });
    } catch (e) {}
})();
</script>
