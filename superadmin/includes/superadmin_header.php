<?php
// Super Admin Sidebar / Header Include
$current_page = basename($_SERVER['PHP_SELF']);
?>
<aside class="fixed left-0 top-0 bottom-0 w-64 bg-white border-r border-slate-200 p-4 flex flex-col z-40 hidden md:flex shadow-sm">
    <div class="flex items-center gap-3 px-2 py-4 mb-4">
        <div class="w-9 h-9 rounded-xl bg-gradient-to-tr from-indigo-600 via-purple-600 to-pink-500 flex items-center justify-center text-white shadow-md">
            <span class="material-symbols-outlined text-[20px]">shield_person</span>
        </div>
        <div>
            <span class="font-extrabold text-sm tracking-tight"><span class="text-indigo-600">Shuttle</span><span class="text-purple-600">Sync</span></span>
            <p class="text-[10px] text-purple-600 font-bold uppercase tracking-widest">SuperAdmin</p>
        </div>
    </div>

    <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-widest px-3 mb-2">Navigation</p>
    <nav class="space-y-1.5">
        <!-- User Management Tab -->
        <a href="index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-purple-50 text-purple-700 font-bold text-sm">
            <span class="material-symbols-outlined text-[20px]">group</span> User Management
        </a>

        <!-- Create New Admin Button -->
        <button type="button" onclick="openCreateAdminModal()" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-600 hover:bg-purple-50 hover:text-purple-600 font-bold text-sm transition-colors text-left">
            <span class="material-symbols-outlined text-[20px]">person_add</span> Create New Admin
        </button>
    </nav>

    <!-- Sign Out -->
    <div class="mt-auto pt-4 border-t border-slate-100">
        <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-600 hover:bg-rose-50 font-bold text-xs transition-colors">
            <span class="material-symbols-outlined text-[18px]">logout</span> Sign Out
        </a>
    </div>
</aside>