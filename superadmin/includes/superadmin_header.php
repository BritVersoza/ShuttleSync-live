<?php
// Super Admin Sidebar / Header Include
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!-- I-load ang Poppins font mula sa Google Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
    body, .font-poppins {
        font-family: 'Poppins', sans-serif !important;
    }
</style>

<aside class="fixed left-0 top-0 bottom-0 w-64 bg-[#080F17] text-white border-r border-slate-800 p-4 flex flex-col z-40 hidden md:flex shadow-sm font-poppins">
    <div class="flex items-center gap-3 px-2 py-4 mb-4">
        <!-- Logo Icon gamit ang Hi-Power Orange (#FF5722) -->
        <div class="w-9 h-9 rounded-xl bg-[#FF5722] flex items-center justify-center text-white shadow-md">
            <span class="material-symbols-outlined text-[20px]">shield_person</span>
        </div>
        <div>
            <!-- HI-POWER / ShuttleSync branding -->
            <span class="font-extrabold text-sm tracking-tight"><span class="text-[#FF5722]">HI</span>-<span class="text-white">POWER</span></span>
            <p class="text-[10px] text-[#FF5722] font-bold uppercase tracking-widest">SuperAdmin</p>
        </div>
    </div>

    <p class="text-[10px] font-extrabold text-slate-400 uppercase tracking-widest px-3 mb-2">Navigation</p>
    <nav class="space-y-1.5">
        <!-- User Management Tab (Active state gamit ang Hi-Power Orange tint) -->
        <a href="index.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl bg-[#FF5722]/15 text-[#FF5722] font-bold text-sm border border-[#FF5722]/30">
            <span class="material-symbols-outlined text-[20px]">group</span> User Management
        </a>

        <!-- Create New Admin Button -->
        <button type="button" onclick="openCreateAdminModal()" class="w-full flex items-center gap-3 px-3 py-2.5 rounded-xl text-slate-300 hover:bg-slate-800 hover:text-[#FF5722] font-semibold text-sm transition-colors text-left">
            <span class="material-symbols-outlined text-[20px]">person_add</span> Create New Admin
        </button>
    </nav>

    <!-- Sign Out -->
    <div class="mt-auto pt-4 border-t border-slate-800">
        <a href="logout.php" class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-rose-400 hover:bg-rose-500/10 font-bold text-xs transition-colors">
            <span class="material-symbols-outlined text-[18px]">logout</span> Sign Out
        </a>
    </div>
</aside>