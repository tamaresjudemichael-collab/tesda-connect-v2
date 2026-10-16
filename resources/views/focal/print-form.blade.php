<x-layouts::app.sidebar :title="$formTitle">
    <flux:main class="p-4 sm:p-6 lg:p-7">
        <style>
            @media print {
                .print-toolbar, .tesda-sidebar, [data-flux-header] { display: none !important; }
                body, main { background: #fff !important; padding: 0 !important; margin: 0 !important; }
                .print-sheet { border: 0 !important; box-shadow: none !important; margin: 0 !important; max-width: none !important; }
                .print-sheet table { font-size: 9pt !important; }
                .print-sheet th, .print-sheet td { padding: 5px !important; }
                thead { display: table-header-group; }
                tr { page-break-inside: avoid; }
            }
        </style>
        <div class="print-toolbar mx-auto mb-4 flex max-w-6xl items-center justify-between gap-3">
            <div><h1 class="text-xl font-bold text-slate-900">Print-ready form</h1><p class="text-sm text-slate-500">Use Print → Save as PDF to create a local PDF file.</p></div>
            <div class="flex gap-2"><flux:button variant="outline" href="{{ route('focal.forms') }}" wire:navigate>Back to Forms</flux:button><flux:button variant="primary" icon="printer" onclick="window.print()">Print / Save PDF</flux:button></div>
        </div>
        <article class="print-sheet mx-auto max-w-6xl rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
            <div class="border-b-2 border-slate-900 pb-4 text-center">
                <p class="text-xs font-bold uppercase tracking-widest text-slate-600">TESDA Connect · Training Operations</p>
                <h1 class="mt-2 text-2xl font-black text-slate-900">{{ $formTitle }}</h1>
                <p class="mt-2 text-sm text-slate-700">Institution: {{ $institutionName }}</p>
                <p class="mt-1 text-xs text-slate-500">Generated {{ $generatedAt->format('M j, Y g:i A') }}</p>
            </div>
            @if($formTitle === 'Meeting Minutes Template')
                <div class="mt-6 grid gap-4 text-sm sm:grid-cols-2"><p>Meeting Date: __________________________________</p><p>Time: _________________________________________</p><p>Venue: ________________________________________</p><p>Facilitator: ____________________________________</p></div>
            @endif
            <div class="mt-6 overflow-x-auto">
                <table class="w-full border-collapse text-left text-xs sm:text-sm">
                    <thead><tr>@foreach($headers as $header)<th class="border border-slate-400 bg-slate-100 px-2 py-2 font-bold text-slate-900">{{ $header }}</th>@endforeach</tr></thead>
                    <tbody>
                        @forelse($rows as $row)<tr>@foreach($headers as $index => $header)<td class="border border-slate-400 px-2 py-2 text-slate-800">{{ $row[$index] ?? '' }}</td>@endforeach</tr>@empty
                            @for($blank = 0; $blank < 15; $blank++)<tr>@foreach($headers as $header)<td class="h-8 border border-slate-400 px-2 py-2">&nbsp;</td>@endforeach</tr>@endfor
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="mt-8 grid gap-10 text-sm sm:grid-cols-2"><p>Prepared by: ___________________________<br><span class="text-xs text-slate-500">Signature / Date</span></p><p>Checked / Received by: __________________<br><span class="text-xs text-slate-500">Signature / Date</span></p></div>
            <p class="mt-8 border-t border-slate-200 pt-3 text-[10px] text-slate-500">This is a system-generated working document. For official TESDA submission, verify and use the latest prescribed TESDA form and revision where applicable.</p>
        </article>
    </flux:main>
</x-layouts::app.sidebar>
