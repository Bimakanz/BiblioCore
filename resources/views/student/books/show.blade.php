<x-app-layout>
    <x-slot name="pageHeading">
        {{ $book->title }}
    </x-slot>

    <x-slot name="pageDescription">
        Baca E-book secara real-time & diskusi dengan pembaca lain.
    </x-slot>

    <div class="grid gap-8 lg:grid-cols-[1.2fr_1fr]">
        <!-- Kiri: Detail Buku -->
        <div
            class="space-y-6 rounded-[2.5rem] border border-[#dcd2bd] bg-white/90 p-6 shadow-[0_25px_70px_-45px_rgba(15,87,82,0.45)]">
            <div class="relative overflow-hidden rounded-[2.5rem]">
                @if ($book->cover_image_path)
                    <img src="{{ asset('storage/' . $book->cover_image_path) }}" alt="{{ $book->title }}"
                        class="w-full rounded-[2.5rem] object-cover shadow-lg" />
                @else
                    <div
                        class="flex h-80 w-full items-center justify-center rounded-[2.5rem] bg-[#f6ecda] text-sm font-semibold uppercase tracking-[0.3em] text-[#b69c74]">
                        Belum ada gambar
                    </div>
                @endif
                <div
                    class="absolute top-4 left-4 inline-flex items-center gap-2 rounded-full border border-white/40 bg-white/20 px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em] text-white">
                    {{ $book->category }}
                </div>
            </div>

            <div class="space-y-3">
                <div
                    class="inline-flex items-center gap-2 rounded-full bg-[#0f766e]/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.3em] text-[#0f766e]">
                    <span class="material-symbols-rounded text-sm">person</span>
                    {{ $book->author ?? 'Penulis tidak diketahui' }}
                </div>
                <p class="text-sm leading-relaxed text-[#4c5b54]">{{ $book->description }}</p>
            </div>
            <!-- Feedback/Saran Form -->
            <form method="POST" action="{{ route('books.like', $book) }}" class="w-full flex gap-2">
                @csrf
                <input type="text" name="feedback" placeholder="Tulis saranmu..."
                    class="flex-1 h-12 rounded-full border border-[#dcd2bd] px-4 text-sm placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:ring-2 focus:ring-[#0f766e]/20 transition" />
                <button type="submit"
                    class="h-12 px-6 rounded-full bg-[#f97316] text-sm font-bold text-white uppercase tracking-[0.2em] shadow-lg transform transition-all duration-300 hover:scale-105 hover:shadow-xl">
                    Kirim
                </button>
            </form>
        </div>

        <!-- Kanan: Teks Novel -->
        <div
            class="rounded-[2.5rem] border border-[#dcd2bd] bg-white/95 p-8 shadow-[0_25px_70px_-45px_rgba(15,87,82,0.45)] flex flex-col">
            <h3 class="text-4xl font-semibold text-center text-[#172a37]"
                style="font-family: 'Space Grotesk', sans-serif;">Mulai Baca</h3>
            <p class="mt-1 text-center text-sm text-[#4c5b54]">Selamat membaca ❤️</p>

            <!-- Area scroll teks -->
            <div
                class="mt-6 flex-1 overflow-y-auto rounded-[1.5rem] border border-[#eaddc2] bg-[#fdfcf9] p-6 text-[#] text-xl leading-relaxed shadow-inner max-h-[150vh] custom-scroll">
                {!! nl2br(e($book->novel_text)) !!}
            </div>
        </div>
    </div>

    <!-- Like & Saran Section -->
    <div class="mt-8 flex flex-col items-center gap-5 w-full max-w-md mx-auto">
        <p class="text-sm text-[#4c5b54] font-medium text-center">Suka dengan novel ini? Beri like dan saran ❤️</p>

        <!-- Like Button -->
        <div class=" relative">
            <form method="POST" action="{{ route('books.like', $book) }}" class="flex justify-center">
                @csrf
                <button type="submit"
                    class="relative h-12 w-full max-w-xs rounded-full bg-gradient-to-r from-[#0f766e] to-[#14b8a6] px-6 text-sm font-bold text-white uppercase tracking-[0.2em] shadow-lg transform transition-all duration-300 hover:scale-105 hover:shadow-xl active:scale-95"
                    id="likeButton">
                    👍 Like
                    <span
                        class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 opacity-0 text-lg animate-like"
                        id="likeEmoji">👍</span>
                </button>
            </form>
            <!-- Success Message (hidden by default) -->
            <div id="likeSuccessMessage"
                class="absolute top-0 left-1/2 transform -translate-x-1/2 -translate-y-[150%] bg-green-500 text-white text-xs font-semibold px-3 py-1 rounded-full opacity-0 transition-all duration-300 whitespace-nowrap"
                id="likeMessageDiv">
                <span id="likeMessage">Novel berhasil disukai!</span>
            </div>
        </div>


    </div>

    <!-- Form komentar & reply -->
    <form method="POST" action="{{ route('books.comment', $book) }}" class="flex flex-col gap-3 mb-6 max-w-3xl mx-auto"
        id="commentForm">
        @csrf
        <input type="hidden" name="parent_id" id="parent_id" value="">
        <textarea name="comment" rows="3" placeholder="Tulis komentar..."
            class="w-full rounded-xl border border-[#dcd2bd] p-3 text-sm placeholder:text-[#9aa29a] focus:border-[#0f766e] focus:ring-2 focus:ring-[#0f766e]/20 transition"></textarea>
        <button type="submit"
            class="self-end rounded-full bg-[#0f766e] px-6 py-2 text-white font-semibold uppercase text-sm tracking-[0.2em] hover:bg-[#115e59] transition">Kirim</button>
    </form>

    <!-- List komentar dengan multi-level reply -->
    <div class="space-y-4 max-w-3xl mx-auto">
        @if($book->comments && $book->comments->count())
            @foreach($book->comments->whereNull('parent_id') as $comment)
                @include('student.books.partials.comment', ['comment' => $comment, 'level' => 0])
            @endforeach
        @else
            <p class="text-sm text-gray-500 max-w-3xl mx-auto">Belum ada komentar. Jadilah yang pertama memberi komentar!
            </p>
        @endif
    </div>

    <script>
        function setReply(commentId, username) {
            const parentInput = document.getElementById('parent_id');
            const textarea = document.querySelector('#commentForm textarea');
            parentInput.value = commentId;
            textarea.placeholder = "Reply ke " + username + "...";
            textarea.focus();
        }

        document.getElementById('likeButton').addEventListener('click', function (e) {
            const emoji = document.getElementById('likeEmoji');
            emoji.classList.remove('animate-like');
            void emoji.offsetWidth;
            emoji.classList.add('animate-like');
        });

        // If there's a success message from server (e.g., from redirect)
        @if(session('success'))
            document.addEventListener('DOMContentLoaded', function () {
                const successMessage = document.getElementById('likeSuccessMessage');
                const likeMessage = document.getElementById('likeMessage');

                // Get the server message
                const serverMessage = "{{ session('success') }}";
                likeMessage.textContent = serverMessage;

                // Change background color based on message type
                if (serverMessage.includes('dihapus')) {
                    // Unliking - red background
                    successMessage.className = successMessage.className.replace('bg-green-500', 'bg-red-500');
                } else {
                    // Liking - green background
                    successMessage.className = successMessage.className.replace('bg-red-500', 'bg-green-500');
                }

                successMessage.classList.remove('opacity-0');
                successMessage.classList.add('animate-show');

                // Reset animation class after it completes
                setTimeout(() => {
                    successMessage.classList.remove('animate-show');
                    successMessage.classList.add('opacity-0');
                }, 2000);
            });
        @endif
    </script>
    </script>

    <style>
        .custom-scroll::-webkit-scrollbar {
            width: 8px;
        }

        .custom-scroll::-webkit-scrollbar-track {
            background: #f6ecda;
            border-radius: 10px;
        }

        .custom-scroll::-webkit-scrollbar-thumb {
            background: #0f766e;
            border-radius: 10px;
        }

        .custom-scroll::-webkit-scrollbar-thumb:hover {
            background: #115e59;
        }

        @keyframes like-pop {
            0% {
                transform: translateY(0) scale(0);
                opacity: 1;
            }

            50% {
                transform: translateY(-30px) scale(1.5);
                opacity: 1;
            }

            100% {
                transform: translateY(-60px) scale(1);
                opacity: 0;
            }
        }

        .animate-like {
            animation: like-pop 0.6s ease forwards;
        }

        @keyframes showMessage {
            0% {
                opacity: 0;
                transform: translate(-50%, -50%) scale(0.8);
            }

            10% {
                opacity: 1;
                transform: translate(-50%, -150%) scale(1);
            }

            90% {
                opacity: 1;
                transform: translate(-50%, -150%) scale(1);
            }

            100% {
                opacity: 0;
                transform: translate(-50%, -200%) scale(0.8);
            }
        }

        .animate-show {
            animation: showMessage 2s ease forwards;
        }
    </style>
</x-app-layout>