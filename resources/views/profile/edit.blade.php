<x-manga-layout title="Profile Settings — BiblioManga">
    <div class="mx-auto w-full max-w-3xl px-4 py-10 sm:px-6 space-y-6">
        <div>
            <h1 class="text-2xl sm:text-3xl font-extrabold tracking-tight text-[#172a37]" style="font-family: 'Space Grotesk', sans-serif;">
                Profile Settings
            </h1>
            <p class="mt-1 text-sm text-[#4c5b54]">
                Update your account information, password, and manage your BiblioManga access.
            </p>
        </div>

        <div class="rounded-[2.5rem] border border-[#dcd2bd] bg-white/90 p-6 shadow-[0_25px_70px_-45px_rgba(15,87,82,0.45)]">
            <div class="max-w-xl">
                @include('profile.partials.update-profile-information-form')
            </div>
        </div>

        <div class="rounded-[2.5rem] border border-[#dcd2bd] bg-white/90 p-6 shadow-[0_25px_70px_-45px_rgba(15,87,82,0.45)]">
            <div class="max-w-xl">
                @include('profile.partials.update-password-form')
            </div>
        </div>

        <div class="rounded-[2.5rem] border border-[#be123c]/30 bg-[#be123c]/10 p-6 shadow-[0_25px_70px_-45px_rgba(190,18,60,0.35)]">
            <div class="max-w-xl">
                @include('profile.partials.delete-user-form')
            </div>
        </div>
    </div>
</x-manga-layout>
