<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'Sign In | BiblioManga' }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Rounded:wght@300;400;600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="relative min-h-screen overflow-auto bg-[#f6f1e8] text-[#24323f] antialiased" style="font-family: 'Inter', sans-serif;">
    <div class="pointer-events-none fixed inset-0 overflow-hidden">
        <div class="absolute -top-40 left-0 h-96 w-96 -translate-x-1/3 rounded-full bg-[#0f766e]/20 blur-3xl"></div>
        <div class="absolute top-32 right-0 h-[30rem] w-[30rem] translate-x-1/4 rounded-full bg-[#f4bb7a]/35 blur-3xl"></div>
        <div class="absolute bottom-0 left-1/2 h-[20rem] w-[20rem] -translate-x-1/2 translate-y-24 rounded-full bg-[#fde68a]/35 blur-3xl"></div>
    </div>

    <div class="relative z-10 flex min-h-screen items-center justify-center px-6 py-12">
        <div class="grid w-full max-w-6xl gap-10 rounded-[3rem] border border-[#dcd2bd] bg-white/75 p-8 shadow-[0_35px_90px_-40px_rgba(15,87,82,0.55)] backdrop-blur md:grid-cols-[1.1fr_0.9fr]">
            <div class="hidden flex-col justify-between rounded-[2.5rem] border border-[#dcd2bd] bg-[#fff7ec] p-10 md:flex">
                <div class="space-y-6">
                    <span class="inline-flex items-center gap-2 rounded-full border border-[#f4bb7a] bg-white/80 px-4 py-1 text-xs font-semibold uppercase tracking-[0.35em] text-[#b95d23]">
                        <span class="material-symbols-rounded text-sm">auto_stories</span>
                        BiblioManga
                    </span>
                    <h1 class="text-4xl font-semibold leading-tight text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                        Read your favorite manga, manhwa, and manhua without limits.
                    </h1>
                    <p class="text-sm leading-relaxed text-[#4c5b54]">
                        Public catalog remains open without login. Account is only required to manage your profile and reading preferences.
                    </p>
                </div>

                <div class="mt-10 space-y-5 text-sm text-[#4c5b54]">
                    <div class="flex items-start gap-3 rounded-[1.75rem] border border-[#f4bb7a]/40 bg-white/80 px-4 py-3">
                        <span class="material-symbols-rounded text-base text-[#f97316]">public</span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.35em] text-[#b95d23]">Public Access</p>
                            <p class="mt-1">Browse and read chapters without registration, login, or fees.</p>
                        </div>
                    </div>
                    <div class="flex items-start gap-3 rounded-[1.75rem] border border-[#0f766e]/40 bg-[#0f766e]/10 px-4 py-3">
                        <span class="material-symbols-rounded text-base text-[#0f766e]">bookmark_added</span>
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.35em] text-[#0f766e]">For Readers</p>
                            <p class="mt-1">Save favorites and reading history directly on your device.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-6 rounded-[2.5rem] border border-[#dcd2bd] bg-white/90 p-8 shadow-[0_25px_70px_-45px_rgba(15,87,82,0.45)]">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-[#0f766e]/10 text-[#0f766e]">
                        <span class="material-symbols-rounded text-2xl">lock</span>
                    </span>
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.35em] text-[#6b766f]">Account Portal</p>
                        <h2 class="text-xl font-semibold text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                            Sign In to BiblioManga
                        </h2>
                    </div>
                </div>

                <div class="space-y-2 text-sm text-[#4c5b54]">
                    <p>Use your registered account to access your profile.</p>
                    <p class="text-xs text-[#9aa29a]">Want to read without an account? The public catalog is still accessible directly.</p>
                </div>

                <div class="space-y-6 animate-fadeInUp">
                    {{ $slot }}
                </div>

                <p class="pt-6 text-center text-xs text-[#9aa29a]">
                    &copy; {{ now()->year }} BiblioManga • Free Online Manga Reader
                </p>
            </div>
        </div>
    </div>
</body>

</html>
