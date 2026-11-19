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

            function frame() {
                if (document.hidden) return;
                ctx.clearRect(0, 0, size, size);
                ctx.drawImage(img, dx, dy, w, h);
                var href = canvas.toDataURL('image/png');
                if (href !== lastHref) {
                    lastHref = href;
                    link.href = href;
                }
            }

            timer = setInterval(frame, 120);
            frame();

            document.addEventListener('visibilitychange', function () {
                if (document.hidden) {
                    clearInterval(timer);
                } else {
                    clearInterval(timer);
                    timer = setInterval(frame, 120);
                    frame();
                }
            });
        };

        img.src = src;
    } catch (e) {}
})();
</script>
